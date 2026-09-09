<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\Service;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Services')] class extends Component
{
    use TenantScreen;

    public bool $showInactive = false;

    public bool $showForm = false;
    public ?int $editingId = null;

    /* Numeric fields are strings, not int/float.
       A typed `public int` property throws a TypeError the moment the user clears
       the input (Livewire tries to set ''), which is a 500 page for a blank field.
       The `integer` and `numeric` rules accept numeric strings, and the model's
       casts handle storage, so nothing is lost. */
    public string $name = '';
    public ?string $description = null;
    public string $duration_minutes = '30';
    public string $price = '0.00';
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
            // The column is string(255), not text — a longer description would be
            // truncated by MySQL in non-strict mode rather than rejected.
            'description' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'active' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'duration_minutes' => 'duration',
            'sort_order' => 'display order',
        ];
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
        $service = Service::findOrFail($id);

        $this->editingId = $service->id;
        $this->name = $service->name;
        $this->description = $service->description;
        $this->duration_minutes = (string) $service->duration_minutes;
        $this->price = (string) $service->price;
        $this->sort_order = (string) $service->sort_order;
        $this->active = $service->active;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $validated = $this->validate();

        $service = $this->editingId === null
            ? new Service()
            : Service::findOrFail($this->editingId);

        $service->fill($validated)->save();

        $this->showForm = false;
        $this->resetForm();

        $this->toast('Service saved.');
    }

    public function toggleActive(int $id): void
    {
        $service = Service::findOrFail($id);

        $service->update(['active' => ! $service->active]);

        $this->toast($service->active ? 'Service is bookable again.' : 'Service hidden from new bookings.');
    }

    /**
     * Delete only if it was never booked; otherwise deactivate.
     *
     * Services have no soft deletes, and appointments.service_id is nullOnDelete —
     * so deleting one that has history leaves those appointments with no service
     * name at all, and "what did we actually sell last quarter" goes blank. The
     * `active` flag exists precisely to retire a service without that loss, so the
     * screen quietly does the safe thing and says so rather than refusing.
     */
    public function delete(int $id): void
    {
        $service = Service::findOrFail($id);

        // withTrashed: a soft-deleted appointment still points at this service and
        // would lose the reference just the same.
        if ($service->appointments()->withTrashed()->exists()) {
            $service->update(['active' => false]);

            $this->toast('Past bookings use this service, so it was hidden instead of deleted.');

            return;
        }

        $service->delete();

        $this->toast('Service deleted.');
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'description', 'duration_minutes',
            'price', 'sort_order', 'active',
        ]);

        $this->resetValidation();
    }

    public function with(): array
    {
        $services = Service::query()
            ->when(! $this->showInactive, fn ($query) => $query->active())
            ->withCount(['appointments' => fn ($query) => $query->withTrashed()])
            ->ordered()
            ->get();

        return [
            'services' => $services,
            'inactiveCount' => Service::where('active', false)->count(),
        ];
    }
}; ?>

