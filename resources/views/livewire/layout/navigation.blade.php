<?php

use App\Livewire\Actions\Logout;
use App\Models\Business;
use App\Support\Tenant;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function operateBusiness(int $id): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        Tenant::operateAs($id);
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function exitOperateMode(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        Tenant::operateAs(null);
        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

@php
    $isSuperAdmin = auth()->user()?->isSuperAdmin();
    $isOperating = $isSuperAdmin && \App\Support\Tenant::check();
    $tenantScreens = ! $isSuperAdmin || $isOperating;
    $operatedBusiness = $isOperating ? \App\Support\Tenant::operatingBusiness() : null;
    $activeSlug = $operatedBusiness?->slug ?? auth()->user()?->business?->slug;
    $allBusinesses = $isSuperAdmin ? \App\Models\Business::orderBy('name')->get() : collect();
@endphp

<nav x-data="{ open: false }" class="bg-white/95 backdrop-blur-md border-b border-slate-200/80 sticky top-0 z-40 shadow-xs transition-all">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-x-2.5 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600">
                        {{-- No fill-current: the mark's outer dot is hollow by design. --}}
                        <x-application-logo class="h-5 w-auto text-mulberry-700" />
                        <span class="font-semibold text-lg tracking-tight text-gray-900">{{ config('app.name') }}</span>
                    </a>
                </div>

                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ $isSuperAdmin && ! $isOperating ? __('Platform') : __('Today') }}
                    </x-nav-link>

                    @if ($tenantScreens)
                        <x-nav-link :href="route('appointments.index')" :active="request()->routeIs('appointments.*')" wire:navigate>
                            {{ __('Diary') }}
                        </x-nav-link>

                        <x-nav-link :href="route('customers.index')" :active="request()->routeIs('customers.*')" wire:navigate>
                            {{ __('Customers') }}
                        </x-nav-link>

                        <x-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')" wire:navigate>
                            {{ __('Services') }}
                        </x-nav-link>

                        <x-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.*')" wire:navigate>
                            {{ __('Staff') }}
                        </x-nav-link>

                        <x-nav-link :href="route('settings.index')" :active="request()->routeIs('settings.*')" wire:navigate>
                            {{ __('Settings') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">
                @if ($isSuperAdmin)
                    @if ($isOperating && $operatedBusiness)
                        <div class="flex items-center gap-2 bg-amber-500/10 border border-amber-500/30 rounded-full px-3 py-1 text-xs text-amber-900 font-semibold shadow-xs">
                            <svg class="h-3.5 w-3.5 text-amber-600 fill-current" viewBox="0 0 24 24">
                                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
                            </svg>
                            <span>Operating: <strong>{{ $operatedBusiness->name }}</strong></span>
                            <button type="button" wire:click="exitOperateMode" title="Exit to platform overview" class="ms-1 px-2 py-0.5 rounded-full bg-amber-200/80 hover:bg-amber-300 text-amber-900 font-bold transition-colors inline-flex items-center gap-1">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                <span>Exit</span>
                            </button>
                        </div>
                    @else
                        <div class="inline-flex items-center gap-1.5 bg-purple-500/10 border border-purple-500/20 rounded-full px-3 py-1 text-xs text-purple-900 font-semibold">
                            <svg class="h-3.5 w-3.5 text-purple-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0112 2.25c5.385 0 9.75 4.365 9.75 9.75s-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12 6.865 2.25 12 2.25z"/>
                            </svg>
                            <span>Platform Mode</span>
                        </div>
                    @endif
                @endif

                @if ($tenantScreens && $activeSlug)
                    <div x-data="{ copied: false }">
                        <button type="button"
                                @click="navigator.clipboard.writeText('{{ route('booking.public', $activeSlug) }}'); copied = true; setTimeout(() => copied = false, 2500)"
                                title="Copy public booking link to share with clients"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200/80 transition-all shadow-sm">
                            <svg class="w-3.5 h-3.5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            <span x-show="!copied">Booking Link</span>
                            <span x-show="copied" style="display: none;" class="text-emerald-700 font-bold inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                Copied!
                            </span>
                        </button>
                    </div>
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @if ($tenantScreens)
                            <x-dropdown-link :href="route('settings.index')" wire:navigate>
                                {{ __('Business Settings') }}
                            </x-dropdown-link>
                        @endif

                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>

    {{--
        ─── Phone navigation ───────────────────────────────────────────────────
        The three daily screens get tab bar slots; the two setup screens and the
        account live behind "More". Everything the owner touches between clients
        is one tap, and nothing is behind a hamburger — the reference points for
        this audience are Instagram and WhatsApp, not admin dashboards.

        style="display: none" so the sheet cannot flash before Alpine boots. The
        project has no x-cloak stylesheet.
    --}}

    <div x-show="open"
         x-transition.opacity
         @click="open = false"
         style="display: none"
         class="sm:hidden fixed inset-0 z-40 bg-gray-900/40 overlay-blur"
         aria-hidden="true"></div>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0"
         x-transition:leave-end="translate-y-full"
         @keydown.escape.window="open = false"
         style="display: none"
         class="sm:hidden fixed inset-x-0 bottom-0 z-50 rounded-t-2xl bg-white shadow-2xl pb-[env(safe-area-inset-bottom)]"
         role="dialog"
         aria-modal="true"
         aria-label="More">
        <div class="px-4 pt-4 pb-2">
            <div class="font-semibold text-base text-gray-900" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
            <div class="text-sm text-gray-500">{{ auth()->user()->email }}</div>
        </div>

        @if ($isSuperAdmin && $isOperating && $operatedBusiness)
            <div class="px-4 py-2.5 bg-amber-50 border-t border-amber-200 flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-900 flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-amber-600 fill-current" viewBox="0 0 24 24">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
                    </svg>
                    <span>Operating: {{ $operatedBusiness->name }}</span>
                </span>
                <button type="button" wire:click="exitOperateMode" @click="open = false" class="text-xs font-bold text-amber-800 bg-amber-200/80 px-2.5 py-1 rounded-full hover:bg-amber-300 inline-flex items-center gap-1">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span>Exit</span>
                </button>
            </div>
        @endif

        @if ($tenantScreens && $activeSlug)
            <div x-data="{ copied: false }" class="px-4 py-2 border-t border-gray-100">
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ route('booking.public', $activeSlug) }}'); copied = true; setTimeout(() => copied = false, 2500)"
                        class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-bold rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 shadow-sm transition-all">
                    <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    <span x-show="!copied">Copy Online Booking Link</span>
                    <span x-show="copied" style="display: none;" class="text-emerald-700 font-bold inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        Link Copied!
                    </span>
                </button>
            </div>
        @endif

        <div class="border-t border-gray-200 py-1">
            @if ($tenantScreens)
                <x-responsive-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')" wire:navigate @click="open = false">
                    {{ __('Services') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.*')" wire:navigate @click="open = false">
                    {{ __('Staff') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('settings.index')" :active="request()->routeIs('settings.*')" wire:navigate @click="open = false">
                    {{ __('Settings') }}
                </x-responsive-nav-link>
            @endif

            <x-responsive-nav-link :href="route('profile')" :active="request()->routeIs('profile')" wire:navigate @click="open = false">
                {{ __('Profile') }}
            </x-responsive-nav-link>

            <button wire:click="logout" class="w-full text-start">
                <x-responsive-nav-link>
                    {{ __('Log Out') }}
                </x-responsive-nav-link>
            </button>
        </div>

        <div class="border-t border-gray-200 p-3">
            <x-secondary-button type="button" class="w-full" @click="open = false">
                {{ __('Close') }}
            </x-secondary-button>
        </div>
    </div>

    <div class="sm:hidden fixed inset-x-0 bottom-0 z-30 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]">
        <div class="flex">
            @php
                $tab = 'flex-1 flex flex-col items-center justify-center gap-y-0.5 min-h-touch py-2 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600 active:scale-95 transition-transform duration-100';
                $on = 'text-mulberry-700';
                $off = 'text-gray-500';
            @endphp

            <a href="{{ route('dashboard') }}" wire:navigate
               @class([$tab, request()->routeIs('dashboard') ? $on : $off])
               @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.955-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                </svg>
                {{ __('Today') }}
                <span class="tab-dot {{ request()->routeIs('dashboard') ? 'bg-mulberry-600' : 'bg-transparent' }}"></span>
            </a>

            @if ($tenantScreens)
                <a href="{{ route('appointments.index') }}" wire:navigate
                   @class([$tab, request()->routeIs('appointments.*') ? $on : $off])
                   @if (request()->routeIs('appointments.*')) aria-current="page" @endif>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                    {{ __('Diary') }}
                    <span class="tab-dot {{ request()->routeIs('appointments.*') ? 'bg-mulberry-600' : 'bg-transparent' }}"></span>
                </a>

                <a href="{{ route('customers.index') }}" wire:navigate
                   @class([$tab, request()->routeIs('customers.*') ? $on : $off])
                   @if (request()->routeIs('customers.*')) aria-current="page" @endif>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    {{ __('Customers') }}
                    <span class="tab-dot {{ request()->routeIs('customers.*') ? 'bg-mulberry-600' : 'bg-transparent' }}"></span>
                </a>
            @endif

            <button type="button" @click="open = ! open"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-label="More"
                    @class([$tab, $off])>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                {{ __('More') }}
            </button>
        </div>
    </div>
</nav>
