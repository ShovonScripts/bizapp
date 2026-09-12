{{--
    Confirmation banner. Requires the component's root element to carry:

        x-data="{ toast: null }"
        x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 2500)"

    and the component to raise messages via the TenantScreen::toast() helper.

    ─── Why there are two of these ───────────────────────────────────────────────
    The visible banner is toggled with x-show, so it is display:none until a message
    arrives — and a live region that is only added to the accessibility tree at the
    moment its text appears is unreliably announced. The sr-only node below is
    therefore always present and always announcing; it is absolutely positioned, so
    it takes no space and does not disturb a parent's space-y rhythm.
--}}

<div class="sr-only" role="status" aria-live="polite" x-text="toast"></div>

<div x-show="toast" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-x-0" x-transition:leave-end="opacity-0 translate-x-4" style="display: none" {{ $attributes->merge(['class' => 'px-4 sm:px-0']) }} aria-hidden="true">
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-800 shadow-sm flex items-center gap-2">
        <svg class="h-4 w-4 text-green-600 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
        </svg>
        <span x-text="toast"></span>
    </div>
</div>
