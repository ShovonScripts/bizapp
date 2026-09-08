<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            // Random suffix rather than Business::uniqueSlug(): factories run in
            // tight loops and a SELECT-then-INSERT check would both be slow and
            // still racy. A collision here is astronomically unlikely.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'niche' => 'salon',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'phone' => '+447'.fake()->numerify('#########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->streetAddress()."\n".fake()->city()."\n".fake()->postcode(),
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
            'settings' => [
                'quiet_hours' => ['from' => '21:00', 'to' => '08:00'],
                'default_channel' => 'telegram',
            ],
        ];
    }

    public function onPaidPlan(): static
    {
        return $this->state(fn () => [
            'subscription_status' => 'active',
            'trial_ends_at' => null,
        ]);
    }

    /** Useful for testing the GMT/BST boundary against a non-UK tenant. */
    public function inTimezone(string $timezone): static
    {
        return $this->state(fn () => ['timezone' => $timezone]);
    }
}
