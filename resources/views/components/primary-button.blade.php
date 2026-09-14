@props(['type' => 'submit'])

{{-- Shim: <x-primary-button> == <x-button variant="primary">.
     Kept so Breeze-era call sites don't churn; the recipe lives in x-button.
     type="submit" is the default, matching the original component. --}}
<x-button variant="primary" :type="$type" {{ $attributes->except('type') }}>{{ $slot }}</x-button>
