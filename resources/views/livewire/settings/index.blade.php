<?php

use App\Livewire\Concerns\TenantScreen;
use App\Models\Business;
use App\Support\Phone;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Business Settings')] class extends Component
{
    use TenantScreen;

    // Profile & Public Info
    public string $name = '';
    public string $slug = '';
    public ?string $niche = 'Hair Salon';
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $address = null;
    public string $timezone = 'Europe/London';
    public string $currency = 'GBP';

    // Operating Hours & Booking
    public string $opening_hours_from = '09:00';
    public string $opening_hours_to = '18:00';
    public array $opening_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    public int $slot_interval_minutes = 30;

    // Messaging & Quiet Hours
    public string $default_channel = 'telegram';
    public string $quiet_hours_from = '21:00';
    public string $quiet_hours_to = '08:00';

    // Deposit & Stripe Payment Policy
    public bool $deposit_enabled = false;
    public string $deposit_type = 'percentage'; // 'percentage' | 'fixed' | 'full'
    public string $deposit_value = '20.00';
    public ?string $stripe_secret_key = null;
    public ?string $stripe_publishable_key = null;
    public ?string $stripe_webhook_secret = null;
    public bool $stripe_test_mode = true;

    /* Whether a secret is already stored - never the secret itself. */
    public bool $stripe_secret_key_set = false;
    public bool $stripe_webhook_secret_set = false;

    public function mount(): void
    {
        $this->guardTenant();
        $business = $this->business();

        $this->name = $business->name;
        $this->slug = $business->slug ?: Str::slug($business->name);
        $this->niche = $business->niche ?: 'Hair Salon';
        $this->phone = Phone::forHumans($business->phone) ?? $business->phone;
        $this->email = $business->email;
        $this->address = $business->address;
        $this->timezone = $business->timezone ?: 'Europe/London';
        $this->currency = $business->currency ?: 'GBP';

        $this->opening_hours_from = $business->setting('opening_hours.from', '09:00');
        $this->opening_hours_to = $business->setting('opening_hours.to', '18:00');
        $this->opening_days = $business->setting('opening_days', ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']);
        $this->slot_interval_minutes = (int) $business->setting('slot_interval_minutes', 30);

        $this->default_channel = $business->defaultChannel();
        $quiet = $business->quietHours();
        $this->quiet_hours_from = $quiet['from'] ?? '21:00';
        $this->quiet_hours_to = $quiet['to'] ?? '08:00';

        $this->deposit_enabled = $business->depositEnabled();
        $this->deposit_type = $business->depositType();
        $this->deposit_value = (string) $business->depositValue();
        $stripe = $business->stripeConfig();
        /*
         * Publishable key is public by design, so it can round-trip to the
         * browser. The secret key and webhook secret must NOT: Livewire
         * serialises every public property into the wire:snapshot rendered in
         * the page, so hydrating them here would ship the decrypted secret to
         * the client on every load. Leave them blank and treat blank on save
         * as "keep what is stored".
         */
        $this->stripe_publishable_key = $stripe['publishable_key'];
        $this->stripe_secret_key = null;
        $this->stripe_webhook_secret = null;
        $this->stripe_secret_key_set = filled($stripe['secret_key']);
        $this->stripe_webhook_secret_set = filled($stripe['webhook_secret']);
        $this->stripe_test_mode = $stripe['test_mode'];
    }

    public function toggleDay(string $day): void
    {
        if (in_array($day, $this->opening_days, true)) {
            $this->opening_days = array_values(array_diff($this->opening_days, [$day]));
        } else {
            $this->opening_days[] = $day;
        }
    }

    public function save(): void
    {
        $business = $this->business();

        $rawPhone = trim((string) $this->phone);
        $normalizedPhone = $rawPhone === '' ? null : (Phone::normalise($rawPhone) ?? $rawPhone);
        $this->phone = $normalizedPhone;

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('businesses', 'slug')->ignore($business->id)],
            'niche' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (filled($value) && ! Phone::looksValid($value)) {
                    $fail('Please enter a valid phone number (e.g. 07700 900123).');
                }
            }],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'opening_hours_from' => ['required', 'string'],
            'opening_hours_to' => ['required', 'string'],
            'opening_days' => ['required', 'array', 'min:1'],
            'slot_interval_minutes' => ['required', 'integer', 'in:15,30,45,60'],
            'default_channel' => ['required', 'string', 'in:telegram,whatsapp,sms,email'],
            'quiet_hours_from' => ['required', 'string'],
            'quiet_hours_to' => ['required', 'string'],
        ]);

        $business->name = $this->name;
        $business->slug = Str::slug($this->slug);
        $business->niche = $this->niche;
        $business->phone = $normalizedPhone;
        $business->email = $this->email;
        $business->address = $this->address;
        $business->timezone = $this->timezone;
        $business->currency = $this->currency;

        $settings = $business->settings ?? [];
        $settings['opening_hours'] = [
            'from' => substr(trim($this->opening_hours_from), 0, 5),
            'to' => substr(trim($this->opening_hours_to), 0, 5),
        ];
        $settings['opening_days'] = $this->opening_days;
        $settings['slot_interval_minutes'] = (int) $this->slot_interval_minutes;
        $settings['default_channel'] = $this->default_channel;
        $settings['quiet_hours'] = [
            'from' => substr(trim($this->quiet_hours_from), 0, 5),
            'to' => substr(trim($this->quiet_hours_to), 0, 5),
        ];
        $settings['deposit'] = [
            'enabled' => (bool) $this->deposit_enabled,
            'type' => $this->deposit_type,
            'value' => (float) $this->deposit_value,
        ];

        $business->settings = $settings;
        $business->save();

        $this->upsertStripeChannel();

        $this->phone = Phone::forHumans($normalizedPhone) ?? $normalizedPhone;
        $this->toast('Business settings updated successfully.');
    }

    protected function upsertStripeChannel(): void
    {
        $business = $this->business();
        $secretKey = trim((string) $this->stripe_secret_key);
        $publishableKey = trim((string) $this->stripe_publishable_key);
        $webhookSecret = trim((string) $this->stripe_webhook_secret);

        $connection = $business->channelConnections()
            ->where('channel', 'stripe')
            ->first();

        /*
         * The secret fields arrive blank on every page load by design (mount
         * does not hydrate them), so blank means "leave the stored value
         * alone" - NOT "clear it". Without this, saving an unrelated field
         * like the phone number would wipe a working Stripe connection.
         */
        $storedSecret = (string) ($connection?->credential('secret_key') ?? '');
        $storedWebhook = (string) ($connection?->credential('webhook_secret') ?? '');

        $effectiveSecret = $secretKey !== '' ? $secretKey : $storedSecret;
        $effectiveWebhook = $webhookSecret !== '' ? $webhookSecret : $storedWebhook;

        if ($effectiveSecret === '' && $publishableKey === '' && $effectiveWebhook === '') {
            if ($connection) {
                $connection->status = \App\Models\ChannelConnection::FAILED;
                $connection->save();
            }

            $this->stripe_secret_key_set = false;
            $this->stripe_webhook_secret_set = false;

            return;
        }

        if (! $connection) {
            $connection = new \App\Models\ChannelConnection();
            $connection->business_id = $business->id;
            $connection->channel = 'stripe';
        }

        $connection->credentials = [
            'secret_key' => $effectiveSecret ?: null,
            'publishable_key' => $publishableKey ?: null,
            'webhook_secret' => $effectiveWebhook ?: null,
        ];
        $connection->meta = [
            'test_mode' => (bool) $this->stripe_test_mode,
        ];
        $connection->status = \App\Models\ChannelConnection::ACTIVE;
        $connection->save();

        /* Clear the inputs; keep only the "is set" signal. */
        $this->stripe_secret_key = null;
        $this->stripe_webhook_secret = null;
        $this->stripe_secret_key_set = filled($effectiveSecret);
        $this->stripe_webhook_secret_set = filled($effectiveWebhook);
    }
}; ?>

