<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <x-seo-head page="privacy" />
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full bg-[#f8f9fc] font-sans antialiased text-gray-900 selection:bg-mulberry-500 selection:text-white">
        <div class="relative min-h-full flex flex-col overflow-hidden">
            {{-- Ambient Glow Orbs --}}
            <div class="pointer-events-none absolute -top-32 left-1/2 -translate-x-1/2 h-[400px] w-[700px] rounded-full bg-gradient-to-tr from-mulberry-300/20 via-pink-200/20 to-slate-200/30 blur-[130px]" aria-hidden="true"></div>

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
                        <a href="{{ route('terms') }}" class="hover:text-black transition-colors">Terms</a>
                        <a href="{{ route('login') }}" class="hover:text-black transition-colors">Log in</a>
                    </nav>
                </div>
            </header>

            <main class="flex-1 relative z-10">
                <div class="mx-auto max-w-4xl px-6 pt-12 pb-16 sm:pt-16 sm:pb-24">
                    <div class="liquid-glass-card rounded-3xl p-8 sm:p-12 shadow-xl">
                        <div class="border-b border-black/10 pb-6 mb-8">
                            <div class="inline-flex items-center gap-2 rounded-full liquid-glass-badge px-3.5 py-1 text-xs font-semibold uppercase tracking-wider text-mulberry-800 shadow-sm">
                                Legal &amp; Compliance
                            </div>
                            <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Privacy Policy</h1>
                            <p class="mt-2 text-sm text-gray-500">Last updated: {{ now()->format('F Y') }}</p>
                        </div>

                        <div class="space-y-8 text-gray-700 text-sm sm:text-base leading-relaxed">
                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">1. What we collect</h2>
                                <p>We collect only what is strictly necessary to run your bookings and reminders: business details, staff profiles, customer contact details (names, phone numbers, email), scheduled appointment records, and reminder channel preferences.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">2. How we use your data</h2>
                                <p>Your data is used solely to operate and deliver the booking and reminder service. We never sell, monetize, or rent personal data to advertisers or third parties. Necessary dispatches (e.g. sending SMS, Telegram or WhatsApp reminder notifications) are executed through secure, enterprise-grade telecommunication APIs.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">3. Data retention &amp; ownership</h2>
                                <p>Appointment records and logs are maintained securely so your business accounts stay complete and tax-compliant. As a business owner, you have full sovereignty over your database and can request permanent deletion or export of customer records at any time.</p>
                            </section>

                            <section>
                                <h2 class="text-lg font-bold text-gray-900 mb-2">4. Your rights under UK GDPR</h2>
                                <p>Under the UK General Data Protection Regulation, both you and your clients maintain the right to access, rectify, or erase personal data. Requests can be submitted directly via our privacy contact channel.</p>
                            </section>

                            <section class="border-t border-black/10 pt-6">
                                <h2 class="text-lg font-bold text-gray-900 mb-2">5. Privacy enquiries</h2>
                                <p>For privacy queries, data protection requests, or compliance documentation, email us at <a href="mailto:support@example.com" class="font-semibold text-mulberry-700 underline">support@example.com</a>.</p>
                            </section>
                        </div>
                    </div>
                </div>
            </main>

            <x-liquid-glass-footer class="mt-12 mb-6" />
        </div>
    </body>
</html>
