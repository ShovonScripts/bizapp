<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Must be explicit. BelongsToBusiness auto-fills business_id from
            // Tenant::id(), which is null outside a request — so without this the
            // insert fails on a NOT NULL constraint.
            'business_id' => Business::factory(),
            'name' => fake()->name(),
            // unique() keeps the (business_id, phone) index happy across a run.
            'phone' => '+447'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'preferred_channel' => 'telegram',
            'marketing_consent' => false,
            'total_spend' => 0,
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }

    /** Consented to marketing — required before any win-back message may be sent. */
    public function marketable(): static
    {
        return $this->state(fn () => [
            'marketing_consent' => true,
            'consent_at' => now()->subMonths(2),
            'consent_source' => 'booking_form',
            'unsubscribed_at' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => [
            'marketing_consent' => false,
            'unsubscribed_at' => now()->subWeek(),
        ]);
    }

    /** Already started the Telegram bot, so we can actually message them. */
    public function telegramLinked(): static
    {
        return $this->state(fn () => [
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
        ]);
    }

    /** Win-back candidate. */
    public function lapsed(int $days = 120): static
    {
        return $this->state(fn () => [
            'last_visit_at' => now()->subDays($days),
            'total_spend' => fake()->randomFloat(2, 30, 400),
        ]);
    }
}
