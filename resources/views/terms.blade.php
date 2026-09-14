<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-seo-head page="terms" />
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-canvas font-sans antialiased text-gray-900 selection:bg-mulberry-500 selection:text-white">
        <div class="relative min-h-full flex flex-col overflow-hidden">
            {{-- Ambient Glow Orbs --}}
            <div class="pointer-events-none absolute -top-32 left-1/2 -translate-x-1/2 h-[400px] w-[700px] rounded-full bg-gradient-to-tr from-mulberry-300/20 via-mulberry-200/20 to-gray-200/30 blur-[130px]" aria-hidden="true"></div>

            {{-- Floating Liquid Glass Navbar --}}
            <header class="sticky top-4 z-40 px-4 sm:px-6">
                <div class="mx-auto flex max-w-5xl items-center justify-between liquid-glass-navbar rounded-2xl px-5 py-3 shadow-lg">
                    <a href="/" class="group flex items-center gap-2.5">
                        <span class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-white via-gray-100 to-gray-200 text-mulberry-800 shadow-[0_2px_8px_rgba(0,0,0,0.08),inset_0_1px_1px_rgba(255,255,255,0.9)] transition-transform duration-300 group-hover:scale-105">
                            <span class="text-base font-bold">{{ substr(config('app.name'), 0, 1) }}</span>
                        </span>
                        <span class="text-lg font-bold tracking-tight text-gray-900">
                            {{ config('app.name') }}
                        </span>
                    </a>
                    <nav class="flex items-center gap-4 text-sm font-medium text-gray-600">
                        <a href="{{ route('pricing') }}" class="hover:text-black transition-colors">Pricing</a>
                        <a href="{{ route('privacy') }}" class="hover:text-black transition-colors">Privacy</a>
                        <a href="{{ route('login') }}" class="hover:text-black transition-colors">Log in</a>
                    </nav>
                </div>
            </header>

            <main class="flex-1 relative z-10">
                <div class="mx-auto max-w-4xl px-6 pt-12 pb-16 sm:pt-16 sm:pb-24">
                    <div class="liquid-glass-card rounded-3xl p-8 sm:p-12 shadow-xl">
                        <div class="border-b border-black/10 pb-6 mb-8">
                            <div class="inline-flex items-center gap-2 rounded-full liquid-glass-badge px-3.5 py-1 text-xs font-semibold uppercase tracking-wider text-mulberry-800 shadow-sm">
                                Legal Terms
                            </div>
                            <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Terms of Service</h1>
                            <p class="mt-2 text-sm text-gray-500">Last updated: {{ now()->format('F Y') }}</p>
                        </div>

                        <div class="space-y-8 text-gray-700 text-sm sm:text-base leading-relaxed">
                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">1. Acceptance of Terms</h2>
                                <p>By creating an account or accessing {{ config('app.name') }}, you agree to be bound by these terms. If you do not agree, please do not use or subscribe to the platform.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">2. Account Responsibility</h2>
                                <p>You are responsible for safeguarding your login credentials, configuring staff access roles accurately, and ensuring all actions taken under your account comply with applicable local law.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">3. Subscription Billing &amp; Refunds</h2>
                                <p>Subscription fees are billed automatically on a recurring monthly or annual basis. You may upgrade, downgrade, or cancel your subscription at any time via your dashboard settings.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">4. Service Availability &amp; Liability</h2>
                                <p>{{ config('app.name') }} is built to ensure high reliability and uptime. The service is provided on an "as is" and "as available" basis without warranties of any kind beyond standard service level commitments.</p>
                            </section>

                            <section class="border-t border-black/10 pt-6">
                                <h2 class="text-lg font-bold text-gray-900 mb-2">5. Questions &amp; Support</h2>
                                <p>For contractual or support enquiries, please contact our support team at <a href="mailto:support@example.com" class="font-semibold text-mulberry-700 underline">support@example.com</a>.</p>
                            </section>
                        </div>
                    </div>
                </div>
            </main>

            <x-liquid-glass-footer class="mt-12 mb-6" />
        </div>
    </body>
</html>
