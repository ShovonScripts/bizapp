@props(['type' => 'submit'])

{{-- Shim: <x-danger-button> == <x-button variant="danger">.
     Red is reserved for this and the cancelled status badge — never decoration.
     The recipe lives in x-button. --}}
<x-button variant="danger" :type="$type" {{ $attributes->except('type') }}>{{ $slot }}</x-button>
