<?php

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.booking')] #[Title('Online Booking')] class extends Component
{
    public string $slug = '';
    public ?int $businessId = null;

    // Steps: 1 = Service, 2 = Staff, 3 = Date & Time, 4 = Details, 5 = Confirmed
    public int $step = 1;

    // Selections
    public ?int $selectedServiceId = null;
    public ?int $selectedStaffId = null; // null = Any Available Specialist
    public string $selectedDate = '';
    public string $selectedTime = '';

    // Customer Form
    public string $customer_name = '';
    public string $customer_phone = '';
    public string $customer_email = '';
    public ?string $notes = null;
    public bool $marketing_consent = false;

    // Confirmation State
    public ?int $confirmedAppointmentId = null;
    public bool $emailConfirmationSent = false;
    public bool $paymentCancelledAlert = false;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $business = Business::where('slug', $slug)->firstOrFail();
        $this->businessId = $business->id;
        Tenant::set($business->id);

        $this->selectedDate = $business->now()->toDateString();

        // Check if customer is returning from Stripe Checkout
        $confirmedId = session()->pull('booking.confirmed');
        if ($confirmedId) {
            $appointment = Appointment::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->with(['service', 'customer', 'staffMember'])
                ->find($confirmedId);

            if ($appointment) {
                $this->confirmedAppointmentId = $appointment->id;
                $this->selectedServiceId = $appointment->service_id;
                $this->customer_name = $appointment->customer?->name ?? 'Valued Client';
                $this->step = 5;
            }
        }

        if (request()->query('cancelled_payment')) {
            $this->paymentCancelledAlert = true;
        }
    }

    public function boot(): void
    {
        if ($this->businessId) {
            Tenant::set($this->businessId);
        }
    }

    public function business(): Business
    {
        return Business::findOrFail($this->businessId);
    }

    public function selectService(int $serviceId): void
    {
        $this->selectedServiceId = $serviceId;
        $this->step = 2;
    }

    public function selectStaff(?int $staffId): void
    {
        $this->selectedStaffId = $staffId;
        $this->step = 3;
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedTime = '';
    }

    public function selectTime(string $time): void
    {
        $this->selectedTime = $time;
        $this->step = 4;
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    /**
     * Generate available time slots for the chosen service, staff, and date.
     * Respects business opening hours (default 09:00 - 18:00) and existing appointments.
     */
    public function getAvailableSlotsProperty(): array
    {
        if (! $this->selectedServiceId || ! $this->selectedDate) {
            return [];
        }

        $business = $this->business();
        $service = Service::withoutGlobalScopes()->where('business_id', $business->id)->find($this->selectedServiceId);
        if (! $service) {
            return [];
        }

        $tz = $business->timezone ?: 'Europe/London';
        $now = $business->now();
        $isToday = $this->selectedDate === $now->toDateString();

        $openTime = $business->setting('opening_hours.from', '09:00');
        $closeTime = $business->setting('opening_hours.to', '18:00');

        $dayStart = Carbon::parse($this->selectedDate . ' ' . $openTime, $tz);
        $dayEnd = Carbon::parse($this->selectedDate . ' ' . $closeTime, $tz);

        // Fetch active staff members to test against
        $activeStaff = StaffMember::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->active()
            ->get();

        if ($activeStaff->isEmpty()) {
            return [];
        }

        // If specific specialist selected, check if they work on this day at all
        if ($this->selectedStaffId !== null) {
            $selectedStaff = $activeStaff->firstWhere('id', $this->selectedStaffId);
            if (! $selectedStaff || ! $selectedStaff->isWorkingOnDate($dayStart)) {
                return [];
            }
        }

        // Fetch existing blocking appointments for the day
        $dayUtcStart = $dayStart->clone()->utc();
        $dayUtcEnd = $dayEnd->clone()->utc();

        $existingAppointments = Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->blocking()
            ->where('starts_at', '<', $dayUtcEnd)
            ->where('ends_at', '>', $dayUtcStart)
            ->get(['staff_member_id', 'starts_at', 'ends_at']);

        $duration = $service->duration_minutes;
        $slots = [];
        $cursor = $dayStart->clone();

        while ($cursor->clone()->addMinutes($duration)->lte($dayEnd)) {
            $timeString = $cursor->format('H:i');
            $slotStartUtc = $cursor->clone()->utc();
            $slotEndUtc = $slotStartUtc->clone()->addMinutes($duration);
            $slotStartLocal = $cursor->clone();
            $slotEndLocal = $slotStartLocal->clone()->addMinutes($duration);

            // Cannot book in the past
            if ($isToday && $cursor->lte($now)) {
                $cursor->addMinutes(30);
                continue;
            }

            // Check clash and specialist shift availability
            $isAvailable = false;
            if ($this->selectedStaffId !== null) {
                $staff = $activeStaff->firstWhere('id', $this->selectedStaffId);
                if ($staff && $staff->isAvailableForSlot($slotStartLocal, $slotEndLocal, $slotStartUtc, $slotEndUtc)) {
                    $hasClash = $existingAppointments->contains(function ($apt) use ($slotStartUtc, $slotEndUtc) {
                        return $apt->staff_member_id === $this->selectedStaffId
                            && $apt->starts_at < $slotEndUtc
                            && $apt->ends_at > $slotStartUtc;
                    });
                    $isAvailable = ! $hasClash;
                }
            } else {
                // "Any Staff": Is there AT LEAST ONE staff member available for this shift and free of clashes?
                foreach ($activeStaff as $staff) {
                    if ($staff->isAvailableForSlot($slotStartLocal, $slotEndLocal, $slotStartUtc, $slotEndUtc)) {
                        $hasClash = $existingAppointments->contains(function ($apt) use ($staff, $slotStartUtc, $slotEndUtc) {
                            return $apt->staff_member_id === $staff->id
                                && $apt->starts_at < $slotEndUtc
                                && $apt->ends_at > $slotStartUtc;
                        });
                        if (! $hasClash) {
                            $isAvailable = true;
                            break;
                        }
                    }
                }
            }

            if ($isAvailable) {
                $slots[] = [
                    'time' => $timeString,
                    'formatted' => $cursor->format('g:i A'),
                    'period' => $cursor->hour < 12 ? 'morning' : ($cursor->hour < 17 ? 'afternoon' : 'evening'),
                ];
            }

            $cursor->addMinutes(30);
        }

        return $slots;
    }

    public function submitBooking()
    {
        $rawPhone = trim($this->customer_phone);
        $normalizedPhone = Phone::normalise($rawPhone) ?? $rawPhone;
        $this->customer_phone = $normalizedPhone;

        $this->validate([
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'customer_phone' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! Phone::looksValid($value)) {
                    $fail('Please enter a valid mobile number (e.g. 07700 900123).');
                }
            }],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'selectedServiceId' => ['required', 'integer'],
            'selectedDate' => ['required', 'date'],
            'selectedTime' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
            'marketing_consent' => ['boolean'],
        ]);

        $business = $this->business();
        $tz = $business->timezone ?: 'Europe/London';

        $service = Service::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->findOrFail($this->selectedServiceId);

        $startsAt = Carbon::parse($this->selectedDate . ' ' . $this->selectedTime, $tz)->utc();
        $endsAt = $startsAt->clone()->addMinutes($service->duration_minutes);

        // Resolve staff member if "any available" was selected
        $assignedStaffId = $this->selectedStaffId;
        if ($assignedStaffId === null) {
            $activeStaff = StaffMember::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->active()
                ->get();

            $slotStartLocal = Carbon::parse($this->selectedDate . ' ' . $this->selectedTime, $tz);
            $slotEndLocal = $slotStartLocal->clone()->addMinutes($service->duration_minutes);

            foreach ($activeStaff as $staff) {
                if (! $staff->isAvailableForSlot($slotStartLocal, $slotEndLocal, $startsAt, $endsAt)) {
                    continue;
                }

                $conflict = Appointment::withoutGlobalScopes()
                    ->where('business_id', $business->id)
                    ->conflictingWith($staff->id, $startsAt, $endsAt)
                    ->exists();

                if (! $conflict) {
                    $assignedStaffId = $staff->id;
                    break;
                }
            }

            // Fallback to first active staff if all busy
            $assignedStaffId = $assignedStaffId ?? $activeStaff->first()?->id;
        }

        // Final double-booking sanity check
        if ($assignedStaffId) {
            $hasConflict = Appointment::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->conflictingWith($assignedStaffId, $startsAt, $endsAt)
                ->exists();

            if ($hasConflict) {
                $this->addError('selectedTime', 'Sorry, this time slot was just booked by someone else. Please choose another slot.');
                $this->step = 3;
                return;
            }
        }

        $depositRequired = $business->depositEnabled();
        $depositAmount = $depositRequired ? $business->calculateDepositFor($service->price) : 0.00;

        $appointment = DB::transaction(function () use ($business, $service, $startsAt, $endsAt, $assignedStaffId, $depositRequired, $depositAmount) {
            // Find or create customer under this business
            $customer = Customer::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->where('phone', $this->customer_phone)
                ->first();

            if (! $customer) {
                $customer = new Customer();
                $customer->business_id = $business->id;
                $customer->name = trim($this->customer_name);
                $customer->phone = $this->customer_phone;
                $customer->email = $this->customer_email ?: null;
                $customer->preferred_channel = $business->defaultChannel();
                $customer->ensureTelegramLinkToken();
                $customer->save();

                if ($this->marketing_consent) {
                    $customer->recordConsent('booking_form');
                }
            } else {
                if (blank($customer->telegram_link_token)) {
                    $customer->ensureTelegramLinkToken();
                    $customer->save();
                }

                if ($this->marketing_consent && ! $customer->marketing_consent) {
                    $customer->recordConsent('booking_form');
                }
            }

            $apt = new Appointment();
            $apt->business_id = $business->id;
            $apt->customer_id = $customer->id;
            $apt->service_id = $service->id;
            $apt->staff_member_id = $assignedStaffId;
            $apt->starts_at = $startsAt;
            $apt->ends_at = $endsAt;
            // If deposit required, initial status is PENDING until deposit is paid; otherwise CONFIRMED
            $apt->status = ($depositRequired && $depositAmount > 0) ? Appointment::PENDING : Appointment::CONFIRMED;
            $apt->price = $service->price;
            $apt->deposit_required = $depositRequired && $depositAmount > 0;
            $apt->deposit_amount = $depositAmount;
            $apt->deposit_status = ($depositRequired && $depositAmount > 0) ? 'unpaid' : 'waived';
            $apt->paid_amount = 0.00;
            $apt->notes = $this->notes;
            $apt->source = 'online';
            $apt->save();

            $customer->recomputeVisitStats();

            return [$apt, $customer];
        });

        [$createdApt, $customer] = $appointment;
        $this->confirmedAppointmentId = $createdApt->id;

        // If deposit is required and > 0, redirect to Stripe Checkout
        if ($createdApt->deposit_required && $createdApt->deposit_amount > 0) {
            try {
                $stripeService = app(\App\Services\Payment\StripePaymentService::class);
                $successUrl = route('stripe.payment.success', $createdApt->id);
                $cancelUrl = route('stripe.payment.cancel', $createdApt->id);

                $session = $stripeService->createCheckoutSession($createdApt, $successUrl, $cancelUrl);

                return redirect()->away($session['url']);
            } catch (\RuntimeException $e) {
                $this->addError('general', $e->getMessage());
                $this->step = 4;
                return;
            }
        }

        // Calendar Invite Generation
        $ics = app(\App\Services\Calendar\IcsGenerator::class);
        $this->googleCalendarUrl = $ics->googleCalendarUrl($createdApt);
        $this->downloadIcsUrl = route('appointments.calendar.ics', $createdApt->cancellation_token);

        // Instant Confirmation Email Dispatch
        if (filled($customer->email)) {
            try {
                \Illuminate\Support\Facades\Mail::to($customer->email)->send(
                    new \App\Mail\AppointmentNotificationMail(
                        $createdApt,
                        "Booking Confirmed: {$service->name} at {$business->name}",
                        "Your appointment has been confirmed! We've attached a calendar invite so you can add it to your calendar with one tap.",
                        true
                    )
                );
                $this->emailConfirmationSent = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[booking] Could not send confirmation email', ['error' => $e->getMessage()]);
            }
        }

        $this->step = 5;
    }
}; ?>

