<?php

namespace Database\Factories;

use App\Models\PaymentRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'amount' => 200.00,
            'reference_text' => 'Pago QR',
            'proof_vault_path' => 'receipts/'.fake()->uuid().'.png',
            'status' => PaymentRequest::STATUS_PENDING,
        ];
    }

    public function forPlan(SubscriptionPlan $plan): static
    {
        return $this->state(fn () => ['subscription_plan_id' => $plan->id, 'amount' => $plan->price]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => PaymentRequest::STATUS_APPROVED, 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => PaymentRequest::STATUS_REJECTED, 'reviewed_at' => now(), 'admin_notes' => 'No se ve bien']);
    }

    public function pdf(): static
    {
        return $this->state(fn () => ['proof_vault_path' => 'receipts/'.fake()->uuid().'.pdf']);
    }
}
