<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->randomElement([
                'Cut & Blow Dry', 'Gents Cut', 'Beard Trim', 'Full Colour',
                'Highlights', 'Root Touch-Up', 'Kids Cut', 'Wash & Style',
            ]),
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60, 90]),
            'price' => fake()->randomFloat(2, 12, 95),
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
