<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => null,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->addDays(30),
            'status' => Subscription::STATUS_ACTIVE,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subDays(60), 'expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => Subscription::STATUS_REVOKED]);
    }
}