<div class="py-8 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto" x-data="{ toast: false, toastMessage: '' }" @toast.window="toastMessage = $event.detail.message; toast = true; setTimeout(() => toast = false, 3000)">
    <!-- Toast Notification Banner -->
    <div x-show="toast"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         style="display: none;"
         class="fixed top-5 right-5 z-50 flex items-center gap-2 px-4 py-3 rounded-xl bg-emerald-600 text-white font-medium text-sm shadow-xl shadow-emerald-900/20">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <span x-text="toastMessage"></span>
    </div>

    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                    Business Settings
                </h1>
                <p class="text-sm text-gray-500 mt-1">
                    Configure your public booking portal, opening hours, and automated messaging rules.
                </p>
            </div>
        <div>
            <button type="button"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-mulberry-700 hover:bg-mulberry-800 text-white text-sm font-semibold shadow-sm transition-all">
                <span wire:loading.remove>Save Changes</span>
                <span wire:loading class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Saving...
                </span>
            </button>
        </div>
    </div>

    <form wire:submit="save" class="space-y-8">
        <!-- SECTION 1: BUSINESS PROFILE & PUBLIC BRANDING -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sm:p-8">
            <div class="flex items-center gap-3 pb-4 border-b border-gray-100 mb-6">
                <div class="w-10 h-10 rounded-xl bg-mulberry-50 text-mulberry-700 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900">Profile & Public Booking URL</h2>
                    <p class="text-xs text-gray-500">Your salon brand name, contact numbers, and online booking link.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Business Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           wire:model="name"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Business Niche / Category
                    </label>
                    <select wire:model="niche"
                            class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600">
                        <option value="Hair Salon">Hair Salon</option>
                        <option value="Barbershop">Barbershop</option>
                        <option value="Nail Studio">Nail Studio</option>
                        <option value="Spa & Wellness">Spa & Wellness</option>
                        <option value="Beauty Clinic">Beauty Clinic</option>
                        <option value="Massage Therapy">Massage Therapy</option>
                        <option value="Tattoo & Piercing">Tattoo & Piercing</option>
                        <option value="Other">Other Services</option>
                    </select>
                    @error('niche') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <!-- Public Slug with Live Link Preview -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Online Booking URL Slug <span class="text-red-500">*</span>
                    </label>
                    <div class="flex rounded-xl shadow-sm">
                        <span class="inline-flex items-center px-3.5 rounded-l-xl border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-xs sm:text-sm font-medium">
                            {{ url('/book') }}/
                        </span>
                        <input type="text"
                               wire:model="slug"
                               placeholder="your-salon-name"
                               class="flex-1 min-w-0 block w-full px-3.5 py-2.5 rounded-none rounded-r-xl border border-gray-300 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    </div>
                    <div class="flex items-center justify-between mt-1.5">
                        <p class="text-[11px] text-gray-500">Clients use this link to self-book online.</p>
                        @if($slug)
                            <a href="{{ url('/book/'.$slug) }}" target="_blank" class="text-xs font-semibold text-mulberry-700 hover:text-mulberry-900 inline-flex items-center gap-1">
                                Open Booking Page &rarr;
                            </a>
                        @endif
                    </div>
                    @error('slug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Contact Phone Number
                    </label>
                    <input type="tel"
                           wire:model="phone"
                           placeholder="07700 900123"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    <p class="text-[11px] text-gray-500 mt-1">Displayed on customer booking confirmations and receipts.</p>
                    @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Contact Email
                    </label>
                    <input type="email"
                           wire:model="email"
                           placeholder="salon@example.com"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Physical Salon Address
                    </label>
                    <textarea wire:model="address"
                              rows="2"
                              placeholder="123 High Street, London, EC1A 1BB"
                              class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm focus:border-mulberry-600 focus:ring-mulberry-600"></textarea>
                    @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- SECTION 2: OPERATING HOURS & ONLINE BOOKING -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sm:p-8">
            <div class="flex items-center gap-3 pb-4 border-b border-gray-100 mb-6">
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900">Operating Hours & Booking Schedule</h2>
                    <p class="text-xs text-gray-500">Defines when your doors are open and available time slots on the booking portal.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Daily Opening Time
                    </label>
                    <input type="time"
                           wire:model="opening_hours_from"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    @error('opening_hours_from') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Daily Closing Time
                    </label>
                    <input type="time"
                           wire:model="opening_hours_to"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    @error('opening_hours_to') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <!-- Working Days Checkbox Pills -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">
                        Active Working Days
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @php
                            $days = [
                                'mon' => 'Monday',
                                'tue' => 'Tuesday',
                                'wed' => 'Wednesday',
                                'thu' => 'Thursday',
                                'fri' => 'Friday',
                                'sat' => 'Saturday',
                                'sun' => 'Sunday',
                            ];
                        @endphp
                        @foreach($days as $code => $label)
                            @php
                                $isActive = in_array($code, $opening_days, true);
                            @endphp
                            <button type="button"
                                    wire:click="toggleDay('{{ $code }}')"
                                    class="px-4 py-2 rounded-xl text-xs font-bold border transition-all {{ $isActive ? 'bg-amber-500 border-amber-600 text-white shadow-sm' : 'bg-gray-50 border-gray-200 text-gray-600 hover:bg-gray-100' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @error('opening_days') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Time Slot Grid Interval
                    </label>
                    <select wire:model="slot_interval_minutes"
                            class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600">
                        <option value="15">15 minutes (e.g. 10:00, 10:15, 10:30)</option>
                        <option value="30">30 minutes (e.g. 10:00, 10:30, 11:00)</option>
                        <option value="45">45 minutes</option>
                        <option value="60">60 minutes (Hourly)</option>
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1">Controls the gap between selectable start times on the public portal.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Timezone
                    </label>
                    <input type="text"
                           wire:model="timezone"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    <p class="text-[11px] text-gray-500 mt-1">Default: Europe/London. Keeps reminders on time during daylight savings.</p>
                </div>
            </div>
        </div>

        <!-- SECTION 3: MESSAGING & AUTOMATED REMINDERS -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sm:p-8">
            <div class="flex items-center gap-3 pb-4 border-b border-gray-100 mb-6">
                <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900">Messaging & Quiet Hours</h2>
                    <p class="text-xs text-gray-500">Manage 24h reminder rules and safeguard clients from late-night notifications.</p>
                </div>
            </div>

            <!-- Telegram Platform Bot Status Card -->
            <div class="mb-6 p-4 rounded-xl bg-sky-50/60 border border-sky-200/80 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-sky-500 text-white flex items-center justify-center font-bold shrink-0">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-xs sm:text-sm font-bold text-sky-900">Telegram Platform Bot Connected</h4>
                        <p class="text-xs text-sky-700 mt-0.5">Active Bot: <span class="font-mono font-semibold">@{{ config('messaging.telegram.bot_username', 'auto_jis_bot') }}</span></p>
                    </div>
                </div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                    Active &bull; Ready
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Default Preferred Channel for New Customers
                    </label>
                    <select wire:model="default_channel"
                            class="w-full sm:w-1/2 rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600">
                        <option value="telegram">Telegram (Automated 24h Bot Reminders)</option>
                        <option value="whatsapp">WhatsApp (1-Tap Direct & Cloud)</option>
                        <option value="sms">SMS</option>
                        <option value="email">Email</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Quiet Hours Start (Evening)
                    </label>
                    <input type="time"
                           wire:model="quiet_hours_from"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    <p class="text-[11px] text-gray-500 mt-1">Default 21:00 (9:00 PM). Automated messages stop sending.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                        Quiet Hours End (Morning)
                    </label>
                    <input type="time"
                           wire:model="quiet_hours_to"
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                    <p class="text-[11px] text-gray-500 mt-1">Default 08:00 (8:00 AM). Dispatching resumes safely in the morning.</p>
                </div>
            </div>
        </div>

        <!-- Section 4: Payments & Deposit Policy -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 sm:p-7">
            <div class="flex items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Payments & Deposit Policy</h2>
                        <p class="text-xs text-gray-500">Require an upfront card deposit during online booking to eliminate no-shows.</p>
                    </div>
                </div>

                <!-- Enable Toggle -->
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model.live="deposit_enabled" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
            </div>

            @if($deposit_enabled)
                <div class="p-4 rounded-xl bg-emerald-50/60 border border-emerald-200/80 mb-6 flex items-start gap-3">
                    <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <h4 class="text-xs sm:text-sm font-bold text-emerald-950">Deposit Protection Active</h4>
                        <p class="text-xs text-emerald-800 mt-0.5">
                            Clients booking through your public portal must pay the specified deposit upfront. Remaining balance is settled in-salon upon service completion.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                            Deposit Calculation Type
                        </label>
                        <select wire:model.live="deposit_type"
                                class="w-full rounded-xl border border-gray-300 px-3.5 py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600">
                            <option value="percentage">Percentage of Service Price (%)</option>
                            <option value="fixed">Fixed Monetary Amount (&pound;)</option>
                            <option value="full">Full Upfront Payment (100%)</option>
                        </select>
                    </div>

                    @if($deposit_type !== 'full')
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
                                {{ $deposit_type === 'percentage' ? 'Deposit Percentage (%)' : 'Deposit Amount (£)' }}
                            </label>
                            <div class="relative rounded-xl shadow-sm">
                                @if($deposit_type === 'fixed')
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                        <span class="text-gray-500 sm:text-sm font-bold">&pound;</span>
                                    </div>
                                @endif
                                <input type="number"
                                       step="0.01"
                                       wire:model="deposit_value"
                                       class="w-full rounded-xl border border-gray-300 {{ $deposit_type === 'fixed' ? 'pl-8' : 'px-3.5' }} py-2.5 text-sm focus:border-mulberry-600 focus:ring-mulberry-600" />
                                @if($deposit_type === 'percentage')
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 sm:text-sm font-bold">%</span>
                                    </div>
                                @endif
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1">
                                {{ $deposit_type === 'percentage' ? 'e.g. 20% deposit on a £50 service is £10.00' : 'e.g. £15 flat deposit for all services' }}
                            </p>
                        </div>
                    @endif
                </div>

                <!-- Stripe Gateway Credentials -->
                <div class="pt-5 border-t border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">
                            Stripe Gateway Connection
                        </h4>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600 cursor-pointer">
                            <input type="checkbox" wire:model="stripe_test_mode" class="rounded border-gray-300 text-mulberry-700 focus:ring-mulberry-600">
                            <span>Sandbox / Test Mode</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 mb-1">
                                Stripe Publishable Key (pk_...)
                            </label>
                            <input type="text"
                                   wire:model="stripe_publishable_key"
                                   placeholder="pk_test_..."
                                   class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-xs font-mono focus:border-mulberry-600 focus:ring-mulberry-600" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 mb-1">
                                Stripe Secret Key (sk_...)
                            </label>
                            <input type="password"
                               wire:model="stripe_secret_key"
                               autocomplete="new-password"
                               @if ($stripe_secret_key_set) placeholder="Saved - leave blank to keep" @else placeholder="sk_test_..." @endif
                               class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-xs font-mono focus:border-mulberry-600 focus:ring-mulberry-600" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">
                            Stripe Webhook Signing Secret (whsec_...)
                        </label>
                        <input type="password"
                           wire:model="stripe_webhook_secret"
                           autocomplete="new-password"
                           @if ($stripe_webhook_secret_set) placeholder="Saved - leave blank to keep" @else placeholder="whsec_..." @endif
                           class="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-xs font-mono focus:border-mulberry-600 focus:ring-mulberry-600" />
                        <p class="text-[11px] text-gray-400 mt-1">
                            Stripe shows this once when you create the webhook endpoint. Without it,
                            paid deposits cannot be confirmed.
                        </p>
                    </div>

                    <p class="text-[11px] text-gray-400 mt-3">
                        Saved keys are stored encrypted and are never shown again. Leave a field
                        blank to keep the key already saved. Leave all of them blank to run in
                        simulated test mode without real card charges.
                    </p>
                </div>
            @else
                <div class="text-xs text-gray-500 bg-gray-50 p-3.5 rounded-xl border border-dashed border-gray-200">
                    Deposit requirement is currently <strong>disabled</strong>. Customers can self-book appointments online without paying an upfront fee.
                </div>
            @endif
        </div>

        <!-- Sticky Submit Bar -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-mulberry-700 hover:bg-mulberry-800 text-white font-semibold text-sm shadow-sm transition-all">
                <span wire:loading.remove>Save All Settings</span>
                <span wire:loading class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Saving Changes...
                </span>
            </button>
        </div>
    </form>
</div>
