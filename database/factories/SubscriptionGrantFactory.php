<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionGrant>
 */
class SubscriptionGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'user_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])->user_id,
            'days' => 30,
            'previous_expires_at' => null,
            'new_expires_at' => now()->addDays(30),
        ];
    }
}
