{{--
    Confirmation banner. Requires the component's root element to carry:

        x-data="{ toast: null }"
        x-on:toast.window="toast = $event.detail.message; setTimeout(() => toast = null, 2500)"

    and the component to raise messages via the TenantScreen::toast() helper.
--}}

<div x-show="toast" x-transition style="display: none" {{ $attributes->merge(['class' => 'px-4 sm:px-0']) }}>
    <div class="rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800" x-text="toast"></div>
</div>
