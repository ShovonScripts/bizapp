@props([
    'variant' => 'primary',
    'type' => 'button',
    'size' => 'md',
])

{{--
    Every button in the product. The variant carries the intent, never the decoration:

        primary    = the one action this screen wants (mulberry, solid)
        secondary  = the way out / a lesser action (white, bordered)
        danger     = destructive only (red)
        ghost      = quiet row action (text only, colours on hover)
        soft       = a filled chip action inside a list (e.g. diary row buttons)

    Before this there were 15+ hand-written "primary button" class lists — mulberry
    on one screen, rose on another, three radii, two shadows. Now there is this file.
    <x-primary-button>, <x-secondary-button> and <x-danger-button> stay as shims for
    the Breeze-era call sites; new code should use <x-button variant="…">.

    min-h-touch (44px) is the floor on everything tappable — the boss uses this
    one-handed between clients.
--}}

@php
    // Tailwind's px-3/px-4 conflict resolves by stylesheet order, not class
    // order — a caller passing class="px-3" would silently LOSE to the base.
    // So horizontal padding is a prop, not an override. 'bare' is for the
    // text-only variants, which should not pad at all.
    $padsizes = ['md' => 'px-4', 'sm' => 'px-3', 'bare' => 'px-1'];
    $pad = $padsizes[$size] ?? $padsizes['md'];

    // font-weight lives on the variants, not $base: Tailwind v3 resolves the
    // font-medium/font-semibold clash by stylesheet order, so a base weight
    // would silently beat any variant that wants a lighter one.
    $base = 'inline-flex items-center justify-center gap-x-2 min-h-touch rounded-lg '.$pad.' text-sm transition-all ease-in-out duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none';

    $variants = [
        'primary' => 'font-semibold bg-mulberry-700 text-white shadow-sm hover:bg-mulberry-800 hover:shadow-md active:bg-mulberry-900 active:scale-[0.98] focus-visible:ring-mulberry-600',
        'secondary' => 'font-semibold bg-white text-gray-700 border border-gray-300 shadow-sm hover:bg-gray-50 active:bg-gray-100 active:scale-[0.98] focus-visible:ring-mulberry-600',
        'danger' => 'font-semibold bg-red-600 text-white shadow-sm hover:bg-red-700 active:bg-red-800 active:scale-[0.98] focus-visible:ring-red-600',
        'ghost' => 'font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-mulberry-600',
        'soft' => 'font-semibold bg-mulberry-50 text-mulberry-800 hover:bg-mulberry-100 focus-visible:ring-mulberry-600',
        /* Text-only table action. No box, no background — a quiet link inside a
           dense row. Use size="bare"; underline-danger turns red on hover for "Delete". */
        'link' => 'font-medium text-mulberry-700 hover:text-mulberry-900 focus-visible:ring-mulberry-600',
        'underline' => 'font-medium text-gray-600 hover:text-gray-900 focus-visible:ring-mulberry-600',
        'underline-danger' => 'font-medium text-gray-500 hover:text-red-700 focus-visible:ring-red-600',
        /* Row actions that end badly — delete/remove. Outlined red, not filled:
           inside a busy list a solid red button competes with status badges. */
        'outline-danger' => 'font-semibold bg-white text-red-700 border border-red-200 hover:bg-red-50 active:bg-red-100 focus-visible:ring-red-600',
    ];

    // Destructive ghost — "Delete" inside a row. Red only on hover so the row stays calm.
    if ($variant === 'ghost-danger') {
        $variant = 'ghost';
        $extra = 'hover:bg-red-50 hover:text-red-700 focus-visible:ring-red-600';
    } else {
        $extra = '';
    }
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => $base.' '.($variants[$variant] ?? $variants['primary']).($extra ? ' '.$extra : '')]) }}>
    {{ $slot }}
</button>
