<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Appointment reminders and follow-ups for salons, barbers and local service businesses.">

        <title>{{ config('app.name') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-gray-50 font-sans antialiased text-gray-900">

        <div class="min-h-full flex flex-col">

            {{-- ---------------------------------------------------------------
                 Header
            ---------------------------------------------------------------- --}}
            <header class="border-b border-gray-200 bg-white">
                <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">

                    <span class="flex items-center gap-2 font-semibold">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-mulberry-700 text-sm font-bold text-white">
                            {{ substr(config('app.name'), 0, 1) }}
                        </span>
                        {{ config('app.name') }}
                    </span>

                    <nav class="flex items-center gap-4 text-sm">
                        @auth
                            <a href="{{ route('dashboard') }}"
                               class="rounded-md bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
                                Go to dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="font-medium text-gray-600 hover:text-gray-900">
                                Log in
                            </a>

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="rounded-md bg-gray-900 px-4 py-2 font-medium text-white hover:bg-gray-700">
                                    Start free trial
                                </a>
                            @endif
                        @endauth
                    </nav>
                </div>
            </header>

            {{-- ---------------------------------------------------------------
                 Hero
            ---------------------------------------------------------------- --}}
            <main class="flex-1">
                <div class="mx-auto max-w-5xl px-6 py-16 sm:py-24">

                    <p class="text-sm font-medium uppercase tracking-wider text-mulberry-700">
                        For UK salons, barbers &amp; local service businesses
                    </p>

                    <h1 class="mt-3 max-w-2xl text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">
                        Stop losing money to no-shows.
                    </h1>

                    <p class="mt-5 max-w-xl text-lg leading-relaxed text-gray-600">
                        Keep your bookings, staff and customers in one place, and let the
                        reminders go out on their own — on WhatsApp, Telegram or email.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        @auth
                            <a href="{{ route('dashboard') }}"
                               class="rounded-md bg-mulberry-700 px-6 py-3 font-medium text-white shadow-sm hover:bg-mulberry-600">
                                Open your dashboard
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="rounded-md bg-mulberry-700 px-6 py-3 font-medium text-white shadow-sm hover:bg-mulberry-600">
                                    Start your 14-day free trial
                                </a>
                            @endif

                            <a href="{{ route('login') }}"
                               class="rounded-md bg-white px-6 py-3 font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50">
                                Log in
                            </a>
                        @endauth
                    </div>

                    <p class="mt-4 text-sm text-gray-500">No card needed to start.</p>

                    {{-- Three things it does, in the owner's language, not ours. --}}
                    <div class="mt-16 grid gap-6 sm:grid-cols-3">
                        @foreach ([
                            ['Your diary, not a paper one', 'See today and tomorrow at a glance, per staff member. Two people can never be booked into the same slot.'],
                            ['Reminders that just happen', 'The day before every appointment, your customer gets a message. Quiet hours respected.'],
                            ['Customers who come back', 'Spot who has not been in for a while, and send them a reason to book again.'],
                        ] as [$title, $body])
                            <div class="rounded-lg border border-gray-200 bg-white p-5">
                                <h2 class="font-semibold">{{ $title }}</h2>
                                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- ---------------------------------------------------------
                         Local development only.
                         Gated on APP_ENV=local so it can never appear on the live
                         site. The passwords are fixtures from DemoBusinessSeeder
                         and are committed to the repo already — nothing secret is
                         being exposed here, but do not relax this gate.
                    ---------------------------------------------------------- --}}
                    @env('local')
                        <div class="mt-16 rounded-lg border border-amber-300 bg-amber-50 p-5">
                            <h2 class="text-sm font-semibold text-amber-900">
                                Local development — demo logins
                            </h2>

                            <p class="mt-1 text-sm text-amber-800">
                                Seeded by <code class="rounded bg-amber-100 px-1">php artisan migrate:fresh --seed</code>.
                                Password for all three: <code class="rounded bg-amber-100 px-1">password</code>.
                                This panel is hidden unless <code class="rounded bg-amber-100 px-1">APP_ENV=local</code>.
                            </p>

                            <ul class="mt-3 space-y-1.5 text-sm text-amber-900">
                                <li>
                                    <code class="font-medium">owner@demosalon.test</code>
                                    — Demo Hair Studio owner. Start here: full demo diary, staff and customers.
                                </li>
                                <li>
                                    <code class="font-medium">owner@demogym.test</code>
                                    — a second business, so a tenant leak is visible. Nothing of the salon's should appear.
                                </li>
                                <li>
                                    <code class="font-medium">admin@example.com</code>
                                    — super-admin. Deliberately gets 403 on Customers, Services and Staff.
                                </li>
                            </ul>
                        </div>
                    @endenv
                </div>
            </main>

            <footer class="border-t border-gray-200 bg-white">
                <div class="mx-auto max-w-5xl px-6 py-6 text-sm text-gray-500">
                    &copy; {{ now()->year }} {{ config('app.name') }}. Built for UK small businesses.
                </div>
            </footer>
        </div>
    </body>
</html>
