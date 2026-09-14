<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\StaffMember;
use App\Support\Phone;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Staff')] class extends Component
{
    use TenantScreen;

    /** Calendar-friendly presets, so a rushed owner doesn't have to pick a hex code. */
    public const COLORS = [
        '#6366f1' => 'Indigo',
        '#0ea5e9' => 'Sky',
        '#10b981' => 'Emerald',
        '#f59e0b' => 'Amber',
        '#ef4444' => 'Red',
        '#ec4899' => 'Pink',
        '#8b5cf6' => 'Violet',
        '#64748b' => 'Slate',
    ];

    public bool $showInactive = false;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public ?string $phone = null;
    public string $color = '#6366f1';
    public string $sort_order = '0';
    public bool $active = true;

    // Schedule & Time-Off State
    public string $activeTab = 'details'; // 'details' | 'schedule' | 'timeoff'
    public array $workingHours = [];
    public array $timeOffList = [];
    public string $newTimeOffDate = '';
    public string $newTimeOffReason = 'Annual Leave';
    public bool $newTimeOffAllDay = true;
    public string $newTimeOffStart = '09:00';
    public string $newTimeOffEnd = '17:00';

    public function mount(): void
    {
        $this->guardTenant();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // No unique rule: staff_members has no unique index on phone, and two
            // staff legitimately share a landline. Shape only.
            'phone' => ['nullable', 'string', 'max:32', $this->phoneShapeRule()],
            // The column is char(7), so anything but #rrggbb would be truncated
            // into a colour nobody chose.
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'color.regex' => 'Pick a colour from the list.',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['sort_order' => 'display order'];
    }

    /**
     * Reject text that is not a phone number at all.
     *
     * The model's phone mutator runs Phone::normalise(), which returns null for
     * anything it cannot parse — so without this rule "ask reception" would be
     * saved as an empty phone and the owner would never know it went missing.
     */
    protected function phoneShapeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (filled($value) && ! Phone::looksValid($value)) {
                $fail('Please enter a real phone number, e.g. 07700 900123.');
            }
        };
    }

    public function create(): void
    {
        $this->resetForm();
        $this->workingHours = StaffMember::defaultWorkingHours();
        $this->timeOffList = [];
        $this->activeTab = 'details';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        // findOrFail IS the authorisation check: another business's id does not
        // exist from inside the global scope, so this 404s.
        $staff = StaffMember::findOrFail($id);

        $this->editingId = $staff->id;
        $this->name = $staff->name;
        $this->phone = $staff->phone;
        $this->color = $staff->color;
        $this->sort_order = (string) $staff->sort_order;
        $this->active = $staff->active;

        $this->workingHours = $staff->working_hours ?: StaffMember::defaultWorkingHours();
        $this->timeOffList = $staff->time_off ?: [];
        $this->activeTab = 'details';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function addTimeOff(): void
    {
        if (blank($this->newTimeOffDate)) {
            $this->addError('newTimeOffDate', 'Please choose a date for time off.');
            return;
        }

        $this->timeOffList[] = [
            'id' => uniqid('off_'),
            'date' => $this->newTimeOffDate,
            'reason' => $this->newTimeOffReason ?: 'Annual Leave',
            'all_day' => $this->newTimeOffAllDay,
            'start' => $this->newTimeOffAllDay ? null : $this->newTimeOffStart,
            'end' => $this->newTimeOffAllDay ? null : $this->newTimeOffEnd,
        ];

        $this->newTimeOffDate = '';
        $this->newTimeOffReason = 'Annual Leave';
        $this->newTimeOffAllDay = true;
    }

    public function removeTimeOff(int $index): void
    {
        unset($this->timeOffList[$index]);
        $this->timeOffList = array_values($this->timeOffList);
    }

    public function save(): void
    {
        // Normalise before validating, not after: the column stores E.164, so the
        // stored value and the validated value must be the same thing.
        $this->phone = $this->normalisePhone($this->phone);
        $this->color = strtolower(trim($this->color));

        $validated = $this->validate();

        $staff = $this->editingId === null
            ? new StaffMember()
            : StaffMember::findOrFail($this->editingId);

        $staff->fill($validated);
        $staff->working_hours = $this->workingHours;
        $staff->time_off = array_values($this->timeOffList);
        $staff->save();

        $this->showForm = false;
        $this->resetForm();

        $this->toast('Staff member saved.');
    }

    /** Blank means NULL; unparseable text is handed back so the error can point at it. */
    protected function normalisePhone(?string $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        return Phone::normalise($raw) ?? $raw;
    }

    public function toggleActive(int $id): void
    {
        $staff = StaffMember::findOrFail($id);

        $staff->update(['active' => ! $staff->active]);

        $this->toast($staff->active
            ? $staff->name.' can be booked again.'
            : $staff->name.' hidden from new bookings.');
    }

    /**
     * Delete only if they were never booked; otherwise deactivate.
     *
     * staff_members has no soft deletes and appointments.staff_member_id is
     * nullOnDelete, so deleting someone with history blanks out who actually did
     * the work — and with it any per-stylist takings figure. Someone leaving the
     * salon is exactly what `active` is for.
     */
    public function delete(int $id): void
    {
        $staff = StaffMember::findOrFail($id);

        // withTrashed: a soft-deleted appointment still points here and would lose
        // the reference just the same.
        if ($staff->appointments()->withTrashed()->exists()) {
            $staff->update(['active' => false]);

            $this->toast($staff->name.' has past bookings, so they were hidden instead of deleted.');

            return;
        }

        $staff->delete();

        $this->toast('Staff member deleted.');
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone', 'color', 'sort_order', 'active', 'workingHours', 'timeOffList', 'activeTab', 'newTimeOffDate']);

        $this->resetValidation();
    }

    public function with(): array
    {
        $staff = StaffMember::query()
            ->when(! $this->showInactive, fn ($query) => $query->active())
            ->withCount([
                // Total: withTrashed, because a cancelled-and-removed booking is
                // still history this person is attached to.
                'appointments' => fn ($query) => $query->withTrashed(),
                // Upcoming: only what they are still expected to do, so
                // cancellations and no-shows are left out (REMINDABLE is exactly
                // that set — pending + confirmed).
                'appointments as upcoming_count' => fn ($query) => $query->upcoming()->remindable(),
            ])
            ->ordered()
            ->get();

        return [
            'staff' => $staff,
            'inactiveCount' => StaffMember::where('active', false)->count(),
            // `self` is not this class inside the compiled Blade view, so the
            // palette has to travel through with().
            'colors' => self::COLORS,
        ];
    }
}; ?>

