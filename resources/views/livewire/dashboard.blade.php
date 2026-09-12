<?php

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\BusinessSnapshot;
use App\Support\SiteSettings;
use App\Support\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The landing page after login, and the screen the boss will demo.
 */
new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component
{
    #[Url(as: 'view', except: 'overview')]
    public string $tab = 'overview';

    #[Url(as: 'platform_tab', except: 'tenants')]
    public string $platformTab = 'tenants';

    public string $seoPage = 'home';

    public string $search = '';
    public bool $showCreateModal = false;

    public string $newBusinessName = '';
    public string $newBusinessNiche = '';
    public string $newBusinessTimezone = 'Europe/London';
    public string $newOwnerName = '';
    public string $newOwnerEmail = '';

    // Site Settings properties:
    public string $siteName = '';
    public string $siteTagline = '';
    public string $supportEmail = '';
    public string $supportPhone = '';
    public string $businessAddress = '';
    public string $currencySymbol = '£';
    public string $defaultTimezone = 'Europe/London';
    public bool $allowRegistrations = true;
    public int $trialDays = 14;
    public string $socialX = '';
    public string $socialGithub = '';
    public string $socialLinkedin = '';
    public string $socialDiscord = '';

    // SEO properties:
    public string $seoTitle = '';
    public string $seoDescription = '';
    public string $seoKeywords = '';
    public string $seoRobots = 'index, follow';
    public string $seoCanonicalBase = '';
    public string $seoTwitterHandle = '';
    public string $seoGoogleVerification = '';

    // Pricing & Stripe properties:
    public string $planStarterPrice = '19';
    public string $planGrowthPrice = '39';
    public string $planBusinessPrice = '69';
    public string $planBillingPeriod = 'month';
    public string $planAnnualDiscount = '20';

    public string $stripePublishableKey = '';
    public string $stripeSecretKey = '';
    public string $stripeWebhookSecret = '';
    public bool $stripeTestMode = true;
    public string $stripeCurrency = 'gbp';
    public bool $showStripeSecret = false;

    public function mount(): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            $this->loadSettings();
            $this->loadSeo();
            $this->loadPricingAndStripe();
        }
    }

    public function loadSettings(): void
    {
        $this->siteName = (string) SiteSettings::get('site_name', config('app.name', 'BizFlow'));
        $this->siteTagline = (string) SiteSettings::get('site_tagline', '');
        $this->supportEmail = (string) SiteSettings::get('support_email', '');
        $this->supportPhone = (string) SiteSettings::get('support_phone', '');
        $this->businessAddress = (string) SiteSettings::get('business_address', '');
        $this->currencySymbol = (string) SiteSettings::get('currency_symbol', '£');
        $this->defaultTimezone = (string) SiteSettings::get('default_timezone', 'Europe/London');
        $this->allowRegistrations = (bool) SiteSettings::get('allow_registrations', true);
        $this->trialDays = (int) SiteSettings::get('trial_days', 14);
        $this->socialX = (string) SiteSettings::get('social_x', '');
        $this->socialGithub = (string) SiteSettings::get('social_github', '');
        $this->socialLinkedin = (string) SiteSettings::get('social_linkedin', '');
        $this->socialDiscord = (string) SiteSettings::get('social_discord', '');
    }

    public function loadSeo(): void
    {
        $page = $this->seoPage;
        $this->seoTitle = (string) SiteSettings::get("seo_{$page}_title", '');
        $this->seoDescription = (string) SiteSettings::get("seo_{$page}_description", '');
        $this->seoKeywords = (string) SiteSettings::get("seo_{$page}_keywords", '');
        $this->seoRobots = (string) SiteSettings::get("seo_{$page}_robots", 'index, follow');
        $this->seoCanonicalBase = (string) SiteSettings::get('seo_canonical_base', config('app.url', 'http://127.0.0.1:8000'));
        $this->seoTwitterHandle = (string) SiteSettings::get('seo_twitter_handle', '');
        $this->seoGoogleVerification = (string) SiteSettings::get('seo_google_site_verification', '');
    }

    public function selectSeoPage(string $page): void
    {
        $this->seoPage = $page;
        $this->loadSeo();
    }

    public function saveSiteSettings(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        SiteSettings::set('site_name', $this->siteName);
        SiteSettings::set('site_tagline', $this->siteTagline);
        SiteSettings::set('support_email', $this->supportEmail);
        SiteSettings::set('support_phone', $this->supportPhone);
        SiteSettings::set('business_address', $this->businessAddress);
        SiteSettings::set('currency_symbol', $this->currencySymbol);
        SiteSettings::set('default_timezone', $this->defaultTimezone);
        SiteSettings::set('allow_registrations', $this->allowRegistrations);
        SiteSettings::set('trial_days', $this->trialDays);
        SiteSettings::set('social_x', $this->socialX);
        SiteSettings::set('social_github', $this->socialGithub);
        SiteSettings::set('social_linkedin', $this->socialLinkedin);
        SiteSettings::set('social_discord', $this->socialDiscord);

        $this->dispatch('toast', message: 'Platform site settings saved successfully.');
    }

    public function saveSeoSettings(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $page = $this->seoPage;
        SiteSettings::set("seo_{$page}_title", $this->seoTitle);
        SiteSettings::set("seo_{$page}_description", $this->seoDescription);
        SiteSettings::set("seo_{$page}_keywords", $this->seoKeywords);
        SiteSettings::set("seo_{$page}_robots", $this->seoRobots);
        SiteSettings::set('seo_canonical_base', $this->seoCanonicalBase);
        SiteSettings::set('seo_twitter_handle', $this->seoTwitterHandle);
        SiteSettings::set('seo_google_site_verification', $this->seoGoogleVerification);

        $this->dispatch('toast', message: "SEO settings for {$page} page saved successfully.");
    }

    public function loadPricingAndStripe(): void
    {
        $this->planStarterPrice = (string) SiteSettings::get('plan_starter_price', '19');
        $this->planGrowthPrice = (string) SiteSettings::get('plan_growth_price', '39');
        $this->planBusinessPrice = (string) SiteSettings::get('plan_business_price', '69');
        $this->planBillingPeriod = (string) SiteSettings::get('plan_billing_period', 'month');
        $this->planAnnualDiscount = (string) SiteSettings::get('plan_annual_discount', '20');

        $this->stripePublishableKey = (string) SiteSettings::get('stripe_publishable_key', config('services.stripe.key', ''));
        $this->stripeSecretKey = (string) SiteSettings::get('stripe_secret_key', config('services.stripe.secret', ''));
        $this->stripeWebhookSecret = (string) SiteSettings::get('stripe_webhook_secret', config('services.stripe.webhook_secret', ''));
        $this->stripeTestMode = (bool) SiteSettings::get('stripe_test_mode', true);
        $this->stripeCurrency = (string) SiteSettings::get('stripe_currency', 'gbp');
    }

    public function savePricingAndStripe(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->validate([
            'planStarterPrice' => ['required', 'numeric', 'min:0'],
            'planGrowthPrice' => ['required', 'numeric', 'min:0'],
            'planBusinessPrice' => ['required', 'numeric', 'min:0'],
            'planBillingPeriod' => ['required', 'string', 'in:month,year'],
            'planAnnualDiscount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stripePublishableKey' => ['nullable', 'string', 'max:255'],
            'stripeSecretKey' => ['nullable', 'string', 'max:255'],
            'stripeWebhookSecret' => ['nullable', 'string', 'max:255'],
            'stripeCurrency' => ['required', 'string', 'max:10'],
        ]);

        SiteSettings::set('plan_starter_price', $this->planStarterPrice);
        SiteSettings::set('plan_growth_price', $this->planGrowthPrice);
        SiteSettings::set('plan_business_price', $this->planBusinessPrice);
        SiteSettings::set('plan_billing_period', $this->planBillingPeriod);
        SiteSettings::set('plan_annual_discount', $this->planAnnualDiscount);

        SiteSettings::set('stripe_publishable_key', trim($this->stripePublishableKey));
        SiteSettings::set('stripe_secret_key', trim($this->stripeSecretKey));
        SiteSettings::set('stripe_webhook_secret', trim($this->stripeWebhookSecret));
        SiteSettings::set('stripe_test_mode', (bool) $this->stripeTestMode);
        SiteSettings::set('stripe_currency', strtolower(trim($this->stripeCurrency)));

        $this->dispatch('toast', message: 'Pricing plans and Stripe credentials updated successfully.');
    }

    public function operate(int $businessId): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        $target = Business::findOrFail($businessId);
        Tenant::operateAs($businessId);
        $this->dispatch('toast', message: "Switched to operating mode for {$target->name}.");
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function exitOperate(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        Tenant::operateAs(null);
        $this->dispatch('toast', message: 'Exited operation mode. Returned to platform overview.');
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function createBusiness(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->validate([
            'newBusinessName' => ['required', 'string', 'max:100'],
            'newBusinessNiche' => ['nullable', 'string', 'max:50'],
            'newBusinessTimezone' => ['required', 'string', 'timezone'],
            'newOwnerName' => ['required', 'string', 'max:100'],
            'newOwnerEmail' => ['required', 'email', 'max:150', 'unique:users,email'],
        ]);

        $slug = Str::slug($this->newBusinessName);
        $baseSlug = $slug;
        $counter = 1;
        while (Business::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $business = Business::create([
            'name' => $this->newBusinessName,
            'slug' => $slug,
            'timezone' => $this->newBusinessTimezone,
            'niche' => $this->newBusinessNiche ?: null,
        ]);

        User::create([
            'name' => $this->newOwnerName,
            'email' => $this->newOwnerEmail,
            'password' => Hash::make('password'),
            'role' => 'owner',
            'business_id' => $business->id,
            'email_verified_at' => now(),
        ]);

        $this->reset(['newBusinessName', 'newBusinessNiche', 'newOwnerName', 'newOwnerEmail', 'showCreateModal']);
        $this->dispatch('toast', message: "Created {$business->name} successfully.");
    }

    public function with(): array
    {
        return Tenant::check() ? $this->ownerData() : $this->platformData();
    }

    /**
     * One business's day, from BusinessSnapshot.
     */
    protected function ownerData(): array
    {
        $business = Business::findOrFail(Tenant::id());
        $snapshot = BusinessSnapshot::for($business);

        return [
            'business' => $business,
            'businesses' => null,
            'totals' => null,

            'greeting' => $this->greeting($snapshot->asAt()->hour),
            'setup' => $snapshot->setup(),
            'today' => $snapshot->today(),
            'tomorrow' => $snapshot->tomorrow(),
            'month' => $snapshot->month(),
            'lapsed' => $snapshot->lapsed(),
            'attention' => $snapshot->attention(),
            'analytics' => $snapshot->analytics(),
        ];
    }

    /**
     * Platform figures for a super-admin.
     */
    protected function platformData(): array
    {
        $query = Business::query()
            ->withCount([
                'customers' => fn ($q) => $q->withoutGlobalScope('business'),
                'appointments' => fn ($q) => $q->withoutGlobalScope('business'),
            ]);

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('niche', 'like', $term)
                  ->orWhere('slug', 'like', $term);
            });
        }

        return [
            'business' => null,
            'businesses' => $query->orderBy('name')->get(),
            'totals' => [
                'businesses' => Business::query()->count(),
                'users' => User::query()->count(),
                'customers' => Customer::query()->acrossAllBusinesses()->count(),
                'appointments' => Appointment::query()->acrossAllBusinesses()->count(),
            ],

            'greeting' => 'Platform',
            'setup' => null,
            'today' => null,
            'tomorrow' => null,
            'month' => null,
            'lapsed' => null,
            'attention' => null,
            'analytics' => null,
        ];
    }

    /** Business-local hour, not server hour. */
    protected function greeting(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}; ?>