<div x-data="{ toast: null }"
     x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 2500)">

    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">

        <div class="flex items-center justify-between px-4 sm:px-0">
            <div>
                <h1 class="text-xl font-semibold text-gray-900">Services</h1>
                <p class="text-sm text-gray-500">What you offer, how long it takes, what it costs</p>
            </div>

            <x-primary-button type="button" wire:click="create">Add service</x-primary-button>
        </div>

        <x-toast />

        <div class="bg-white shadow-sm sm:rounded-lg">

            @if ($inactiveCount > 0)
                <div class="border-b border-gray-100 px-4">
                    <label class="inline-flex min-h-touch items-center gap-2 py-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model.live="showInactive"
                               class="h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                        Show hidden ({{ $inactiveCount }})
                    </label>
                </div>
            @endif

            @php
                $act = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600';
                $actDanger = 'inline-flex items-center justify-center min-h-touch rounded-lg border border-red-200 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600';
            @endphp

            @if ($services->isEmpty())
                <div class="px-4 py-12 text-center">
                    @if ($inactiveCount > 0)
                        {{-- Not "no services yet": there are some, they are just all
                             hidden, and the toggle to bring them back is right above. --}}
                        <p class="text-gray-500">Everything is hidden right now.</p>
                        <p class="mt-1 text-sm text-gray-500">Tick "Show hidden ({{ $inactiveCount }})" above to see them.</p>
                    @else
                        <p class="text-gray-500">No services yet.</p>
                        <p class="mt-1 text-sm text-gray-500">Add the ones you book most often first — the rest can wait.</p>
                        <x-primary-button type="button" wire:click="create" class="mt-4">Add your first service</x-primary-button>
                    @endif
                </div>
            @else

            {{-- ─── Phone: cards. Keys are prefixed apart from the table's below. ─── --}}
            <ul class="divide-y divide-gray-100 sm:hidden">
                @foreach ($services as $service)
                    <li wire:key="service-card-{{ $service->id }}"
                        class="p-4 {{ $service->active ? '' : 'opacity-60' }}">

                        <div class="font-medium text-gray-900">
                            {{ $service->name }}

                            @unless ($service->active)
                                <span class="ms-1 inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">Hidden</span>
                            @endunless
                        </div>

                        @if ($service->description)
                            <p class="mt-0.5 text-sm text-gray-500">{{ $service->description }}</p>
                        @endif

                        <div class="mt-1 flex flex-wrap items-baseline gap-x-4 text-sm text-gray-500">
                            <span class="font-medium tabular-nums text-gray-900">{{ $service->duration_minutes }} min</span>
                            <span class="font-medium tabular-nums text-gray-900">£{{ number_format((float) $service->price, 2) }}</span>
                            <span>Booked <span class="font-medium tabular-nums text-gray-900">{{ (int) $service->appointments_count }}</span> times</span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="edit({{ $service->id }})" class="{{ $act }}">Edit</button>

                            <button type="button" wire:click="toggleActive({{ $service->id }})" class="{{ $act }}">
                                {{ $service->active ? 'Hide' : 'Show' }}
                            </button>

                            @if ((int) $service->appointments_count === 0)
                                <button type="button" wire:click="delete({{ $service->id }})"
                                        wire:confirm="Delete {{ $service->name }}?"
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
                            <th class="px-4 py-3 text-left font-medium">Service</th>
                            <th class="px-4 py-3 text-right font-medium">Duration</th>
                            <th class="px-4 py-3 text-right font-medium">Price</th>
                            <th class="px-4 py-3 text-right font-medium">Booked</th>
                            <th class="px-4 py-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($services as $service)
                            <tr wire:key="service-row-{{ $service->id }}"
                                class="hover:bg-gray-50 {{ $service->active ? '' : 'opacity-60' }}">

                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">
                                        {{ $service->name }}

                                        @unless ($service->active)
                                            <span class="ms-1 inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">
                                                Hidden
                                            </span>
                                        @endunless
                                    </div>

                                    @if ($service->description)
                                        <div class="text-gray-500">{{ $service->description }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right text-gray-700 tabular-nums whitespace-nowrap">
                                    {{ $service->duration_minutes }} min
                                </td>

                                <td class="px-4 py-3 text-right text-gray-700 tabular-nums whitespace-nowrap">
                                    £{{ number_format((float) $service->price, 2) }}
                                </td>

                                <td class="px-4 py-3 text-right text-gray-500 tabular-nums whitespace-nowrap">
                                    {{ (int) $service->appointments_count }}
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button type="button" wire:click="edit({{ $service->id }})"
                                            class="rounded font-medium text-mulberry-700 hover:text-mulberry-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">Edit</button>

                                    <button type="button" wire:click="toggleActive({{ $service->id }})"
                                            class="ms-3 rounded text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                                        {{ $service->active ? 'Hide' : 'Show' }}
                                    </button>

                                    {{-- Only offered when there is no history to lose. Cast because an
                                         aggregate column can arrive as a string depending on the driver,
                                         and a strict comparison would silently hide the button. --}}
                                    @if ((int) $service->appointments_count === 0)
                                        <button type="button" wire:click="delete({{ $service->id }})"
                                                wire:confirm="Delete {{ $service->name }}?"
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
        <x-form-modal :title="$editingId ? 'Edit service' : 'Add service'"
                      :submit-label="$editingId ? 'Save changes' : 'Add service'">

            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full"
                              placeholder="Ladies Cut &amp; Blow Dry" autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="description" value="Description" />
                <x-text-input wire:model="description" id="description" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="duration_minutes" value="Minutes" />
                    <x-text-input wire:model="duration_minutes" id="duration_minutes" type="number"
                                  min="5" max="600" step="5" class="mt-1 block w-full" />
                    <p class="mt-1 text-xs text-gray-500">Sets the end time when booking.</p>
                    <x-input-error :messages="$errors->get('duration_minutes')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="price" value="Price (£)" />
                    <x-text-input wire:model="price" id="price" type="number"
                                  min="0" step="0.01" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('price')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="sort_order" value="Order in the list" />
                    <x-text-input wire:model="sort_order" id="sort_order" type="number"
                                  min="0" max="999" class="mt-1 block w-full" />
                    <p class="mt-1 text-xs text-gray-500">Low numbers show first. Leave it at 0 if you don't mind.</p>
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                </div>
            </div>

            <label class="flex min-h-touch items-start gap-3 rounded-lg bg-gray-50 p-3">
                <input type="checkbox" wire:model="active"
                       class="mt-0.5 h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                <span class="text-sm text-gray-700">
                    Available to book
                    <span class="block text-xs text-gray-500">
                        Unticking hides it from new bookings but keeps it on past ones,
                        so your history and figures stay intact.
                    </span>
                </span>
            </label>
        </x-form-modal>
    @endif
</div>