<div x-data="{ toast: null }"
     x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 2500)">

    <x-page title="Staff" subtitle="Who does the work, and their colour on the calendar">
        <x-slot:actions>
            <x-button wire:click="create">
                <svg class="h-4 w-4 -ml-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                Add staff
            </x-button>
        </x-slot:actions>

        <x-toast class="!px-0" />

        <x-card>

            @if ($inactiveCount > 0)
                <div class="border-b border-gray-100 px-4">
                    <label class="inline-flex min-h-touch items-center gap-2 py-2 text-sm text-gray-600">
                        <x-checkbox wire:model.live="showInactive" />
                        Show {{ $inactiveCount }} hidden
                    </label>
                </div>
            @endif

            @if ($staff->isEmpty())
                <div class="px-4 py-16 text-center">
                    @if ($inactiveCount > 0)
                        {{-- Not "no staff yet": there are people, they are just all
                             hidden, and the toggle to bring them back is right above. --}}
                        <p class="text-gray-500">Everyone is hidden right now.</p>
                        <p class="mt-1 text-sm text-gray-400">Tick "Show {{ $inactiveCount }} hidden" above to see them.</p>
                    @else
                        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-mulberry-50 text-mulberry-700 mb-3 shadow-xs ring-1 ring-mulberry-100 transition-transform duration-300 hover:scale-105">
                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                        </div>
                        <p class="text-gray-500 font-medium">No staff yet.</p>
                        <p class="mt-1 text-sm text-gray-400">Add yourself first, then anyone who takes their own bookings.</p>
                        <x-primary-button type="button" wire:click="create" class="mt-4">
                            <svg class="h-4 w-4 -ml-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                            Add the first person
                        </x-primary-button>
                    @endif
                </div>
            @else

            {{-- ─── Phone: cards. Keys are prefixed apart from the table's below. ─── --}}
            <ul class="divide-y divide-gray-100 sm:hidden">
                @foreach ($staff as $member)
                    <li wire:key="staff-card-{{ $member->id }}"
                        class="p-4 {{ $member->active ? '' : 'opacity-60' }}">

                        <div class="flex items-center gap-2">
                            <span class="inline-block h-3 w-3 shrink-0 rounded-full"
                                  style="background-color: {{ $member->color }}"></span>

                            <span class="font-medium text-gray-900">{{ $member->name }}</span>

                            @unless ($member->active)
                                <span class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">Hidden</span>
                            @endunless
                        </div>

                        @if ($member->phone)
                            <a href="tel:{{ $member->phone }}"
                               class="ms-5 inline-flex min-h-touch items-center rounded-lg text-sm font-medium text-mulberry-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                {{ \App\Support\Phone::forHumans($member->phone) }}
                            </a>
                        @endif

                        <div class="ms-5 flex flex-wrap items-baseline gap-x-4 text-sm text-gray-500">
                            <span>
                                Coming up
                                <span class="font-medium tabular-nums text-gray-900">{{ (int) $member->upcoming_count }}</span>
                            </span>

                            <span>
                                Booked in all
                                <span class="font-medium tabular-nums text-gray-900">{{ (int) $member->appointments_count }}</span>
                            </span>
                        </div>

                        <div class="ms-5 mt-2 flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200">
                                {{ $member->workingDaysSummary() }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-button variant="secondary" size="sm" type="button" wire:click="edit({{ $member->id }})">Edit</x-button>

                            <x-button variant="secondary" size="sm" type="button" wire:click="toggleActive({{ $member->id }})">
                                {{ $member->active ? 'Hide' : 'Show' }}
                            </x-button>

                            @if ((int) $member->appointments_count === 0)
                                <x-button variant="outline-danger" size="sm" type="button" wire:click="delete({{ $member->id }})" wire:confirm="Delete {{ $member->name }}?">Delete</x-button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- ─── Desktop: the table ───────────────────────────────────────── --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Name</th>
                            <th class="px-4 py-3 text-left font-medium">Phone</th>
                            <th class="px-4 py-3 text-left font-medium">Schedule</th>
                            <th class="px-4 py-3 text-right font-medium">Upcoming</th>
                            <th class="px-4 py-3 text-right font-medium">Total booked</th>
                            <th class="px-4 py-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($staff as $member)
                            <tr wire:key="staff-row-{{ $member->id }}"
                                class="hover:bg-gray-50 {{ $member->active ? '' : 'opacity-60' }}">

                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block h-3 w-3 shrink-0 rounded-full"
                                              style="background-color: {{ $member->color }}"></span>

                                        <span class="font-medium text-gray-900">{{ $member->name }}</span>

                                        @unless ($member->active)
                                            <span class="inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">
                                                Hidden
                                            </span>
                                        @endunless
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    {{ \App\Support\Phone::forHumans($member->phone) ?? '—' }}
                                </td>

                                <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200">
                                        {{ $member->workingDaysSummary() }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    @if ((int) $member->upcoming_count > 0)
                                        <span class="font-medium text-gray-900">{{ (int) $member->upcoming_count }}</span>
                                    @else
                                        <span class="text-gray-500">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right text-gray-500 tabular-nums whitespace-nowrap">
                                    {{ (int) $member->appointments_count }}
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <x-button variant="link" size="bare" wire:click="edit({{ $member->id }})">Edit</x-button>

                                    <x-button variant="underline" size="bare" class="ms-3" wire:click="toggleActive({{ $member->id }})">
                                        {{ $member->active ? 'Hide' : 'Show' }}
                                    </x-button>

                                    {{-- Only offered when there is no history to lose. Cast because an
                                         aggregate column can arrive as a string depending on the driver,
                                         and a strict comparison would silently hide the button. --}}
                                    @if ((int) $member->appointments_count === 0)
                                        <x-button variant="underline-danger" size="bare" class="ms-3" wire:click="delete({{ $member->id }})"
                                                wire:confirm="Delete {{ $member->name }}?">Delete</x-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-card>
    </x-page>

    @if ($showForm)
        <x-form-modal :title="$editingId ? 'Edit staff member' : 'Add staff member'"
                      :submit-label="$editingId ? 'Save changes' : 'Add staff member'">

            <!-- Navigation Tabs -->
            {{-- design-allow:start — underlined tab strip: a selected-state control,
                 not one of x-button's intents. --}}
            <div class="flex border-b border-gray-200 -mt-2">
                <button type="button" wire:click="$set('activeTab', 'details')"
                        class="py-2.5 px-4 text-xs sm:text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'details' ? 'border-mulberry-700 text-mulberry-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Profile &amp; Details
                </button>
                <button type="button" wire:click="$set('activeTab', 'schedule')"
                        class="py-2.5 px-4 text-xs sm:text-sm font-semibold border-b-2 transition-colors {{ $activeTab === 'schedule' ? 'border-mulberry-700 text-mulberry-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Working Hours
                </button>
                <button type="button" wire:click="$set('activeTab', 'timeoff')"
                        class="py-2.5 px-4 text-xs sm:text-sm font-semibold border-b-2 transition-colors flex items-center gap-1.5 {{ $activeTab === 'timeoff' ? 'border-mulberry-700 text-mulberry-700' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Time Off &amp; Leave
                    @if(count($timeOffList) > 0)
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-amber-100 text-amber-800 font-bold">{{ count($timeOffList) }}</span>
                    @endif
                </button>
                {{-- design-allow:end --}}
            </div>

            @if ($activeTab === 'details')
                <div class="space-y-4">
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full"
                                      placeholder="Jade Thompson" autofocus />
                        <p class="mt-1 text-xs text-gray-500">Shown on the diary and in booking confirmations.</p>
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="phone" value="Phone (optional)" />
                            <x-text-input wire:model="phone" id="phone" type="tel" class="mt-1 block w-full"
                                          placeholder="07700 900123" />
                            <p class="mt-1 text-xs text-gray-500">Used for SMS reminders. Leave blank if they don't take bookings directly.</p>
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="sort_order" value="Order in the list" />
                            <x-text-input wire:model="sort_order" id="sort_order" type="number"
                                          min="0" max="999" class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-gray-500">Low numbers show first. Leave it at 0 if you don't mind.</p>
                            <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label value="Calendar colour" />
                        <p class="mt-1 text-xs text-gray-500">Shown on the diary so you can tell staff apart at a glance.</p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            {{-- design-allow:start — the swatches ARE the control; the hex is
                                 data, not decoration (it renders on the calendar). --}}
                            @foreach ($colors as $hex => $label)
                                <button type="button" wire:click="$set('color', '{{ $hex }}')"
                                        title="{{ $label }}" aria-label="{{ $label }}"
                                        aria-pressed="{{ $color === $hex ? 'true' : 'false' }}"
                                        class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                    <span class="h-8 w-8 rounded-full ring-offset-2 transition {{ $color === $hex ? 'ring-2 ring-gray-900' : '' }}"
                                          style="background-color: {{ $hex }}"></span>
                                </button>
                            @endforeach
                            {{-- design-allow:end --}}
                        </div>

                        <x-input-error :messages="$errors->get('color')" class="mt-2" />
                    </div>

                    <label class="flex min-h-touch items-start gap-3 rounded-lg bg-gray-50 p-3">
                        <x-checkbox wire:model="active" class="mt-0.5" />
                        <span class="text-sm text-gray-700">
                            Available to book
                            <span class="block text-xs text-gray-500">
                                Untick when someone leaves. Their past bookings stay on the record,
                                so your takings history doesn't change.
                            </span>
                        </span>
                    </label>
                </div>
            @endif

            @if ($activeTab === 'schedule')
                <div class="space-y-3">
                    {{-- design-allow:start — seven compact day rows; the xs time/date/text
                         inputs stay small on purpose (x-text-input's 44px floor would
                         triple the panel height). --}}
                    <p class="text-xs text-gray-500">Configure which days and hours this specialist is available for appointments.</p>
                    <div class="space-y-1.5 border border-gray-200 rounded-xl divide-y divide-gray-100 overflow-hidden bg-white">
                        @foreach (['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'] as $dayKey => $dayLabel)
                            <div class="p-2.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 {{ empty($workingHours[$dayKey]['is_working']) ? 'bg-gray-50/70 opacity-70' : 'bg-white' }}">
                                <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                    <x-checkbox wire:model="workingHours.{{ $dayKey }}.is_working" />
                                    <span class="text-sm font-semibold text-gray-800">{{ $dayLabel }}</span>
                                </label>
                                @if (!empty($workingHours[$dayKey]['is_working']))
                                    <div class="flex items-center gap-2 text-xs">
                                        <input type="time" wire:model="workingHours.{{ $dayKey }}.start"
                                               class="rounded-lg border-gray-300 text-xs py-1 px-2 focus:ring-mulberry-600 focus:border-mulberry-600">
                                        <span class="text-gray-400">to</span>
                                        <input type="time" wire:model="workingHours.{{ $dayKey }}.end"
                                               class="rounded-lg border-gray-300 text-xs py-1 px-2 focus:ring-mulberry-600 focus:border-mulberry-600">
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400 italic">Off duty</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    {{-- design-allow:end --}}
                </div>
            @endif

            @if ($activeTab === 'timeoff')
                <div class="space-y-4">
                    <p class="text-xs text-gray-500">Block vacation, medical leave, or personal time off. Appointments cannot be booked during these times.</p>

                    <!-- Add Time Off Card -->
                    {{-- design-allow:start — compact form inside a tab: xs date/time
                         inputs, same 44px-floor reason as the schedule grid. --}}
                    <div class="p-3.5 bg-gray-50 rounded-xl border border-gray-200 space-y-3">
                        <div class="font-semibold text-xs text-gray-800">Add Leave or Time Off</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="text-[11px] font-medium text-gray-600 block mb-1">Date</label>
                                <input type="date" wire:model="newTimeOffDate"
                                       class="w-full text-xs rounded-lg border-gray-300 py-1.5 px-2.5 focus:ring-mulberry-600 focus:border-mulberry-600">
                                <x-input-error :messages="$errors->get('newTimeOffDate')" class="mt-1" />
                            </div>
                            <div>
                                <label class="text-[11px] font-medium text-gray-600 block mb-1">Reason</label>
                                <input type="text" wire:model="newTimeOffReason" placeholder="e.g. Annual Leave, Doctor"
                                       class="w-full text-xs rounded-lg border-gray-300 py-1.5 px-2.5 focus:ring-mulberry-600 focus:border-mulberry-600">
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <label class="inline-flex items-center gap-2 cursor-pointer text-xs text-gray-700">
                                <x-checkbox wire:model.live="newTimeOffAllDay" />
                                <span>All day absence</span>
                            </label>

                            @if(!$newTimeOffAllDay)
                                <div class="flex items-center gap-2 text-xs">
                                    <input type="time" wire:model="newTimeOffStart" class="rounded-lg border-gray-300 text-xs py-1 px-2">
                                    <span class="text-gray-400">to</span>
                                    <input type="time" wire:model="newTimeOffEnd" class="rounded-lg border-gray-300 text-xs py-1 px-2">
                                </div>
                            @endif

                            <x-button variant="secondary" size="sm" wire:click="addTimeOff">
                                + Add Block
                            </x-button>
                        </div>
                    </div>
                    {{-- design-allow:end --}}

                    <!-- List of Scheduled Time Off -->
                    <div class="space-y-2">
                        <div class="text-xs font-semibold text-gray-700">Scheduled Leaves &amp; Absences ({{ count($timeOffList) }})</div>
                        @if(empty($timeOffList))
                            <p class="text-xs text-gray-400 italic">No time-off or vacations scheduled for this specialist.</p>
                        @else
                            <div class="divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden bg-white max-h-48 overflow-y-auto">
                                @foreach($timeOffList as $idx => $off)
                                    <div class="p-2.5 flex items-center justify-between text-xs hover:bg-gray-50">
                                        <div class="flex items-center gap-2">
                                            <span class="text-amber-600 font-bold">&#127796;</span>
                                            <div>
                                                <div class="font-semibold text-gray-900">{{ \Illuminate\Support\Carbon::parse($off['date'])->format('D, j M Y') }}</div>
                                                <div class="text-[11px] text-gray-500">
                                                    {{ $off['reason'] }} &middot; {{ !empty($off['all_day']) ? 'Full Day' : ($off['start'].' - '.$off['end']) }}
                                                </div>
                                            </div>
                                        </div>
                                        <x-button variant="underline-danger" size="bare" wire:click="removeTimeOff({{ $idx }})">
                                            Remove
                                        </x-button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </x-form-modal>
    @endif
</div>
