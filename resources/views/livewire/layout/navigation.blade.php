<?php

use App\Livewire\Actions\Logout;
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
}; ?>

@php
    /*
     * Super-admins have no business_id, so every tenant screen aborts 403 for them.
     * Computed once here rather than repeated across the top bar, the sheet and the
     * tab bar, where the three copies would eventually disagree.
     */
    $tenantScreens = ! auth()->user()->isSuperAdmin();
@endphp

<nav x-data="{ open: false }" class="bg-white border-b border-gray-200">
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
                        {{ __('Today') }}
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
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
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
         class="sm:hidden fixed inset-0 z-40 bg-gray-900/40"
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

        <div class="border-t border-gray-200 py-1">
            @if ($tenantScreens)
                <x-responsive-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')" wire:navigate @click="open = false">
                    {{ __('Services') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.*')" wire:navigate @click="open = false">
                    {{ __('Staff') }}
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
                $tab = 'flex-1 flex flex-col items-center justify-center gap-y-1 min-h-touch py-2 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-mulberry-600';
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
            </a>

            @if ($tenantScreens)
                <a href="{{ route('appointments.index') }}" wire:navigate
                   @class([$tab, request()->routeIs('appointments.*') ? $on : $off])
                   @if (request()->routeIs('appointments.*')) aria-current="page" @endif>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                    {{ __('Diary') }}
                </a>

                <a href="{{ route('customers.index') }}" wire:navigate
                   @class([$tab, request()->routeIs('customers.*') ? $on : $off])
                   @if (request()->routeIs('customers.*')) aria-current="page" @endif>
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    {{ __('Customers') }}
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
