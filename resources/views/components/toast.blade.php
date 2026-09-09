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

<div x-show="toast" x-transition style="display: none" {{ $attributes->merge(['class' => 'px-4 sm:px-0']) }} aria-hidden="true">
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800" x-text="toast"></div>
</div>
