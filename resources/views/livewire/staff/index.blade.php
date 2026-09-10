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

        $this->resetValidation();
        $this->showForm = true;
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

        $staff->fill($validated)->save();

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
        $this->reset(['editingId', 'name', 'phone', 'color', 'sort_order', 'active']);

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

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        <div class="flex items-center justify-between px-4 sm:px-0">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Staff</h1>
                <p class="text-sm text-gray-500">Who does the work, and their colour on the calendar</p>
            </div>

            <x-primary-button type="button" wire:click="create">Add staff</x-primary-button>
        </div>

        <x-toast />

        <div class="bg-white shadow-sm sm:rounded-lg">

            @if ($inactiveCount > 0)
                <div class="border-b border-gray-100 px-4">
                    <label class="inline-flex min-h-touch items-center gap-2 py-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model.live="showInactive"
                               class="h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                        Show {{ $inactiveCount }} hidden
                    </label>
                </div>
            @endif

            @php
                $act = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600';
                $actDanger = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-red-200 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600';
            @endphp

            @if ($staff->isEmpty())
                <div class="px-4 py-12 text-center">
                    @if ($inactiveCount > 0)
                        {{-- Not "no staff yet": there are people, they are just all
                             hidden, and the toggle to bring them back is right above. --}}
                        <p class="text-gray-500">Everyone is hidden right now.</p>
                        <p class="mt-1 text-sm text-gray-500">Tick "Show {{ $inactiveCount }} hidden" above to see them.</p>
                    @else
                        <p class="text-gray-500">No staff yet.</p>
                        <p class="mt-1 text-sm text-gray-500">Add yourself first, then anyone who takes their own bookings.</p>
                        <x-primary-button type="button" wire:click="create" class="mt-4">Add the first person</x-primary-button>
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

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="edit({{ $member->id }})" class="{{ $act }}">Edit</button>

                            <button type="button" wire:click="toggleActive({{ $member->id }})" class="{{ $act }}">
                                {{ $member->active ? 'Hide' : 'Show' }}
                            </button>

                            @if ((int) $member->appointments_count === 0)
                                <button type="button" wire:click="delete({{ $member->id }})"
                                        wire:confirm="Delete {{ $member->name }}?"
                                        class="{{ $actDanger }}">Delete</button>
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
                                    <button type="button" wire:click="edit({{ $member->id }})"
                                            class="rounded font-medium text-mulberry-700 hover:text-mulberry-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">Edit</button>

                                    <button type="button" wire:click="toggleActive({{ $member->id }})"
                                            class="ms-3 rounded text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        {{ $member->active ? 'Hide' : 'Show' }}
                                    </button>

                                    {{-- Only offered when there is no history to lose. Cast because an
                                         aggregate column can arrive as a string depending on the driver,
                                         and a strict comparison would silently hide the button. --}}
                                    @if ((int) $member->appointments_count === 0)
                                        <button type="button" wire:click="delete({{ $member->id }})"
                                                wire:confirm="Delete {{ $member->name }}?"
                                                class="ms-3 rounded text-gray-500 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-form-modal :title="$editingId ? 'Edit staff member' : 'Add staff member'"
                      :submit-label="$editingId ? 'Save changes' : 'Add staff member'">

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
                    @foreach ($colors as $hex => $label)
                        {{-- The swatch is 32px but the button around it is a 44px target,
                             so a thumb picks the colour it meant to pick. --}}
                        <button type="button" wire:click="$set('color', '{{ $hex }}')"
                                title="{{ $label }}" aria-label="{{ $label }}"
                                aria-pressed="{{ $color === $hex ? 'true' : 'false' }}"
                                class="inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                            <span class="h-8 w-8 rounded-full ring-offset-2 transition {{ $color === $hex ? 'ring-2 ring-gray-900' : '' }}"
                                  style="background-color: {{ $hex }}"></span>
                        </button>
                    @endforeach
                </div>

                <x-input-error :messages="$errors->get('color')" class="mt-2" />
            </div>

            <label class="flex min-h-touch items-start gap-3 rounded-lg bg-gray-50 p-3">
                <input type="checkbox" wire:model="active"
                       class="mt-0.5 h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                <span class="text-sm text-gray-700">
                    Available to book
                    <span class="block text-xs text-gray-500">
                        Untick when someone leaves. Their past bookings stay on the record,
                        so your takings history doesn't change.
                    </span>
                </span>
            </label>
        </x-form-modal>
    @endif
</div>
