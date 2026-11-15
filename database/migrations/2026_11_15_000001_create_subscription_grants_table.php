<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de accesos otorgados: una fila por cada pago aprobado o código canjeado.
     *
     * La tabla `subscriptions` guarda una sola fila vigente por persona y cada extensión
     * sobrescribe de qué pago o código vino. Aquí queda el rastro completo.
     */
    public function up(): void
    {
        Schema::create('subscription_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('subscription_plan_id')
                ->nullable()
                ->constrained('subscription_plans')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            // Únicos: un mismo pago o un mismo código no puede otorgar días dos veces.
            $table->foreignId('payment_request_id')
                ->nullable()
                ->unique()
                ->constrained('payment_requests')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('access_code_id')
                ->nullable()
                ->unique()
                ->constrained('access_codes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedInteger('days');
            $table->timestamp('previous_expires_at')->nullable();
            $table->timestamp('new_expires_at');
            $table->foreignId('granted_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        $this->backfillFromExistingSubscriptions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_grants');
    }

    /**
     * Las suscripciones que ya existían reciben una fila de historial con lo que se sabe de ellas:
     * su último pago o código y la vigencia total acumulada.
     */
    private function backfillFromExistingSubscriptions(): void
    {
        DB::table('subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions): void {
            $reviewers = DB::table('payment_requests')
                ->whereIn('id', $subscriptions->pluck('payment_request_id')->filter()->all())
                ->pluck('reviewed_by', 'id');

            $rows = [];

            foreach ($subscriptions as $subscription) {
                $startsAt = Carbon::parse($subscription->starts_at);
                $expiresAt = Carbon::parse($subscription->expires_at);

                $rows[] = [
                    'user_id' => $subscription->user_id,
                    'subscription_id' => $subscription->id,
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'payment_request_id' => $subscription->payment_request_id,
                    'access_code_id' => $subscription->access_code_id,
                    'days' => max(1, (int) round($startsAt->floatDiffInDays($expiresAt))),
                    'previous_expires_at' => null,
                    'new_expires_at' => $subscription->expires_at,
                    'granted_by' => $subscription->payment_request_id !== null
                        ? ($reviewers[$subscription->payment_request_id] ?? null)
                        : null,
                    'created_at' => $subscription->created_at ?? $subscription->starts_at,
                ];
            }

            if ($rows !== []) {
                DB::table('subscription_grants')->insert($rows);
            }
        });
    }
};
