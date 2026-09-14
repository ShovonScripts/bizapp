@props([
    'padded' => false,
])

{{--
    The one card recipe.

    Lists and tables use the default (flush + overflow-hidden) so rows can run edge
    to edge and clip to the rounded corner. A block of copy — a form, a settings
    section — passes padded for breathing room instead.

    rounded-card and shadow-card are the design tokens added in tailwind.config.js;
    they replace the four radii (lg/xl/2xl/3xl) and five shadows pages mixed at random.
--}}

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white shadow-card '.($padded ? 'p-5 sm:p-7' : 'overflow-hidden')]) }}>
    {{ $slot }}
</div>
