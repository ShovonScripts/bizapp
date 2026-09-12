<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $business_name = '';
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     *
     * [SECURITY] WHY THIS IS NOT THE STOCK BREEZE VERSION.
     *
     * Breeze ships `User::create($validated)` with no business_id. In this app
     * business_id = null means SUPER-ADMIN — a user who bypasses every tenant
     * scope and can read every client's customer records. Left as-is, anyone who
     * found /register on the live site would get exactly that. Under UK GDPR
     * that is a reportable personal-data breach, not merely a bug.
     *
     * So registration always creates a Business first and attaches the user to
     * it as 'owner'. There is no code path here that produces a super-admin;
     * those are made deliberately in tinker.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        // One transaction: a user without a business, or a business without an
        // owner, are both broken states. Better to create neither than one.
        $user = DB::transaction(function () use ($validated) {
            $business = Business::create([
                'name' => $validated['business_name'],
                'slug' => Business::uniqueSlug($validated['business_name']),
                'niche' => 'salon',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);

            return User::create([
                'business_id' => $business->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'owner',
            ]);
        });

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <form wire:submit="register">
        <!-- Business Name -->
        <div>
            <x-input-label for="business_name" :value="__('Business name')" />
            <x-text-input wire:model="business_name" id="business_name" class="block mt-1 w-full" type="text" name="business_name" required autofocus autocomplete="organization" />
            <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
        </div>

        <!-- Name -->
        <div class="mt-4">
            <x-input-label for="name" :value="__('Your name')" />
            <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-mulberry-600" href="{{ route('login') }}" wire:navigate>
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</div>
