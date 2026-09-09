@props(['disabled' => false])

{{--
    The dropdown twin of <x-text-input>, and it exists for the same two reasons:
    every select in the app should be a 44px thumb target, and its text must be at
    least 16px on a phone or iOS Safari zooms the page the moment it opens. Six
    selects were carrying six hand-written copies of the same class string, which is
    exactly how the "Staff" dropdown ends up a different height from "Service".

    Options go in the slot:
        <x-select-input wire:model="status" id="status" class="mt-1 block w-full">
            <option value="">Unassigned</option>
        </x-select-input>
--}}
<select @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-touch border-gray-300 focus:border-mulberry-600 focus:ring-mulberry-600 rounded-lg shadow-sm text-base sm:text-sm']) }}>
    {{ $slot }}
</select>
