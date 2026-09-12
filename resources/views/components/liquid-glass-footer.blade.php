@php
    $siteName = \App\Support\SiteSettings::get('general', 'site_name', config('app.name', 'BizFlow'));
    $siteTagline = \App\Support\SiteSettings::get('general', 'site_tagline', 'Liquid glass interfaces, crafted with care for modern local businesses and teams that love detail.');
    $supportEmail = \App\Support\SiteSettings::get('general', 'support_email', 'support@example.com');
    $socialX = \App\Support\SiteSettings::get('social', 'x', 'https://x.com');
    $socialGithub = \App\Support\SiteSettings::get('social', 'github', 'https://github.com');
    $socialLinkedin = \App\Support\SiteSettings::get('social', 'linkedin', 'https://linkedin.com');
    $socialDiscord = \App\Support\SiteSettings::get('social', 'discord', '#');
@endphp

@props([
    'brand' => $siteName,
    'tagline' => $siteTagline,
    'newsletterAction' => '#',
    'showNewsletter' => true,
])

<footer {{ $attributes->merge(['class' => 'w-full py-12 px-4 sm:px-6 lg:px-8']) }}>
    <div class="mx-auto max-w-6xl">
        {{-- Outer Liquid Glass Chrome Border Wrapper --}}
        <div class="liquid-glass-border rounded-[32px] sm:rounded-[40px] p-[3px] transition-all duration-300">
            {{-- Inner Liquid Glass Content Card --}}
            <div class="liquid-glass-content relative overflow-hidden rounded-[29px] sm:rounded-[37px] p-7 sm:p-10 lg:p-12 text-gray-900 backdrop-blur-xl">
                
                {{-- Ambient Light Accent in background --}}
                <div class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full bg-white/40 blur-3xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-mulberry-500/10 blur-3xl" aria-hidden="true"></div>

                {{-- Top Section: Brand + Links --}}
                <div class="relative z-10 flex flex-col gap-10 lg:flex-row lg:items-start lg:justify-between">
                    
                    {{-- Brand & Newsletter Column --}}
                    <div class="max-w-sm flex flex-col gap-6">
                        <div>
                            <a href="/" class="group inline-flex items-center gap-3">
                                <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-white via-gray-100 to-gray-300 text-mulberry-800 shadow-[0_2px_8px_rgba(0,0,0,0.1),inset_0_1px_1px_rgba(255,255,255,0.9)] transition-transform duration-300 group-hover:scale-105">
                                    <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24">
                                        <path d="M12 2L2 7l10 5 10-5-10-5zm0 9.25L4.5 7.5 12 3.75 19.5 7.5 12 11.25zM2 17l10 5 10-5-2.5-1.25L12 19.5l-7.5-3.75L2 17zm0-5l10 5 10-5-2.5-1.25L12 14.5l-7.5-3.75L2 12z"/>
                                    </svg>
                                </div>
                                <span class="text-xl font-bold tracking-tight text-gray-900">
                                    {{ $brand }}
                                </span>
                            </a>
                            <p class="mt-3 text-sm leading-relaxed text-gray-700/90">
                                {{ $tagline }}
                            </p>
                        </div>

                        @if ($showNewsletter)
                            <form action="{{ $newsletterAction }}" method="POST" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 max-w-md">
                                @csrf
                                <div class="relative flex-1">
                                    <input 
                                        type="email" 
                                        name="email" 
                                        placeholder="Your email" 
                                        required 
                                        class="liquid-glass-input w-full rounded-full border border-white/50 px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-500 focus:border-white focus:outline-none focus:ring-2 focus:ring-white/40"
                                    />
                                </div>
                                <button 
                                    type="submit" 
                                    class="liquid-glass-btn shrink-0 rounded-full border border-white/80 px-5 py-2.5 text-sm font-semibold text-gray-900 focus:outline-none focus:ring-2 focus:ring-black/10">
                                    Subscribe
                                </button>
                            </form>
                        @endif
                    </div>

                    {{-- 3 Navigation Columns --}}
                    <div class="grid grid-cols-2 gap-8 sm:grid-cols-3 sm:gap-12 lg:gap-16">
                        {{-- Column 1: Product --}}
                        <div class="flex flex-col gap-3">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-600/90">
                                Product
                            </span>
                            <ul class="flex flex-col space-y-2.5 text-sm">
                                <li>
                                    <a href="/#features" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Features
                                    </a>
                                </li>
                                <li>
                                    <a href="{{ route('pricing') }}" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Pricing
                                    </a>
                                </li>
                                <li>
                                    <a href="/#how-it-works" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Diary &amp; Reminders
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Integrations
                                    </a>
                                </li>
                            </ul>
                        </div>

                        {{-- Column 2: Company --}}
                        <div class="flex flex-col gap-3">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-600/90">
                                Company
                            </span>
                            <ul class="flex flex-col space-y-2.5 text-sm">
                                <li>
                                    <a href="#" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        About Us
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Our Work
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Careers
                                    </a>
                                </li>
                                <li>
                                    <a href="mailto:{{ $supportEmail }}" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Contact
                                    </a>
                                </li>
                            </ul>
                        </div>

                        {{-- Column 3: Resources --}}
                        <div class="col-span-2 sm:col-span-1 flex flex-col gap-3">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-600/90">
                                Resources
                            </span>
                            <ul class="flex flex-col space-y-2.5 text-sm">
                                <li>
                                    <a href="{{ route('privacy') }}" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Privacy Policy
                                    </a>
                                </li>
                                <li>
                                    <a href="{{ route('terms') }}" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Terms of Service
                                    </a>
                                </li>
                                <li>
                                    <a href="mailto:{{ $supportEmail }}" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        Support Desk
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="text-gray-700 transition-all duration-200 hover:text-black hover:translate-x-1 inline-block">
                                        System Status
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                </div>

                {{-- Divider --}}
                <div class="relative z-10 my-8 sm:my-10 h-[1px] w-full bg-black/10"></div>

                {{-- Bottom Section: Copyright + Social Icons --}}
                <div class="relative z-10 flex flex-col items-center justify-between gap-4 text-xs text-gray-600 sm:flex-row">
                    <p>
                        &copy; {{ date('Y') }} {{ $brand }}. Crafted for modern local businesses.
                    </p>

                    {{-- Liquid Glass Social Badges --}}
                    <div class="flex items-center gap-2.5">
                        {{-- X / Twitter --}}
                        <a href="{{ $socialX }}" target="_blank" rel="noopener noreferrer" aria-label="Twitter"
                           class="liquid-glass-social grid h-9 w-9 place-items-center rounded-full text-gray-700 hover:text-black">
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                        </a>

                        {{-- GitHub --}}
                        <a href="{{ $socialGithub }}" target="_blank" rel="noopener noreferrer" aria-label="GitHub"
                           class="liquid-glass-social grid h-9 w-9 place-items-center rounded-full text-gray-700 hover:text-black">
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                            </svg>
                        </a>

                        {{-- LinkedIn --}}
                        <a href="{{ $socialLinkedin }}" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn"
                           class="liquid-glass-social grid h-9 w-9 place-items-center rounded-full text-gray-700 hover:text-black">
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                <path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.46 10.9v8.37H9.2V10.9H6.46M7.83 6.45a1.64 1.64 0 0 0-1.64 1.64 1.64 1.64 0 0 0 1.64 1.64c.9 0 1.63-.74 1.63-1.64a1.64 1.64 0 0 0-1.63-1.64z"/>
                            </svg>
                        </a>

                        {{-- Discord / Community --}}
                        <a href="{{ $socialDiscord }}" aria-label="Community"
                           class="liquid-glass-social grid h-9 w-9 place-items-center rounded-full text-gray-700 hover:text-black">
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.929 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.894.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                            </svg>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</footer>
