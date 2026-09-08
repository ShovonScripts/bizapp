<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffMember>
 */
class StaffMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => null,   // most salon staff never log in
            'name' => fake()->firstName(),
            'phone' => '+447'.fake()->unique()->numerify('#########'),
            'color' => fake()->randomElement(['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6']),
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
