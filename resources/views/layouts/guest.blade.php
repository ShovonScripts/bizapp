<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans text-gray-900 antialiased bg-[#f8f9fc] selection:bg-mulberry-500 selection:text-white">
        <div class="relative min-h-screen flex flex-col justify-center items-center px-4 py-12 overflow-hidden">
            {{-- Ambient Liquid Glass Glow Orbs --}}
            <div class="pointer-events-none absolute -top-40 left-1/2 -translate-x-1/2 h-[450px] w-[750px] rounded-full bg-gradient-to-tr from-mulberry-300/25 via-pink-200/20 to-slate-200/30 blur-[130px]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-40 right-1/4 h-[400px] w-[400px] rounded-full bg-gradient-to-bl from-rose-200/20 via-mulberry-100/25 to-transparent blur-[120px]" aria-hidden="true"></div>

            {{-- Logo --}}
            <div class="relative z-10 mb-8 text-center">
                <a href="/" class="group inline-flex items-center gap-3" wire:navigate>
                    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-white via-gray-100 to-gray-200 text-mulberry-800 shadow-[0_4px_12px_rgba(0,0,0,0.08),inset_0_1px_1px_rgba(255,255,255,0.9)] transition-transform duration-300 group-hover:scale-105">
                        <span class="text-xl font-bold">{{ substr(config('app.name'), 0, 1) }}</span>
                    </div>
                    <span class="text-2xl font-bold tracking-tight text-gray-900">
                        {{ config('app.name') }}
                    </span>
                </a>
            </div>

            {{-- Liquid Glass Form Container --}}
            <div class="relative z-10 w-full sm:max-w-md">
                <div class="liquid-glass-border rounded-[32px] p-[2px] shadow-2xl">
                    <div class="liquid-glass-content rounded-[30px] p-6 sm:p-9 backdrop-blur-2xl">
                        {{ $slot }}
                    </div>
                </div>
            </div>

            {{-- Back to Home Link --}}
            <div class="relative z-10 mt-6 text-center">
                <a href="/" class="text-xs font-semibold text-gray-500 hover:text-black transition-colors" wire:navigate>
                    &larr; Return to homepage
                </a>
            </div>
        </div>
    </body>
</html>
