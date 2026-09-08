<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates one demo salon plus a super-admin, so you can log in immediately
 * and so tenant scoping has something to actually scope.
 *
 * Run:  php artisan db:seed --class=DemoBusinessSeeder
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Super-admin: business_id stays null, so they are not scoped to anyone.
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'business_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        $salon = Business::updateOrCreate(
            ['slug' => 'demo-salon'],
            [
                'name' => 'Demo Hair Studio',
                'niche' => 'salon',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'phone' => '+441234567890',
                'email' => 'hello@demosalon.test',
                'address' => "12 High Street\nManchester\nM1 1AA",
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
                'settings' => [
                    'quiet_hours' => ['from' => '21:00', 'to' => '08:00'],
                    'default_channel' => 'telegram',
                    'invoice_prefix' => 'DHS',
                ],
            ]
        );

        User::updateOrCreate(
            ['email' => 'owner@demosalon.test'],
            [
                'business_id' => $salon->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ]
        );

        // A second business exists only so you can prove isolation works:
        // log in as owner@demosalon.test and confirm you cannot see this one's data.
        $gym = Business::updateOrCreate(
            ['slug' => 'demo-gym'],
            [
                'name' => 'Demo Fitness',
                'niche' => 'gym',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]
        );

        User::updateOrCreate(
            ['email' => 'owner@demogym.test'],
            [
                'business_id' => $gym->id,
                'name' => 'Gym Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ]
        );

        $this->command->info('Seeded 2 businesses + 3 users. Password for all: password');
    }
}
