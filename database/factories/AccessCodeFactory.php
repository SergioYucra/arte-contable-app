<?php

namespace Database\Factories;

use App\Models\AccessCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessCode>
 */
class AccessCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            // El código lo genera el modelo al crearse (AccessCode::generateSecureCode).
            'subscription_plan_id' => null,
            'duration_days' => 30,
            'reference' => null,
            'created_by' => User::factory(),
        ];
    }
}
