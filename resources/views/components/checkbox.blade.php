@props(['disabled' => false])

{{--
    Checkbox, one recipe. The accent colour was re-typed (and drifted — rose once,
    mulberry now) in a dozen places. Use with an id/for pair like any field:

        <x-checkbox wire:model="active" id="active" />
--}}

<input type="checkbox" @disabled($disabled) {{ $attributes->merge(['class' => 'h-5 w-5 rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600']) }}>
