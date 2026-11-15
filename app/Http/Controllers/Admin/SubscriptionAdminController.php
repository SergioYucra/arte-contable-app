<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\SubscriptionStorageService;
use App\Support\HumanDate;
use App\Support\LikePattern;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionAdminController extends Controller
{
    /** Cantidad de pagos ya revisados y de códigos que se envían por página. */
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly SubscriptionStorageService $storageService,
        private readonly MembershipService $membershipService,
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

        // Todos los pendientes, del más antiguo al más nuevo: ninguno queda fuera de la bandeja.
        $pendingRequests = PaymentRequest::with(['user.subscriptions', 'plan', 'reviewer'])
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->oldest('id')
            ->get()
            ->map(fn (PaymentRequest $r) => $this->formatPaymentRequest($r));

        $reviewedPage = $this->reviewedRequestsQuery()->paginate(self::PAGE_SIZE);
        $codesPage = $this->accessCodesQuery(null)->paginate(self::PAGE_SIZE);

        return view('admin.subscriptions.index', [
            'initialPlans' => $plans,
            'initialPendingRequests' => $pendingRequests,
            'initialReviewedRequests' => $this->pagePayload($reviewedPage, fn (PaymentRequest $r) => $this->formatPaymentRequest($r)),
            'initialAccessCodes' => $this->pagePayload($codesPage, fn (AccessCode $c) => $this->formatAccessCode($c)),
            'initialSummary' => $this->summary(),
            'urls' => [
                'storePlan' => route('admin.subscriptions.plans.store'),
                'storeCode' => route('admin.subscriptions.codes.store'),
                'reviewedRequests' => route('admin.subscriptions.requests.index'),
                'accessCodes' => route('admin.subscriptions.codes.index'),
                'members' => route('admin.subscriptions.members.index'),
            ],
        ]);
    }

    /**
     * Pagos ya revisados (aprobados o rechazados), de 50 en 50, para "Ver más".
     */
    public function reviewedRequests(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        $page = $this->reviewedRequestsQuery()->paginate(self::PAGE_SIZE);

        return response()->json($this->pagePayload($page, fn (PaymentRequest $r) => $this->formatPaymentRequest($r)));
    }

    /**
     * Códigos de acceso, de 50 en 50, con búsqueda opcional por código, referencia o persona.
     */
    public function accessCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->accessCodesQuery($validated['q'] ?? null)->paginate(self::PAGE_SIZE);

        return response()->json($this->pagePayload($page, fn (AccessCode $c) => $this->formatAccessCode($c)));
    }

    /**
     * Cifras de la cabecera del panel.
     *
     * @return array{pending_payments: int, active_members: int, expiring_soon: int, unused_codes: int}
     */
    private function summary(): array
    {
        $now = Carbon::now();
        $active = Subscription::where('status', Subscription::STATUS_ACTIVE)->where('expires_at', '>', $now);

        return [
            'pending_payments' => PaymentRequest::where('status', PaymentRequest::STATUS_PENDING)->count(),
            'active_members' => (clone $active)->distinct()->count('user_id'),
            'expiring_soon' => (clone $active)->where('expires_at', '<=', $now->copy()->addDays(15))->distinct()->count('user_id'),
            'unused_codes' => AccessCode::whereNull('redeemed_at')->whereNull('revoked_at')->count(),
        ];
    }

    private function reviewedRequestsQuery(): Builder
    {
        return PaymentRequest::with(['user', 'plan', 'reviewer'])
            ->where('status', '!=', PaymentRequest::STATUS_PENDING)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id');
    }

    private function accessCodesQuery(?string $search): Builder
    {
        $query = AccessCode::with(['plan' => fn ($q) => $q->withTrashed(), 'redeemer', 'creator'])->latest('id');

        if (filled($search)) {
            $pattern = LikePattern::contains($search);
            $escape = LikePattern::ESCAPE_CLAUSE;

            $query->where(function (Builder $q) use ($pattern, $escape): void {
                $q->whereRaw("code LIKE ? {$escape}", [strtoupper($pattern)])
                    ->orWhereRaw("reference LIKE ? {$escape}", [$pattern])
                    ->orWhereHas('redeemer', fn (Builder $u) => $u
                        ->whereRaw("name LIKE ? {$escape}", [$pattern])
                        ->orWhereRaw("email LIKE ? {$escape}", [$pattern]));
            });
        }

        return $query;
    }

    /**
     * @return array{items: array<int, mixed>, next_page: int|null, total: int}
     */
    private function pagePayload(LengthAwarePaginator $page, callable $format): array
    {
        return [
            'items' => collect($page->items())->map($format)->values()->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
        ];
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
            'message' => "«{$plan->name}» archivado. Quien ya lo tiene conserva su acceso.",
            'plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * Restaura un plan previamente archivado.
     */
    public function restorePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::onlyTrashed()->findOrFail($id);

        // Al archivar se libera el nombre; si otro plan vivo lo tomó, restaurar duplicaría nombres.
        if (SubscriptionPlan::where('name', $plan->name)->exists()) {
            throw ValidationException::withMessages([
                'name' => "Ya hay otro plan llamado «{$plan->name}». Cambia el nombre de ese plan antes de restaurar este.",
            ]);
        }

        DB::transaction(function () use ($plan, $request): void {
            $plan->restore();

            activity('subscription_plans')
                ->causedBy($request->user())
                ->performedOn($plan)
                ->log("Plan '{$plan->name}' restaurado");
        });

        return response()->json([
            'success' => true,
            'message' => "«{$plan->name}» restaurado.",
            'plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * Aprueba una solicitud de pago P2P en una transacción atómica segura.
     */
    public function approvePaymentRequest(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->ensurePending($paymentRequest, 'aprobar');

        $paymentRequest->loadMissing(['user', 'plan']);
        $user = $paymentRequest->user;
        $plan = $paymentRequest->plan;

        if ($user === null || $user->trashed()) {
            throw ValidationException::withMessages([
                'payment_request' => 'La cuenta que envió este pago ya no está activa. Reactívala en Usuarios antes de aprobar.',
            ]);
        }

        // Antes se asumían 365 días si faltaba el plan. Ahora no se adivina: sin plan no se aprueba.
        if ($plan === null) {
            throw ValidationException::withMessages([
                'payment_request' => 'Este pago no tiene un plan asociado, así que no se sabe cuántos días dar. Recházalo y pide a la persona que vuelva a enviarlo eligiendo un plan.',
            ]);
        }

        $expiresAt = null;

        DB::transaction(function () use ($paymentRequest, $user, $plan, $request, &$expiresAt): void {
            $now = Carbon::now();

            $grant = $this->membershipService->grantFromPayment($paymentRequest, $request->user());
            $expiresAt = $grant->new_expires_at;
            $startsAt = $grant->previous_expires_at === null ? $now : $grant->subscription->starts_at;

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
            'message' => "{$user->name} ya puede leer todo hasta el ".HumanDate::long($expiresAt).'.',
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Solo se aprueban o rechazan pagos pendientes. Cambiar de opinión sobre un pago ya revisado
     * dejaría días otorgados sin pago o un pago aprobado marcado como rechazado.
     */
    private function ensurePending(PaymentRequest $paymentRequest, string $action): void
    {
        if ($paymentRequest->status === PaymentRequest::STATUS_PENDING) {
            return;
        }

        $state = $paymentRequest->status === PaymentRequest::STATUS_APPROVED ? 'aprobado' : 'rechazado';
        $hint = $paymentRequest->status === PaymentRequest::STATUS_APPROVED
            ? 'Si hubo un error, quita el acceso desde «Personas con acceso».'
            : 'La persona puede enviar un comprobante nuevo.';

        throw ValidationException::withMessages([
            'payment_request' => "No se puede {$action}: este pago ya fue {$state}. {$hint}",
        ]);
    }

    /**
     * Rechaza una solicitud de pago indicando el motivo al alumno.
     */
    public function rejectPaymentRequest(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->ensurePending($paymentRequest, 'rechazar');

        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'admin_notes.required' => 'Escribe el motivo para que la persona sepa qué corregir.',
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
            'message' => 'Pago rechazado. La persona verá el motivo en su cuenta.',
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
            'summary' => $this->summary(),
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
        // Código escrito a mano (ej. "2026Arte"): se guarda en mayúsculas, igual que se normaliza al canjear.
        if (filled($request->input('custom_code'))) {
            $request->merge(['custom_code' => strtoupper(trim((string) $request->input('custom_code')))]);
        }

        $validated = $request->validate([
            // Solo planes no archivados: antes la validación los aceptaba y luego findOrFail daba 404.
            'subscription_plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->withoutTrashed()],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:1825'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
            'custom_code' => [
                'nullable',
                'string',
                'min:6',
                'max:32',
                // Letras sin tilde, números y guiones; sin espacios ni guion al inicio o al final.
                'regex:/^[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])?$/',
                Rule::unique('access_codes', 'code'),
            ],
        ], [
            'subscription_plan_id.exists' => 'Ese plan está archivado o ya no existe. Elige otro o usa «Elegir los días».',
            'duration_days.min' => 'La duración mínima debe ser de al menos 1 día.',
            'duration_days.max' => 'La duración máxima permitida es de 1825 días (5 años).',
            'quantity.required' => 'Indica la cantidad de códigos a generar.',
            'quantity.min' => 'Debes generar al menos 1 código.',
            'quantity.max' => 'Puedes generar como máximo 100 códigos por lote.',
            'custom_code.min' => 'El código debe tener al menos 6 caracteres.',
            'custom_code.max' => 'El código puede tener como máximo 32 caracteres.',
            'custom_code.regex' => 'Usa solo letras (sin tildes ni ñ), números y guiones, sin espacios.',
            'custom_code.unique' => 'Ese código ya existe. Elige otro.',
        ]);

        $customCode = $validated['custom_code'] ?? null;

        // Un código escrito a mano es uno solo: repetirlo en un lote crearía duplicados.
        if ($customCode !== null && (int) $validated['quantity'] !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Un código escrito a mano se crea de uno en uno.',
            ]);
        }

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

        try {
            $createdCodes = $this->createAccessCodes($plan, $durationDays, $quantity, $reference, $customCode, $request);
        } catch (UniqueConstraintViolationException) {
            // Otro admin (u otra pestaña) creó el mismo código entre la validación y el guardado.
            throw ValidationException::withMessages([
                'custom_code' => 'Ese código ya existe. Elige otro.',
            ]);
        }

        $formatted = array_map(function (AccessCode $code) use ($plan, $request) {
            if ($plan) {
                $code->setRelation('plan', $plan);
            }
            $code->setRelation('creator', $request->user());

            return $this->formatAccessCode($code);
        }, $createdCodes);

        $duration = HumanDate::duration($durationDays);
        $msg = $quantity === 1
            ? "Código listo: da {$duration} de acceso."
            : "{$quantity} códigos listos: cada uno da {$duration} de acceso.";

        return response()->json([
            'success' => true,
            'message' => $msg,
            'quantity' => $quantity,
            'access_codes' => $formatted,
            'access_code' => $formatted[0],
            'summary' => $this->summary(),
        ], 201);
    }

    /**
     * @return list<AccessCode>
     */
    private function createAccessCodes(?SubscriptionPlan $plan, int $durationDays, int $quantity, ?string $reference, ?string $customCode, Request $request): array
    {
        return DB::transaction(function () use ($plan, $durationDays, $quantity, $reference, $customCode, $request): array {
            $list = [];

            for ($i = 0; $i < $quantity; $i++) {
                $code = AccessCode::create([
                    'code' => $customCode ?? AccessCode::generateSecureCode(),
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
                    'written_by_hand' => $customCode !== null,
                ])
                ->log($customCode !== null
                    ? "Código '{$customCode}' escrito a mano ({$durationDays} días)"
                    : "Lote de {$quantity} códigos prepago generado ".($plan ? "para '{$plan->name}'" : 'personalizado')." ({$durationDays} días)");

            return $list;
        });
    }

    /**
     * Revoca un código de acceso prepago no canjeado.
     */
    public function revokeAccessCode(Request $request, AccessCode $accessCode): JsonResponse
    {
        if (! $accessCode->isAvailable()) {
            throw ValidationException::withMessages([
                'access_code' => 'Este código ya fue usado o anulado. Si lo usaron por error, quita el acceso desde «Personas con acceso».',
            ]);
        }

        DB::transaction(function () use ($accessCode, $request): void {
            $accessCode->update(['revoked_at' => Carbon::now()]);

            activity('access_codes')
                ->causedBy($request->user())
                ->performedOn($accessCode)
                ->log("Código prepago '{$accessCode->code}' revocado");
        });

        $accessCode->load(['plan' => fn ($q) => $q->withTrashed(), 'redeemer', 'creator']);

        return response()->json([
            'success' => true,
            'message' => "Código {$accessCode->code} anulado. Ya nadie puede usarlo.",
            'access_code' => $this->formatAccessCode($accessCode),
            'summary' => $this->summary(),
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
                // Único solo entre planes no archivados: un plan archivado no bloquea su nombre.
                Rule::unique('subscription_plans', 'name')->ignore($ignoreId)->withoutTrashed(),
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
            'price_formatted' => HumanDate::money((float) $plan->price),
            'duration_days' => (int) $plan->duration_days,
            'duration_label' => HumanDate::duration((int) $plan->duration_days),
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
        $plan = $request->plan;
        $user = $request->user;
        $isPending = $request->status === PaymentRequest::STATUS_PENDING;

        // Fecha hasta la que quedaría el acceso si se aprueba hoy (solo para pendientes con plan).
        $current = $isPending && $user !== null ? $this->membershipService->currentSubscription($user) : null;
        $wouldExpire = $isPending && $user !== null && $plan !== null
            ? $this->membershipService->previewExpiry($user, (int) $plan->duration_days)
            : null;

        // Si la persona no escribió nota, el sistema guardaba "Pago QR <plan>"; eso no es una nota suya.
        $note = trim((string) $request->reference_text);
        $studentNote = $note === '' || str_starts_with($note, 'Pago QR ') ? null : $note;

        return [
            'id' => $request->id,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name ?? 'Cuenta eliminada',
                'email' => $user?->email ?? '',
            ],
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'duration_days' => (int) $plan->duration_days,
                'duration_label' => HumanDate::duration((int) $plan->duration_days),
                'is_archived' => $plan->trashed(),
            ] : null,
            'amount' => (float) $request->amount,
            'amount_formatted' => HumanDate::money((float) $request->amount),
            'student_note' => $studentNote,
            'status' => $request->status,
            'status_label' => match ($request->status) {
                PaymentRequest::STATUS_APPROVED => 'Aprobado',
                PaymentRequest::STATUS_REJECTED => 'Rechazado',
                default => 'Por revisar',
            },
            'admin_notes' => $request->admin_notes,
            'reviewer_name' => $request->reviewer?->name,
            'reviewed_at' => HumanDate::dateTime($request->reviewed_at),
            'created_at' => HumanDate::dateTime($request->created_at),
            'created_human' => HumanDate::relative($request->created_at),
            'current_access_until' => HumanDate::long($current?->expires_at),
            'would_expire_at' => HumanDate::long($wouldExpire),
            'proof_kind' => $request->proofKind(),
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
            'duration_label' => HumanDate::duration((int) $code->duration_days),
            'plan_name' => $code->plan?->name,
            'is_available' => $code->isAvailable(),
            'status' => $code->revoked_at ? 'revoked' : ($code->redeemed_at ? 'used' : 'available'),
            'status_label' => $code->revoked_at ? 'Anulado' : ($code->redeemed_at ? 'Usado' : 'Sin usar'),
            'redeemer_name' => $code->redeemer?->name,
            'creator_name' => $code->creator?->name,
            'redeemed_at' => HumanDate::dateTime($code->redeemed_at),
            'created_at' => HumanDate::dateTime($code->created_at),
            'revoke_url' => route('admin.subscriptions.codes.revoke', $code->id),
        ];
    }
}
