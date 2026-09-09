<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Diary')] class extends Component
{
    use TenantScreen;

    /* -----------------------------------------------------------------
     | Which day are we looking at
     |
     | Always a Y-m-d string in the BUSINESS's timezone, never UTC. In the URL
     | so a day can be bookmarked or sent to a colleague.
     * ----------------------------------------------------------------- */

    #[Url(as: 'day', except: '')]
    public string $date = '';

    public ?int $staffFilter = null;
    public bool $showCancelled = false;

    /* ------------------------------ The form ----------------------------- */

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $customerSearch = '';
    public ?int $customer_id = null;

    public bool $addingCustomer = false;
    public string $new_customer_name = '';
    public ?string $new_customer_phone = null;

    public ?int $service_id = null;
    public ?int $staff_member_id = null;

    /* Numeric and date fields are strings. A typed `public int` throws a TypeError
       the moment the user clears the input (Livewire sets ''), which is a 500 page
       instead of a validation message. */
    public string $form_date = '';
    public string $form_time = '10:00';
    public string $duration_minutes = '30';
    public string $price = '0.00';
    public string $status = Appointment::CONFIRMED;
    public ?string $notes = null;

    /**
     * Double-booking is a warning, not a wall.
     *
     * MySQL cannot express range exclusion, so this could never be a DB
     * constraint — and it should not be. A salon genuinely does overlap on
     * purpose: colour developing in the chair while the next client is shampooed.
     * So we name the clash, make the owner look at it, and let them proceed.
     */
    public ?string $conflict = null;
    public bool $allowOverlap = false;

    /**
     * Set when the inline "new customer" panel turned out to match someone already
     * on file, so save() can say so in the one confirmation it shows.
     *
     * Protected, so Livewire neither serialises it nor accepts it from the browser:
     * it is set and read inside a single request and must not survive to the next.
     */
    protected ?string $reusedCustomerNotice = null;

    public function mount(): void
    {
        $this->guardTenant();

        /* Runs unconditionally: #[Url] has already hydrated $date from ?day= by
           now, and safeDate() leaves a valid date alone. So this both fills in
           today when the query string is absent and scrubs it when it is junk. */
        $this->date = $this->safeDate($this->date);
    }

    /* ================================================================
     | Day navigation
     * ================================================================ */

    /**
     * Never let a bad date reach Carbon::parse().
     *
     * $date arrives from the query string, so `?day=lol` is a 500 waiting to
     * happen on a URL anyone can type.
     */
    protected function safeDate(?string $value): string
    {
        if (blank($value)) {
            return $this->business()->now()->toDateString();
        }

        try {
            return Carbon::parse($value, $this->timezone())->toDateString();
        } catch (\Throwable) {
            return $this->business()->now()->toDateString();
        }
    }

    protected function timezone(): string
    {
        return $this->business()->timezone ?: 'Europe/London';
    }

    /** The viewed day as a Carbon in business-local time. */
    protected function viewing(): Carbon
    {
        return Carbon::parse($this->date, $this->timezone())->startOfDay();
    }

    public function goToDate(string $date): void
    {
        $this->date = $this->safeDate($date);
    }

    /**
     * The date input is client-controlled and can be cleared, which would leave
     * $date as '' — and Carbon::parse('') quietly means "now", so the page would
     * disagree with itself. Snapping back to a real day keeps that impossible.
     */
    public function updatedDate(): void
    {
        $this->date = $this->safeDate($this->date);
    }

    public function previousDay(): void
    {
        $this->date = $this->viewing()->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->date = $this->viewing()->addDay()->toDateString();
    }

    public function goToToday(): void
    {
        $this->date = $this->business()->now()->toDateString();
    }

    /* ================================================================
     | Opening the form
     * ================================================================ */

    public function create(): void
    {
        $this->resetForm();

        $this->form_date = $this->date;
        $this->form_time = $this->defaultTime();
        $this->showForm = true;
    }

    /**
     * A sensible starting time: the next quarter hour if we are looking at today,
     * otherwise mid-morning. Saves the owner typing over a time already past.
     */
    protected function defaultTime(): string
    {
        $now = $this->business()->now();

        if ($this->date !== $now->toDateString()) {
            return '10:00';
        }

        $next = $now->clone()->startOfMinute()->addMinutes(15 - ($now->minute % 15));

        return $next->isSameDay($now) ? $next->format('H:i') : '10:00';
    }

    public function edit(int $id): void
    {
        // findOrFail IS the authorisation check: another business's id does not
        // exist from inside the global scope, so this 404s.
        $appointment = Appointment::findOrFail($id);

        $local = $this->business()->toLocal($appointment->starts_at);

        $this->editingId = $appointment->id;
        $this->customer_id = $appointment->customer_id;
        $this->service_id = $appointment->service_id;
        $this->staff_member_id = $appointment->staff_member_id;
        $this->form_date = $local->toDateString();
        $this->form_time = $local->format('H:i');
        $this->duration_minutes = (string) $appointment->durationMinutes();
        $this->price = (string) $appointment->price;
        $this->status = $appointment->status;
        $this->notes = $appointment->notes;

        $this->customerSearch = '';
        $this->addingCustomer = false;
        $this->conflict = null;
        $this->allowOverlap = false;

        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Picking a service fills in its duration and price.
     *
     * Only fires for a change made in the browser. Livewire does not run updated
     * hooks for server-side assignment, which is exactly what we want: opening an
     * old booking for editing must not overwrite the price it was sold at.
     */
    public function updatedServiceId(mixed $value): void
    {
        $service = Service::find($value);

        if ($service === null) {
            return;
        }

        $this->duration_minutes = (string) $service->duration_minutes;
        $this->price = (string) $service->price;
    }

    /**
     * A clash warning is only true for the values that produced it, so any change
     * to the when/who fields retracts both the warning and the override.
     * Otherwise a ticked "book anyway" would silently carry over to a different slot.
     */
    public function updated(string $property, mixed $value = null): void
    {
        if (in_array($property, ['form_date', 'form_time', 'duration_minutes', 'staff_member_id', 'service_id'], true)) {
            $this->conflict = null;
            $this->allowOverlap = false;
        }
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = Customer::findOrFail($id)->id;

        $this->customerSearch = '';
        $this->addingCustomer = false;
    }

    public function clearCustomer(): void
    {
        $this->customer_id = null;
        $this->customerSearch = '';
    }

    public function startAddingCustomer(): void
    {
        $this->addingCustomer = true;
        $this->customer_id = null;

        // Whatever they typed into the search box was almost certainly the name.
        $this->new_customer_name = $this->customerSearch;
        $this->new_customer_phone = null;
    }

    public function cancelAddingCustomer(): void
    {
        $this->addingCustomer = false;
        $this->reset(['new_customer_name', 'new_customer_phone']);
        $this->resetValidation();
    }

    /* ================================================================
     | Saving
     * ================================================================ */

    protected function rules(): array
    {
        return [
            /* The three id fields are the tenant-leak surface of this screen. A
               Livewire payload is client-controlled, so without the business_id
               clause someone could attach another salon's customer to their own
               booking. Rule::exists builds a plain query builder, so the
               BelongsToBusiness global scope does NOT apply here — the clause has
               to be written by hand. */
            'customer_id' => [
                Rule::requiredIf(fn () => ! $this->addingCustomer),
                'nullable',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('business_id', Tenant::id())
                    ->whereNull('deleted_at'),
            ],

            // No `active` check: editing a booking whose service has since been
            // retired must still validate, or old records become uneditable.
            'service_id' => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where('business_id', Tenant::id()),
            ],
            'staff_member_id' => [
                'nullable', 'integer',
                Rule::exists('staff_members', 'id')->where('business_id', Tenant::id()),
            ],

            'form_date' => ['required', 'date_format:Y-m-d'],
            'form_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],

            'new_customer_name' => [
                Rule::requiredIf(fn () => $this->addingCustomer),
                'nullable', 'string', 'max:255',
            ],
            'new_customer_phone' => ['nullable', 'string', 'max:32', $this->phoneShapeRule()],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'customer_id' => 'customer',
            'service_id' => 'service',
            'staff_member_id' => 'staff member',
            'form_date' => 'date',
            'form_time' => 'time',
            'duration_minutes' => 'duration',
            'new_customer_name' => 'name',
            'new_customer_phone' => 'phone',
        ];
    }

    protected function messages(): array
    {
        return [
            'customer_id.required' => 'Choose who the appointment is for.',
            'customer_id.exists' => 'That customer could not be found.',
        ];
    }

    /**
     * Reject text that is not a phone number at all — the Customer model's mutator
     * turns anything unparseable into null, so without this rule "ask reception"
     * would be saved as an empty phone and nobody would know it went missing.
     *
     * Runs AFTER normalisation (see save()), so looksValid() is comparing against
     * E.164 as it should. Checking the raw "07700 900123" would fail every time.
     */
    protected function phoneShapeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (filled($value) && ! Phone::looksValid($value)) {
                $fail('Please enter a real phone number, e.g. 07700 900123.');
            }
        };
    }

    public function save(): void
    {
        // <input type="time"> can submit HH:MM:SS. Normalise before validating, so
        // the value that passes the rules is the value we actually parse.
        $this->form_time = substr(trim($this->form_time), 0, 5);

        /* Phone to E.164 before validating, same as the Customers screen. Anything
           unparseable is handed back unchanged so the error message points at the
           owner's own text instead of an empty box. */
        $raw = trim((string) $this->new_customer_phone);
        $this->new_customer_phone = $raw === '' ? null : (Phone::normalise($raw) ?? $raw);

        $this->validate();

        $business = $this->business();
        $tz = $this->timezone();

        // The one conversion that matters. The form speaks the salon's local time;
        // the column stores UTC. Doing this the other way round puts every booking
        // an hour out for half the year.
        $startsAt = Carbon::parse($this->form_date.' '.$this->form_time, $tz)->utc();
        $endsAt = $startsAt->clone()->addMinutes((int) $this->duration_minutes);

        if (! $this->allowOverlap && ! $this->checkForClash($startsAt, $endsAt)) {
            return;
        }

        $appointment = DB::transaction(function () use ($startsAt, $endsAt) {
            // Created inside the transaction so a failure further down does not
            // leave a half-entered customer behind.
            $customerId = $this->addingCustomer
                ? $this->resolveNewCustomer()
                : $this->customer_id;

            $appointment = $this->editingId === null
                ? new Appointment()
                : Appointment::findOrFail($this->editingId);

            // Kept so the OLD customer's figures are corrected too when a booking
            // is moved from one person to another.
            $previousCustomerId = $appointment->customer_id;

            $appointment->fill([
                'customer_id' => $customerId,
                'service_id' => $this->service_id,
                'staff_member_id' => $this->staff_member_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $this->status,
                'price' => $this->price,
                'notes' => $this->notes,
            ]);

            // business_id is filled by BelongsToBusiness, never by the form.
            if (! $appointment->exists) {
                $appointment->source = 'manual';
            }

            $appointment->save();

            foreach (array_unique(array_filter([$previousCustomerId, $customerId])) as $id) {
                Customer::find($id)?->recomputeVisitStats();
            }

            return $appointment;
        });

        // Follow the booking: if it was moved to another day, show that day rather
        // than leaving the owner staring at the day it just left.
        $this->date = $this->form_date;

        $this->showForm = false;
        $this->resetForm();

        $this->toast(trim(
            ($this->reusedCustomerNotice ? $this->reusedCustomerNotice.' ' : '')
            .'Booked for '.$business->toLocal($appointment->starts_at)->format('D j M, H:i').'.'
        ));
    }

    /** True if the slot is clear. Otherwise sets $conflict and returns false. */
    protected function checkForClash(Carbon $startsAt, Carbon $endsAt): bool
    {
        $clash = Appointment::query()
            ->with(['customer', 'staffMember'])
            ->conflictingWith($this->staff_member_id, $startsAt, $endsAt, $this->editingId)
            ->first();

        if ($clash === null) {
            return true;
        }

        $business = $this->business();

        $this->conflict = sprintf(
            '%s already has %s from %s to %s.',
            $clash->staffMember?->name ?? 'That staff member',
            $clash->customer?->name ?? 'another booking',
            $business->toLocal($clash->starts_at)->format('H:i'),
            $business->toLocal($clash->ends_at)->format('H:i'),
        );

        return false;
    }

    /**
     * Turn the inline "new customer" panel into a customer id.
     *
     * An already-known number is the same person, not an error: the owner is on
     * the phone and does not want a validation lecture. withTrashed because a
     * soft-deleted customer still occupies the (business_id, phone) unique index,
     * so inserting a second row would be a raw database error they cannot act on.
     */
    protected function resolveNewCustomer(): int
    {
        $phone = Phone::normalise((string) $this->new_customer_phone);

        $existing = $phone === null
            ? null
            : Customer::withTrashed()->where('phone', $phone)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            // Said out loud, because silently booking for someone else would be
            // worse than an error. Two people can share a mobile. Held rather than
            // toasted: save() raises one confirmation, and a second dispatch would
            // simply overwrite this one before the owner could read it.
            $this->reusedCustomerNotice = 'That number is already '.$existing->name.'.';

            return $existing->id;
        }

        return Customer::create([
            'name' => $this->new_customer_name,
            'phone' => $phone,
            'preferred_channel' => $this->business()->defaultChannel(),
        ])->id;
    }

    /* ================================================================
     | What happened on the day
     * ================================================================ */

    /**
     * Every status change goes through Appointment::changeStatus(), which also
     * rebuilds the customer's last visit and total spend. A bare status update
     * here would leave those figures — and the lapsed-customer list — wrong.
     */
    public function setStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, Appointment::STATUSES, true), 422);

        $appointment = Appointment::findOrFail($id);

        $appointment->changeStatus($status);

        $this->toast($appointment->customer?->name
            ? $appointment->customer->name.' marked '.strtolower($appointment->statusLabel()).'.'
            : 'Appointment marked '.strtolower($appointment->statusLabel()).'.');
    }

    /**
     * Soft delete — appointments are the record of what happened, so "delete"
     * means "take it off the diary", not "erase last month's takings". The
     * customer's figures are recomputed because a removed booking no longer counts.
     */
    public function delete(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $customer = $appointment->customer;

        $appointment->delete();

        $customer?->recomputeVisitStats();

        $this->toast('Appointment removed.');
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'customerSearch', 'customer_id', 'addingCustomer',
            'new_customer_name', 'new_customer_phone', 'service_id', 'staff_member_id',
            'form_date', 'form_time', 'duration_minutes', 'price', 'status', 'notes',
            'conflict', 'allowOverlap',
        ]);

        $this->resetValidation();
    }

    /* ================================================================
     | Rendering
     * ================================================================ */

    /** Badge colours, kept out of the markup so the five statuses read as a set. */
    public function statusBadge(string $status): string
    {
        return match ($status) {
            Appointment::PENDING => 'bg-amber-50 text-amber-800 ring-amber-200',
            Appointment::CONFIRMED => 'bg-sky-50 text-sky-800 ring-sky-200',
            Appointment::COMPLETED => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            Appointment::NO_SHOW => 'bg-red-50 text-red-800 ring-red-200',
            default => 'bg-gray-100 text-gray-600 ring-gray-200',
        };
    }

    /**
     * The seven-day strip above the list: navigation and a workload glance in one.
     *
     * Counts are grouped in PHP, not SQL. Grouping by local date in the database
     * would need CONVERT_TZ (which needs MySQL's timezone tables loaded) and has
     * no SQLite equivalent at all, so the tests would exercise different code than
     * production. One query, one map.
     */
    protected function weekStrip(): array
    {
        $business = $this->business();
        $tz = $this->timezone();

        $start = $this->viewing()->startOfWeek(Carbon::MONDAY);
        $end = $start->clone()->addDays(6)->endOfDay();

        $counts = Appointment::query()
            ->blocking()
            ->whereBetween('starts_at', [$start->clone()->utc(), $end->clone()->utc()])
            ->pluck('starts_at')
            ->map(fn ($startsAt) => $business->toLocal($startsAt)->toDateString())
            ->countBy();

        $today = $business->now()->toDateString();

        return collect(range(0, 6))
            ->map(function (int $offset) use ($start, $counts, $today) {
                $day = $start->clone()->addDays($offset);
                $key = $day->toDateString();

                return [
                    'date' => $key,
                    'weekday' => $day->format('D'),
                    'dayOfMonth' => $day->format('j'),
                    'count' => (int) ($counts[$key] ?? 0),
                    'isToday' => $key === $today,
                    'isSelected' => $key === $this->date,
                ];
            })
            ->all();
    }

    public function with(): array
    {
        $business = $this->business();

        $appointments = Appointment::query()
            ->with(['customer', 'service', 'staffMember'])
            ->onLocalDate($business, $this->date)
            ->when($this->staffFilter, fn ($query) => $query->where('staff_member_id', $this->staffFilter))
            ->when(
                ! $this->showCancelled,
                fn ($query) => $query->whereNotIn('status', [Appointment::CANCELLED, Appointment::NO_SHOW])
            )
            ->orderBy('starts_at')
            ->get();

        /* The dropdowns list what is bookable today PLUS whatever this booking
           already uses — otherwise opening an old appointment whose stylist has
           left would silently reassign it on save. */
        $services = Service::query()
            ->where(fn ($query) => $query->active()->orWhere('id', $this->service_id))
            ->ordered()
            ->get();

        $staff = StaffMember::query()
            ->where(fn ($query) => $query->active()->orWhere('id', $this->staff_member_id))
            ->ordered()
            ->get();

        return [
            'business' => $business,
            'appointments' => $appointments,
            'weekStrip' => $this->weekStrip(),
            'services' => $services,
            'staff' => $staff,
            'staffForFilter' => StaffMember::query()->active()->ordered()->get(),
            'selectedCustomer' => $this->customer_id ? Customer::find($this->customer_id) : null,
            'customerResults' => $this->showForm && ! $this->addingCustomer
                ? Customer::query()->search($this->customerSearch)->orderBy('name')->limit(8)->get()
                : collect(),
            'heading' => $this->viewing()->format('l j F Y'),
            'isToday' => $this->date === $business->now()->toDateString(),
            'expectedTakings' => $appointments->whereIn('status', Appointment::BLOCKING)->sum('price'),

            // value => label, so the dropdown reads the same as the row badges.
            'statusOptions' => collect(Appointment::STATUSES)
                ->mapWithKeys(fn (string $status) => [
                    $status => (new Appointment(['status' => $status]))->statusLabel(),
                ])
                ->all(),
        ];
    }
}; ?>

