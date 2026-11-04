<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionStorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionAdminController extends Controller
{
    public function __construct(
        private readonly SubscriptionStorageService $storageService,
    ) {}

    /**
     * Carga inicial del panel administrativo de suscripciones y pagos.
     */
    public function index(): View
    {
        $plans = SubscriptionPlan::withTrashed()
            ->withCount([
                'subscriptions as active_subscriptions_count' => fn ($q) => $q->where('status', Subscription::STATUS_ACTIVE)->where('expires_at', '>', now()),
                'paymentRequests as pending_requests_count' => fn ($q) => $q->where('status', PaymentRequest::STATUS_PENDING),
            ])
            ->orderBy('sort_order')
            ->latest('id')
            ->get()
            ->map(fn (SubscriptionPlan $p) => $this->formatPlan($p));

        $paymentRequests = PaymentRequest::with(['user', 'plan', 'reviewer'])
            ->latest('id')
            ->take(100)
            ->get()
            ->map(fn (PaymentRequest $r) => $this->formatPaymentRequest($r));

        $accessCodes = AccessCode::with(['plan', 'redeemer', 'creator'])
            ->latest('id')
            ->take(100)
            ->get()
            ->map(fn (AccessCode $c) => $this->formatAccessCode($c));

        return view('admin.subscriptions.index', [
            'initialPlans' => $plans,
            'initialRequests' => $paymentRequests,
            'initialAccessCodes' => $accessCodes,
        ]);
    }

    /**
     * Registra un nuevo plan de suscripción con su propio código QR bancario.
     */
    public function storePlan(Request $request): JsonResponse
    {
        $validated = $this->validatePlanPayload($request);

        $qrPath = null;
        if ($request->hasFile('qr_image')) {
            $qrPath = $this->storageService->storePlanQr($request->file('qr_image'));
        }

        try {
            $plan = DB::transaction(function () use ($validated, $qrPath, $request): SubscriptionPlan {
                $created = SubscriptionPlan::create([
                    'name' => $validated['name'],
                    'price' => $validated['price'],
                    'duration_days' => $validated['duration_days'],
                    'qr_image_path' => $qrPath,
                    'description' => $validated['description'],
                    'is_active' => $validated['is_active'],
                    'sort_order' => $validated['sort_order'],
                ]);

                activity('subscription_plans')
                    ->causedBy($request->user())
                    ->performedOn($created)
                    ->log("Plan de suscripción '{$created->name}' creado ({$created->price} BOB, {$created->duration_days} días)");

                return $created;
            });
        } catch (\Throwable $e) {
            if ($qrPath !== null) {
                $this->storageService->deletePlanQr($qrPath);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => "Se creó el plan «{$plan->name}» correctamente.",
            'plan' => $this->formatPlan($plan),
        ], 201);
    }

    /**
     * Actualiza la configuración, precio o código QR de un plan existente.
     */
    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::withTrashed()->findOrFail($id);
        $validated = $this->validatePlanPayload($request, $plan->id);

        $oldQrPath = $plan->qr_image_path;
        $newQrPath = null;
        $deleteOldQr = false;

        if ($request->hasFile('qr_image')) {
            $newQrPath = $this->storageService->storePlanQr($request->file('qr_image'));
            $deleteOldQr = true;
        } elseif ($validated['remove_qr']) {
            $deleteOldQr = true;
        }

        try {
            DB::transaction(function () use ($plan, $validated, $newQrPath, $request): void {
                $attributes = [
                    'name' => $validated['name'],
                    'price' => $validated['price'],
                    'duration_days' => $validated['duration_days'],
                    'description' => $validated['description'],
                    'is_active' => $validated['is_active'],
                    'sort_order' => $validated['sort_order'],
                ];

                if ($newQrPath !== null) {
                    $attributes['qr_image_path'] = $newQrPath;
                } elseif ($validated['remove_qr']) {
                    $attributes['qr_image_path'] = null;
                }

                $plan->update($attributes);

                activity('subscription_plans')
                    ->causedBy($request->user())
                    ->performedOn($plan)
                    ->log("Plan '{$plan->name}' actualizado ({$plan->price} BOB)");
            });
        } catch (\Throwable $e) {
            if ($newQrPath !== null) {
                $this->storageService->deletePlanQr($newQrPath);
            }
            throw $e;
        }

        if ($deleteOldQr && $oldQrPath !== $newQrPath) {
            $this->storageService->deletePlanQr($oldQrPath);
        }

        return response()->json([
            'success' => true,
            'message' => "El plan «{$plan->name}» fue actualizado.",
            'plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * Archiva un plan (soft delete).
     */
    public function destroyPlan(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        DB::transaction(function () use ($plan, $request): void {
            $plan->delete();

            activity('subscription_plans')
                ->causedBy($request->user())
                ->performedOn($plan)
                ->log("Plan '{$plan->name}' archivado");
        });

        return response()->json([
            'success' => true,
            'message' => "El plan «{$plan->name}» fue archivado.",
            'plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * Restaura un plan previamente archivado.
     */
    public function restorePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($plan, $request): void {
            $plan->restore();

            activity('subscription_plans')
                ->causedBy($request->user())
                ->performedOn($plan)
                ->log("Plan '{$plan->name}' restaurado");
        });

        return response()->json([
            'success' => true,
            'message' => "El plan «{$plan->name}» fue reactivado.",
            'plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * Aprueba una solicitud de pago P2P en una transacción atómica segura.
     */
    public function approvePaymentRequest(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        if ($paymentRequest->status === PaymentRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'payment_request' => 'Esta solicitud ya había sido aprobada previamente.',
            ]);
        }

        $paymentRequest->loadMissing(['user', 'plan']);
        $user = $paymentRequest->user;
        $plan = $paymentRequest->plan;

        $durationDays = $plan?->duration_days ?: 365;

        DB::transaction(function () use ($paymentRequest, $user, $plan, $durationDays, $request): void {
            $now = Carbon::now();

            // Si el usuario ya contaba con una suscripción activa previa, extender el tiempo a partir del vencimiento actual
            $existingActiveSubscription = Subscription::where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->where('expires_at', '>', $now)
                ->latest('expires_at')
                ->first();

            $startsAt = $existingActiveSubscription ? $existingActiveSubscription->starts_at : $now;
            $baseForExpiry = $existingActiveSubscription ? Carbon::parse($existingActiveSubscription->expires_at) : $now;
            $expiresAt = $baseForExpiry->copy()->addDays($durationDays);

            if ($existingActiveSubscription) {
                $existingActiveSubscription->update([
                    'subscription_plan_id' => $plan?->id ?: $existingActiveSubscription->subscription_plan_id,
                    'expires_at' => $expiresAt,
                    'payment_request_id' => $paymentRequest->id,
                ]);
            } else {
                Subscription::create([
                    'user_id' => $user->id,
                    'book_edition_id' => null,
                    'subscription_plan_id' => $plan?->id,
                    'payment_request_id' => $paymentRequest->id,
                    'access_code_id' => null,
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
                    'status' => Subscription::STATUS_ACTIVE,
                ]);
            }

            // Asignar rol "User" si es necesario o asegurar acceso
            if (! $user->hasRole('User') && ! $user->hasAnyRole(['SuperAdmin', 'Admin', 'Manager'])) {
                $user->syncRoles(['User']);
            }

            $paymentRequest->update([
                'status' => PaymentRequest::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ]);

            activity('payment_requests')
                ->causedBy($request->user())
                ->performedOn($paymentRequest)
                ->withProperties([
                    'user_id' => $user->id,
                    'plan' => $plan?->name,
                    'amount' => $paymentRequest->amount,
                    'starts_at' => $startsAt->toIso8601String(),
                    'expires_at' => $expiresAt->toIso8601String(),
                ])
                ->log("Pago de '{$user->name}' aprobado ({$paymentRequest->amount} BOB, hasta {$expiresAt->format('d/m/Y')})");
        });

        $paymentRequest->load(['user', 'plan', 'reviewer']);

        return response()->json([
            'success' => true,
            'message' => "La solicitud de {$user->name} fue aprobada y su membresía quedó activa.",
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
        ]);
    }

    /**
     * Rechaza una solicitud de pago indicando el motivo al alumno.
     */
    public function rejectPaymentRequest(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'admin_notes.required' => 'Ingresa el motivo del rechazo para que el alumno pueda corregirlo.',
            'admin_notes.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        DB::transaction(function () use ($paymentRequest, $validated, $request): void {
            $paymentRequest->update([
                'status' => PaymentRequest::STATUS_REJECTED,
                'admin_notes' => trim($validated['admin_notes']),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => Carbon::now(),
            ]);

            activity('payment_requests')
                ->causedBy($request->user())
                ->performedOn($paymentRequest)
                ->log("Solicitud de pago #{$paymentRequest->id} rechazada");
        });

        $paymentRequest->load(['user', 'plan', 'reviewer']);

        return response()->json([
            'success' => true,
            'message' => 'La solicitud fue rechazada y se registró la observación.',
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
        ]);
    }

    /**
     * Visualizador seguro en streaming del comprobante de pago alojado en PrivateVault.
     */
    public function previewProof(PaymentRequest $paymentRequest): StreamedResponse
    {
        return $this->storageService->streamProof($paymentRequest->proof_vault_path);
    }

    /**
     * Emite uno o varios códigos de acceso prepago aleatorios (asociados a un plan o personalizados con días libres).
     */
    public function storeAccessCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:1825'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
        ], [
            'subscription_plan_id.exists' => 'El plan seleccionado no existe.',
            'duration_days.min' => 'La duración mínima debe ser de al menos 1 día.',
            'duration_days.max' => 'La duración máxima permitida es de 1825 días (5 años).',
            'quantity.required' => 'Indica la cantidad de códigos a generar.',
            'quantity.min' => 'Debes generar al menos 1 código.',
            'quantity.max' => 'Puedes generar como máximo 100 códigos por lote.',
        ]);

        $plan = ! empty($validated['subscription_plan_id'])
            ? SubscriptionPlan::findOrFail($validated['subscription_plan_id'])
            : null;

        $durationDays = $plan ? (int) $plan->duration_days : (int) ($validated['duration_days'] ?? 0);

        if ($durationDays <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Debes seleccionar un plan o ingresar la cantidad de días de duración para el código.',
                'errors' => [
                    'duration_days' => ['Ingresa los días de duración del acceso.'],
                ],
            ], 422);
        }

        $quantity = (int) $validated['quantity'];
        $reference = ! empty($validated['reference']) ? trim($validated['reference']) : null;

        $createdCodes = DB::transaction(function () use ($plan, $durationDays, $quantity, $reference, $request): array {
            $list = [];

            for ($i = 0; $i < $quantity; $i++) {
                $code = AccessCode::create([
                    'code' => AccessCode::generateSecureCode(),
                    'subscription_plan_id' => $plan?->id,
                    'duration_days' => $durationDays,
                    'reference' => $reference,
                    'created_by' => $request->user()->id,
                ]);

                $list[] = $code;
            }

            activity('access_codes')
                ->causedBy($request->user())
                ->performedOn($plan ?: $request->user())
                ->withProperties([
                    'quantity' => $quantity,
                    'plan' => $plan?->name ?? 'Personalizado (Sin plan)',
                    'duration_days' => $durationDays,
                    'reference' => $reference,
                ])
                ->log("Lote de {$quantity} códigos prepago generado ".($plan ? "para '{$plan->name}'" : 'personalizado')." ({$durationDays} días)");

            return $list;
        });

        $formatted = array_map(function (AccessCode $code) use ($plan, $request) {
            if ($plan) {
                $code->setRelation('plan', $plan);
            }
            $code->setRelation('creator', $request->user());

            return $this->formatAccessCode($code);
        }, $createdCodes);

        $targetDesc = $plan ? "«{$plan->name}»" : "acceso personalizado ({$durationDays} días)";
        $msg = $quantity === 1
            ? "Código «{$createdCodes[0]->code}» generado con éxito ({$targetDesc})."
            : "Se generaron {$quantity} códigos ({$targetDesc}) con éxito.";

        return response()->json([
            'success' => true,
            'message' => $msg,
            'quantity' => $quantity,
            'access_codes' => $formatted,
            'access_code' => $formatted[0],
        ], 201);
    }

    /**
     * Revoca un código de acceso prepago no canjeado.
     */
    public function revokeAccessCode(Request $request, AccessCode $accessCode): JsonResponse
    {
        if (! $accessCode->isAvailable()) {
            throw ValidationException::withMessages([
                'access_code' => 'Este código ya no se encuentra disponible para revocar.',
            ]);
        }

        DB::transaction(function () use ($accessCode, $request): void {
            $accessCode->update(['revoked_at' => Carbon::now()]);

            activity('access_codes')
                ->causedBy($request->user())
                ->performedOn($accessCode)
                ->log("Código prepago '{$accessCode->code}' revocado");
        });

        $accessCode->load(['plan', 'redeemer', 'creator']);

        return response()->json([
            'success' => true,
            'message' => "El código «{$accessCode->code}» fue revocado.",
            'access_code' => $this->formatAccessCode($accessCode),
        ]);
    }

    /**
     * Valida los datos del formulario de un plan de suscripción.
     *
     * @return array{name: string, price: float, duration_days: int, description: ?string, is_active: bool, sort_order: int, remove_qr: bool}
     */
    private function validatePlanPayload(Request $request, ?int $ignoreId = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')),
            'price' => (float) $request->input('price', 0),
            'duration_days' => (int) $request->input('duration_days', 365),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_active' => filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
            'remove_qr' => filter_var($request->input('remove_qr', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:100',
                Rule::unique('subscription_plans', 'name')->ignore($ignoreId),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:1825'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'remove_qr' => ['required', 'boolean'],
            'qr_image' => ['nullable', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'mimes:webp,jpg,jpeg,png', 'max:2048'],
        ], [
            'name.required' => 'Ingresa el nombre del plan.',
            'name.unique' => 'Ya existe un plan con ese mismo nombre.',
            'price.required' => 'Indica el precio del plan en Bolivianos.',
            'price.numeric' => 'El precio debe ser un número válido.',
            'duration_days.required' => 'Indica los días de duración del plan.',
            'qr_image.mimetypes' => 'La imagen del QR debe estar en formato WebP, JPG o PNG.',
            'qr_image.max' => 'La imagen del QR no debe superar los 2 MB.',
        ]);

        return [
            'name' => (string) $validated['name'],
            'price' => (float) $validated['price'],
            'duration_days' => (int) $validated['duration_days'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'is_active' => (bool) $validated['is_active'],
            'sort_order' => (int) $validated['sort_order'],
            'remove_qr' => (bool) $validated['remove_qr'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPlan(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'price_formatted' => number_format((float) $plan->price, 2, '.', ',').' BOB',
            'duration_days' => (int) $plan->duration_days,
            'description' => $plan->description ?? '',
            'qr_image_url' => $plan->qr_image_url,
            'is_active' => (bool) $plan->is_active,
            'sort_order' => (int) $plan->sort_order,
            'is_archived' => $plan->trashed(),
            'active_subscriptions_count' => (int) ($plan->active_subscriptions_count ?? 0),
            'pending_requests_count' => (int) ($plan->pending_requests_count ?? 0),
            'update_url' => route('admin.subscriptions.plans.update', $plan->id),
            'destroy_url' => route('admin.subscriptions.plans.destroy', $plan->id),
            'restore_url' => route('admin.subscriptions.plans.restore', $plan->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentRequest(PaymentRequest $request): array
    {
        return [
            'id' => $request->id,
            'user' => [
                'id' => $request->user?->id,
                'name' => $request->user?->name ?? 'Usuario no disponible',
                'email' => $request->user?->email ?? '',
            ],
            'plan' => $request->plan ? [
                'id' => $request->plan->id,
                'name' => $request->plan->name,
                'price' => (float) $request->plan->price,
                'duration_days' => (int) $request->plan->duration_days,
            ] : null,
            'amount' => (float) $request->amount,
            'amount_formatted' => number_format((float) $request->amount, 2, '.', ',').' BOB',
            'reference_text' => $request->reference_text,
            'status' => $request->status,
            'status_label' => match ($request->status) {
                PaymentRequest::STATUS_APPROVED => 'Aprobado',
                PaymentRequest::STATUS_REJECTED => 'Rechazado',
                default => 'Pendiente',
            },
            'admin_notes' => $request->admin_notes,
            'reviewer_name' => $request->reviewer?->name,
            'reviewed_at' => $request->reviewed_at?->format('d/m/Y H:i'),
            'created_at' => $request->created_at?->format('d/m/Y H:i'),
            'created_human' => $request->created_at?->diffForHumans(),
            'proof_url' => route('admin.subscriptions.requests.proof', $request->id),
            'approve_url' => route('admin.subscriptions.requests.approve', $request->id),
            'reject_url' => route('admin.subscriptions.requests.reject', $request->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAccessCode(AccessCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'reference' => $code->reference ?? '',
            'duration_days' => (int) $code->duration_days,
            'plan_name' => $code->plan?->name,
            'is_available' => $code->isAvailable(),
            'status_label' => $code->revoked_at ? 'Revocado' : ($code->redeemed_at ? 'Canjeado' : 'Disponible'),
            'redeemer_name' => $code->redeemer?->name,
            'creator_name' => $code->creator?->name,
            'redeemed_at' => $code->redeemed_at?->format('d/m/Y H:i'),
            'created_at' => $code->created_at?->format('d/m/Y H:i'),
            'revoke_url' => route('admin.subscriptions.codes.revoke', $code->id),
        ];
    }
}
