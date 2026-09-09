@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-mulberry-700 text-sm font-semibold leading-5 text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 rounded-sm transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-900 hover:border-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 rounded-sm transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
