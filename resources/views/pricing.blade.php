<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-seo-head page="pricing" />
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-canvas font-sans antialiased text-gray-900 selection:bg-mulberry-500 selection:text-white">
        <div class="relative min-h-full flex flex-col overflow-hidden">
            {{-- Ambient Liquid Glass Background Glow Orbs --}}
            <div class="pointer-events-none absolute -top-32 left-1/2 -translate-x-1/2 h-[450px] w-[750px] rounded-full bg-gradient-to-tr from-mulberry-300/20 via-mulberry-200/20 to-gray-200/30 blur-[130px]" aria-hidden="true"></div>
            <div class="pointer-events-none absolute top-[700px] -right-32 h-[400px] w-[400px] rounded-full bg-gradient-to-bl from-mulberry-200/20 via-mulberry-100/20 to-transparent blur-[120px]" aria-hidden="true"></div>

            {{-- Floating Liquid Glass Navbar --}}
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
                        <a href="/#how-it-works" class="hover:text-black transition-colors">How it works</a>
                        <a href="/#features" class="hover:text-black transition-colors">Features</a>
                        <a href="{{ route('pricing') }}" class="text-mulberry-800 font-semibold">Pricing</a>
                    </nav>

                    <div class="flex items-center gap-3 text-sm">
                        <a href="{{ route('login') }}" class="font-medium text-gray-700 hover:text-black transition-colors px-2 py-1">
                            Log in
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="liquid-glass-btn-primary rounded-full px-4 sm:px-5 py-2 text-sm font-semibold text-white shadow-md">
                                Start free trial
                            </a>
                        @endif
                    </div>
                </div>
            </header>

            <main class="flex-1 relative z-10">
                <div class="mx-auto max-w-5xl px-6 pt-12 pb-16 sm:pt-20 sm:pb-24">
                    <div class="text-center max-w-2xl mx-auto">
                        <div class="inline-flex items-center gap-2 rounded-full liquid-glass-badge px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-mulberry-800 shadow-sm">
                            Transparent Pricing
                        </div>
                        <h1 class="mt-5 text-4xl font-extrabold tracking-tight sm:text-5xl text-gray-900">
                            Simple, predictable plans.
                        </h1>
                        <p class="mt-4 text-lg text-gray-600">
                            No setup fees. No long-term lock-in. Cancel or switch anytime.
                        </p>
                    </div>

                    {{-- 3 Liquid Glass Pricing Tier Cards --}}
                    <div class="mt-16 grid gap-8 lg:grid-cols-3 items-stretch">
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
                                    <h2 class="text-xl font-bold text-gray-900">{{ $plan }}</h2>
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

                    {{-- Stripe Credential & Payment Security Box --}}
                    <div class="mt-12 liquid-glass-border rounded-3xl p-[2px] shadow-lg">
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
                                            All transactions, card data, and recurring subscriptions are encrypted and processed by Stripe's certified banking infrastructure with 256-bit SSL encryption. Zero card numbers are stored on our servers.
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

                    {{-- FAQ & Bespoke Support in Liquid Glass Panel --}}
                    <div class="mt-20 liquid-glass-card rounded-3xl p-8 sm:p-12 shadow-lg">
                        <div class="grid gap-10 sm:grid-cols-2">
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">Answers</span>
                                <h3 class="mt-1 text-xl font-bold text-gray-900">Frequently asked questions</h3>
                                <dl class="mt-6 space-y-5 text-sm">
                                    <div>
                                        <dt class="font-semibold text-gray-900">Do I need a credit card to test?</dt>
                                        <dd class="mt-1 text-gray-600 leading-relaxed">No. Start your 14-day trial with just your email. We only request billing details when you choose to stay.</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-gray-900">Can I switch or cancel plans later?</dt>
                                        <dd class="mt-1 text-gray-600 leading-relaxed">Yes. Upgrades take effect instantly. You can change plans or cancel at any time with one click.</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-gray-900">What happens after the 14-day trial?</dt>
                                        <dd class="mt-1 text-gray-600 leading-relaxed">Your data remains safe and accessible. You can choose a tier whenever you are ready, or ask us to erase everything.</dd>
                                    </div>
                                </dl>
                            </div>
                            <div class="flex flex-col justify-between border-t sm:border-t-0 sm:border-l border-black/10 pt-8 sm:pt-0 sm:pl-10">
                                <div>
                                    <span class="text-xs font-semibold uppercase tracking-wider text-mulberry-700">Custom Setup</span>
                                    <h3 class="mt-1 text-xl font-bold text-gray-900">Need something bespoke?</h3>
                                    <p class="mt-3 text-sm text-gray-600 leading-relaxed">
                                        We support multi-location businesses, franchises, and specialized appointment setups across the UK. Let us know how your operation runs.
                                    </p>
                                </div>
                                <div class="mt-6">
                                    <a href="mailto:support@example.com" 
                                       class="liquid-glass-btn inline-flex items-center gap-2 rounded-full border border-white/90 px-6 py-2.5 text-sm font-semibold text-gray-900 shadow">
                                        <svg class="h-4 w-4 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                                        </svg>
                                        Talk to our team
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <x-liquid-glass-footer class="mt-12 mb-6" />
        </div>
    </body>
</html>
