<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Book an Appointment' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|plus-jakarta-sans:500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans text-gray-900 antialiased bg-gray-50 selection:bg-mulberry-500 selection:text-white">
        <!-- Global Loading Bar -->
        <div wire:loading.delay class="fixed inset-x-0 top-0 z-[100] h-1 overflow-hidden bg-mulberry-100">
            <div class="h-full w-1/3 bg-mulberry-600 loading-bar"></div>
        </div>

        <main id="content" class="min-h-screen py-6 sm:py-12 px-4 sm:px-6">
            {{ $slot }}
        </main>

        <!-- Cookie consent -->
        <div id="cookie-consent" class="fixed inset-x-0 bottom-0 z-50 border-t border-gray-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80">
            <div class="mx-auto max-w-5xl px-4 py-3 sm:px-6 sm:py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-gray-700">
                        We use essential cookies to run bookings and reminders. Nothing is sold to third parties.
                        <a href="{{ route('privacy') }}" class="underline">Read our privacy policy</a>.
                    </p>
                    <button type="button" onclick="localStorage.setItem('cookie-consent', '1'); document.getElementById('cookie-consent').remove();"
                            class="shrink-0 rounded-md bg-mulberry-700 px-4 py-2 text-sm font-medium text-white hover:bg-mulberry-600">
                        Got it
                    </button>
                </div>
            </div>
        </div>

        <script>
            if (localStorage.getItem('cookie-consent')) {
                document.getElementById('cookie-consent')?.remove();
            }
        </script>
    </body>
</html>
