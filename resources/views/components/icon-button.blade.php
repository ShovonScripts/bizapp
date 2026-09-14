@props([
    'label',
])

{{--
    Icon-only button: chevron toggles, drawer closes. `label` is mandatory
    because there is no visible text for a screen reader to read.
    The touch target is min-h-touch/min-w-touch even though the icon is 20px.
--}}

<button type="button" aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'inline-flex min-h-touch min-w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 transition-colors']) }}>
    {{ $slot }}
</button>