<div x-data="{ toast: null }"
     x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 3000)">

    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

        {{-- ---------------------------------------------------------------
             Heading
        ---------------------------------------------------------------- --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-0">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">
                    Diary
                    @if ($isToday)
                        <span class="ms-1 text-sm font-normal text-gray-500">— today</span>
                    @endif
                </h1>
                <p class="text-sm text-gray-500">{{ $heading }}</p>
            </div>

            <x-primary-button type="button" wire:click="create">New booking</x-primary-button>
        </div>

        <x-toast />

        {{-- ---------------------------------------------------------------
             Week strip — navigation and workload at a glance
        ---------------------------------------------------------------- --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="flex items-center gap-2 border-b border-gray-100 p-3">
                {{-- These two arrows are how the diary is actually navigated, so they
                     get full targets rather than the 30px they had. --}}
                <button type="button" wire:click="previousDay"
                        class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                        aria-label="Previous day">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L9.06 10l3.71 3.71a.75.75 0 11-1.06 1.06l-4.25-4.25a.75.75 0 010-1.06l4.25-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
                    </svg>
                </button>

                <button type="button" wire:click="goToToday"
                        class="inline-flex min-h-touch items-center justify-center rounded-lg px-4 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                    Today
                </button>

                <button type="button" wire:click="nextDay"
                        class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                        aria-label="Next day">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L10.94 10 7.23 6.29a.75.75 0 111.06-1.06l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                </button>

                <input type="date" wire:model.live="date" aria-label="Jump to a date"
                       class="ms-auto min-h-touch rounded-lg border-gray-300 text-base shadow-sm focus:border-mulberry-600 focus:ring-mulberry-600 sm:text-sm">
            </div>

            <div class="grid grid-cols-7 divide-x divide-gray-100">
                @foreach ($weekStrip as $day)
                    <button type="button" wire:key="strip-{{ $day['date'] }}"
                            wire:click="goToDate('{{ $day['date'] }}')"
                            @if ($day['isSelected']) aria-current="date" @endif
                            class="px-1 py-3 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600 {{ $day['isSelected'] ? 'bg-mulberry-50' : 'hover:bg-gray-50' }}">

                        <span class="block text-xs uppercase tracking-wide {{ $day['isToday'] ? 'font-semibold text-mulberry-700' : 'text-gray-500' }}">
                            {{ $day['weekday'] }}
                        </span>

                        <span class="mt-0.5 block text-lg leading-none tabular-nums {{ $day['isSelected'] ? 'font-semibold text-mulberry-800' : 'text-gray-900' }}">
                            {{ $day['dayOfMonth'] }}
                        </span>

                        <span class="mt-1 block text-xs tabular-nums {{ $day['count'] > 0 ? 'text-gray-500' : 'text-gray-300' }}">
                            {{ $day['count'] > 0 ? $day['count'] : '·' }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ---------------------------------------------------------------
             Filters + the day itself
        ---------------------------------------------------------------- --}}
        <div class="bg-white shadow-sm sm:rounded-lg">

            <div class="flex flex-wrap items-center gap-4 border-b border-gray-100 p-4">
                <x-select-input wire:model.live="staffFilter" aria-label="Show one person's bookings">
                    <option value="">Everyone</option>
                    @foreach ($staffForFilter as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </x-select-input>

                <label class="inline-flex min-h-touch items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="showCancelled"
                           class="h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                    Show cancellations
                </label>

                <span class="ms-auto text-sm text-gray-500">
                    {{-- Plain PHP, not Str::plural: the Str facade alias is not
                         registered in Laravel 12's config/app.php, so calling it from
                         the markup half of a Volt file is a "Class not found" fatal. --}}
                    {{ $appointments->count() }} {{ $appointments->count() === 1 ? 'booking' : 'bookings' }}
                    @if ($expectedTakings > 0)
                        · <span class="font-medium text-gray-900">£{{ number_format((float) $expectedTakings, 2) }}</span> expected
                    @endif
                </span>
            </div>

            @php
                /*
                 * Every action in the expanded strip is a real 44px target. Defined
                 * once here rather than inside the loop, where it would be rebuilt
                 * for every booking on the day.
                 */
                $act = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600';
                $actDanger = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-red-200 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600';
            @endphp

            <ul class="divide-y divide-gray-100">
                @forelse ($appointments as $appointment)
                    @php
                        $isActive = in_array($appointment->status, \App\Models\Appointment::REMINDABLE, true);
                        $isPending = $appointment->status === \App\Models\Appointment::PENDING;
                    @endphp

                    <li wire:key="appointment-{{ $appointment->id }}"
                        x-data="{ actions: false }"
                        class="p-4 {{ $appointment->isCancelled() ? 'opacity-75' : '' }}">

                        <div class="flex flex-wrap items-start gap-x-4 gap-y-3">

                            {{-- Time. The one thing on this screen allowed to shout:
                                 it is what the eye looks for when the phone comes out
                                 mid-appointment. --}}
                            <div class="w-16 shrink-0 sm:w-24">
                                <div class="text-lg font-semibold leading-tight tabular-nums text-gray-900">
                                    {{ $business->toLocal($appointment->starts_at)->format('H:i') }}
                                </div>
                                <div class="text-xs tabular-nums text-gray-500">
                                    till {{ $business->toLocal($appointment->ends_at)->format('H:i') }}
                                </div>
                            </div>

                            {{-- Who and what --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-gray-900">
                                        {{ $appointment->customer?->name ?? 'Customer removed' }}
                                    </span>

                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $this->statusBadge($appointment->status) }}">
                                        {{ $appointment->statusLabel() }}
                                    </span>
                                </div>

                                <div class="mt-0.5 text-sm text-gray-600">
                                    {{-- Blank only if the service or staff record was hard-deleted;
                                         the screens prevent that, but history can predate them. --}}
                                    {{ $appointment->service?->name ?? 'No service set' }}

                                    @if ($appointment->staffMember)
                                        <span class="text-gray-400">·</span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="inline-block h-2 w-2 rounded-full"
                                                  style="background-color: {{ $appointment->staffMember->color }}"></span>
                                            {{ $appointment->staffMember->name }}
                                        </span>
                                    @endif

                                    <span class="text-gray-400">·</span>
                                    £{{ number_format((float) $appointment->price, 2) }}
                                </div>

                                @if ($appointment->notes)
                                    <div class="mt-1 text-sm text-gray-500">{{ $appointment->notes }}</div>
                                @endif

                                @if ($appointment->customer && ! $appointment->customer->canReceiveTransactional())
                                    <div class="mt-1 text-xs text-amber-700">
                                        No reminder will be sent — this customer has opted out.
                                    </div>
                                @endif
                            </div>

                            {{--
                                One action inline, the rest a tap away.

                                This used to be six bare text links, 20px tall and 12px
                                apart, with "Done" immediately beside "No-show" — on the
                                screen an owner uses all day, one-handed, with wet hands.
                                The inline button is whatever comes next in the booking's
                                life; everything else moves into the strip below, where it
                                gets a real target and cannot be hit by accident.

                                w-full puts the cluster on its own line on a phone and
                                sm:w-auto returns it to the end of the row on a desktop.
                            --}}
                            <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                                @if ($isActive)
                                    <button type="button"
                                            wire:click="setStatus({{ $appointment->id }}, '{{ $isPending ? 'confirmed' : 'completed' }}')"
                                            class="inline-flex items-center justify-center min-h-touch rounded-lg px-4 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 {{ $isPending ? 'bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200 hover:bg-sky-100 focus-visible:ring-sky-600' : 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 focus-visible:ring-emerald-600' }}">
                                        {{ $isPending ? 'Confirm' : 'Done' }}
                                    </button>
                                @elseif ($appointment->isCancelled())
                                    {{-- Only cancellations and no-shows get this. A COMPLETED
                                         booking is not "active" either, but it must never show
                                         a one-tap "Put back in the diary": setStatus() runs
                                         recomputeVisitStats(), so a stray tap would quietly
                                         take that visit's money back off the customer's total
                                         spend and roll back their last-visit date. Reopening a
                                         finished booking is an Edit, and Edit is in the strip. --}}
                                    <button type="button" wire:click="setStatus({{ $appointment->id }}, 'confirmed')"
                                            class="{{ $act }}">Put back in the diary</button>
                                @endif

                                <button type="button" @click="actions = ! actions"
                                        :aria-expanded="actions ? 'true' : 'false'"
                                        aria-label="More actions for this booking"
                                        class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3zM10 11.5a1.5 1.5 0 110-3 1.5 1.5 0 010 3zM10 17a1.5 1.5 0 110-3 1.5 1.5 0 010 3z" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Expands in place rather than floating. A dropdown anchored to
                             the last row of a long day opens off the bottom of the phone. --}}
                        <div x-show="actions"
                             x-collapse
                             style="display: none"
                             class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">

                            <button type="button" wire:click="edit({{ $appointment->id }})" class="{{ $act }}">Edit</button>

                            @if ($isActive)
                                @if ($isPending)
                                    <button type="button" wire:click="setStatus({{ $appointment->id }}, 'completed')"
                                            class="{{ $act }}">Done</button>
                                @endif

                                <button type="button" wire:click="setStatus({{ $appointment->id }}, 'no_show')"
                                        class="{{ $act }}">Didn't turn up</button>

                                <button type="button" wire:click="setStatus({{ $appointment->id }}, 'cancelled')"
                                        wire:confirm="Cancel this appointment?"
                                        class="{{ $act }}">Cancel</button>
                            @endif

                            <button type="button" wire:click="delete({{ $appointment->id }})"
                                    wire:confirm="Remove this appointment from the diary?"
                                    class="{{ $actDanger }}">Remove</button>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-12 text-center">
                        <p class="text-gray-500">{{ $isToday ? 'Nothing booked today.' : 'Nothing booked on this day.' }}</p>
                        <x-primary-button type="button" wire:click="create" class="mt-4">Add a booking</x-primary-button>
                    </li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- ---------------------------------------------------------------
         The booking form
    ---------------------------------------------------------------- --}}
    @if ($showForm)
        <x-form-modal :title="$editingId ? 'Edit booking' : 'New booking'"
                      :submit-label="$editingId ? 'Save changes' : 'Book it'"
                      max-width="sm:max-w-2xl">

            {{-- Customer: chip if chosen, search if not, inline create if new --}}
            <div>
                <x-input-label value="Customer" />

                @if ($selectedCustomer)
                    <div class="mt-1 flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2">
                        <span class="text-sm">
                            <span class="font-medium text-gray-900">{{ $selectedCustomer->name }}</span>
                            @if ($selectedCustomer->phone)
                                <span class="text-gray-500">· {{ \App\Support\Phone::forHumans($selectedCustomer->phone) }}</span>
                            @endif
                        </span>

                        <button type="button" wire:click="clearCustomer"
                                class="-me-2 inline-flex min-h-touch shrink-0 items-center rounded-lg px-2 text-sm font-medium text-mulberry-700 hover:text-mulberry-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">Change</button>
                    </div>
                @elseif ($addingCustomer)
                    <div class="mt-1 space-y-3 rounded-md border border-gray-200 p-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_customer_name" value="Name" />
                                <x-text-input wire:model="new_customer_name" id="new_customer_name" type="text"
                                              class="mt-1 block w-full" placeholder="Sarah Ahmed" />
                                <x-input-error :messages="$errors->get('new_customer_name')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="new_customer_phone" value="Phone" />
                                <x-text-input wire:model="new_customer_phone" id="new_customer_phone" type="tel"
                                              class="mt-1 block w-full" placeholder="07700 900123" />
                                <x-input-error :messages="$errors->get('new_customer_phone')" class="mt-2" />
                            </div>
                        </div>

                        <p class="text-xs text-gray-500">
                            Without a phone number this customer cannot be sent reminders.
                        </p>

                        <button type="button" wire:click="cancelAddingCustomer"
                                class="inline-flex min-h-touch items-center rounded-lg text-sm text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">Pick an existing customer instead</button>
                    </div>
                @else
                    {{-- autofocus is what <x-form-modal> looks for when it opens. Picking
                         the customer is always the first move on a new booking, so the
                         keyboard should already be up by the time the sheet settles.
                         On an edit there is no search box, so nothing takes focus and no
                         keyboard appears — which is right, the details are already filled.

                         keydown.enter.prevent is not a nicety, it is a bug fix. This box
                         lives inside the modal's <form>, so a single text input plus a
                         submit button means the browser implicitly submits on Enter — and
                         on a phone the keyboard's blue key IS Enter. Typing a name and
                         reaching for "search" was firing save() on a booking with no
                         customer, no date and no time. Now it just puts the keyboard away
                         so the results underneath are visible, which is what the key
                         should have done in the first place. enterkeyhint labels it. --}}
                    <x-text-input wire:model.live.debounce.300ms="customerSearch" type="search" autofocus
                                  x-ref="customerSearch" enterkeyhint="search"
                                  x-on:keydown.enter.prevent="$event.target.blur()"
                                  class="mt-1 block w-full" placeholder="Search by name, phone or email" />

                    <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />

                    <div class="mt-2 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200">
                        @forelse ($customerResults as $result)
                            {{-- Blur the search box, not the button: tapping the result makes
                                 the search box vanish, and a focused element that disappears
                                 leaves the keyboard up over the rest of the form. --}}
                            <button type="button" wire:key="result-{{ $result->id }}"
                                    wire:click="selectCustomer({{ $result->id }})"
                                    x-on:click="$refs.customerSearch?.blur()"
                                    class="flex min-h-touch w-full items-center px-3 py-2 text-start text-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600">
                                <span>
                                    <span class="font-medium text-gray-900">{{ $result->name }}</span>
                                    @if ($result->phone)
                                        <span class="text-gray-500">· {{ \App\Support\Phone::forHumans($result->phone) }}</span>
                                    @endif
                                </span>
                            </button>
                        @empty
                            <p class="px-3 py-3 text-sm text-gray-500">No customer found.</p>
                        @endforelse
                    </div>

                    <button type="button" wire:click="startAddingCustomer"
                            x-on:click="$refs.customerSearch?.blur()"
                            class="mt-2 inline-flex min-h-touch items-center rounded-lg text-sm font-medium text-mulberry-700 hover:text-mulberry-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                        + New customer
                    </button>
                @endif
            </div>

            {{-- Service and staff --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="service_id" value="Service" />
                    <x-select-input wire:model.live="service_id" id="service_id" class="mt-1 block w-full">
                        <option value="">No service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->id }}">{{ $service->label() }}</option>
                        @endforeach
                    </x-select-input>
                    <p class="mt-1 text-xs text-gray-500">Fills in the duration and price.</p>
                    <x-input-error :messages="$errors->get('service_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="staff_member_id" value="Staff" />
                    <x-select-input wire:model.live="staff_member_id" id="staff_member_id" class="mt-1 block w-full">
                        <option value="">Unassigned</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </x-select-input>
                    <p class="mt-1 text-xs text-gray-500">Needed to spot double-bookings.</p>
                    <x-input-error :messages="$errors->get('staff_member_id')" class="mt-2" />
                </div>
            </div>

            {{-- When --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <x-input-label for="form_date" value="Date" />
                    <x-text-input wire:model.live="form_date" id="form_date" type="date" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form_date')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="form_time" value="Time" />
                    <x-text-input wire:model.live="form_time" id="form_time" type="time" step="300" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form_time')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="duration_minutes" value="Minutes" />
                    <x-text-input wire:model.live="duration_minutes" id="duration_minutes" type="number"
                                  min="5" max="600" step="5" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('duration_minutes')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="price" value="Price (£)" />
                    <x-text-input wire:model="price" id="price" type="number" min="0" step="0.01"
                                  class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('price')" class="mt-2" />
                </div>
            </div>

            {{-- Double-booking warning. Deliberately overridable: salons do overlap
                 on purpose, and a wall here would just push them back to paper.

                 It only appears after a save attempt, and it sits five fields up from
                 the button that triggered it — on a phone that is off the top of the
                 scrolling body, so tapping "Book it" used to look like nothing had
                 happened at all. Scroll it into view and announce it. --}}
            @if ($conflict)
                <div x-data="{
                         init() {
                             this.$nextTick(() => this.$el.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                         },
                     }"
                     role="alert"
                     class="rounded-lg border border-amber-300 bg-amber-50 p-3">
                    <p class="text-sm font-medium text-amber-900">Double booking</p>
                    <p class="mt-1 text-sm text-amber-800">{{ $conflict }}</p>

                    <label class="mt-1 flex min-h-touch items-center gap-2 text-sm font-medium text-amber-900">
                        <input type="checkbox" wire:model="allowOverlap"
                               class="h-5 w-5 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                        Book it anyway
                    </label>
                </div>
            @endif

            {{-- Status and notes --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <x-input-label for="status" value="Status" />
                    <x-select-input wire:model="status" id="status" class="mt-1 block w-full">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select-input>
                    <x-input-error :messages="$errors->get('status')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="notes" value="Notes (optional)" />
                    <x-text-input wire:model="notes" id="notes" type="text" class="mt-1 block w-full"
                                  placeholder="Allergic to the usual toner" />
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
            </div>
        </x-form-modal>
    @endif
</div>
