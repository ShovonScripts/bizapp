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

    #[Url(as: 'view', except: 'list')]
    public string $viewMode = 'list';

    public ?int $staffFilter = null;
    public bool $showCancelled = false;

    /* -------------------------- Quick Reschedule -------------------------- */
    public bool $showReschedule = false;
    public ?int $reschedulingId = null;
    public string $reschedule_date = '';
    public string $reschedule_time = '';
    public ?int $reschedule_staff_id = null;
    public ?string $reschedule_conflict = null;
    public bool $reschedule_allow_overlap = false;

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

        if (in_array($property, ['reschedule_date', 'reschedule_time', 'reschedule_staff_id'], true)) {
            $this->reschedule_conflict = null;
            $this->reschedule_allow_overlap = false;
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

    public function markPaid(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $appointment->update([
            'paid_amount' => $appointment->price,
            'deposit_status' => 'paid',
        ]);

        $this->toast("Payment marked as fully paid (£{$appointment->price}).");
    }

    public function waiveDeposit(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $appointment->update([
            'deposit_status' => 'waived',
        ]);

        $this->toast('Deposit requirement waived.');
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

    public function setViewMode(string $mode): void
    {
        if (in_array($mode, ['list', 'columns'], true)) {
            $this->viewMode = $mode;
        }
    }

    public function createForStaff(int $staffId): void
    {
        $this->resetForm();
        $this->form_date = $this->date;
        $this->form_time = $this->defaultTime();
        $this->staff_member_id = $staffId;
        $this->showForm = true;
    }

    public function openReschedule(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $local = $this->business()->toLocal($appointment->starts_at);

        $this->reschedulingId = $appointment->id;
        $this->reschedule_date = $local->toDateString();
        $this->reschedule_time = $local->format('H:i');
        $this->reschedule_staff_id = $appointment->staff_member_id;
        $this->reschedule_conflict = null;
        $this->reschedule_allow_overlap = false;
        $this->showReschedule = true;
    }

    public function applyReschedulePreset(string $preset): void
    {
        if (! $this->reschedulingId) {
            return;
        }

        try {
            $date = Carbon::parse($this->reschedule_date, $this->timezone());
            $time = Carbon::parse($this->reschedule_date.' '.$this->reschedule_time, $this->timezone());
        } catch (\Throwable) {
            return;
        }

        match ($preset) {
            'tomorrow' => $this->reschedule_date = $date->addDay()->toDateString(),
            'plus_2_days' => $this->reschedule_date = $date->addDays(2)->toDateString(),
            'plus_1_week' => $this->reschedule_date = $date->addWeek()->toDateString(),
            'plus_15m' => $this->reschedule_time = $time->addMinutes(15)->format('H:i'),
            'minus_15m' => $this->reschedule_time = $time->subMinutes(15)->format('H:i'),
            'plus_30m' => $this->reschedule_time = $time->addMinutes(30)->format('H:i'),
            'minus_30m' => $this->reschedule_time = $time->subMinutes(30)->format('H:i'),
            default => null,
        };

        $this->reschedule_conflict = null;
        $this->reschedule_allow_overlap = false;
    }

    public function saveReschedule(): void
    {
        if (! $this->reschedulingId) {
            return;
        }

        $this->validate([
            'reschedule_date' => ['required', 'date_format:Y-m-d'],
            'reschedule_time' => ['required', 'date_format:H:i'],
            'reschedule_staff_id' => [
                'nullable',
                Rule::exists('staff_members', 'id')->where(
                    fn ($q) => $q->where('business_id', $this->business()->id)
                ),
            ],
        ]);

        $appointment = Appointment::findOrFail($this->reschedulingId);
        $duration = $appointment->durationMinutes();

        $business = $this->business();
        $start = Carbon::parse($this->reschedule_date.' '.$this->reschedule_time, $this->timezone())->utc();
        $end = $start->clone()->addMinutes($duration);

        if (! $this->reschedule_allow_overlap && $this->reschedule_staff_id) {
            $clash = Appointment::query()
                ->with(['customer', 'staffMember'])
                ->conflictingWith($this->reschedule_staff_id, $start, $end, $appointment->id)
                ->first();

            if ($clash !== null) {
                $this->reschedule_conflict = sprintf(
                    '%s already has %s from %s to %s.',
                    $clash->staffMember?->name ?? 'That staff member',
                    $clash->customer?->name ?? 'another booking',
                    $business->toLocal($clash->starts_at)->format('H:i'),
                    $business->toLocal($clash->ends_at)->format('H:i'),
                );
                return;
            }
        }

        $appointment->update([
            'starts_at' => $start,
            'ends_at' => $end,
            'staff_member_id' => $this->reschedule_staff_id,
        ]);

        $appointment->customer?->recomputeVisitStats();

        $this->date = $this->reschedule_date;
        $this->showReschedule = false;
        $this->reschedulingId = null;

        $this->toast('Rescheduled to '.$this->reschedule_date.' at '.$this->reschedule_time.'.');
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
            'staffMembersForColumns' => StaffMember::query()
                ->active()
                ->when($this->staffFilter, fn ($q) => $q->where('id', $this->staffFilter))
                ->ordered()
                ->get(),
            'unassignedAppointments' => $appointments->whereNull('staff_member_id')->values(),
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

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        {{-- ---------------------------------------------------------------
             Heading
        ---------------------------------------------------------------- --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-0">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                    Diary
                    @if ($isToday)
                        <span class="ms-1 text-sm font-normal text-gray-500">— today</span>
                    @endif
                </h1>
                <p class="text-sm text-gray-500">{{ $heading }}</p>
            </div>

            <div class="flex items-center gap-2">
                {{-- View mode switcher --}}
                <div class="inline-flex rounded-lg p-0.5 bg-gray-100 ring-1 ring-gray-200">
                    <button type="button" wire:click="setViewMode('list')"
                            title="List view"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all {{ $viewMode === 'list' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900' }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                        List
                    </button>
                    <button type="button" wire:click="setViewMode('columns')"
                            title="Staff columns"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all {{ $viewMode === 'columns' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900' }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" /></svg>
                        Staff Columns
                    </button>
                </div>

                <x-primary-button type="button" wire:click="create">
                    <svg class="h-4 w-4 -ml-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                    New booking
                </x-primary-button>
            </div>
        </div>

        <x-toast />

        {{-- ---------------------------------------------------------------
             Week strip — navigation and workload at a glance
        ---------------------------------------------------------------- --}}
        <div class="rounded-card border border-gray-200 bg-white shadow-card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-gray-100 p-3 relative">
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

                {{-- Interactive Month Calendar Popover --}}
                <div class="ms-auto flex items-center gap-2"
                     x-data="{
                         calendarOpen: false,
                         currentMonth: new Date('{{ $date }}T00:00:00').getMonth(),
                         currentYear: new Date('{{ $date }}T00:00:00').getFullYear(),
                         monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                         daysInMonth(year, month) { return new Date(year, month + 1, 0).getDate(); },
                         firstDay(year, month) {
                             let d = new Date(year, month, 1).getDay();
                             return d === 0 ? 6 : d - 1;
                         },
                         prevMonth() {
                             if (this.currentMonth === 0) { this.currentMonth = 11; this.currentYear--; }
                             else { this.currentMonth--; }
                         },
                         nextMonth() {
                             if (this.currentMonth === 11) { this.currentMonth = 0; this.currentYear++; }
                             else { this.currentMonth++; }
                         },
                         pick(day) {
                             let m = String(this.currentMonth + 1).padStart(2, '0');
                             let d = String(day).padStart(2, '0');
                             $wire.goToDate(`${this.currentYear}-${m}-${d}`);
                             this.calendarOpen = false;
                         }
                     }">
                    <button type="button" @click="calendarOpen = !calendarOpen"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                            title="Interactive Month Calendar">
                        <svg class="h-4 w-4 text-mulberry-700" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd" />
                        </svg>
                        <span class="hidden sm:inline">Calendar</span>
                    </button>

                    <div x-show="calendarOpen"
                         @click.outside="calendarOpen = false"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         style="display: none"
                         class="absolute right-3 top-14 z-50 w-72 rounded-2xl bg-white p-4 shadow-2xl ring-1 ring-black/10">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                            <button type="button" @click="prevMonth" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-600 transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                            </button>
                            <span class="text-sm font-semibold text-gray-900" x-text="`${monthNames[currentMonth]} ${currentYear}`"></span>
                            <button type="button" @click="nextMonth" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-600 transition-colors">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </button>
                        </div>

                        <div class="grid grid-cols-7 gap-1 pt-3 text-center text-xs font-semibold text-gray-400">
                            <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                        </div>

                        <div class="grid grid-cols-7 gap-1 pt-2 text-center text-sm">
                            <template x-for="blank in firstDay(currentYear, currentMonth)" :key="'b'+blank">
                                <div class="h-8"></div>
                            </template>
                            <template x-for="day in daysInMonth(currentYear, currentMonth)" :key="'d'+day">
                                <button type="button"
                                        @click="pick(day)"
                                        :class="{
                                            'bg-mulberry-700 text-white font-semibold shadow-sm': '{{ $date }}' === `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
                                            'hover:bg-mulberry-50 text-gray-800': '{{ $date }}' !== `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
                                        }"
                                        class="h-8 w-8 mx-auto flex items-center justify-center rounded-lg transition-colors"
                                        x-text="day">
                                </button>
                            </template>
                        </div>

                        <div class="mt-3 pt-2.5 border-t border-gray-100 flex items-center justify-between text-xs">
                            <button type="button" wire:click="goToToday" @click="calendarOpen = false" class="font-medium text-mulberry-700 hover:text-mulberry-900">Today</button>
                            <button type="button" @click="calendarOpen = false" class="text-gray-400 hover:text-gray-600">Close</button>
                        </div>
                    </div>

                    <input type="date" wire:model.live="date" aria-label="Jump to a date"
                           class="min-h-touch rounded-lg border-gray-300 text-base shadow-sm focus:border-mulberry-600 focus:ring-mulberry-600 sm:text-sm">
                </div>
            </div>

            <div class="grid grid-cols-7 divide-x divide-gray-100">
                @foreach ($weekStrip as $day)
                    <button type="button" wire:key="strip-{{ $day['date'] }}"
                            wire:click="goToDate('{{ $day['date'] }}')"
                            @if ($day['isSelected']) aria-current="date" @endif
                            class="day-cell px-1 py-3 text-center focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600 {{ $day['isSelected'] ? 'bg-mulberry-50' : 'hover:bg-gray-50' }}">

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
        <div class="rounded-card border border-gray-200 bg-white shadow-card overflow-hidden">

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

            @if ($viewMode === 'columns')
                {{-- -----------------------------------------------------------
                     Staff Columns View — Side-by-side staff calendar
                ------------------------------------------------------------ --}}
                <div class="p-4 overflow-x-auto">
                    <div class="flex gap-4 min-w-max pb-2">
                        @forelse ($staffMembersForColumns as $staffMember)
                            @php
                                $staffBookings = $appointments->where('staff_member_id', $staffMember->id)->values();
                                $staffTakings = $staffBookings->whereIn('status', \App\Models\Appointment::BLOCKING)->sum('price');
                            @endphp
                            <div class="w-80 flex-shrink-0 bg-gray-50/50 rounded-xl border border-gray-200/90 flex flex-col shadow-2xs">
                                {{-- Staff Header --}}
                                <div class="p-3.5 border-b border-gray-200/80 bg-white rounded-t-xl flex items-center justify-between"
                                     style="border-top: 3px solid {{ $staffMember->color }}">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-8 w-8 rounded-full flex items-center justify-center text-xs font-bold text-white shadow-2xs"
                                              style="background-color: {{ $staffMember->color }}">
                                            {{ strtoupper(substr($staffMember->name, 0, 1)) }}
                                        </span>
                                        <div>
                                            <h3 class="text-sm font-semibold text-gray-900 leading-tight">{{ $staffMember->name }}</h3>
                                            <p class="text-xs text-gray-500">{{ $staffBookings->count() }} {{ $staffBookings->count() === 1 ? 'booking' : 'bookings' }} · £{{ number_format((float) $staffTakings, 2) }}</p>
                                        </div>
                                    </div>

                                    <button type="button" wire:click="createForStaff({{ $staffMember->id }})"
                                            title="Add booking for {{ $staffMember->name }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-gray-50 border border-gray-200 text-gray-600 hover:text-mulberry-700 hover:border-mulberry-300 hover:bg-mulberry-50 transition-all">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                                    </button>
                                </div>

                                {{-- Bookings for this staff member --}}
                                <div class="p-3 space-y-3 flex-1 overflow-y-auto max-h-[640px]">
                                    @forelse ($staffBookings as $appointment)
                                        @php
                                            $isActive = in_array($appointment->status, \App\Models\Appointment::REMINDABLE, true);
                                            $isPending = $appointment->status === \App\Models\Appointment::PENDING;
                                        @endphp
                                        <div wire:key="col-app-{{ $appointment->id }}"
                                             class="rounded-xl border border-gray-200 bg-white p-3 shadow-2xs hover:shadow-card transition-all {{ $appointment->isCancelled() ? 'opacity-70' : '' }}"
                                             style="border-left: 4px solid {{ $staffMember->color }}">
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <span class="text-xs font-bold tabular-nums text-gray-900 bg-gray-100 px-1.5 py-0.5 rounded">
                                                        {{ $business->toLocal($appointment->starts_at)->format('H:i') }} – {{ $business->toLocal($appointment->ends_at)->format('H:i') }}
                                                    </span>
                                                    <div class="mt-1.5 flex items-center gap-1.5">
                                                        <h4 class="text-sm font-semibold text-gray-900 leading-tight">{{ $appointment->customer?->name ?? 'Customer removed' }}</h4>
                                                        @if ($appointment->customer?->phone)
                                                            @php
                                                                $colCleanTel = preg_replace('/[^0-9]/', '', (string) ($appointment->customer->whatsappTarget() ?: $appointment->customer->phone));
                                                            @endphp
                                                            <a href="https://wa.me/{{ $colCleanTel }}?text={{ rawurlencode('Hi '.$appointment->customer->name.', from '.$business->name.'!') }}"
                                                               target="_blank" rel="noopener noreferrer"
                                                               title="WhatsApp {{ $appointment->customer->name }}"
                                                               class="p-0.5 text-emerald-600 hover:text-emerald-700">
                                                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-medium ring-1 ring-inset {{ $this->statusBadge($appointment->status) }}">
                                                    {{ $appointment->statusLabel() }}
                                                </span>
                                            </div>

                                            <div class="mt-1 text-xs text-gray-600 flex flex-wrap items-center gap-1">
                                                <span>{{ $appointment->service?->name ?? 'No service set' }} · £{{ number_format((float) $appointment->price, 2) }}</span>
                                                @if($appointment->isFullyPaid())
                                                    <span class="inline-block px-1 py-0.2 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">Paid</span>
                                                @elseif($appointment->hasPaidDeposit())
                                                    <span class="inline-block px-1 py-0.2 rounded text-[10px] font-bold bg-sky-100 text-sky-800">Dep £{{ number_format($appointment->paid_amount, 0) }}</span>
                                                @endif
                                            </div>

                                            @if ($appointment->notes)
                                                <div class="mt-1 text-xs text-gray-500 italic truncate">{{ $appointment->notes }}</div>
                                            @endif

                                            <div class="mt-2.5 pt-2 border-t border-gray-100 flex items-center justify-between gap-1">
                                                @if ($isActive)
                                                    <button type="button"
                                                            wire:click="setStatus({{ $appointment->id }}, '{{ $isPending ? 'confirmed' : 'completed' }}')"
                                                            class="text-xs font-semibold px-2 py-1 rounded {{ $isPending ? 'bg-sky-50 text-sky-800 hover:bg-sky-100' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
                                                        {{ $isPending ? 'Confirm' : 'Done' }}
                                                    </button>
                                                @elseif ($appointment->isCancelled())
                                                    <button type="button" wire:click="setStatus({{ $appointment->id }}, 'confirmed')"
                                                            class="text-xs font-medium px-2 py-1 rounded bg-gray-100 text-gray-700 hover:bg-gray-200">
                                                        Restore
                                                    </button>
                                                @endif

                                                <div class="flex items-center gap-1 ms-auto">
                                                    <button type="button" wire:click="openReschedule({{ $appointment->id }})"
                                                            title="Quick reschedule"
                                                            class="p-1 rounded text-gray-400 hover:text-mulberry-700 hover:bg-mulberry-50 transition-colors">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                    </button>
                                                    <button type="button" wire:click="edit({{ $appointment->id }})"
                                                            title="Edit booking"
                                                            class="p-1 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="py-8 px-3 text-center border-2 border-dashed border-gray-200 rounded-xl bg-white/50">
                                            <p class="text-xs text-gray-400">Free all day</p>
                                            <button type="button" wire:click="createForStaff({{ $staffMember->id }})"
                                                    class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-mulberry-700 hover:text-mulberry-900">
                                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                                                Book slot
                                            </button>
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        @empty
                            <div class="p-8 text-center w-full text-gray-400">No staff members found.</div>
                        @endforelse

                        {{-- Unassigned appointments if any --}}
                        @if ($unassignedAppointments->isNotEmpty())
                            <div class="w-80 flex-shrink-0 bg-gray-50/50 rounded-xl border border-dashed border-gray-300 flex flex-col shadow-2xs">
                                <div class="p-3.5 border-b border-gray-200/80 bg-white rounded-t-xl">
                                    <h3 class="text-sm font-semibold text-gray-700">Unassigned Staff</h3>
                                    <p class="text-xs text-gray-500">{{ $unassignedAppointments->count() }} bookings need a stylist</p>
                                </div>
                                <div class="p-3 space-y-3 flex-1 overflow-y-auto max-h-[640px]">
                                    @foreach ($unassignedAppointments as $appointment)
                                        <div wire:key="unassigned-app-{{ $appointment->id }}" class="rounded-xl border border-gray-200 bg-white p-3 shadow-2xs">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="text-xs font-bold tabular-nums text-gray-900 bg-gray-100 px-1.5 py-0.5 rounded">
                                                    {{ $business->toLocal($appointment->starts_at)->format('H:i') }}
                                                </span>
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-medium ring-1 ring-inset {{ $this->statusBadge($appointment->status) }}">
                                                    {{ $appointment->statusLabel() }}
                                                </span>
                                            </div>
                                            <h4 class="mt-1 text-sm font-semibold text-gray-900">{{ $appointment->customer?->name ?? 'Customer removed' }}</h4>
                                            <div class="mt-1 text-xs text-gray-600">{{ $appointment->service?->name ?? 'No service' }}</div>
                                            <button type="button" wire:click="openReschedule({{ $appointment->id }})" class="mt-2 text-xs font-semibold text-mulberry-700 hover:underline">
                                                Assign Staff Member →
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @else
                {{-- -----------------------------------------------------------
                     List View — Chronological appointments
                ------------------------------------------------------------ --}}
                <ul class="divide-y divide-gray-100">
                    @forelse ($appointments as $appointment)
                        @php
                            $isActive = in_array($appointment->status, \App\Models\Appointment::REMINDABLE, true);
                            $isPending = $appointment->status === \App\Models\Appointment::PENDING;
                        @endphp

                        <li wire:key="appointment-{{ $appointment->id }}"
                            x-data="{ actions: false }"
                            class="appointment-card p-4 {{ $appointment->isCancelled() ? 'opacity-75' : '' }}"
                            style="--staff-color: {{ $appointment->staffMember?->color ?? '#7d2f51' }}">
                            <style>[wire\:key="appointment-{{ $appointment->id }}"]::before { background: var(--staff-color); }</style>

                            <div class="flex flex-wrap items-start gap-x-4 gap-y-3 pl-2">

                                {{-- Time --}}
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

                                        @if ($appointment->customer?->phone)
                                            @php
                                                $cleanTel = preg_replace('/[^0-9]/', '', (string) ($appointment->customer->whatsappTarget() ?: $appointment->customer->phone));
                                            @endphp
                                            <span class="inline-flex items-center gap-1 ms-0.5">
                                                <a href="https://wa.me/{{ $cleanTel }}?text={{ rawurlencode('Hi '.$appointment->customer->name.', from '.$business->name.'!') }}"
                                                   target="_blank" rel="noopener noreferrer"
                                                   title="WhatsApp {{ $appointment->customer->name }}"
                                                   class="p-1 rounded text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 transition-colors">
                                                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                                </a>
                                                <a href="tel:{{ $appointment->customer->phone }}"
                                                   title="Call {{ $appointment->customer->name }}"
                                                   class="p-1 rounded text-sky-600 hover:bg-sky-50 hover:text-sky-700 transition-colors">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                                                </a>
                                            </span>
                                        @endif

                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $this->statusBadge($appointment->status) }}">
                                            {{ $appointment->statusLabel() }}
                                        </span>
                                    </div>

                                    <div class="mt-0.5 text-sm text-gray-600">
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

                                        @if($appointment->isFullyPaid())
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                                Paid in full
                                            </span>
                                        @elseif($appointment->hasPaidDeposit())
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-bold bg-sky-100 text-sky-800">
                                                Deposit £{{ number_format($appointment->paid_amount, 2) }}
                                            </span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-amber-100 text-amber-800">
                                                Due £{{ number_format($appointment->balanceDue(), 2) }}
                                            </span>
                                        @elseif($appointment->deposit_required && $appointment->deposit_status === 'unpaid')
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200">
                                                Deposit Unpaid (£{{ number_format($appointment->deposit_amount, 2) }})
                                            </span>
                                        @endif
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

                                {{-- Quick action cluster --}}
                                <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                                    @if ($isActive)
                                        <button type="button"
                                                wire:click="setStatus({{ $appointment->id }}, '{{ $isPending ? 'confirmed' : 'completed' }}')"
                                                class="inline-flex items-center justify-center min-h-touch rounded-lg px-4 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 {{ $isPending ? 'bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200 hover:bg-sky-100 focus-visible:ring-sky-600' : 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 focus-visible:ring-emerald-600' }}">
                                            {{ $isPending ? 'Confirm' : 'Done' }}
                                        </button>
                                    @elseif ($appointment->isCancelled())
                                        <x-button variant="secondary" size="sm" type="button" wire:click="setStatus({{ $appointment->id }}, 'confirmed')">Put back in the diary</x-button>
                                    @endif

                                    <button type="button" wire:click="openReschedule({{ $appointment->id }})"
                                            title="Quick reschedule"
                                            class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-mulberry-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </button>

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

                            <div x-show="actions"
                                 x-collapse
                                 style="display: none"
                                 class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">

                                <x-button variant="secondary" size="sm" type="button" wire:click="edit({{ $appointment->id }})">Edit</x-button>

                                <x-button variant="secondary" size="sm" type="button" wire:click="openReschedule({{ $appointment->id }})">
                                    Reschedule
                                </x-button>

                                @if(! $appointment->isFullyPaid())
                                    <button type="button" wire:click="markPaid({{ $appointment->id }})"
                                            title="Record that client has settled their remaining bill"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition-colors">
                                        &check; Mark Paid (&pound;{{ number_format($appointment->balanceDue(), 2) }})
                                    </button>
                                @endif

                                @if ($isActive)
                                    @if ($isPending)
                                        <x-button variant="secondary" size="sm" type="button" wire:click="setStatus({{ $appointment->id }}, 'completed')">Done</x-button>
                                    @endif

                                    <x-button variant="secondary" size="sm" type="button" wire:click="setStatus({{ $appointment->id }}, 'no_show')">Didn't turn up</x-button>

                                    <x-button variant="secondary" size="sm" type="button" wire:click="setStatus({{ $appointment->id }}, 'cancelled')" wire:confirm="Cancel this appointment?">Cancel</x-button>
                                @endif

                                <x-button variant="outline-danger" size="sm" type="button" wire:click="delete({{ $appointment->id }})" wire:confirm="Remove this appointment from the diary?">Remove</x-button>
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-16 text-center">
                            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-mulberry-50 text-mulberry-700 mb-3 ring-1 ring-mulberry-100 transition-transform duration-300 hover:scale-105">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                </svg>
                            </div>
                            <p class="text-gray-500 font-medium">{{ $isToday ? 'Nothing booked today.' : 'Nothing booked on this day.' }}</p>
                            <p class="text-sm text-gray-400 mt-1">Tap below to fill this slot.</p>
                            <x-primary-button type="button" wire:click="create" class="mt-4">
                                <svg class="h-4 w-4 -ml-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                                Add a booking
                            </x-primary-button>
                        </li>
                    @endforelse
                </ul>
            @endif
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
                    <p class="mt-1 text-xs text-gray-500">Pending = reminder will be sent. Confirmed = same. Completed/Cancelled/No-show = no reminder.</p>
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

    {{-- ---------------------------------------------------------------
         Quick Reschedule Modal
    ---------------------------------------------------------------- --}}
    @if ($showReschedule)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="reschedule-modal-title" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-gray-900/50 overlay-blur transition-opacity"></div>

            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg border border-gray-100"
                     @click.outside="$wire.showReschedule = false">

                    {{-- Header --}}
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 bg-gray-50/70">
                        <div class="flex items-center gap-2.5">
                            <div class="h-8 w-8 rounded-lg bg-mulberry-50 flex items-center justify-center text-mulberry-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <h2 id="reschedule-modal-title" class="text-base font-semibold text-gray-900">
                                Quick Reschedule
                            </h2>
                        </div>
                        <button type="button" wire:click="$set('showReschedule', false)" class="text-gray-400 hover:text-gray-600 rounded-lg p-1.5 transition-colors">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                    </div>

                    {{-- Body --}}
                    <div class="px-6 py-5 space-y-4">
                        @php
                            $rescheduleTarget = \App\Models\Appointment::with(['customer', 'service', 'staffMember'])->find($reschedulingId);
                        @endphp
                        @if ($rescheduleTarget)
                            <div class="p-3.5 bg-gray-50/80 rounded-xl border border-gray-100 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ $rescheduleTarget->customer?->name ?? 'Customer' }}</p>
                                    <p class="text-xs text-gray-500">{{ $rescheduleTarget->service?->name ?? 'Service' }} ({{ $rescheduleTarget->durationMinutes() }}m)</p>
                                </div>
                                <span class="text-xs font-medium px-2.5 py-1 bg-white rounded-md border border-gray-200 text-gray-700 shadow-2xs">
                                    Current: {{ $business->toLocal($rescheduleTarget->starts_at)->format('D j M, H:i') }}
                                </span>
                            </div>
                        @endif

                        {{-- Quick Presets --}}
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Quick Presets</label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="applyReschedulePreset('tomorrow')"
                                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-700 hover:bg-mulberry-50 hover:text-mulberry-700 transition-colors">
                                    +1 Day
                                </button>
                                <button type="button" wire:click="applyReschedulePreset('plus_2_days')"
                                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-700 hover:bg-mulberry-50 hover:text-mulberry-700 transition-colors">
                                    +2 Days
                                </button>
                                <button type="button" wire:click="applyReschedulePreset('plus_1_week')"
                                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-700 hover:bg-mulberry-50 hover:text-mulberry-700 transition-colors">
                                    +1 Week
                                </button>
                                <button type="button" wire:click="applyReschedulePreset('minus_15m')"
                                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-700 hover:bg-mulberry-50 hover:text-mulberry-700 transition-colors">
                                    -15m
                                </button>
                                <button type="button" wire:click="applyReschedulePreset('plus_15m')"
                                        class="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-700 hover:bg-mulberry-50 hover:text-mulberry-700 transition-colors">
                                    +15m
                                </button>
                            </div>
                        </div>

                        {{-- Form controls --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="reschedule_date" value="New Date" />
                                <x-text-input id="reschedule_date" type="date" wire:model.live="reschedule_date" class="mt-1 block w-full text-sm" />
                                <x-input-error :messages="$errors->get('reschedule_date')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="reschedule_time" value="New Time" />
                                <x-text-input id="reschedule_time" type="time" wire:model.live="reschedule_time" class="mt-1 block w-full text-sm" />
                                <x-input-error :messages="$errors->get('reschedule_time')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="reschedule_staff_id" value="Staff Member" />
                            <x-select-input id="reschedule_staff_id" wire:model.live="reschedule_staff_id" class="mt-1 block w-full text-sm">
                                <option value="">No preference / unassigned</option>
                                @foreach ($staffForFilter as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('reschedule_staff_id')" class="mt-1" />
                        </div>

                        {{-- Conflict alert --}}
                        @if ($reschedule_conflict)
                            <div class="rounded-xl bg-amber-50 p-3.5 border border-amber-200">
                                <div class="flex items-start gap-2.5">
                                    <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <p class="text-xs font-semibold text-amber-900">Schedule Conflict</p>
                                        <p class="mt-0.5 text-xs text-amber-800">{{ $reschedule_conflict }}</p>
                                        <label class="mt-2.5 inline-flex items-center gap-2 text-xs font-medium text-amber-900 cursor-pointer">
                                            <input type="checkbox" wire:model="reschedule_allow_overlap" class="h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                                            <span>Book anyway (allow double-booking)</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center justify-end gap-3 px-6 py-4 bg-gray-50 border-t border-gray-100">
                        <x-secondary-button wire:click="$set('showReschedule', false)">
                            Cancel
                        </x-secondary-button>
                        <x-primary-button wire:click="saveReschedule">
                            Save new time
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