<div>
    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if ($business === null)

            {{-- ------------------------------------------------------------------
                 Super-admin: Platform Mission Control, Site Settings & SEO Manager
                 ------------------------------------------------------------------ --}}

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 px-4 sm:px-0">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight text-gray-900 font-display">Platform</h1>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800 border border-purple-200 shadow-xs">
                            <svg class="h-3.5 w-3.5 text-purple-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0112 2.25c5.385 0 9.75 4.365 9.75 9.75s-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12 6.865 2.25 12 2.25z"/>
                            </svg>
                            Superadmin Control
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">
                        Oversee tenant businesses, configure platform settings, and manage website SEO meta tags.
                    </p>
                </div>

                {{-- Tab Selection Navigation --}}
                <div class="flex items-center gap-1.5 bg-slate-200/80 p-1 rounded-2xl">
                    <button type="button" wire:click="$set('platformTab', 'tenants')"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $platformTab === 'tenants' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                        </svg>
                        Businesses
                    </button>

                    <button type="button" wire:click="$set('platformTab', 'settings')"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $platformTab === 'settings' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/>
                        </svg>
                        Site Settings
                    </button>

                    <button type="button" wire:click="$set('platformTab', 'pricing')"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $platformTab === 'pricing' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                        </svg>
                        Pricing &amp; Stripe
                    </button>

                    <button type="button" wire:click="$set('platformTab', 'seo')"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all {{ $platformTab === 'seo' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-.778.099-1.533.284-2.253"/>
                        </svg>
                        SEO Manager
                    </button>
                </div>
            </div>

            {{-- -------------------------------------------------------------
                 TAB 1: TENANTS & BUSINESSES
                 ------------------------------------------------------------- --}}
            @if ($platformTab === 'tenants')
                {{-- Platform KPI Stat Cards --}}
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    @foreach ($totals as $label => $count)
                        <div wire:key="total-{{ $label }}" class="stat-card stat-card--mulberry bg-white p-5 shadow-sm sm:rounded-2xl border border-slate-100 animate-fade-in-up delay-{{ $loop->iteration }}">
                            <div class="flex items-center justify-between">
                                <div class="text-xs uppercase tracking-wider font-semibold text-gray-500">{{ $label }}</div>
                                <span class="h-2 w-2 rounded-full bg-mulberry-500"></span>
                            </div>
                            <div class="mt-2 text-3xl font-extrabold tabular-nums text-gray-900">{{ $count }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Create Business Modal / Collapsible Form --}}
                @if ($showCreateModal)
                    <div class="liquid-glass-border rounded-3xl p-[2px] shadow-xl animate-fade-in-up">
                        <div class="liquid-glass-content rounded-[22px] p-6 sm:p-8 backdrop-blur-xl">
                            <div class="flex items-center justify-between border-b border-black/10 pb-4 mb-6">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900">Provision New Business Tenant</h3>
                                    <p class="text-xs text-gray-600">Creates a new business record with an initial owner account.</p>
                                </div>
                                <button type="button" wire:click="$set('showCreateModal', false)" class="text-gray-400 hover:text-gray-700 p-1">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            <form wire:submit="createBusiness" class="space-y-4">
                                <div class="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Business Name *</label>
                                        <input type="text" wire:model="newBusinessName" placeholder="e.g. Apex Barbershop" required
                                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600"/>
                                        @error('newBusinessName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Niche / Category</label>
                                        <input type="text" wire:model="newBusinessNiche" placeholder="e.g. Barbershop, Hair Salon"
                                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600"/>
                                        @error('newBusinessNiche') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Timezone *</label>
                                        <input type="text" wire:model="newBusinessTimezone" placeholder="Europe/London" required
                                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600"/>
                                        @error('newBusinessTimezone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2 pt-2">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Owner Name *</label>
                                        <input type="text" wire:model="newOwnerName" placeholder="e.g. John Doe" required
                                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600"/>
                                        @error('newOwnerName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Owner Email *</label>
                                        <input type="email" wire:model="newOwnerEmail" placeholder="e.g. owner@apexbarbers.test" required
                                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600"/>
                                        @error('newOwnerEmail') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-3 pt-4 border-t border-black/10">
                                    <button type="button" wire:click="$set('showCreateModal', false)"
                                            class="rounded-full border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="liquid-glass-btn-primary rounded-full px-6 py-2 text-xs font-semibold text-white shadow">
                                        Save &amp; Provision
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Businesses Management Panel --}}
                <div class="bg-white shadow-sm sm:rounded-2xl border border-slate-100 animate-fade-in-up delay-5 overflow-hidden">
                    <div class="border-b border-gray-100 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <h2 class="text-base font-bold text-gray-900">Registered Businesses</h2>
                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">
                                {{ count($businesses) }}
                            </span>

                            <button type="button" wire:click="$toggle('showCreateModal')"
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-mulberry-50 text-mulberry-800 hover:bg-mulberry-100 border border-mulberry-200 transition-colors">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                </svg>
                                <span>New Business</span>
                            </button>
                        </div>

                        <div class="relative max-w-xs w-full">
                            <input type="text" wire:model.live.debounce.250ms="search" placeholder="Search businesses..."
                                   class="w-full rounded-full border border-gray-300 pl-9 pr-4 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600" />
                            <svg class="absolute left-3 top-2 h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <ul class="divide-y divide-gray-100">
                        @forelse ($businesses as $client)
                            <li wire:key="business-{{ $client->id }}" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 sm:p-5 transition-colors hover:bg-slate-50/80">
                                <div class="flex items-start gap-3.5">
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-mulberry-50 to-mulberry-100 text-mulberry-800 font-bold text-sm shadow-xs border border-mulberry-200/60">
                                        {{ substr($client->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="font-bold text-gray-900 text-sm sm:text-base">{{ $client->name }}</div>
                                            @if ($client->niche)
                                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                                    {{ $client->niche }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5 flex flex-wrap items-center gap-2">
                                            <span>{{ $client->timezone }}</span>
                                            <span>&bull;</span>
                                            <span>Joined {{ $client->created_at->format('j M Y') }}</span>
                                            @if ($client->slug)
                                                <span>&bull;</span>
                                                <a href="{{ route('booking.public', $client->slug) }}" target="_blank" class="text-mulberry-700 hover:underline inline-flex items-center gap-1 font-medium">
                                                    <span>Public Portal</span>
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between sm:justify-end gap-4 border-t sm:border-t-0 pt-3 sm:pt-0">
                                    <div class="text-xs tabular-nums text-gray-500 text-right">
                                        <div class="font-semibold text-gray-800">{{ $client->customers_count }} customers</div>
                                        <div>{{ $client->appointments_count }} bookings</div>
                                    </div>

                                    <button type="button" wire:click="operate({{ $client->id }})"
                                            title="Enter this business to operate its diary, appointments, staff and settings"
                                            class="liquid-glass-btn rounded-full px-4 py-1.5 text-xs font-bold text-gray-900 border border-white/90 shadow-sm inline-flex items-center gap-1.5 hover:bg-white hover:scale-105 transition-all">
                                        <svg class="h-3.5 w-3.5 text-amber-600 fill-current" viewBox="0 0 24 24">
                                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
                                        </svg>
                                        <span>Operate</span>
                                    </button>
                                </div>
                            </li>
                        @empty
                            <li class="p-8 text-center text-sm text-gray-500">
                                No businesses match your search.
                            </li>
                        @endforelse
                    </ul>
                </div>
            @endif

            {{-- -------------------------------------------------------------
                 TAB 2: SITE SETTINGS
                 ------------------------------------------------------------- --}}
            @if ($platformTab === 'settings')
                <div class="liquid-glass-card rounded-3xl p-6 sm:p-10 shadow-lg animate-fade-in-up">
                    <form wire:submit="saveSiteSettings" class="space-y-8">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <svg class="h-5 w-5 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/>
                                </svg>
                                General Website &amp; Brand Settings
                            </h2>
                            <p class="text-xs text-gray-500 mt-1">These settings control the public brand identity, footer info, and contact details across the website.</p>
                        </div>

                        {{-- Section 1: Branding --}}
                        <div class="grid gap-6 sm:grid-cols-2 border-t border-black/10 pt-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Platform Name</label>
                                <input type="text" wire:model="siteName" required
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Brand Tagline</label>
                                <input type="text" wire:model="siteTagline"
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>
                        </div>

                        {{-- Section 2: Contact Details --}}
                        <div class="grid gap-6 sm:grid-cols-3 border-t border-black/10 pt-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Support Email</label>
                                <input type="email" wire:model="supportEmail"
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Support Phone</label>
                                <input type="text" wire:model="supportPhone"
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Registered Office Address</label>
                                <input type="text" wire:model="businessAddress"
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>
                        </div>

                        {{-- Section 3: Billing & Regional --}}
                        <div class="grid gap-6 sm:grid-cols-3 border-t border-black/10 pt-6">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default Currency Symbol</label>
                                <input type="text" wire:model="currencySymbol" required maxlength="5"
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default Timezone</label>
                                <input type="text" wire:model="defaultTimezone" required
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1.5">Free Trial Duration (Days)</label>
                                <input type="number" wire:model="trialDays" min="0" max="90" required
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>
                        </div>

                        {{-- Section 4: Platform Registration Policy --}}
                        <div class="border-t border-black/10 pt-6">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="allowRegistrations"
                                       class="h-4 w-4 rounded border-gray-300 text-mulberry-600 focus:ring-mulberry-500" />
                                <div>
                                    <span class="text-sm font-semibold text-gray-900">Allow Public Business Registrations</span>
                                    <p class="text-xs text-gray-500">When enabled, visitors can self-register their business trial via /register.</p>
                                </div>
                            </label>
                        </div>

                        {{-- Section 5: Social Media Profiles --}}
                        <div class="border-t border-black/10 pt-6">
                            <h3 class="text-sm font-bold text-gray-900 mb-4">Official Social Profiles</h3>
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">X / Twitter</label>
                                    <input type="url" wire:model="socialX" placeholder="https://x.com/yourhandle"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">GitHub</label>
                                    <input type="url" wire:model="socialGithub" placeholder="https://github.com/yourorg"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">LinkedIn</label>
                                    <input type="url" wire:model="socialLinkedin" placeholder="https://linkedin.com/company/yourpage"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Discord / Community</label>
                                    <input type="url" wire:model="socialDiscord" placeholder="https://discord.gg/yourserver"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-black/10 pt-6">
                            <button type="submit"
                                    class="liquid-glass-btn-primary rounded-full px-7 py-2.5 text-xs font-bold text-white shadow-md inline-flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                                Save Site Settings
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- -------------------------------------------------------------
                 TAB 3: PRICING & STRIPE CREDENTIALS BOX
                 ------------------------------------------------------------- --}}
            @if ($platformTab === 'pricing')
                <div class="space-y-8 animate-fade-in-up">
                    {{-- Header --}}
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <svg class="h-5 w-5 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
                                </svg>
                                Pricing Plans &amp; Stripe Gateway Credentials
                            </h2>
                            <p class="text-xs text-gray-500 mt-1">Configure subscription pricing tiers, billing cycles, and link your Stripe account API keys.</p>
                        </div>

                        {{-- Stripe Connection Badge --}}
                        <div>
                            @if (!empty($stripePublishableKey) && !empty($stripeSecretKey))
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/80 shadow-xs">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    Stripe Connected ({{ $stripeTestMode ? 'Test Mode' : 'Live' }})
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/80 shadow-xs">
                                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                                    Stripe Setup Pending
                                </span>
                            @endif
                        </div>
                    </div>

                    <form wire:submit="savePricingAndStripe" class="space-y-8">
                        {{-- -------------------------------------------------------------
                             STRIPE CREDENTIALS BOX
                             ------------------------------------------------------------- --}}
                        <div class="liquid-glass-card rounded-3xl p-6 sm:p-10 shadow-lg relative overflow-hidden">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-black/10 pb-6 mb-6">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="grid h-8 w-8 place-items-center rounded-xl bg-slate-900 text-white font-bold text-xs shadow-xs">
                                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                                <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697.5 12.523.5 7.054.5 3.197 3.398 3.197 7.79c0 4.542 3.864 6.273 6.671 7.378 2.378.934 3.197 1.579 3.197 2.533 0 .991-.89 1.488-2.316 1.488-2.378 0-5.11-1.125-6.84-2.148l-.92 5.513c1.782.99 4.887 1.706 7.76 1.706 5.867 0 9.778-2.834 9.778-7.534 0-4.66-3.805-6.398-6.551-7.576z"/>
                                            </svg>
                                        </span>
                                        <h3 class="text-base font-bold text-gray-900">Stripe Gateway Credentials Box</h3>
                                        <span class="rounded-full bg-slate-100 text-slate-700 px-2.5 py-0.5 text-[11px] font-semibold border border-slate-200">
                                            PCI-DSS Level 1
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1.5">
                                        API keys are encrypted at rest with AES-256 and communicated securely over TLS 1.3 to Stripe.
                                    </p>
                                </div>

                                {{-- Test Mode vs Live Mode Toggle --}}
                                <div class="flex items-center gap-3 bg-slate-100/90 p-1.5 rounded-2xl border border-slate-200 shrink-0">
                                    <span class="text-xs font-bold text-gray-700 ps-2">Environment:</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" wire:model.live="stripeTestMode" class="sr-only peer">
                                        <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                                        <span class="ms-2 text-xs font-bold {{ $stripeTestMode ? 'text-amber-700' : 'text-emerald-700' }}">
                                            {{ $stripeTestMode ? 'Test Mode (Sandbox)' : 'Live Production' }}
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="grid gap-6 sm:grid-cols-2">
                                {{-- Publishable Key --}}
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-xs font-semibold text-gray-700">Stripe Publishable Key</label>
                                        <span class="text-[11px] text-gray-400 font-mono">{{ $stripeTestMode ? 'pk_test_...' : 'pk_live_...' }}</span>
                                    </div>
                                    <input type="text" wire:model="stripePublishableKey" placeholder="{{ $stripeTestMode ? 'pk_test_51...' : 'pk_live_51...' }}"
                                           class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 font-mono focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    @error('stripePublishableKey') <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span> @enderror
                                    <p class="text-[11px] text-gray-500 mt-1">Public key used for frontend Stripe Elements &amp; Checkout redirects.</p>
                                </div>

                                {{-- Default Settlement Currency --}}
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Default Processing Currency</label>
                                    <select wire:model="stripeCurrency" class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs">
                                        <option value="gbp">GBP (£) — British Pound Sterling</option>
                                        <option value="usd">USD ($) — United States Dollar</option>
                                        <option value="eur">EUR (€) — Euro</option>
                                    </select>
                                    <p class="text-[11px] text-gray-500 mt-1">Default 3-letter ISO currency code sent in Stripe checkout sessions.</p>
                                </div>

                                {{-- Secret Key --}}
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-xs font-semibold text-gray-700">Stripe Secret Key</label>
                                        <button type="button" wire:click="$toggle('showStripeSecret')" class="text-[11px] text-mulberry-700 hover:underline font-medium">
                                            {{ $showStripeSecret ? 'Hide Key' : 'Reveal Key' }}
                                        </button>
                                    </div>
                                    <input type="{{ $showStripeSecret ? 'text' : 'password' }}" wire:model="stripeSecretKey" placeholder="{{ $stripeTestMode ? 'sk_test_51...' : 'sk_live_51...' }}"
                                           class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 font-mono focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    @error('stripeSecretKey') <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span> @enderror
                                    <p class="text-[11px] text-gray-500 mt-1">Restricted backend key. Grants API access to create charges and manage sessions.</p>
                                </div>

                                {{-- Webhook Signing Secret --}}
                                <div>
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-xs font-semibold text-gray-700">Stripe Webhook Signing Secret</label>
                                        <span class="text-[11px] text-gray-400 font-mono">whsec_...</span>
                                    </div>
                                    <input type="{{ $showStripeSecret ? 'text' : 'password' }}" wire:model="stripeWebhookSecret" placeholder="whsec_..."
                                           class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 font-mono focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    @error('stripeWebhookSecret') <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span> @enderror
                                    <p class="text-[11px] text-gray-500 mt-1">Verifies inbound HMAC signatures from Stripe event notifications.</p>
                                </div>
                            </div>

                            {{-- Webhook Listener URL Copy Box --}}
                            <div class="mt-6 rounded-2xl bg-slate-50 border border-slate-200/80 p-4">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div>
                                        <span class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                            <svg class="h-3.5 w-3.5 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
                                            </svg>
                                            Inbound Webhook Endpoint URL
                                        </span>
                                        <p class="text-[11px] text-gray-500 mt-0.5">Register this URL in your Stripe Dashboard &rarr; Developers &rarr; Webhooks.</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="text" readonly value="{{ url('/stripe/webhook') }}" id="stripe-webhook-input"
                                               class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs text-gray-800 font-mono w-64 sm:w-80 shadow-xs" />
                                        <button type="button" onclick="navigator.clipboard.writeText('{{ url('/stripe/webhook') }}'); this.innerText = 'Copied!';"
                                                class="liquid-glass-btn rounded-xl px-3 py-1.5 text-xs font-bold text-gray-800 border border-slate-300 hover:bg-white transition-all shadow-xs">
                                            Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- -------------------------------------------------------------
                             PRICING PLANS CONFIGURATION
                             ------------------------------------------------------------- --}}
                        <div class="liquid-glass-card rounded-3xl p-6 sm:p-10 shadow-lg">
                            <div class="border-b border-black/10 pb-4 mb-6">
                                <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                                    <svg class="h-5 w-5 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Platform Subscription Tier Rates
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    Update the recurring rates displayed to visiting businesses on the landing page and pricing table.
                                </p>
                            </div>

                            <div class="grid gap-6 sm:grid-cols-3">
                                {{-- Starter Plan --}}
                                <div class="rounded-2xl border border-slate-200 bg-white/70 p-5 shadow-xs">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Starter Plan</span>
                                        <span class="rounded bg-slate-100 text-slate-700 text-[10px] font-semibold px-2 py-0.5">Solo</span>
                                    </div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Monthly Rate ({{ $currencySymbol }})</label>
                                    <div class="relative">
                                        <span class="absolute left-3.5 top-2 text-sm text-gray-500 font-bold">{{ $currencySymbol }}</span>
                                        <input type="number" wire:model="planStarterPrice" min="0" step="1" required
                                               class="w-full rounded-xl border border-gray-300 pl-8 pr-3 py-2 text-base font-bold text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-2">1 staff member, up to 100 customers, Telegram reminders.</p>
                                </div>

                                {{-- Growth Plan --}}
                                <div class="rounded-2xl border-2 border-mulberry-500/80 bg-mulberry-50/40 p-5 shadow-sm relative">
                                    <div class="absolute -top-3 right-4">
                                        <span class="rounded-full bg-mulberry-700 text-white text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 shadow-xs">
                                            Popular
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-bold uppercase tracking-wider text-mulberry-900">Growth Plan</span>
                                        <span class="rounded bg-mulberry-100 text-mulberry-800 text-[10px] font-semibold px-2 py-0.5">Teams</span>
                                    </div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Monthly Rate ({{ $currencySymbol }})</label>
                                    <div class="relative">
                                        <span class="absolute left-3.5 top-2 text-sm text-gray-500 font-bold">{{ $currencySymbol }}</span>
                                        <input type="number" wire:model="planGrowthPrice" min="0" step="1" required
                                               class="w-full rounded-xl border border-mulberry-300 pl-8 pr-3 py-2 text-base font-bold text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-2">Up to 10 staff, unlimited clients, WhatsApp + Stripe deposits.</p>
                                </div>

                                {{-- Business Plan --}}
                                <div class="rounded-2xl border border-slate-200 bg-white/70 p-5 shadow-xs">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Business Plan</span>
                                        <span class="rounded bg-slate-100 text-slate-700 text-[10px] font-semibold px-2 py-0.5">Enterprise</span>
                                    </div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Monthly Rate ({{ $currencySymbol }})</label>
                                    <div class="relative">
                                        <span class="absolute left-3.5 top-2 text-sm text-gray-500 font-bold">{{ $currencySymbol }}</span>
                                        <input type="number" wire:model="planBusinessPrice" min="0" step="1" required
                                               class="w-full rounded-xl border border-gray-300 pl-8 pr-3 py-2 text-base font-bold text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-2">Unlimited staff, multi-location, custom branding, SLA.</p>
                                </div>
                            </div>

                            {{-- Billing Cycle & Discount Details --}}
                            <div class="grid gap-6 sm:grid-cols-2 border-t border-black/10 pt-6 mt-6">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Standard Billing Cycle</label>
                                    <select wire:model="planBillingPeriod" class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs">
                                        <option value="month">Monthly Billing (Default)</option>
                                        <option value="year">Annual Billing</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Annual Upfront Discount (%)</label>
                                    <input type="number" wire:model="planAnnualDiscount" min="0" max="100"
                                           class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                                </div>
                            </div>
                        </div>

                        {{-- Submit Button --}}
                        <div class="flex items-center justify-end gap-3">
                            <button type="submit"
                                    class="liquid-glass-btn-primary rounded-full px-8 py-3 text-xs font-bold text-white shadow-md inline-flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                                <span>Save Pricing &amp; Stripe Settings</span>
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- -------------------------------------------------------------
                 TAB 4: SEO MANAGER
                 ------------------------------------------------------------- --}}
            @if ($platformTab === 'seo')
                <div class="liquid-glass-card rounded-3xl p-6 sm:p-10 shadow-lg animate-fade-in-up space-y-8">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <svg class="h-5 w-5 text-mulberry-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-.778.099-1.533.284-2.253"/>
                                </svg>
                                Search Engine Optimization (SEO) Manager
                            </h2>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Configure live meta titles, descriptions, social OpenGraph sharing, indexing instructions, and structured schema.</p>
                    </div>

                    {{-- Page Selector Sub-navigation --}}
                    <div class="flex flex-wrap items-center gap-2 border-b border-black/10 pb-4">
                        <span class="text-xs font-semibold text-gray-500 me-2">Select Page:</span>
                        @foreach (['home' => 'Homepage (/)', 'pricing' => 'Pricing (/pricing)', 'privacy' => 'Privacy (/privacy)', 'terms' => 'Terms (/terms)'] as $key => $label)
                            <button type="button" wire:click="selectSeoPage('{{ $key }}')"
                                    class="px-3.5 py-1.5 rounded-full text-xs font-bold transition-all {{ $seoPage === $key ? 'bg-mulberry-700 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Live Google Search SERP Snippet Preview --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div class="flex items-center gap-2 text-xs text-slate-500 mb-3">
                            <span class="font-bold text-slate-700 uppercase tracking-wider text-[11px]">Google Search Result Preview</span>
                            <span>&bull;</span>
                            <span class="text-emerald-600 font-medium">Simulated snippet</span>
                        </div>
                        <div class="max-w-2xl font-sans">
                            <div class="flex items-center gap-2 text-xs text-[#202124]">
                                <div class="grid h-4 w-4 place-items-center rounded-full bg-slate-100 text-[10px] font-bold text-mulberry-700">
                                    {{ substr($siteName ?: config('app.name'), 0, 1) }}
                                </div>
                                <span class="text-xs text-[#202124]">{{ $seoCanonicalBase ?: 'http://127.0.0.1:8000' }}/{{ $seoPage === 'home' ? '' : $seoPage }}</span>
                            </div>
                            <h4 class="mt-1 text-base sm:text-lg font-medium text-[#1a0dab] hover:underline cursor-pointer">
                                {{ $seoTitle ?: 'Page Title' }} — {{ $siteName ?: config('app.name') }}
                            </h4>
                            <p class="mt-1 text-xs sm:text-sm text-[#4d5156] leading-relaxed line-clamp-2">
                                {{ $seoDescription ?: 'Add a descriptive summary of this page to appear in search results.' }}
                            </p>
                        </div>
                    </div>

                    {{-- Page Meta Tags Form --}}
                    <form wire:submit="saveSeoSettings" class="space-y-6">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-semibold text-gray-700">Page Meta Title *</label>
                                    <span class="text-[11px] text-gray-400">{{ strlen($seoTitle) }}/60 chars</span>
                                </div>
                                <input type="text" wire:model.live.debounce.150ms="seoTitle" required
                                       class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Search Engine Robots Directive</label>
                                <select wire:model="seoRobots" class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs">
                                    <option value="index, follow">index, follow (Standard Indexing)</option>
                                    <option value="noindex, follow">noindex, follow (Hide page, follow links)</option>
                                    <option value="index, nofollow">index, nofollow (Index page, ignore links)</option>
                                    <option value="noindex, nofollow">noindex, nofollow (Fully blocked from search)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-semibold text-gray-700">Meta Description *</label>
                                <span class="text-[11px] text-gray-400">{{ strlen($seoDescription) }}/160 chars</span>
                            </div>
                            <textarea wire:model.live.debounce.150ms="seoDescription" rows="3" required
                                      class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs"></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Meta Keywords</label>
                            <input type="text" wire:model="seoKeywords" placeholder="booking software, salon diary, reminders..."
                                   class="w-full rounded-xl border border-gray-300 px-4 py-2 text-sm text-gray-900 focus:border-mulberry-600 focus:ring-1 focus:ring-mulberry-600 shadow-xs" />
                        </div>

                        {{-- Global SEO Properties --}}
                        <div class="border-t border-black/10 pt-6">
                            <h3 class="text-sm font-bold text-gray-900 mb-4">Global Search &amp; Sharing Configurations</h3>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Canonical Base URL</label>
                                    <input type="url" wire:model="seoCanonicalBase" placeholder="https://example.com"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Twitter Creator @Handle</label>
                                    <input type="text" wire:model="seoTwitterHandle" placeholder="@bizflowapp"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Google Site Verification Code</label>
                                    <input type="text" wire:model="seoGoogleVerification" placeholder="google-site-verification token"
                                           class="w-full rounded-xl border border-gray-300 px-3 py-1.5 text-xs text-gray-900" />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 border-t border-black/10 pt-6">
                            <button type="submit"
                                    class="liquid-glass-btn-primary rounded-full px-7 py-2.5 text-xs font-bold text-white shadow-md inline-flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                                Save SEO Configuration
                            </button>
                        </div>
                    </form>
                </div>
            @endif

        @else

            {{-- ------------------------------------------------------------------
                 Super-admin Operating Mode Active Banner
                 ------------------------------------------------------------------ --}}
            @if (auth()->user()->isSuperAdmin() && \App\Support\Tenant::isOperating())
                <div class="liquid-glass-border rounded-2xl p-[2px] mb-4 shadow-lg animate-fade-in-up">
                    <div class="bg-gradient-to-r from-amber-500/15 via-rose-500/10 to-amber-500/15 backdrop-blur-xl rounded-[14px] p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="grid h-8 w-8 place-items-center rounded-xl bg-amber-500 text-white font-bold text-xs shadow-sm">
                                <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
                                </svg>
                            </span>
                            <div>
                                <span class="font-bold text-gray-900">Superadmin Operating Mode</span>
                                <span class="text-xs text-gray-600 block sm:inline sm:ms-2">
                                    You are actively managing <strong>{{ $business->name }}</strong>. All actions (Diary, Customers, Services, Staff, Settings) apply directly to this business.
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 self-end sm:self-center shrink-0">
                            <button type="button" wire:click="exitOperate" class="liquid-glass-btn rounded-full px-4 py-1.5 text-xs font-bold text-gray-800 border border-white/80 shadow-sm hover:bg-white transition-all inline-flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/>
                                </svg>
                                <span>Exit to Platform</span>
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @php
                /* Local closures rather than component methods: Blade can only call
                   PUBLIC methods on $this, and neither of these should be reachable
                   from the browser. */
                $money = fn ($amount) => '£'.number_format((float) $amount, 2);
                $at = fn ($utc) => $business->toLocal($utc)->format('H:i');
            @endphp

            <div class="bg-white/80 backdrop-blur-md rounded-2xl p-5 sm:p-7 border border-slate-200/80 shadow-xs relative overflow-hidden animate-fade-in-up">
                <div class="absolute -top-16 -right-16 w-48 h-48 bg-gradient-to-br from-rose-100 to-indigo-100 rounded-full blur-3xl opacity-60 pointer-events-none"></div>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 relative z-10">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-bold tracking-tight text-slate-900 font-display">{{ $greeting }}</h1>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200/60">
                                Live
                            </span>
                        </div>
                        <p class="text-sm text-slate-500 mt-1 flex items-center gap-2">
                            <span class="font-semibold text-slate-700">{{ $business->name }}</span>
                            <span class="text-slate-300">&bull;</span>
                            <span>{{ $today['date']->format('l, j F Y') }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        @if ($business->slug)
                            <a href="{{ route('booking.public', $business->slug) }}" target="_blank"
                               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-all">
                                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                <span>Public Page</span>
                            </a>
                        @endif

                        <a href="{{ route('appointments.index') }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-mulberry-700 hover:bg-mulberry-800 text-white shadow-sm shadow-mulberry-900/20 transition-all">
                            <span>Open Diary</span>
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L11.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 11-1.04-1.08l3.158-2.96H3.75A.75.75 0 013 10z" clip-rule="evenodd" /></svg>
                        </a>
                    </div>
                </div>

                <!-- Dashboard View Switcher: Today's Diary vs Revenue Analytics -->
                <div class="flex items-center gap-2 pt-6 border-t border-slate-100 mt-5">
                    <button type="button"
                            wire:click="$set('tab', 'overview')"
                            class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all {{ $tab === 'overview' ? 'bg-mulberry-700 text-white shadow-sm shadow-mulberry-900/20' : 'bg-slate-50 text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        Today's Schedule &amp; Actions
                    </button>
                    <button type="button"
                            wire:click="$set('tab', 'analytics')"
                            class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all flex items-center gap-1.5 {{ $tab === 'analytics' ? 'bg-mulberry-700 text-white shadow-sm shadow-mulberry-900/20' : 'bg-slate-50 text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <span>Performance Analytics</span>
                    </button>
                </div>
            </div>

            @if ($tab === 'overview')
                @if (! $setup['ready'])

                {{-- A grid of zeros is not a dashboard, it is a shrug. While the
                     essentials are missing, say what to do instead of showing them. --}}

                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4 animate-fade-in-up delay-1">
                    <div>
                        <h2 class="font-semibold text-gray-900">Two things and you can take a booking</h2>
                        <p class="text-sm text-gray-500">
                            Once these are in, this page shows your day, your takings and who needs reminding.
                        </p>
                    </div>

                    <ul class="space-y-3 text-sm">
                        <li class="flex flex-wrap items-center gap-2">
                            @if ($setup['services'] > 0)
                                <span class="inline-flex items-center justify-center h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </span>
                                <span class="text-gray-500">
                                    {{ $setup['services'] }}
                                    {{ $setup['services'] === 1 ? 'service' : 'services' }} added
                                </span>
                            @else
                                <span class="inline-flex items-center justify-center h-5 w-5 rounded-full bg-gray-100 text-gray-400 text-xs">&bull;</span>
                                <a href="{{ route('services.index') }}" wire:navigate
                                   class="font-medium text-mulberry-700 hover:text-mulberry-900">
                                    Add what you offer
                                </a>
                                <span class="text-gray-500">&mdash; name, how long, how much</span>
                            @endif
                        </li>

                        <li class="flex flex-wrap items-center gap-2">
                            @if ($setup['customers'] > 0)
                                <span class="inline-flex items-center justify-center h-5 w-5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </span>
                                <span class="text-gray-500">
                                    {{ $setup['customers'] }}
                                    {{ $setup['customers'] === 1 ? 'customer' : 'customers' }} on file
                                </span>
                            @else
                                <span class="inline-flex items-center justify-center h-5 w-5 rounded-full bg-gray-100 text-gray-400 text-xs">&bull;</span>
                                <a href="{{ route('customers.index') }}" wire:navigate
                                   class="font-medium text-mulberry-700 hover:text-mulberry-900">
                                    Add your first customer
                                </a>
                                <span class="text-gray-500">&mdash; a mobile number is enough</span>
                            @endif
                        </li>
                    </ul>

                    @if ($setup['staff'] === 0)
                        <p class="text-xs text-gray-500">
                            Working on your own? You can skip staff entirely and add them later.
                        </p>
                    @endif
                </div>

            @else

                {{-- ------------------------- The four numbers ------------------------- --}}

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                    <div class="stat-card stat-card--mulberry bg-white shadow-xs hover:shadow-md sm:rounded-2xl p-5 border border-slate-100 transition-all animate-fade-in-up delay-1">
                        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Today's Diary</div>
                        <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-slate-900">
                            {{ $today['live']->count() }}
                        </div>
                        <div class="text-xs text-slate-500 mt-1 font-medium">
                            {{ $today['live']->count() === 1 ? 'booking' : 'bookings' }},
                            {{ $money($today['expected']) }} expected
                        </div>
                        @if ($today['done']->isNotEmpty())
                            <div class="mt-3 flex items-center gap-1.5">
                                <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-mulberry-500 transition-all duration-500" style="width: {{ $today['live']->count() > 0 ? round($today['done']->count() / ($today['live']->count() + $today['done']->count()) * 100) : 100 }}%"></div>
                                </div>
                                <span class="text-xs font-semibold tabular-nums text-slate-600">{{ $today['done']->count() }} done</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ $money($today['takenSoFar']) }} taken
                            </div>
                        @endif
                    </div>

                    <div class="stat-card stat-card--sky bg-white shadow-xs hover:shadow-md sm:rounded-2xl p-5 border border-slate-100 transition-all animate-fade-in-up delay-2">
                        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Tomorrow's Queue</div>
                        <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-slate-900">
                            {{ $tomorrow['all']->count() }}
                        </div>
                        <div class="text-xs text-slate-500 mt-1 font-medium">
                            {{ $tomorrow['all']->count() === 1 ? 'booking' : 'bookings' }} to remind
                        </div>
                        @if ($tomorrow['unreachable']->isNotEmpty())
                            <div class="mt-2.5 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                {{ $tomorrow['unreachable']->count() }} unreachable
                            </div>
                        @endif
                    </div>

                    <div class="stat-card stat-card--emerald bg-white shadow-xs hover:shadow-md sm:rounded-2xl p-5 border border-slate-100 transition-all animate-fade-in-up delay-3">
                        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">{{ $month['label'] }} Revenue</div>
                        <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-emerald-700">
                            {{ $money($month['earned']) }}
                        </div>
                        <div class="text-xs text-slate-500 mt-1 font-medium">
                            from {{ $month['completed'] }} completed
                        </div>
                        @if ($month['booked'] > 0)
                            <div class="mt-1 text-xs text-slate-500">
                                {{ $money($month['booked']) }} still in the diary
                            </div>
                        @endif
                    </div>

                    <div class="stat-card stat-card--amber bg-white shadow-xs hover:shadow-md sm:rounded-2xl p-5 border border-slate-100 transition-all animate-fade-in-up delay-4">
                        <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">No-shows this month</div>
                        <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums {{ $month['noShows'] > 0 ? 'text-amber-700' : 'text-slate-900' }}">
                            {{ $month['noShows'] }}
                        </div>
                        <div class="text-xs text-slate-500 mt-1 font-medium">
                            {{ $money($month['noShowValue']) }} of empty chair
                        </div>
                        @if ($month['cancelled'] > 0)
                            <div class="mt-1 text-xs text-slate-500">
                                {{ $month['cancelled'] }} cancelled in advance
                            </div>
                        @endif
                    </div>

                </div>

                {{-- Rendered only when there is something to act on, so this page
                     never becomes a banner an owner learns to skip past. --}}
                @if (count($attention) > 0)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 animate-fade-in-up delay-5">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-amber-600 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                            </svg>
                            <p class="text-sm font-medium text-amber-900">Needs your attention</p>
                        </div>

                        <ul class="mt-2 space-y-1.5">
                            @foreach ($attention as $item)
                                <li wire:key="attention-{{ $loop->index }}"
                                    class="flex flex-wrap items-center gap-2 text-sm text-amber-800">
                                    <span>{{ $item['text'] }}</span>
                                    <a href="{{ route($item['route'], $item['params']) }}" wire:navigate
                                       class="font-medium text-amber-900 underline hover:no-underline">
                                         {{ $item['action'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- --------------------------- Today and tomorrow --------------------------- --}}

                <div class="grid gap-4 lg:grid-cols-3">

                    <div class="bg-white shadow-xs sm:rounded-2xl border border-slate-100 lg:col-span-2 overflow-hidden animate-fade-in-up delay-3">
                        <div class="flex items-center justify-between border-b border-slate-100 p-5">
                            <div>
                                <h2 class="font-bold text-slate-900 text-base">Today's Appointments</h2>
                                <p class="text-xs text-slate-500">Live order of visits for today</p>
                            </div>
                            <a href="{{ route('appointments.index', ['day' => $today['date']->toDateString()]) }}"
                               wire:navigate class="text-xs font-bold text-mulberry-700 hover:text-mulberry-900 transition-colors inline-flex items-center gap-1">
                                Full Diary &rarr;
                            </a>
                        </div>

                        @if ($today['next'])
                            <div class="border-b border-slate-100 p-4 bg-gradient-to-r from-mulberry-50/70 via-rose-50/30 to-white">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block h-2 w-2 rounded-full bg-mulberry-600 animate-pulse"></span>
                                    <span class="text-xs uppercase tracking-wider font-bold text-mulberry-800">Next Up Today</span>
                                </div>
                                <div class="mt-1.5 font-bold text-slate-900 text-base">
                                    {{ $at($today['next']->starts_at) }}
                                    &middot; {{ $today['next']->customer?->name ?? 'Customer removed' }}
                                </div>
                                <div class="text-xs text-slate-600 mt-0.5">
                                    {{ $today['next']->service?->name ?? 'No service set' }}
                                    @if ($today['next']->staffMember)
                                        with <span class="font-semibold text-slate-700">{{ $today['next']->staffMember->name }}</span>
                                    @endif
                                    &middot; {{ $today['next']->starts_at->diffForHumans() }}
                                </div>
                            </div>
                        @endif

                        <ul class="divide-y divide-slate-100 text-sm">
                            @forelse ($today['all'] as $appointment)
                                <li wire:key="today-{{ $appointment->id }}"
                                    class="appointment-card flex items-center justify-between gap-3 p-4 hover:bg-slate-50/60 transition-colors {{ $appointment->isCancelled() ? 'opacity-60' : '' }}"
                                    style="--staff-color: {{ $appointment->staffMember?->color ?? 'transparent' }}">
                                    <style>
                                        [wire\:key="today-{{ $appointment->id }}"]::before { background: var(--staff-color); }
                                    </style>

                                    <div class="flex min-w-0 items-baseline gap-3 pl-2">
                                        <span class="w-12 shrink-0 font-bold tabular-nums text-slate-900">
                                            {{ $at($appointment->starts_at) }}
                                        </span>

                                        <span class="min-w-0">
                                            <span class="font-bold text-slate-900">
                                                {{ $appointment->customer?->name ?? 'Customer removed' }}
                                            </span>
                                            <span class="text-slate-500 text-xs sm:text-sm">
                                                &middot; {{ $appointment->service?->name ?? 'No service set' }}
                                            </span>
                                        </span>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-3">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $appointment->isCancelled() ? 'bg-gray-100 text-gray-600' : ($appointment->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700') }}">
                                            {{ $appointment->statusLabel() }}
                                        </span>
                                        <span class="tabular-nums font-extrabold text-slate-900 text-sm">
                                            {{ $money($appointment->price) }}
                                        </span>
                                    </div>
                                </li>
                            @empty
                                <li class="px-4 py-12 text-center">
                                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 mb-3 shadow-xs ring-1 ring-rose-100 transition-transform duration-300 hover:scale-105">
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                        </svg>
                                    </div>
                                    <p class="text-slate-500 font-medium">
                                        Nothing booked today.
                                    </p>
                                    <a href="{{ route('appointments.index') }}" wire:navigate
                                       class="mt-2 inline-block text-xs font-bold text-mulberry-700 hover:text-mulberry-900">Add a booking &rarr;</a>
                                </li>
                            @endforelse
                        </ul>
                    </div>

                    <div class="space-y-4">

                        {{-- Named for what it can prove today: who is contactable. It becomes
                             "reminders sent" once messages:dispatch exists — until then this
                             page must not claim a message went out. --}}
                        <div class="bg-white shadow-xs sm:rounded-2xl border border-slate-100 overflow-hidden animate-fade-in-up delay-4">
                            <div class="border-b border-slate-100 p-5">
                                <h2 class="font-bold text-slate-900 text-base">Tomorrow's Reminders</h2>
                                <p class="text-xs text-slate-500">{{ $tomorrow['date']->format('l, j F') }}</p>
                            </div>

                            <div class="space-y-3 p-5 text-sm">
                                @if ($tomorrow['all']->isEmpty())
                                    <div class="text-center py-6">
                                        <div class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 mb-2 shadow-xs ring-1 ring-indigo-100 transition-transform duration-300 hover:scale-105">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                            </svg>
                                        </div>
                                        <p class="text-slate-500 text-xs font-medium">Nothing booked for tomorrow yet.</p>
                                    </div>
                                @else
                                    <p class="text-gray-700">
                                        <span class="font-semibold text-emerald-700">{{ $tomorrow['reachable']->count() }}</span>
                                        of {{ $tomorrow['all']->count() }} can be reached.
                                    </p>

                                    @if ($tomorrow['unreachable']->isNotEmpty())
                                        <div class="rounded-md bg-amber-50 p-3">
                                            <p class="font-medium text-amber-800">Needs a phone call</p>

                                            <ul class="mt-1 space-y-1 text-amber-900">
                                                @foreach ($tomorrow['unreachable'] as $appointment)
                                                    <li wire:key="unreachable-{{ $appointment->id }}">
                                                        {{ $at($appointment->starts_at) }}
                                                        &middot;
                                                        {{ $appointment->customer?->name ?? 'Customer removed' }}
                                                        <span class="text-xs">
                                                             ({{ $appointment->customer?->unreachableReason() ?? 'customer removed' }})
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>

                                            <a href="{{ route('customers.index') }}" wire:navigate
                                               class="mt-2 inline-block font-medium text-amber-900 underline hover:no-underline">
                                                Fix their details
                                            </a>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- Win-back. Two numbers, because they lead somewhere different:
                             one is a campaign you can run today, the other is a reason to
                             start asking for consent at the till. --}}
                        <div class="bg-white shadow-xs sm:rounded-2xl border border-slate-100 animate-fade-in-up delay-5 overflow-hidden">
                            <div class="border-b border-gray-100 p-4 bg-gradient-to-r from-white to-mulberry-50/30">
                                <h2 class="font-semibold text-gray-900">Not seen in 90 days</h2>
                            </div>

                            <div class="space-y-2 p-4 text-sm">
                                <div class="text-2xl font-semibold tabular-nums text-gray-900">
                                    {{ $lapsed['total'] }}
                                </div>

                                @if ($lapsed['total'] === 0)
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 shrink-0">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </span>
                                        <p class="text-gray-500">Everyone has been in recently.</p>
                                    </div>
                                @else
                                    <p class="text-gray-500">
                                        {{ $lapsed['contactable'] }} you may message and can reach.
                                    </p>

                                    @if ($lapsed['marketable'] > $lapsed['contactable'])
                                        <p class="text-xs text-gray-500">
                                            {{ $lapsed['marketable'] - $lapsed['contactable'] }}
                                            agreed to marketing but have no working contact details.
                                        </p>
                                    @endif

                                    @if ($lapsed['total'] > $lapsed['marketable'])
                                        <p class="text-xs text-gray-500">
                                            {{ $lapsed['total'] - $lapsed['marketable'] }}
                                            have not agreed to marketing, so they must not be included.
                                        </p>
                                    @endif

                                    <a href="{{ route('customers.index', ['filter' => 'lapsed']) }}" wire:navigate
                                       class="inline-flex items-center gap-1 font-medium text-mulberry-700 hover:text-mulberry-900 transition-colors">
                                        See who
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L11.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 11-1.04-1.08l3.158-2.96H3.75A.75.75 0 013 10z" clip-rule="evenodd" /></svg>
                                    </a>
                                @endif
                            </div>
                        </div>

                    </div>
                </div>

            @endif

            @else
                <!-- EXECUTIVE REVENUE & PERFORMANCE ANALYTICS VIEW -->
                <div class="space-y-6 animate-fade-in-up">
                    <!-- 4 Executive KPI Cards -->
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="stat-card stat-card--emerald bg-white shadow-sm sm:rounded-2xl p-5 border border-slate-100">
                            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Realized Revenue</div>
                            <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-emerald-700">
                                {{ $money($analytics['kpis']['totalRevenue']) }}
                            </div>
                            <div class="text-xs text-slate-500 mt-1 font-medium">
                                Across {{ $analytics['kpis']['totalCompleted'] }} completed {{ $analytics['kpis']['totalCompleted'] === 1 ? 'visit' : 'visits' }}
                            </div>
                        </div>

                        <div class="stat-card stat-card--mulberry bg-white shadow-sm sm:rounded-2xl p-5 border border-slate-100">
                            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Avg. Ticket Size (AOV)</div>
                            <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-slate-900">
                                {{ $money($analytics['kpis']['aov']) }}
                            </div>
                            <div class="text-xs text-slate-500 mt-1 font-medium">
                                Average spend per completed client
                            </div>
                        </div>

                        <div class="stat-card stat-card--sky bg-white shadow-sm sm:rounded-2xl p-5 border border-slate-100">
                            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Client Retention Rate</div>
                            <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-sky-700">
                                {{ $analytics['kpis']['retentionRate'] }}%
                            </div>
                            <div class="text-xs text-slate-500 mt-1 font-medium">
                                {{ $analytics['kpis']['repeatCustomers'] }} clients with 2+ bookings
                            </div>
                        </div>

                        <div class="stat-card stat-card--amber bg-white shadow-sm sm:rounded-2xl p-5 border border-slate-100">
                            <div class="text-xs uppercase tracking-wider text-slate-500 font-bold">Online Self-Booking Share</div>
                            <div class="mt-1.5 text-2xl sm:text-3xl font-extrabold tabular-nums text-amber-700">
                                {{ $analytics['kpis']['onlineShare'] }}%
                            </div>
                            <div class="text-xs text-slate-500 mt-1 font-medium">
                                {{ $analytics['kpis']['onlineBookings'] }} booked via client portal
                            </div>
                        </div>
                    </div>

                    <!-- Weekly Revenue Bar Chart Card -->
                    <div class="bg-white shadow-sm sm:rounded-2xl border border-slate-200/80 p-6 sm:p-7">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-5 border-b border-slate-100">
                            <div>
                                <h3 class="text-base sm:text-lg font-bold text-slate-900">Weekly Revenue Velocity</h3>
                                <p class="text-xs text-slate-500">Daily earnings and appointment volume over the last 7 days.</p>
                            </div>
                            <div class="text-left sm:text-right">
                                <div class="text-xs text-slate-400 font-medium">7-Day Total Takings</div>
                                <div class="text-xl sm:text-2xl font-black text-rose-600">
                                    {{ $money($analytics['weekly']['totalRevenue']) }}
                                </div>
                            </div>
                        </div>

                        <!-- 7-Bar Chart Container -->
                        <div class="pt-6 pb-2">
                            <div class="grid grid-cols-7 gap-2 sm:gap-4 items-end h-56 sm:h-64 px-1">
                                @foreach ($analytics['weekly']['days'] as $day)
                                    <div class="flex flex-col items-center justify-end h-full gap-2 group">
                                        <!-- Price Tag on top of bar -->
                                        <div class="text-[11px] sm:text-xs font-bold tabular-nums {{ $day['revenue'] > 0 ? 'text-slate-900' : 'text-slate-400' }} transition-transform group-hover:scale-110">
                                            {{ $day['revenue'] > 0 ? '£'.number_format($day['revenue'], 0) : '£0' }}
                                        </div>

                                        <!-- Bar with dynamic height -->
                                        <div class="w-full max-w-[48px] rounded-t-xl transition-all duration-500 {{ $day['isToday'] ? 'bg-gradient-to-t from-rose-600 to-rose-400 ring-2 ring-rose-400 shadow-lg shadow-rose-600/20' : ($day['revenue'] > 0 ? 'bg-gradient-to-t from-mulberry-700 to-mulberry-500 hover:from-mulberry-800 hover:to-mulberry-600' : 'bg-slate-100 hover:bg-slate-200') }}"
                                             style="height: {{ $day['heightPct'] }}%;">
                                        </div>

                                        <!-- Day Label -->
                                        <div class="text-center pt-1">
                                            <div class="text-xs font-bold {{ $day['isToday'] ? 'text-rose-600' : 'text-slate-700' }}">
                                                {{ $day['day'] }}
                                            </div>
                                            <div class="text-[10px] text-slate-400">
                                                {{ $day['date'] }}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Two Columns: Top Services & Staff Leaderboard -->
                    <div class="grid gap-6 lg:grid-cols-2">
                        <!-- Top Services Leaderboard -->
                        <div class="bg-white shadow-sm sm:rounded-2xl border border-slate-200/80 p-6">
                            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                                <h3 class="font-bold text-slate-900 text-base">Top Performing Services</h3>
                                <a href="{{ route('services.index') }}" wire:navigate class="text-xs font-semibold text-rose-600 hover:underline">
                                    Manage &rarr;
                                </a>
                            </div>

                            <div class="space-y-4">
                                @forelse ($analytics['topServices'] as $svc)
                                    <div class="space-y-1.5">
                                        <div class="flex items-center justify-between text-xs sm:text-sm">
                                            <span class="font-bold text-slate-900 flex items-center gap-2">
                                                <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-extrabold flex items-center justify-center">
                                                    {{ $loop->iteration }}
                                                </span>
                                                {{ $svc['name'] }}
                                            </span>
                                            <div class="text-right tabular-nums">
                                                <span class="font-extrabold text-slate-900">{{ $money($svc['revenue']) }}</span>
                                                <span class="text-slate-400 text-xs font-normal">({{ $svc['count'] }} {{ $svc['count'] === 1 ? 'sale' : 'sales' }})</span>
                                            </div>
                                        </div>
                                        <div class="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-mulberry-600 to-rose-500 rounded-full transition-all duration-500"
                                                 style="width: {{ $svc['share'] }}%;"></div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-10 text-center text-slate-400 text-sm">
                                        No completed bookings yet to calculate top services.
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <!-- Staff Specialist Leaderboard -->
                        <div class="bg-white shadow-sm sm:rounded-2xl border border-slate-200/80 p-6">
                            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                                <h3 class="font-bold text-slate-900 text-base">Staff Earnings & Productivity</h3>
                                <a href="{{ route('staff.index') }}" wire:navigate class="text-xs font-semibold text-rose-600 hover:underline">
                                    View Staff &rarr;
                                </a>
                            </div>

                            <div class="space-y-3">
                                @forelse ($analytics['staffLeaderboard'] as $staff)
                                    <div class="p-3.5 rounded-xl border border-slate-100 hover:bg-slate-50/60 transition-colors flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full text-white font-bold flex items-center justify-center text-sm shadow-sm"
                                                 style="background-color: {{ $staff['color'] }};">
                                                {{ substr($staff['name'], 0, 1) }}
                                            </div>
                                            <div>
                                                <h4 class="font-bold text-slate-900 text-sm">{{ $staff['name'] }}</h4>
                                                <p class="text-xs text-slate-500">{{ $staff['count'] }} completed {{ $staff['count'] === 1 ? 'booking' : 'bookings' }}</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-extrabold text-slate-900 text-sm tabular-nums">
                                                {{ $money($staff['revenue']) }}
                                            </div>
                                            <div class="text-[10px] text-slate-400 font-medium">Earned</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-10 text-center text-slate-400 text-sm">
                                        No staff members active or assigned.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        @endif

    </div>
</div>
