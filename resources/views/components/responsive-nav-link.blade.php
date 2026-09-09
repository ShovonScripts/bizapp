@props(['active'])

@php
// min-h-touch because this is the phone menu — every row here is a thumb target.
$classes = ($active ?? false)
            ? 'flex items-center w-full min-h-touch ps-3 pe-4 py-2 border-l-4 border-mulberry-700 text-start text-base font-semibold text-mulberry-800 bg-mulberry-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600 transition duration-150 ease-in-out'
            : 'flex items-center w-full min-h-touch ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
