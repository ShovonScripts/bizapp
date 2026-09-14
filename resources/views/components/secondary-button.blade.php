@props(['type' => 'button'])

{{-- Shim: <x-secondary-button> == <x-button variant="secondary">.
     The recipe lives in x-button. --}}
<x-button variant="secondary" :type="$type" {{ $attributes->except('type') }}>{{ $slot }}</x-button>
