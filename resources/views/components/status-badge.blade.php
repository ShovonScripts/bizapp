@props([
    'status',
])

{{--
    A status pill. The colour carries the meaning, so the map lives here once
    rather than being re-typed per screen:

        amber   = needs attention (pending)
        sky     = confirmed
        emerald = done (completed)
        red     = ended badly (cancelled / no-show)
        gray    = anything else

    These are deliberately far from the mulberry brand colour — see the palette
    note in tailwind.config.js — so a badge never reads as a button.

    The label is the slot, so the caller owns the wording ("Confirmed", "Done", …):

        <x-status-badge :status="$appointment->status">{{ $appointment->status_label }}</x-status-badge>
--}}

@php
    $tones = [
        'pending'   => 'bg-amber-50 text-amber-800 ring-amber-200',
        'confirmed' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'cancelled' => 'bg-red-50 text-red-800 ring-red-200',
        'no_show'   => 'bg-red-50 text-red-800 ring-red-200',
    ];
    $tone = $tones[$status] ?? 'bg-gray-100 text-gray-600 ring-gray-200';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset '.$tone]) }}>
    {{ $slot }}
</span>
