@props(['disabled' => false])

{{--
    Multi-line field, matched to x-text-input's border/focus treatment. The
    min-h-touch and 16px-floor rules from x-text-input apply here too.
--}}

<textarea @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-mulberry-600 focus:ring-mulberry-600 rounded-lg shadow-sm text-base sm:text-sm']) }}>{{ $slot }}</textarea>
