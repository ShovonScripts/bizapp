@props([
    'title' => null,
    'subtitle' => null,
    'wide' => true,
])

{{--
    The page frame — every authenticated screen opens and closes the same way.

    It owns two things the pages used to each invent: the max-width container, and
    the header row (title + one-line subtitle + right-aligned actions). Before this,
    the same "page title" job was written five different ways (text-xl semibold,
    text-2xl extrabold slate, text-lg medium …). One component, one heading scale.

    Pass `:wide="false"` for a narrower reading page (settings, legal); the default
    matches the list/diary screens at max-w-7xl.

    Actions go in the `actions` slot so they line up on the right:

        <x-page title="Services" subtitle="What you offer">
            <x-slot:actions>
                <x-button wire:click="create">Add service</x-button>
            </x-slot:actions>
            ... body ...
        </x-page>
--}}

<div {{ $attributes->merge(['class' => 'mx-auto w-full space-y-4 px-4 py-8 sm:px-6 lg:px-8 '.($wide ? 'max-w-7xl' : 'max-w-5xl')]) }}>
    @if ($title || trim($actions ?? '') !== '')
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                @if ($title)
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ $title }}</h1>
                @endif
                @if ($subtitle)
                    <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>

            @if (trim($actions ?? '') !== '')
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    {{ $actions }}
                </div>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>