<div class="max-w-2xl mx-auto">
    @php
        $biz = $this->business();
        $services = App\Models\Service::withoutGlobalScopes()->where('business_id', $biz->id)->active()->ordered()->get();
        $staffMembers = App\Models\StaffMember::withoutGlobalScopes()->where('business_id', $biz->id)->active()->ordered()->get();
        $selectedService = $this->selectedServiceId ? $services->firstWhere('id', $this->selectedServiceId) : null;
        $selectedStaff = $this->selectedStaffId ? $staffMembers->firstWhere('id', $this->selectedStaffId) : null;

        $confirmedAppointment = null;
        $googleCalendarUrl = null;
        $downloadIcsUrl = null;
        $telegramInviteUrl = null;
        $whatsappUrl = null;
        $cancellationUrl = null;

        if ($this->confirmedAppointmentId) {
            $confirmedAppointment = \App\Models\Appointment::withoutGlobalScopes()
                ->where('business_id', $biz->id)
                ->with(['service', 'customer', 'staffMember'])
                ->find($this->confirmedAppointmentId);

            if ($confirmedAppointment) {
                $ics = app(\App\Services\Calendar\IcsGenerator::class);
                $googleCalendarUrl = $ics->googleCalendarUrl($confirmedAppointment);
                $downloadIcsUrl = route('appointments.calendar.ics', $confirmedAppointment->cancellation_token);

                $botUsername = config('messaging.telegram.bot_username');
                if ($botUsername && $confirmedAppointment->customer?->telegram_link_token) {
                    $telegramInviteUrl = "https://t.me/" . ltrim($botUsername, '@') . "?start=" . $confirmedAppointment->customer->telegram_link_token;
                }

                if ($biz->phone) {
                    $cleanPhone = preg_replace('/\D+/', '', $biz->phone);
                    $localTime = $biz->toLocal($confirmedAppointment->starts_at)->format('l, j M \a\t g:i A');
                    $text = urlencode("Hi {$biz->name}! I just secured my booking for {$confirmedAppointment->service->name} on {$localTime}.");
                    $whatsappUrl = "https://wa.me/{$cleanPhone}?text={$text}";
                }

                if ($confirmedAppointment->cancellation_token) {
                    $cancellationUrl = route('booking.cancel', [$biz->slug, $confirmedAppointment->cancellation_token]);
                }
            }
        }
    @endphp

    <!-- Business Header / Branding Banner -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6 sm:p-8 mb-6 text-center relative overflow-hidden">
        <div class="absolute -top-12 -right-12 w-36 h-36 bg-gradient-to-br from-rose-100 to-indigo-100 rounded-full blur-2xl opacity-70 pointer-events-none"></div>
        <div class="absolute -bottom-12 -left-12 w-36 h-36 bg-gradient-to-tr from-amber-100 to-rose-100 rounded-full blur-2xl opacity-70 pointer-events-none"></div>

        <div class="relative z-10">
            <div class="w-16 h-16 sm:w-20 sm:h-20 mx-auto rounded-2xl bg-gradient-to-br from-rose-500 to-indigo-600 text-white flex items-center justify-center text-2xl sm:text-3xl font-bold shadow-md shadow-rose-500/20 mb-3">
                {{ substr($biz->name, 0, 1) }}
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight font-display">
                {{ $biz->name }}
            </h1>
            @if($biz->niche)
                <p class="text-sm font-medium text-slate-500 mt-1 capitalize">{{ $biz->niche }} &middot; Online Booking</p>
            @endif

            <div class="flex flex-wrap items-center justify-center gap-4 text-xs sm:text-sm text-slate-600 mt-3 pt-3 border-t border-slate-100">
                @if($biz->address)
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ $biz->address }}
                    </span>
                @endif
                @if($biz->phone)
                    <a href="tel:{{ $biz->phone }}" class="flex items-center gap-1.5 text-rose-600 hover:text-rose-700 font-medium">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        {{ $biz->phone }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    @if($step < 5)
        <!-- Progress Steps Bar -->
        <nav class="mb-6 bg-white rounded-xl p-3 border border-slate-200 shadow-sm">
            <ol class="flex items-center justify-between text-xs font-semibold text-slate-500">
                <li class="flex items-center gap-1.5 cursor-pointer {{ $step >= 1 ? 'text-rose-600' : '' }}" wire:click="goToStep(1)">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] {{ $step >= 1 ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-500' }}">1</span>
                    <span class="hidden sm:inline">Service</span>
                </li>
                <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>

                <li class="flex items-center gap-1.5 cursor-pointer {{ $step >= 2 ? 'text-rose-600' : '' }}" wire:click="goToStep(2)">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] {{ $step >= 2 ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-500' }}">2</span>
                    <span class="hidden sm:inline">Staff</span>
                </li>
                <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>

                <li class="flex items-center gap-1.5 cursor-pointer {{ $step >= 3 ? 'text-rose-600' : '' }}" wire:click="goToStep(3)">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] {{ $step >= 3 ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-500' }}">3</span>
                    <span class="hidden sm:inline">Date & Time</span>
                </li>
                <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>

                <li class="flex items-center gap-1.5 {{ $step >= 4 ? 'text-rose-600' : '' }}">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-[11px] {{ $step >= 4 ? 'bg-rose-600 text-white' : 'bg-slate-100 text-slate-500' }}">4</span>
                    <span class="hidden sm:inline">Details</span>
                </li>
            </ol>
        </nav>
    @endif

    <!-- STEP 1: CHOOSE SERVICE -->
    @if($step === 1)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-5 sm:p-7">
            <h2 class="text-xl font-bold text-slate-900 mb-1">Select a Service</h2>
            <p class="text-sm text-slate-500 mb-5">Choose the service you'd like to book today.</p>

            <div class="space-y-3">
                @forelse($services as $svc)
                    <div wire:click="selectService({{ $svc->id }})"
                         class="group p-4 sm:p-5 rounded-xl border border-slate-200 hover:border-rose-400 hover:bg-rose-50/30 transition-all cursor-pointer flex items-center justify-between gap-4 {{ $selectedServiceId === $svc->id ? 'ring-2 ring-rose-500 border-rose-500 bg-rose-50/40' : '' }}">
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-slate-900 group-hover:text-rose-600 transition-colors">
                                {{ $svc->name }}
                            </h3>
                            @if($svc->description)
                                <p class="text-xs sm:text-sm text-slate-500 mt-0.5 line-clamp-2">{{ $svc->description }}</p>
                            @endif
                            <div class="flex items-center gap-3 mt-2 text-xs font-semibold text-slate-600">
                                <span class="flex items-center gap-1 text-slate-500">
                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ $svc->duration_minutes }} mins
                                </span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="text-lg sm:text-xl font-extrabold text-slate-900 group-hover:text-rose-600">
                                &pound;{{ number_format($svc->price, 2) }}
                            </span>
                            <div class="mt-1">
                                <span class="inline-flex items-center text-xs font-semibold text-rose-600 group-hover:underline">
                                    Book &rarr;
                                </span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-10 text-slate-500">
                        <p>No active services available for online booking at this time.</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <!-- STEP 2: CHOOSE STAFF MEMBER -->
    @if($step === 2)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-5 sm:p-7">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Select Specialist</h2>
                    <p class="text-sm text-slate-500">Choose who you'd like to perform your {{ $selectedService?->name }}.</p>
                </div>
                <button type="button" wire:click="goToStep(1)" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                    &larr; Back
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
                <!-- Any Available Staff -->
                <div wire:click="selectStaff(null)"
                     class="p-4 rounded-xl border border-slate-200 hover:border-rose-400 hover:bg-rose-50/30 transition-all cursor-pointer flex items-center gap-3.5 {{ $selectedStaffId === null ? 'ring-2 ring-rose-500 border-rose-500 bg-rose-50/40' : '' }}">
                    <div class="w-11 h-11 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-sm shrink-0">
                        <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-slate-900">Any Available Specialist</h4>
                        <p class="text-xs text-slate-500">Fastest availability</p>
                    </div>
                </div>

                <!-- Specific Staff Members -->
                @foreach($staffMembers as $staff)
                    <div wire:click="selectStaff({{ $staff->id }})"
                         class="p-4 rounded-xl border border-slate-200 hover:border-rose-400 hover:bg-rose-50/30 transition-all cursor-pointer flex items-center gap-3.5 {{ $selectedStaffId === $staff->id ? 'ring-2 ring-rose-500 border-rose-500 bg-rose-50/40' : '' }}">
                        <div class="w-11 h-11 rounded-full flex items-center justify-center font-bold text-white text-sm shrink-0 shadow-sm"
                             style="background-color: {{ $staff->color ?: '#e11d48' }};">
                            {{ substr($staff->name, 0, 1) }}
                        </div>
                        <div>
                            <h4 class="text-sm font-bold text-slate-900">{{ $staff->name }}</h4>
                            <p class="text-xs text-slate-500">Specialist</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- STEP 3: PICK DATE & TIME -->
    @if($step === 3)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-5 sm:p-7">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Pick Date & Time</h2>
                    <p class="text-sm text-slate-500">Service: <span class="font-semibold text-slate-800">{{ $selectedService?->name }}</span> ({{ $selectedService?->duration_minutes }}m) &middot; Specialist: <span class="font-semibold text-slate-800">{{ $selectedStaff ? $selectedStaff->name : 'Any Available' }}</span></p>
                </div>
                <button type="button" wire:click="goToStep(2)" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                    &larr; Back
                </button>
            </div>

            <!-- Date Selector Pills (Next 14 days) -->
            <div class="mt-4 mb-6">
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Select Day</label>
                <div class="flex gap-2 overflow-x-auto pb-2 scrollbar-thin">
                    @php
                        $now = $biz->now();
                    @endphp
                    @for($i = 0; $i < 14; $i++)
                        @php
                            $candidate = $now->clone()->addDays($i);
                            $dateStr = $candidate->toDateString();
                            $isChosen = $selectedDate === $dateStr;
                        @endphp
                        <button type="button"
                                wire:click="selectDate('{{ $dateStr }}')"
                                class="flex-shrink-0 w-16 sm:w-20 py-3 px-2 rounded-xl text-center border transition-all {{ $isChosen ? 'border-rose-500 bg-rose-600 text-white shadow-md shadow-rose-500/20' : 'border-slate-200 bg-white hover:border-slate-300 text-slate-700' }}">
                            <div class="text-[11px] font-semibold uppercase {{ $isChosen ? 'text-rose-100' : 'text-slate-400' }}">
                                {{ $i === 0 ? 'Today' : ($i === 1 ? 'Tmrw' : $candidate->format('D')) }}
                            </div>
                            <div class="text-lg font-extrabold mt-0.5">
                                {{ $candidate->format('j') }}
                            </div>
                            <div class="text-[10px] {{ $isChosen ? 'text-rose-100' : 'text-slate-400' }}">
                                {{ $candidate->format('M') }}
                            </div>
                        </button>
                    @endfor
                </div>
            </div>

            <!-- Time Slots Grid -->
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-3">
                    Available Times for {{ Carbon::parse($selectedDate)->format('l, F j') }}
                </label>

                @php
                    $slots = $this->availableSlots;
                @endphp

                @if(count($slots) > 0)
                    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2 sm:gap-2.5">
                        @foreach($slots as $slot)
                            <button type="button"
                                    wire:click="selectTime('{{ $slot['time'] }}')"
                                    class="py-2.5 px-3 rounded-lg text-sm font-semibold border transition-all text-center {{ $selectedTime === $slot['time'] ? 'border-rose-500 bg-rose-500 text-white shadow-sm' : 'border-slate-200 hover:border-rose-300 hover:bg-rose-50/50 text-slate-800 bg-slate-50/50' }}">
                                {{ $slot['formatted'] }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-10 bg-slate-50 rounded-xl border border-dashed border-slate-200 text-slate-500 text-sm">
                        <svg class="w-8 h-8 mx-auto text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        No available slots on this date. Please choose another day above.
                    </div>
                @endif

                @error('selectedTime')
                    <p class="text-rose-600 text-xs mt-2 font-medium">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif

    <!-- STEP 4: CUSTOMER DETAILS -->
    @if($step === 4)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-5 sm:p-7">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">Your Details</h2>
                    <p class="text-sm text-slate-500">Provide your contact info to confirm the appointment.</p>
                </div>
                <button type="button" wire:click="goToStep(3)" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                    &larr; Back
                </button>
            </div>

            @if($paymentCancelledAlert)
                <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 flex items-start gap-2.5">
                    <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <div class="font-bold">Payment was not completed</div>
                        <div>You can review your details and try again below to secure your booking.</div>
                    </div>
                </div>
            @endif

            @php
                $depositActive = $biz->depositEnabled();
                $depositDue = $depositActive ? $biz->calculateDepositFor($selectedService?->price ?? 0) : 0.00;
                $balanceDue = max(0.0, ($selectedService?->price ?? 0) - $depositDue);
            @endphp

            <!-- Summary Card -->
            <div class="bg-slate-50 rounded-xl p-4 border border-slate-200 mb-6">
                <div class="flex flex-wrap items-center justify-between gap-4 pb-3 border-b border-slate-200">
                    <div>
                        <div class="text-xs text-slate-500 font-medium">Selected Booking</div>
                        <div class="text-base font-bold text-slate-900 mt-0.5">{{ $selectedService?->name }}</div>
                        <div class="text-xs text-slate-600 mt-0.5">
                            {{ Carbon::parse($selectedDate.' '.$selectedTime)->format('l, j M Y \a\t g:i A') }}
                            &middot; {{ $selectedStaff ? $selectedStaff->name : 'Any Specialist' }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-500 font-medium">Total Service Price</div>
                        <div class="text-xl font-extrabold text-slate-900">&pound;{{ number_format($selectedService?->price, 2) }}</div>
                    </div>
                </div>

                @if($depositActive && $depositDue > 0)
                    <div class="pt-3 flex items-center justify-between text-xs sm:text-sm">
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md font-bold bg-rose-100 text-rose-800 text-[11px]">
                                Online Deposit Required
                            </span>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                Balance of &pound;{{ number_format($balanceDue, 2) }} payable at your appointment
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-slate-500 font-medium block">Due Right Now</span>
                            <span class="text-lg font-black text-rose-600">&pound;{{ number_format($depositDue, 2) }}</span>
                        </div>
                    </div>
                @endif
            </div>

            <form wire:submit="submitBooking" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Full Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           wire:model="customer_name"
                           placeholder="e.g. Sarah Jenkins"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-500 focus:ring-mulberry-500" />
                    @error('customer_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Mobile Phone Number <span class="text-red-500">*</span>
                    </label>
                    <input type="tel"
                           wire:model="customer_phone"
                           placeholder="e.g. 07700 900123"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-500 focus:ring-mulberry-500" />
                    <p class="text-[11px] text-gray-500 mt-1">We'll send your booking reminders to this phone number.</p>
                    @error('customer_phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Email Address <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    <input type="email"
                           wire:model="customer_email"
                           placeholder="sarah@example.com"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-500 focus:ring-mulberry-500" />
                    @error('customer_email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Special Requests or Notes <span class="text-gray-400 font-normal">(Optional)</span>
                    </label>
                    <textarea wire:model="notes"
                              rows="2"
                              placeholder="Any preferences, allergies, or notes for your specialist..."
                              class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm focus:border-mulberry-500 focus:ring-mulberry-500"></textarea>
                    @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50/60 p-4">
                    <input type="checkbox" id="marketing_consent" wire:model="marketing_consent"
                           class="mt-0.5 h-4 w-4 rounded border-gray-300 text-mulberry-600 focus:ring-mulberry-500">
                    <label for="marketing_consent" class="text-sm text-gray-700 cursor-pointer">
                        Send me occasional offers and news.
                        <span class="block text-xs text-gray-500 mt-0.5">Optional, and separate from your booking. Unsubscribe any time.</span>
                    </label>
                </div>

                <p class="text-[11px] leading-relaxed text-gray-500">
                    Booking confirmations and appointment reminders are always sent as part of your booking.
                    See how we handle your details in our <a href="{{ route('privacy') }}" target="_blank" rel="noopener" class="font-medium text-gray-700 underline hover:text-mulberry-600">Privacy Policy</a>.
                </p>

                <div class="pt-2">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            class="w-full py-3.5 px-4 bg-gradient-to-r from-mulberry-700 to-mulberry-600 hover:from-mulberry-800 hover:to-mulberry-700 text-white font-bold rounded-xl shadow-lg shadow-mulberry-600/25 transition-all flex items-center justify-center gap-2">
                        <span wire:loading.remove>
                            @if($depositActive && $depositDue > 0)
                                Pay Deposit &amp; Confirm &middot; &pound;{{ number_format($depositDue, 2) }}
                            @else
                                Confirm Booking &middot; &pound;{{ number_format($selectedService?->price, 2) }}
                            @endif
                        </span>
                        <span wire:loading class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            @if($depositActive && $depositDue > 0)
                                Redirecting to Secured Checkout...
                            @else
                                Confirming Your Booking...
                            @endif
                        </span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    <!-- STEP 5: CONFIRMED SUCCESS SCREEN -->
    @if($step === 5)
        <div class="bg-white rounded-3xl shadow-lg border border-slate-200/80 p-6 sm:p-10 text-center relative overflow-hidden">
            <!-- Green Celebration Ring -->
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-5 shadow-inner">
                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 mb-3">
                Booking Confirmed
            </span>

            <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                You're All Set, {{ explode(' ', $customer_name)[0] }}!
            </h2>
            <p class="text-sm text-slate-600 mt-2 max-w-md mx-auto">
                We've reserved your slot at <span class="font-semibold text-slate-900">{{ $biz->name }}</span>.
            </p>

            <!-- Appointment Recap Card -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200/80 text-left my-6 max-w-md mx-auto">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                    <div>
                        <div class="text-xs text-slate-500">Service</div>
                        <div class="font-bold text-slate-900 text-base">{{ $selectedService?->name }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-500">Price</div>
                        <div class="font-extrabold text-slate-900 text-base">&pound;{{ number_format($selectedService?->price, 2) }}</div>
                    </div>
                </div>

                <div class="pt-3 space-y-2 text-xs sm:text-sm text-slate-700">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span>{{ Carbon::parse($selectedDate.' '.$selectedTime)->format('l, j F Y \a\t g:i A') }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span>Specialist: {{ $selectedStaff ? $selectedStaff->name : 'Assigned Specialist' }}</span>
                    </div>

                    @if($biz->address)
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span>{{ $biz->address }}</span>
                        </div>
                    @endif

                    @php
                        $confirmedApt = $confirmedAppointmentId ? App\Models\Appointment::withoutGlobalScopes()->find($confirmedAppointmentId) : null;
                    @endphp

                    @if($confirmedApt && $confirmedApt->deposit_required)
                        <div class="mt-3 pt-3 border-t border-slate-200 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-emerald-700 bg-emerald-100/80 px-2 py-0.5 rounded">
                                    &check; Deposit Paid: &pound;{{ number_format($confirmedApt->paid_amount, 2) }}
                                </span>
                            </div>
                            <div class="text-right">
                                <span class="text-slate-500">Remaining Balance:</span>
                                <span class="font-bold text-slate-900">&pound;{{ number_format($confirmedApt->balanceDue(), 2) }}</span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Email Confirmation Sent Notice -->
            @if($emailConfirmationSent)
                <div class="mb-4 p-3.5 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center justify-center gap-2 max-w-md mx-auto">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Confirmation &amp; calendar invite emailed to <strong>{{ $customer_email }}</strong></span>
                </div>
            @endif

            <!-- Add to Calendar Card -->
            <div class="p-4 rounded-2xl bg-gradient-to-r from-slate-900 to-slate-800 text-white text-left max-w-md mx-auto mb-4 shadow-md">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center text-lg">
                        &#128197;
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-white">Add to Your Calendar</h4>
                        <p class="text-xs text-slate-300">Sync with Google, Apple, or Outlook with 1 tap</p>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 pt-1">
                    @if($googleCalendarUrl)
                        <a href="{{ $googleCalendarUrl }}" target="_blank"
                           class="flex-1 py-2.5 px-3 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold text-center transition-colors shadow-sm flex items-center justify-center gap-1.5">
                            <span>Google Calendar</span> &rarr;
                        </a>
                    @endif
                    @if($downloadIcsUrl)
                        <a href="{{ $downloadIcsUrl }}"
                           class="flex-1 py-2.5 px-3 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-semibold text-center transition-colors flex items-center justify-center gap-1.5 border border-white/20">
                            <span>Apple / Outlook (.ics)</span>
                        </a>
                    @endif
                </div>
            </div>

            <!-- Automated Reminders Activation -->
            <div class="max-w-md mx-auto space-y-3">
                @if($telegramInviteUrl)
                    <div class="p-4 rounded-2xl bg-sky-50 border border-sky-200 text-left flex items-start gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-sky-500 text-white flex items-center justify-center shrink-0 shadow-sm">
                            <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h4 class="text-sm font-bold text-sky-900">Never Miss Your Appointment</h4>
                            <p class="text-xs text-sky-700 mt-0.5">Connect to our Telegram Bot with one tap to get your 24h automated reminder.</p>
                            <a href="{{ $telegramInviteUrl }}" target="_blank"
                               class="inline-flex items-center gap-1.5 mt-2.5 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold rounded-lg shadow-sm transition-all">
                                Connect Telegram Bot &rarr;
                            </a>
                        </div>
                    </div>
                @endif

                @if($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" target="_blank"
                       class="w-full py-3 px-4 rounded-xl border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 text-sm font-bold flex items-center justify-center gap-2 transition-all">
                        <svg class="w-4 h-4 fill-emerald-600" viewBox="0 0 24 24">
                            <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.971.53 1.77.813 2.796.814 3.179 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.768-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.312.045-.634.075-1.782-.401-.892-.37-1.531-.96-2.147-1.576-.617-.616-1.206-1.255-1.576-2.147-.476-1.148-.446-1.47-.401-1.782.05-.333.419-1.026.824-1.17.133-.048.28-.02.396.066.166.124.73 1.077.788 1.196.059.12.042.247-.024.364-.093.167-.282.383-.342.457-.089.109-.168.21-.073.375.244.423.639.873 1.065 1.25.426.377.876.719 1.344.912.167.069.267.014.343-.075.092-.108.384-.447.487-.6.103-.153.206-.128.343-.077.138.051 1.299.613 1.488.707.19.094.275.143.315.212.04.069.04.401-.104.806z"/>
                        </svg>
                        Message Salon on WhatsApp
                    </a>
                @endif
            </div>

            <div class="mt-8 pt-6 border-t border-slate-100 text-xs text-slate-400">
                Need to make changes? Call <span class="font-semibold text-slate-600">{{ $biz->phone ?: $biz->name }}</span>.
                @if($cancellationUrl)
                    <span class="mx-2">·</span>
                    <a href="{{ $cancellationUrl }}" class="text-red-500 hover:text-red-700 underline">Cancel booking</a>
                @endif
            </div>
        </div>
    @endif
</div>
