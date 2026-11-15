<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\SubscriptionStorageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipController extends Controller
{
    public function __construct(
        private readonly SubscriptionStorageService $storageService,
        private readonly MembershipService $membershipService,
    ) {}

    /**
     * Muestra la vista principal de membresía para el alumno.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $p) => $this->formatPlan($p));

        $membership = $this->getMembershipSummary($user);

        $pendingRequest = PaymentRequest::where('user_id', $user->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->with('plan')
            ->latest()
            ->first();

        $recentRequests = PaymentRequest::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (PaymentRequest $r) => $this->formatPaymentRequest($r));

        $recentCodes = AccessCode::where('redeemed_by', $user->id)
            ->with('plan')
            ->latest('redeemed_at')
            ->take(5)
            ->get()
            ->map(fn (AccessCode $c) => $this->formatRedeemedCode($c));

        return view('membership.index', [
            'initialPlans' => $plans,
            'initialMembership' => $membership,
            'initialPendingRequest' => $pendingRequest ? $this->formatPaymentRequest($pendingRequest) : null,
            'initialRecentRequests' => $recentRequests,
            'initialRecentCodes' => $recentCodes,
            'storePaymentUrl' => route('membership.payment.store'),
            'redeemCodeUrl' => route('membership.redeem'),
        ]);
    }

    /**
     * Registra un nuevo comprobante de pago P2P para su posterior revisión por administración.
     */
    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id,deleted_at,NULL'],
            'proof_file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:5120'],
            'student_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'subscription_plan_id.required' => 'Debes seleccionar un plan de suscripción.',
            'subscription_plan_id.exists' => 'El plan seleccionado no está disponible.',
            'proof_file.required' => 'Debes adjuntar el comprobante o captura de la transferencia.',
            'proof_file.mimes' => 'El comprobante debe ser una imagen (WebP, JPG, PNG) o PDF.',
            'proof_file.max' => 'El archivo no debe exceder los 5 MB.',
            'student_notes.max' => 'La nota opcional no debe exceder los 500 caracteres.',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);

        if (! $plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Este plan no se encuentra activo actualmente para nuevas solicitudes.',
            ], 422);
        }

        $existingPending = PaymentRequest::where('user_id', $request->user()->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes una solicitud de pago en revisión. Espera que la administración la verifique antes de enviar otra.',
            ], 422);
        }

        $uploaded = $this->storageService->storePaymentProof($request->file('proof_file'));

        $paymentRequest = PaymentRequest::create([
            'user_id' => $request->user()->id,
            'subscription_plan_id' => $plan->id,
            'amount' => $plan->price,
            'reference_text' => ! empty($validated['student_notes']) ? trim($validated['student_notes']) : "Pago QR {$plan->name}",
            'proof_vault_path' => $uploaded['path'],
            'status' => PaymentRequest::STATUS_PENDING,
        ]);

        activity('payment_requests')
            ->causedBy($request->user())
            ->performedOn($paymentRequest)
            ->withProperties([
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'amount' => $plan->price,
            ])
            ->log("El alumno '{$request->user()->name}' envió comprobante para '{$plan->name}' ({$plan->price} BOB)");

        $paymentRequest->load('plan');

        return response()->json([
            'success' => true,
            'message' => 'Tu comprobante fue enviado correctamente. El equipo revisará tu pago a la brevedad.',
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
        ], 201);
    }

    /**
     * Canjea un código prepago aleatorio, activando o extendiendo la membresía del alumno.
     */
    public function redeemCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ], [
            'code.required' => 'Ingresa el código prepago.',
        ]);

        $normalizedCode = strtoupper(trim($validated['code']));
        /** @var User $user */
        $user = $request->user();

        $result = DB::transaction(function () use ($normalizedCode, $user) {
            $code = AccessCode::where('code', $normalizedCode)
                ->lockForUpdate()
                ->first();

            if (! $code) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => 'El código de acceso no existe. Verifica los caracteres e intenta nuevamente.',
                ];
            }

            if ($code->revoked_at !== null) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'Este código ha sido revocado y ya no es válido.',
                ];
            }

            if ($code->redeemed_at !== null) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'Este código ya fue canjeado anteriormente.',
                ];
            }

            $now = Carbon::now();
            $durationDays = (int) $code->duration_days;

            // Regla única de vigencia; también deja la fila de historial.
            $grant = $this->membershipService->grantFromCode($code, $user);
            $expiresAt = $grant->new_expires_at;

            $code->update([
                'redeemed_by' => $user->id,
                'redeemed_at' => $now,
            ]);

            if (! $user->hasRole('User') && ! $user->hasAnyRole(['SuperAdmin', 'Admin', 'Manager'])) {
                $user->syncRoles(['User']);
            }

            activity('access_codes')
                ->causedBy($user)
                ->performedOn($code)
                ->withProperties([
                    'duration_days' => $durationDays,
                    'expires_at' => $expiresAt->toIso8601String(),
                ])
                ->log("El usuario '{$user->name}' canjeó el código '{$code->code}' (+{$durationDays} días)");

            return [
                'success' => true,
                'status' => 200,
                'message' => "¡Código canjeado con éxito! Se sumaron {$durationDays} días a tu membresía.",
            ];
        });

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'membership' => $this->getMembershipSummary($user->fresh()),
        ]);
    }

    /**
     * Entrega en streaming seguro el comprobante de pago subido por el alumno.
     */
    public function streamProof(Request $request, PaymentRequest $paymentRequest): StreamedResponse
    {
        if ($paymentRequest->user_id !== $request->user()->id && ! $request->user()->can('manage-users')) {
            abort(403, 'No tienes permiso para ver este comprobante.');
        }

        return $this->storageService->streamProof($paymentRequest->proof_vault_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function getMembershipSummary(User $user): array
    {
        $now = Carbon::now();

        $activeSub = Subscription::where('user_id', $user->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->with('plan')
            ->latest('expires_at')
            ->first();

        if (! $activeSub) {
            return [
                'is_active' => false,
                'status_label' => 'Sin membresía activa',
                'plan_name' => 'Acceso Gratuito',
                'days_remaining' => 0,
                'expires_at' => null,
                'starts_at' => null,
            ];
        }

        $expiresAt = Carbon::parse($activeSub->expires_at);
        $daysRemaining = max(0, (int) ceil($now->floatDiffInDays($expiresAt, false)));

        return [
            'is_active' => true,
            'status_label' => 'Membresía activa',
            'plan_name' => $activeSub->plan?->name ?? 'Acceso Especial',
            'days_remaining' => $daysRemaining,
            'expires_at' => $expiresAt->format('d/m/Y'),
            'starts_at' => Carbon::parse($activeSub->starts_at)->format('d/m/Y'),
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentRequest(PaymentRequest $request): array
    {
        $statusLabels = [
            PaymentRequest::STATUS_PENDING => 'En revisión',
            PaymentRequest::STATUS_APPROVED => 'Aprobado',
            PaymentRequest::STATUS_REJECTED => 'Rechazado',
        ];

        return [
            'id' => $request->id,
            'plan_name' => $request->plan?->name ?? 'Plan no disponible',
            'amount_formatted' => number_format((float) $request->amount, 2, '.', ',').' BOB',
            'status' => $request->status,
            'status_label' => $statusLabels[$request->status] ?? $request->status,
            'reference_text' => $request->reference_text ?? '',
            'admin_notes' => $request->admin_notes ?? '',
            'created_at' => $request->created_at?->format('d/m/Y H:i'),
            'proof_url' => route('membership.proof', $request->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRedeemedCode(AccessCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'plan_name' => $code->plan?->name ?? 'Acceso Personalizado',
            'duration_days' => (int) $code->duration_days,
            'redeemed_at' => $code->redeemed_at?->format('d/m/Y H:i'),
        ];
    }
}
