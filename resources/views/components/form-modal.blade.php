{{--
    Modal shell for the add/edit forms.

    Deliberately NOT built on Breeze's <x-modal>: that keeps `show` inside Alpine
    (x-data="{ show: ... }"), and Livewire's DOM morphing never re-runs x-data — so
    binding it to a Livewire property works on the first render only, and Alpine
    closing the modal never tells the server. Here the caller wraps this in
    @if ($showForm), so the server state is the single source of truth and Alpine
    inside a freshly inserted node initialises correctly every time.

    Expects the calling component to have a `showForm` property and a `save()` method.

    ─── Shape ───────────────────────────────────────────────────────────────────
    Header, scrolling body, pinned footer. The booking form is taller than a phone
    — customer block, two selects, a four-up date/time/duration/price row, the
    conflict panel, status and notes — and it used to scroll as one piece, which put
    "Book it" somewhere below the bottom of the screen with nothing to suggest it
    existed. Only the middle section scrolls now, so the submit button is always
    on screen.

    On a phone it rises from the bottom edge like a sheet; on a desktop it centres.
--}}

@props([
    'title',
    'submitLabel' => 'Save',
    'maxWidth' => 'sm:max-w-lg',
])

<div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
     x-data="{
         init() {
             // Stop the page behind from scrolling under the sheet — on a phone,
             // dragging the modal used to drag the diary with it.
             document.body.classList.add('overflow-hidden');
             this.$nextTick(() => this.$el.querySelector('[autofocus]')?.focus());
         },
         destroy() {
             document.body.classList.remove('overflow-hidden');
         },
         /*
          * Put the on-screen keyboard away.
          *
          * A focused input that simply disappears — because the sheet closed, or
          * because a Livewire re-render replaced that branch of the form — does not
          * reliably retract the keyboard on iOS. It stays up over the diary with
          * nothing left to type into, and the only way out is the tiny Done key.
          * Blurring first makes it deterministic instead of leaving it to the browser.
          *
          * Deliberately NOT wired to scroll: half the point of a scrolling sheet is
          * reading the rest of the form while still typing, and killing the keyboard
          * on a stray thumb-drag would be worse than the problem.
          */
         dropKeyboard() {
             document.activeElement?.blur();
         },
     }"
     x-on:keydown.escape.window="dropKeyboard(); $wire.set('showForm', false)"
     role="dialog"
     aria-modal="true"
     aria-labelledby="form-modal-title">

    {{-- No dismiss-on-click. A stray edge tap discarding a half-typed booking is a
         worse outcome than one extra tap on Cancel, and thumbs hit edges. --}}
    <div class="fixed inset-0 bg-gray-900/50" aria-hidden="true"></div>

    {{-- x-on:submit runs alongside wire:submit, not instead of it. Safe to blur
         here: a deferred wire:model captures its value on `input`, so nothing
         typed is lost — only the keyboard goes. --}}
    <form wire:submit="save" x-on:submit="dropKeyboard()"
          class="relative flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-h-[85vh] sm:rounded-lg {{ $maxWidth }}">

        <div class="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 px-5 py-4">
            <h2 id="form-modal-title" class="text-lg font-semibold text-gray-900">{{ $title }}</h2>

            <button type="button" wire:click="$set('showForm', false)" x-on:click="dropKeyboard()"
                    class="-me-2 inline-flex min-h-touch min-w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600"
                    aria-label="Close without saving">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                </svg>
            </button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
            {{ $slot }}
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <x-secondary-button type="button" wire:click="$set('showForm', false)" x-on:click="dropKeyboard()">Cancel</x-secondary-button>
            <x-primary-button>{{ $submitLabel }}</x-primary-button>
        </div>
    </form>
</div>
