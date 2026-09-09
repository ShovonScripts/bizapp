@props(['disabled' => false])

{{--
    min-h-touch and text-base on the input itself: anything under 16px makes iOS
    Safari zoom the whole page when the field takes focus, which on the booking form
    throws the layout sideways mid-typing. sm:text-sm returns it to the compact size
    on a desktop, where the zoom behaviour does not exist.
--}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-touch border-gray-300 focus:border-mulberry-600 focus:ring-mulberry-600 rounded-lg shadow-sm text-base sm:text-sm']) }}>
