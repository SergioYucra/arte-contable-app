<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Plan '.fake()->unique()->words(2, true),
            'price' => 200.00,
            'duration_days' => 365,
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function semester(): static
    {
        return $this->state(fn () => ['price' => 120.00, 'duration_days' => 180]);
    }
}
