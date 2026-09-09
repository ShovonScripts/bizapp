{{--
    Modal shell for the add/edit forms.

    Deliberately NOT built on Breeze's <x-modal>: that keeps `show` inside Alpine
    (x-data="{ show: ... }"), and Livewire's DOM morphing never re-runs x-data — so
    binding it to a Livewire property works on the first render only, and Alpine
    closing the modal never tells the server. Here the caller wraps this in
    @if ($showForm), so the server state is the single source of truth and Alpine
    inside a freshly inserted node initialises correctly every time.

    Expects the calling component to have a `showForm` property and a `save()` method.
--}}

@props([
    'title',
    'submitLabel' => 'Save',
    'maxWidth' => 'sm:max-w-lg',
])

<div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
     x-on:keydown.escape.window="$wire.set('showForm', false)">

    <div class="fixed inset-0 bg-gray-500/75" wire:click="$set('showForm', false)"></div>

    <form wire:submit="save"
          class="relative mx-auto mb-6 w-full {{ $maxWidth }} space-y-4 rounded-lg bg-white p-6 shadow-xl">

        <h2 class="text-lg font-medium text-gray-900">{{ $title }}</h2>

        {{ $slot }}

        <div class="flex justify-end gap-3 pt-2">
            <x-secondary-button type="button" wire:click="$set('showForm', false)">Cancel</x-secondary-button>
            <x-primary-button>{{ $submitLabel }}</x-primary-button>
        </div>
    </form>
</div>
