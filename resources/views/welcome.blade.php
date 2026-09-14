<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-seo-head page="home" />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-canvas font-sans antialiased text-gray-900 selection:bg-mulberry-500 selection:text-white">

        <div class="relative min-h-full flex flex-col overflow-hidden">
            {{-- Ambient Liquid Glass Background Glow Orbs --}}
            <div class="pointer-events-none absolute -top-40 left-1/2 -translate-x-1/2 h-[500px] w-[800px] rounded-full bg-gradient-to-tr from-mulberry-300/25 via-mulberry-200/20 to-gray-200/40 blur-[130px]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute top-[600px] -right-40 h-[450px] w-[450px] rounded-full bg-gradient-to-bl from-mulberry-200/20 via-mulberry-100/30 to-transparent blur-[120px]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute top-[1400px] -left-40 h-[500px] w-[500px] rounded-full bg-gradient-to-tr from-mulberry-100/25 via-mulberry-100/20 to-transparent blur-[130px]" aria-hidden="true"></div>

            {{-- ---------------------------------------------------------------
                 Floating Liquid Glass Header Navbar
            ---------------------------------------------------------------- --}}
            <header class="sticky top-4 z-40 px-4 sm:px-6">
                <div class="mx-auto flex max-w-5xl items-center justify-between liquid-glass-navbar rounded-2xl px-5 py-3 shadow-lg transition-all duration-300">

                    <a href="/" class="group flex items-center gap-2.5">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-white via-gray-100 to-gray-200 text-mulberry-800 shadow-[0_2px_8px_rgba(0,0,0,0.08),inset_0_1px_1px_rgba(255,255,255,0.9)] transition-transform duration-300 group-hover:scale-105">
                            <span class="text-base font-bold">{{ substr(config('app.name'), 0, 1) }}</span>
                        </span>
                        <span class="text-lg font-bold tracking-tight text-gray-900">
                            {{ config('app.name') }}
                        </span>
                    </a>

                    <nav class="hidden md:flex items-center gap-6 text-sm font-medium text-gray-600">
                        <a href="#how-it-works" class="hover:text-black transition-colors">How it works</a>
                        <a href="#features" class="hover:text-black transition-colors">Features</a>
                        <a href="#pricing" class="hover:text-black transition-colors">Pricing</a>
                    </nav>

                    <div class="flex items-center gap-3 text-sm">
                        @auth
                            <a href="{{ route('dashboard') }}"
                               class="liquid-glass-btn-primary rounded-full px-5 py-2 text-sm font-semibold text-white shadow-md">
                                Dashboard &rarr;
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="font-medium text-gray-700 hover:text-black transition-colors px-2 py-1">
                                Log in
                            </a>

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}"
                                   class="liquid-glass-btn-primary rounded-full px-4 sm:px-5 py-2 text-sm font-semibold text-white shadow-md">
                                    Start free trial
                                </a>
                            @endif
                        @endauth
                    </div>
                </div>
            </header>

            {{-- ---------------------------------------------------------------
                 Hero Section
            ---------------------------------------------------------------- --}}
            <main class="flex-1 relative z-10">
                <div class="mx-auto max-w-5xl px-6 pt-12 pb-16 sm:pt-20 sm:pb-24">

                    <div class="text-center max-w-3xl mx-auto">
                        {{-- Category Pill Badge --}}
                        <div class="inline-flex items-center gap-2 rounded-full liquid-glass-badge px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-mulberry-800 shadow-sm animate-fade-in-up">
                            <span class="h-2 w-2 rounded-full bg-mulberry-600 animate-pulse-dot"></span>
                            For UK salons, barbers &amp; local service businesses
                        </div>

                        {{-- Main Headline --}}
                        <h1 class="mt-6 text-4xl font-extrabold leading-[1.12] tracking-tight text-gray-900 sm:text-6xl animate-fade-in-up">
                            Bookings that <span class="bg-gradient-to-r from-mulberry-800 via-mulberry-600 to-mulberry-500 bg-clip-text text-transparent">run themselves</span>.
                        </h1>

                        {{-- Subtitle --}}
                        <p class="mt-6 text-lg sm:text-xl leading-relaxed text-gray-600 animate-fade-in-up">
                            A shared diary, one-tap booking and automatic reminders — so your
                            customers show up on time and your team never double-books.
                        </p>

                        {{-- Action CTA Row --}}
                        <div class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:gap-4">
                            @auth
                                <a href="{{ route('dashboard') }}"
                                   class="liquid-glass-btn-primary rounded-full px-8 py-3.5 font-semibold text-white shadow-lg text-base">
                                    Open your dashboard
                                </a>
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}"
                                       class="liquid-glass-btn-primary rounded-full px-8 py-3.5 font-semibold text-white shadow-lg text-base">
                                        Start your 14-day free trial
                                    </a>
                                @endif

                                <a href="{{ route('login') }}"
                                   class="liquid-glass-btn rounded-full border border-white/80 px-7 py-3.5 font-semibold text-gray-800 shadow text-base">
                                    Log in
                                </a>
                            @endauth
                        </div>

                        <p class="mt-3.5 text-xs sm:text-sm text-gray-500">
                            No card needed to start &bull; Set up in 10 minutes &bull; Instant confirmation
                        </p>
                    </div>

                    {{-- ---------------------------------------------------------
                         Liquid Glass Live UI Preview Showcase Card
                    ---------------------------------------------------------- --}}
                    <div class="mt-14 sm:mt-18">
                        <div class="liquid-glass-border rounded-glass sm:rounded-3xl p-[2.5px] shadow-2xl transition-transform duration-500 hover:scale-[1.01]">
                            <div class="liquid-glass-content rounded-glass-inner p-5 sm:p-8 backdrop-blur-2xl">
                                
                                {{-- Preview Top Bar --}}
                                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-black/10 pb-5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex gap-1.5">
                                            <span class="h-3 w-3 rounded-full bg-red-400/80"></span>
                                            <span class="h-3 w-3 rounded-full bg-amber-400/80"></span>
                                            <span class="h-3 w-3 rounded-full bg-emerald-400/80"></span>
                                        </div>
                                        <div class="h-4 w-[1px] bg-black/10"></div>
                                        <span class="text-xs font-semibold text-gray-800 tracking-wide">
                                            Today's Diary &bull; {{ date('l, j F') }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 text-xs font-medium text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            Reminders Active
                                        </span>
                                        <span class="text-xs font-semibold text-gray-700 bg-white/60 border border-white/80 rounded-full px-3 py-1 shadow-sm">
                                            £485 booked today
                                        </span>
                                    </div>
                                </div>

                                {{-- Preview Diary Mock Grid --}}
                                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                                    {{-- Slot 1 --}}
                                    <div class="liquid-glass-card rounded-2xl p-4 shadow-sm">
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <span class="font-semibold text-mulberry-800">10:00 — 10:45</span>
                                            <span class="rounded bg-emerald-100 text-emerald-800 px-1.5 py-0.5 text-[10px] font-medium">Confirmed</span>
                                        </div>
                                        <h4 class="mt-2 text-sm font-bold text-gray-900">James Walker</h4>
                                        <p class="text-xs text-gray-600">Fade &amp; Beard Shapeup</p>
                                        <div class="mt-3 flex items-center justify-between border-t border-black/5 pt-2 text-[11px] text-gray-500">
                                            <span>Staff: Marcus</span>
                                            <span class="inline-flex items-center gap-1 text-emerald-600 font-medium">
                                                <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                Reminder Sent
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Slot 2 --}}
                                    <div class="liquid-glass-card-accent rounded-2xl p-4 shadow-sm ring-1 ring-mulberry-400/40">
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <span class="font-semibold text-mulberry-800">11:15 — 12:30</span>
                                            <span class="rounded bg-mulberry-100 text-mulberry-800 px-1.5 py-0.5 text-[10px] font-medium">Deposit Paid</span>
                                        </div>
                                        <h4 class="mt-2 text-sm font-bold text-gray-900">Emma Thornton</h4>
                                        <p class="text-xs text-gray-600">Full Balayage &amp; Blow Dry</p>
                                        <div class="mt-3 flex items-center justify-between border-t border-black/5 pt-2 text-[11px] text-gray-500">
                                            <span>Staff: Chloe</span>
                                            <span class="inline-flex items-center gap-1 text-emerald-600 font-medium">
                                                <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                WhatsApp Sent
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Slot 3 --}}
                                    <div class="liquid-glass-card rounded-2xl p-4 shadow-sm">
                                        <div class="flex items-center justify-between text-xs text-gray-500">
                                            <span class="font-semibold text-mulberry-800">14:00 — 15:00</span>
                                            <span class="rounded bg-sky-100 text-sky-800 px-1.5 py-0.5 text-[10px] font-medium">Online Booking</span>
                                        </div>
                                        <h4 class="mt-2 text-sm font-bold text-gray-900">David Ross</h4>
                                        <p class="text-xs text-gray-600">Deep Tissue Therapy</p>
                                        <div class="mt-3 flex items-center justify-between border-t border-black/5 pt-2 text-[11px] text-gray-500">
                                            <span>Staff: Sophie</span>
                                            <span class="inline-flex items-center gap-1 text-emerald-600 font-medium">
                                                <svg class="h-3 w-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                Telegram Confirmed
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Fast Highlights Row --}}
                                <div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                                    <div class="rounded-xl bg-white/40 border border-white/60 p-2.5">
                                        <div class="text-lg font-bold text-gray-900">0%</div>
                                        <div class="text-[11px] text-gray-600">No-show Rate</div>
                                    </div>
                                    <div class="rounded-xl bg-white/40 border border-white/60 p-2.5">
                                        <div class="text-lg font-bold text-gray-900">24h</div>
                                        <div class="text-[11px] text-gray-600">Prior Reminders</div>
                                    </div>
                                    <div class="rounded-xl bg-white/40 border border-white/60 p-2.5">
                                        <div class="text-lg font-bold text-gray-900">1 Tap</div>
                                        <div class="text-[11px] text-gray-600">Client Booking</div>
                                    </div>
                                    <div class="rounded-xl bg-white/40 border border-white/60 p-2.5">
                                        <div class="text-lg font-bold text-gray-900">100%</div>
                                        <div class="text-[11px] text-gray-600">UK GDPR Compliant</div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         How it works — three steps in Liquid Glass cards
                    ---------------------------------------------------------- --}}
                    <div id="how-it-works" class="mt-24 sm:mt-32">
                        <div class="text-center max-w-xl mx-auto">
                            <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">
                                Simple 3-step setup
                            </span>
                            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl text-gray-900">
                                How it works
                            </h2>
                            <p class="mt-3 text-sm text-gray-600">
                                No technical knowledge needed. You are up and running in minutes.
                            </p>
                        </div>

                        <div class="mt-12 grid gap-6 sm:grid-cols-3">
                            @foreach ([
                                ['1', 'Set up once', 'Add your services, staff and opening hours. Takes about 10 minutes with our guided wizard.'],
                                ['2', 'Share your link', 'Customers book themselves via a mobile-optimized page without downloading any apps.'],
                                ['3', 'Reminders happen automatically', '24 hours before each appointment, customer gets a friendly message on WhatsApp, Telegram or email.'],
                            ] as [$step, $title, $body])
                                <div class="liquid-glass-card liquid-glass-card-hover rounded-3xl p-6 sm:p-8 flex flex-col justify-between">
                                    <div>
                                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-white via-mulberry-50 to-mulberry-100 text-base font-bold text-mulberry-800 shadow-[0_2px_8px_rgba(125,47,81,0.15),inset_0_1px_1px_rgba(255,255,255,0.9)]">
                                            {{ $step }}
                                        </span>
                                        <h3 class="mt-5 text-lg font-bold text-gray-900">{{ $title }}</h3>
                                        <p class="mt-2.5 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         Features / What owners use it for
                    ---------------------------------------------------------- --}}
                    <div id="features" class="mt-24 sm:mt-32">
                        <div class="text-center max-w-xl mx-auto">
                            <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">
                                Built for real businesses
                            </span>
                            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl text-gray-900">
                                Everything you need to fill your chairs
                            </h2>
                            <p class="mt-3 text-sm text-gray-600">
                                Say goodbye to paper diaries, missed calls and costly no-shows.
                            </p>
                        </div>

                        <div class="mt-12 grid gap-6 sm:grid-cols-3">
                            <div class="liquid-glass-card liquid-glass-card-hover rounded-3xl p-6 sm:p-8">
                                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-white/80 text-mulberry-800 shadow-sm">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                </div>
                                <h3 class="mt-5 text-lg font-bold text-gray-900">Your diary, not a paper one</h3>
                                <p class="mt-2.5 text-sm leading-relaxed text-gray-600">
                                    See today and tomorrow at a glance, per staff member. Double-bookings are mathematically impossible.
                                </p>
                            </div>

                            <div class="liquid-glass-card-accent liquid-glass-card-hover rounded-3xl p-6 sm:p-8 ring-1 ring-mulberry-300/40">
                                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-white text-mulberry-800 shadow-sm">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                                    </svg>
                                </div>
                                <h3 class="mt-5 text-lg font-bold text-gray-900">Reminders that just happen</h3>
                                <p class="mt-2.5 text-sm leading-relaxed text-gray-600">
                                    The day before every appointment, your customer gets a message. Quiet hours are automatically respected.
                                </p>
                            </div>

                            <div class="liquid-glass-card liquid-glass-card-hover rounded-3xl p-6 sm:p-8">
                                <div class="grid h-12 w-12 place-items-center rounded-2xl bg-white/80 text-mulberry-800 shadow-sm">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
                                    </svg>
                                </div>
                                <h3 class="mt-5 text-lg font-bold text-gray-900">Customers who come back</h3>
                                <p class="mt-2.5 text-sm leading-relaxed text-gray-600">
                                    Spot customers who haven't visited in 6+ weeks and trigger automatic win-back promotions to refill empty slots.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         Social Proof & Testimonials in Liquid Glass Cards
                    ---------------------------------------------------------- --}}
                    <div class="mt-24 sm:mt-32">
                        <div class="text-center max-w-xl mx-auto">
                            <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">
                                Trusted in the UK
                            </span>
                            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl text-gray-900">
                                Loved by salons, barbers &amp; therapists
                            </h2>
                        </div>

                        <div class="mt-12 grid gap-6 sm:grid-cols-3">
                            @foreach ([
                                ['Sarah M.', 'Hair salon owner, London', 'Cut no-shows from 15% to under 3% in our first month. The Telegram reminders are a complete game changer.'],
                                ['James K.', 'Barbershop owner, Manchester', 'Finally I can see who my top clients are and easily message the ones who drifted away.'],
                                ['Priya R.', 'Beauty therapist, Birmingham', 'My clients rave about the clean booking screen and automated reminders. Saves me hours of admin every week.'],
                            ] as [$name, $role, $quote])
                                <div class="liquid-glass-card liquid-glass-card-hover rounded-3xl p-6 sm:p-8 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center gap-1 text-amber-400">
                                            @for ($i = 0; $i < 5; $i++)
                                                <svg class="h-4 w-4 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                            @endfor
                                        </div>
                                        <p class="mt-4 text-sm leading-relaxed text-gray-700 italic">"{{ $quote }}"</p>
                                    </div>
                                    <div class="mt-6 flex items-center gap-3 border-t border-black/5 pt-4">
                                        <div class="h-9 w-9 rounded-full bg-mulberry-700 text-white font-semibold text-xs grid place-items-center">
                                            {{ substr($name, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-gray-900">{{ $name }}</p>
                                            <p class="text-xs text-gray-500">{{ $role }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         Pricing Section with Stripe Credential & Trust Box
                    ---------------------------------------------------------- --}}
                    <div id="pricing" class="mt-24 sm:mt-32 scroll-mt-24">
                        <div class="text-center max-w-2xl mx-auto">
                            <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">
                                Predictable Plans
                            </span>
                            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl text-gray-900">
                                Simple, transparent pricing for growing teams
                            </h2>
                            <p class="mt-4 text-base text-gray-600">
                                Start with a 14-day full feature trial. No credit card required. Cancel or change plans anytime.
                            </p>
                        </div>

                        {{-- 3 Liquid Glass Pricing Tier Cards --}}
                        <div class="mt-12 grid gap-8 lg:grid-cols-3 items-stretch">
                            @foreach (\App\Support\SiteSettings::pricingPlans() as $planItem)
                                @php
                                    $plan = $planItem['name'];
                                    $price = $planItem['price'];
                                    $period = $planItem['period'];
                                    $desc = $planItem['description'];
                                    $popular = $planItem['popular'];
                                    $features = $planItem['features'];
                                @endphp
                                <div class="{{ $popular ? 'liquid-glass-card-accent ring-2 ring-mulberry-400 shadow-xl' : 'liquid-glass-card liquid-glass-card-hover shadow-md' }} rounded-3xl p-8 flex flex-col justify-between relative">
                                    @if ($popular)
                                        <div class="absolute -top-3.5 left-1/2 -translate-x-1/2">
                                            <span class="liquid-glass-btn-primary rounded-full px-3.5 py-1 text-[11px] font-bold uppercase tracking-wider text-white shadow-md">
                                                Most Popular
                                            </span>
                                        </div>
                                    @endif

                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">{{ $plan }}</h3>
                                        <p class="mt-1.5 text-sm text-gray-600">{{ $desc }}</p>
                                        <div class="mt-6 flex items-baseline gap-1">
                                            <span class="text-4xl font-extrabold tracking-tight text-gray-900">{{ $price }}</span>
                                            <span class="text-sm font-medium text-gray-500">/{{ $period }}</span>
                                        </div>

                                        <div class="my-6 h-[1px] w-full bg-black/10"></div>

                                        <ul class="space-y-3.5 text-sm text-gray-700">
                                            @foreach ($features as $feature)
                                                <li class="flex items-start gap-2.5">
                                                    <div class="grid h-5 w-5 shrink-0 place-items-center rounded-full {{ $popular ? 'bg-mulberry-100 text-mulberry-800' : 'bg-emerald-100 text-emerald-800' }}">
                                                        <svg class="h-3 w-3 stroke-[3]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                        </svg>
                                                    </div>
                                                    <span>{{ $feature }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <div class="mt-8">
                                        <a href="{{ route('register') }}" 
                                           class="{{ $popular ? 'liquid-glass-btn-primary text-white' : 'liquid-glass-btn text-gray-900 border border-white/80' }} block w-full rounded-full py-3 text-center text-sm font-semibold shadow transition-all">
                                            Start 14-day {{ $plan }} trial
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Stripe Credential & Trust Box --}}
                        <div class="mt-10 liquid-glass-border rounded-3xl p-[2px] shadow-lg">
                            <div class="liquid-glass-content rounded-glass-inner p-6 sm:p-8 backdrop-blur-xl">
                                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                                    <div class="flex items-start sm:items-center gap-4">
                                        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gray-900 text-white shadow-sm">
                                            <svg class="h-6 w-6 fill-current" viewBox="0 0 24 24">
                                                <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697.5 12.523.5 7.054.5 3.197 3.398 3.197 7.79c0 4.542 3.864 6.273 6.671 7.378 2.378.934 3.197 1.579 3.197 2.533 0 .991-.89 1.488-2.316 1.488-2.378 0-5.11-1.125-6.84-2.148l-.92 5.513c1.782.99 4.887 1.706 7.76 1.706 5.867 0 9.778-2.834 9.778-7.534 0-4.66-3.805-6.398-6.551-7.576z"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <h3 class="text-base font-bold text-gray-900">Secured with Stripe Payment Infrastructure</h3>
                                                <span class="rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-[11px] font-bold border border-emerald-200">
                                                    PCI-DSS Level 1
                                                </span>
                                            </div>
                                            <p class="text-xs text-gray-600 mt-1 max-w-xl leading-relaxed">
                                                All recurring subscriptions and customer booking deposits are processed by Stripe's bank-grade payment gateway with 256-bit SSL encryption. Zero card numbers touch our server.
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Payment Badges --}}
                                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                        <span class="rounded-xl border border-white/70 bg-white/60 px-3 py-1.5 text-xs font-bold text-gray-700 shadow-2xs">Visa</span>
                                        <span class="rounded-xl border border-white/70 bg-white/60 px-3 py-1.5 text-xs font-bold text-gray-700 shadow-2xs">Mastercard</span>
                                        <span class="rounded-xl border border-white/70 bg-white/60 px-3 py-1.5 text-xs font-bold text-gray-700 shadow-2xs">Amex</span>
                                        <span class="rounded-xl border border-white/70 bg-white/60 px-3 py-1.5 text-xs font-bold text-gray-700 shadow-2xs">Apple Pay</span>
                                        <span class="rounded-xl border border-white/70 bg-white/60 px-3 py-1.5 text-xs font-bold text-gray-700 shadow-2xs">Google Pay</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         Trust badges in Liquid Glass Pill Bar
                    ---------------------------------------------------------- --}}
                    <div class="mt-16 text-center">
                        <div class="liquid-glass-pill rounded-full py-3.5 px-6 sm:px-10 inline-flex flex-wrap items-center justify-center gap-6 sm:gap-10 text-xs font-semibold text-gray-700 shadow-sm">
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0112 2.25c5.385 0 9.75 4.365 9.75 9.75s-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12 6.865 2.25 12 2.25z"/></svg>
                                UK GDPR Compliant
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                                Secure 256-Bit SSL
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                14-Day Free Trial
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125v-9.75c0-.621.504-1.125 1.125-1.125h.375m1.5-1.5h3.375"/></svg>
                                No Card Required
                            </span>
                        </div>
                    </div>

                    {{-- ---------------------------------------------------------
                         Local development demo credentials callout
                    ---------------------------------------------------------- --}}
                    @env('local')
                        <div class="mt-20 liquid-glass-card border-amber-300/80 bg-gradient-to-br from-amber-50/70 to-orange-50/50 rounded-3xl p-6 sm:p-8">
                            <h2 class="text-base font-bold text-amber-900 flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-amber-500 animate-ping"></span>
                                Local Development Demo Logins
                            </h2>

                            <p class="mt-2 text-sm text-amber-800">
                                Seeded by <code class="rounded bg-amber-200/60 px-1.5 py-0.5 font-mono text-xs">php artisan migrate:fresh --seed</code>.
                                Password for all three: <code class="rounded bg-amber-200/60 px-1.5 py-0.5 font-mono text-xs">password</code>.
                                This callout is completely hidden in production.
                            </p>

                            <ul class="mt-4 space-y-2 text-sm text-amber-950">
                                <li class="flex items-center gap-2">
                                    <code class="rounded bg-white/70 px-2 py-0.5 font-mono text-xs font-semibold">owner@demosalon.test</code>
                                    <span>— Demo Hair Studio owner (full demo diary, staff, services and customers).</span>
                                </li>
                                <li class="flex items-center gap-2">
                                    <code class="rounded bg-white/70 px-2 py-0.5 font-mono text-xs font-semibold">owner@demogym.test</code>
                                    <span>— Second tenant (proves complete tenant isolation).</span>
                                </li>
                                <li class="flex items-center gap-2">
                                    <code class="rounded bg-white/70 px-2 py-0.5 font-mono text-xs font-semibold">admin@example.com</code>
                                    <span>— Super-admin account.</span>
                                </li>
                            </ul>
                        </div>
                    @endenv

                </div>
            </main>

            {{-- ---------------------------------------------------------------
                 Floating Liquid Glass Cookie Consent Dock
            ---------------------------------------------------------------- --}}
            <div id="cookie-consent" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-[92%] max-w-2xl">
                <div class="liquid-glass-navbar rounded-full px-5 py-3 shadow-2xl flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-700">
                    <p class="text-center sm:text-left">
                        We use essential cookies for your booking diary. Nothing is ever sold to third parties.
                        <a href="{{ route('privacy') }}" class="font-semibold underline hover:text-black">Privacy policy</a>.
                    </p>
                    <button type="button" onclick="localStorage.setItem('cookie-consent', '1'); document.getElementById('cookie-consent').remove();"
                            class="liquid-glass-btn-primary shrink-0 rounded-full px-5 py-1.5 text-xs font-semibold text-white shadow">
                        Got it
                    </button>
                </div>
            </div>

            <script>
                if (localStorage.getItem('cookie-consent')) {
                    document.getElementById('cookie-consent')?.remove();
                }
            </script>

            {{-- ---------------------------------------------------------------
                 Liquid Glass Footer
            ---------------------------------------------------------------- --}}
            <x-liquid-glass-footer class="mt-12 mb-6" />

        </div>
    </body>
</html>
