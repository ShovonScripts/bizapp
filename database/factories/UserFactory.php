<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * IMPORTANT: business_id defaults to a real Business, NOT null.
     *
     * In this app business_id = null means SUPER-ADMIN, who bypasses every
     * tenant scope. If the factory left it null, a test like
     * "user A cannot see business B's customers" would pass for the wrong
     * reason — the user would be an admin who legitimately sees everything.
     * A tenant-isolation test that cannot fail is worse than no test.
     *
     * Use ->superAdmin() when you actually want an unscoped user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'owner',
        ];
    }

    /** Unscoped user — sees across all businesses. */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'business_id' => null,
            'role' => 'admin',
        ]);
    }

    public function for_business(Business $business): static
    {
        return $this->state(fn (array $attributes) => [
            'business_id' => $business->id,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
