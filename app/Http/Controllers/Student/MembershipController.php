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
use App\Support\HumanDate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MembershipController extends Controller
{
    /** Filas de historial que se muestran de cada tipo (pagos y códigos). */
    private const HISTORY_LIMIT = 20;

    /** Desde cuántos días antes se avisa que el acceso vence. Igual que "vencen pronto" del panel admin. */
    private const EXPIRING_SOON_DAYS = 15;

    public function __construct(
        private readonly SubscriptionStorageService $storageService,
        private readonly MembershipService $membershipService,
    ) {}

    /**
     * Página de membresía del estudiante.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $state = $this->studentState($user);

        return view('membership.index', [
            'initialPlans' => $state['plans'],
            'initialMembership' => $state['membership'],
            'initialPendingRequest' => $state['pending_request'],
            'initialRecentRequests' => $state['recent_requests'],
            'initialRecentCodes' => $state['recent_codes'],
            'storePaymentUrl' => route('membership.payment.store'),
            'redeemCodeUrl' => route('membership.redeem'),
            'statusUrl' => route('membership.status'),
        ]);
    }

    /**
     * Estado al día de la membresía. La página lo consulta mientras hay un pago en revisión,
     * para avisar apenas se aprueba o se rechaza sin que la persona tenga que recargar.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()
            ->json($this->studentState($user))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Registra el comprobante de un pago para que la administración lo revise.
     */
    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id,deleted_at,NULL'],
            'proof_file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,pdf', 'max:5120'],
            // La columna reference_text guarda 255 caracteres: aceptar más rompía el envío en MySQL.
            'student_notes' => ['nullable', 'string', 'max:255'],
            'expected_amount' => ['nullable', 'numeric', 'min:0'],
            'previous_amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'subscription_plan_id.required' => 'Elige un plan antes de enviar tu comprobante.',
            'subscription_plan_id.exists' => 'Ese plan ya no está disponible. Actualiza la página y elige otro.',
            'proof_file.required' => 'Adjunta la captura o el comprobante de tu pago.',
            'proof_file.mimes' => 'El comprobante debe ser una imagen (JPG, PNG o WebP) o un PDF.',
            'proof_file.max' => 'El archivo pesa más de 5 MB. Envía una captura de pantalla del comprobante en lugar de una foto.',
            // QA: si el archivo supera el límite de subida del servidor, Laravel decía "Subida fallida en proof file".
            'proof_file.uploaded' => 'No se pudo subir el archivo, probablemente por su tamaño. Envía una captura de pantalla del comprobante en lugar de una foto, o un archivo más liviano.',
            'student_notes.max' => 'La nota puede tener como máximo 255 caracteres.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);

        if (! $plan->is_active) {
            return response()->json([
                'success' => false,
                'code' => 'plan_unavailable',
                'message' => 'Ese plan ya no está disponible. Elige otro de la lista.',
                'plans' => $this->activePlans($user),
            ], 422);
        }

        if ($pending = $this->pendingPayment($user)) {
            return $this->pendingExistsResponse($pending);
        }

        // El monto sale siempre del servidor. Lo que envía la página solo sirve para detectar que
        // la persona estaba viendo un precio viejo (terminó el precio especial o cambió el plan).
        $amount = $plan->currentPrice();

        if (isset($validated['expected_amount']) && abs((float) $validated['expected_amount'] - $amount) >= 0.005) {
            return response()->json([
                'success' => false,
                'code' => 'price_changed',
                'message' => 'El precio de este plan cambió mientras tenías la página abierta: ahora es '.HumanDate::money($amount).'. Revisa el monto y el QR antes de pagar. Si ya pagaste el monto anterior, envía igual tu comprobante y cuéntalo en la nota.',
                'plans' => $this->activePlans($user),
            ], 409);
        }

        $note = filled($validated['student_notes'] ?? null) ? trim($validated['student_notes']) : "Pago QR {$plan->name}";

        // Si el precio cambió mientras la persona pagaba, su comprobante puede traer el monto anterior.
        // Se deja dicho junto a la nota para que quien revisa entienda la diferencia. Es un dato que
        // informa la página, no el monto del pago: ese lo sigue fijando el servidor.
        if (isset($validated['previous_amount']) && abs((float) $validated['previous_amount'] - $amount) >= 0.005) {
            $note = Str::limit('Vio antes otro precio: '.HumanDate::money((float) $validated['previous_amount']).'. '.$note, 250, '…');
        }

        $uploaded = $this->storageService->storePaymentProof($request->file('proof_file'));
        $specialLabel = $plan->hasActiveSpecialPrice() ? ($plan->special_label ?: 'Precio especial') : null;

        // QA: dos envíos simultáneos (dos pestañas) creaban dos pagos pendientes, porque ambos pasaban la
        // verificación de arriba. Dentro de la transacción se bloquea la fila de la persona y se vuelve a
        // verificar, así solo uno queda registrado.
        try {
            $paymentRequest = DB::transaction(function () use ($user, $plan, $amount, $specialLabel, $note, $uploaded): ?PaymentRequest {
                User::whereKey($user->id)->lockForUpdate()->first();

                if ($this->pendingPayment($user) !== null) {
                    return null;
                }

                return PaymentRequest::create([
                    'user_id' => $user->id,
                    'subscription_plan_id' => $plan->id,
                    'amount' => $amount,
                    'special_price_label' => $specialLabel,
                    'reference_text' => $note,
                    'proof_vault_path' => $uploaded['path'],
                    'status' => PaymentRequest::STATUS_PENDING,
                ]);
            });
        } catch (Throwable $e) {
            // Si el registro falla, el archivo ya guardado quedaría suelto en la bóveda.
            $this->storageService->deletePaymentProof($uploaded['path']);

            throw $e;
        }

        if ($paymentRequest === null) {
            $this->storageService->deletePaymentProof($uploaded['path']);

            return $this->pendingExistsResponse($this->pendingPayment($user));
        }

        activity('payment_requests')
            ->causedBy($user)
            ->performedOn($paymentRequest)
            ->withProperties([
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'amount' => $amount,
                'special_price_label' => $specialLabel,
            ])
            ->log("El estudiante '{$user->name}' envió comprobante para '{$plan->name}' (".HumanDate::money($amount).')');

        $paymentRequest->load('plan');

        return response()->json([
            'success' => true,
            'message' => 'Recibimos tu comprobante. Te avisaremos en esta página cuando esté revisado.',
            'payment_request' => $this->formatPaymentRequest($paymentRequest),
        ], 201);
    }

    /**
     * Retira un envío que todavía nadie revisó (comprobante equivocado, plan equivocado).
     * Borra el pago pendiente y su archivo; la persona puede enviar otro enseguida.
     */
    public function cancelPayment(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 404 para pagos ajenos: no se confirma ni que existan.
        abort_unless($paymentRequest->user_id === $user->id, 404);

        // Se relee con bloqueo: la administración puede estar aprobándolo en este mismo instante.
        $removed = DB::transaction(function () use ($paymentRequest, $user): ?PaymentRequest {
            $locked = PaymentRequest::whereKey($paymentRequest->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== PaymentRequest::STATUS_PENDING) {
                return null;
            }

            $locked->loadMissing('plan');

            activity('payment_requests')
                ->causedBy($user)
                ->performedOn($locked)
                ->withProperties([
                    'plan_id' => $locked->subscription_plan_id,
                    'plan_name' => $locked->plan?->name,
                    'amount' => $locked->amount,
                    'sent_at' => $locked->created_at?->toIso8601String(),
                ])
                ->log("El estudiante '{$user->name}' retiró su comprobante antes de la revisión (".HumanDate::money((float) $locked->amount).')');

            // La línea de arriba ya cuenta lo ocurrido; se evita el registro automático "deleted".
            $locked->disableLogging();
            $locked->delete();

            return $locked;
        });

        if ($removed === null) {
            return response()->json([
                'success' => false,
                'code' => 'already_reviewed',
                'message' => 'Este pago ya fue revisado, así que no se puede retirar. Aquí abajo ves el resultado.',
            ] + $this->studentState($user), 422);
        }

        // Fuera de la transacción: si el borrado del archivo falla, el retiro ya quedó hecho.
        $this->storageService->deletePaymentProof($removed->proof_vault_path);

        return response()->json([
            'success' => true,
            'message' => 'Retiraste tu envío. Cuando quieras, elige un plan y manda otro comprobante.',
        ] + $this->studentState($user));
    }

    /**
     * Canjea un código de acceso: activa la membresía o suma días a la que ya tiene.
     */
    public function redeemCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
        ], [
            'code.required' => 'Escribe tu código de acceso.',
            'code.max' => 'Ese código es demasiado largo. Revisa que lo hayas copiado bien.',
        ]);

        $codeId = $this->findCodeId($validated['code']);
        /** @var User $user */
        $user = $request->user();

        $result = DB::transaction(function () use ($codeId, $user) {
            // Se bloquea por su id: dos personas no pueden canjear el mismo código a la vez.
            $code = $codeId === null ? null : AccessCode::whereKey($codeId)->lockForUpdate()->first();

            if (! $code) {
                return [
                    'success' => false,
                    'code_error' => 'not_found',
                    'message' => 'No encontramos ese código. Revisa que esté escrito igual que en tu libro o en el mensaje que recibiste.',
                ];
            }

            if ($code->revoked_at !== null) {
                return [
                    'success' => false,
                    'code_error' => 'voided',
                    'message' => 'Este código fue anulado y ya no sirve. Pide uno nuevo a quien te lo dio.',
                ];
            }

            if ($code->redeemed_at !== null) {
                return [
                    'success' => false,
                    'code_error' => 'used',
                    'message' => 'Este código ya se usó. Cada código sirve una sola vez.',
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
                ->log("El estudiante '{$user->name}' canjeó el código '{$code->code}' (+{$durationDays} días)");

            return [
                'success' => true,
                'message' => 'Código canjeado. Se sumaron '.HumanDate::duration($durationDays).' a tu acceso: puedes leer todo hasta el '.HumanDate::long($expiresAt).'.',
                'code' => $code,
            ];
        });

        if (! $result['success']) {
            // Siempre 422: lo que falló es lo que la persona escribió, exista o no el código.
            return response()->json([
                'success' => false,
                'code' => $result['code_error'],
                'message' => $result['message'],
            ], 422);
        }

        // El canje cambió el acceso: se vuelve a leer antes de describirlo.
        $user->load('subscriptions');

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'membership' => $this->membershipSummary($user),
            // Los planes dicen hasta cuándo llegaría el acceso: cambia al sumar días.
            'plans' => $this->activePlans($user),
            // QA: el historial agregaba una fila inventada ("—"); ahora recibe el código real.
            'redeemed_code' => $this->formatRedeemedCode($result['code']->load('plan')),
        ]);
    }

    /**
     * Entrega el comprobante a quien lo subió o a la administración.
     */
    public function streamProof(Request $request, PaymentRequest $paymentRequest): StreamedResponse
    {
        // 404 y no 403: a quien no le corresponde no se le confirma que el comprobante existe.
        if ($paymentRequest->user_id !== $request->user()->id && ! $request->user()->can('manage-users')) {
            abort(404);
        }

        return $this->storageService->streamProof($paymentRequest->proof_vault_path);
    }

    /**
     * Todo lo que cambia con el tiempo en la página: planes, acceso, pago en revisión e historial.
     *
     * @return array{plans: list<array<string, mixed>>, membership: array<string, mixed>, pending_request: array<string, mixed>|null, recent_requests: list<array<string, mixed>>, recent_codes: list<array<string, mixed>>}
     */
    private function studentState(User $user): array
    {
        // Esta respuesta se pide cada 45 s mientras hay un pago en revisión: las suscripciones se leen
        // una sola vez (el servicio de membresía usa la relación ya cargada) y siempre de nuevo.
        $user->load('subscriptions');

        $requests = PaymentRequest::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->latest('id')
            ->take(self::HISTORY_LIMIT)
            ->get();

        // Con un pago en revisión no se puede enviar otro, así que si existe es de los más recientes.
        $pending = $requests->first(fn (PaymentRequest $r) => $r->status === PaymentRequest::STATUS_PENDING);

        $recentRequests = $requests
            ->map(fn (PaymentRequest $r) => $this->formatPaymentRequest($r))
            ->values()
            ->all();

        $recentCodes = AccessCode::where('redeemed_by', $user->id)
            ->with('plan')
            ->latest('redeemed_at')
            ->latest('id')
            ->take(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (AccessCode $c) => $this->formatRedeemedCode($c))
            ->values()
            ->all();

        return [
            'plans' => $this->activePlans($user),
            'membership' => $this->membershipSummary($user),
            'pending_request' => $pending ? $this->formatPaymentRequest($pending) : null,
            'recent_requests' => $recentRequests,
            'recent_codes' => $recentCodes,
        ];
    }

    private function pendingPayment(User $user): ?PaymentRequest
    {
        return PaymentRequest::where('user_id', $user->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->with('plan')
            ->latest()
            ->latest('id')
            ->first();
    }

    /**
     * Ya hay un pago en revisión. Se devuelve para que la página lo muestre aunque se haya
     * enviado desde otra pestaña u otro dispositivo.
     */
    private function pendingExistsResponse(?PaymentRequest $pending): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'pending_exists',
            'message' => 'Ya tienes un pago en revisión. Cuando se confirme podrás enviar otro.',
            'pending_request' => $pending ? $this->formatPaymentRequest($pending) : null,
        ], 422);
    }

    /**
     * Busca el código como pudo escribirlo la persona: sin importar espacios, mayúsculas ni guiones.
     * Vale para los generados (AC-XXXX-XXXX) y para los que la administración escribe a mano.
     *
     * Devuelve su id, o null si no existe o si lo escrito calza con más de un código
     * (dos códigos que solo se diferencian por los guiones): en la duda no se canjea ninguno.
     */
    private function findCodeId(string $typed): ?int
    {
        // Algunos teclados de celular cambian el guion por una raya larga.
        $clean = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $typed) ?? $typed;
        $clean = strtoupper(preg_replace('/\s+/u', '', $clean) ?? '');
        $bare = str_replace('-', '', $clean);

        if ($bare === '') {
            return null;
        }

        $matches = AccessCode::whereRaw("REPLACE(code, '-', '') = ?", [$bare])->pluck('code', 'id');

        $exact = $matches->search($clean, true);

        if ($exact !== false) {
            return (int) $exact;
        }

        return $matches->count() === 1 ? (int) $matches->keys()->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipSummary(User $user): array
    {
        $now = Carbon::now();
        // Quien administra ya puede leer todo: la página se lo dice en vez de ofrecerle planes sin más.
        $isStaff = $user->can('manage-users');

        // Misma regla de vigencia que usa el resto de la membresía (aprobar pagos, canjear, quitar acceso).
        $user->loadMissing('subscriptions');
        $active = $this->membershipService->currentSubscription($user);

        if ($active === null) {
            // QA: quien venció o perdió el acceso veía "Acceso Gratuito" sin ninguna explicación.
            $last = $user->subscriptions->sortByDesc('id')->first();

            return [
                'is_active' => false,
                'is_staff' => $isStaff,
                'status_label' => 'Sin membresía activa',
                'plan_name' => 'Muestra gratis',
                'days_remaining' => 0,
                'total_days' => 0,
                'expiring_soon' => false,
                'expires_at' => null,
                'starts_at' => null,
                'previous' => $last === null ? null : [
                    'state' => $last->status === Subscription::STATUS_REVOKED ? 'revoked' : 'expired',
                    'until' => HumanDate::long($last->expires_at),
                ],
            ];
        }

        $expiresAt = Carbon::parse($active->expires_at);
        $startsAt = Carbon::parse($active->starts_at);
        $daysRemaining = max(0, (int) ceil($now->floatDiffInDays($expiresAt, false)));

        // QA: la barra de vigencia calculaba siempre sobre 365 días.
        $totalDays = max(1, (int) ceil($startsAt->floatDiffInDays($expiresAt)));

        $planName = $active->subscription_plan_id !== null
            ? SubscriptionPlan::withTrashed()->find($active->subscription_plan_id)?->name
            : null;

        return [
            'is_active' => true,
            'is_staff' => $isStaff,
            'status_label' => 'Membresía activa',
            'plan_name' => $planName ?? 'Acceso con código',
            'days_remaining' => $daysRemaining,
            'total_days' => $totalDays,
            // "Vence pronto" si quedan 15 días o menos y ya pasó al menos la mitad del periodo:
            // un código de una semana recién canjeado no vence pronto, acaba de empezar.
            'expiring_soon' => $daysRemaining <= min(self::EXPIRING_SOON_DAYS, intdiv($totalDays, 2)),
            'expires_at' => HumanDate::long($expiresAt),
            'starts_at' => HumanDate::long($startsAt),
            'previous' => null,
        ];
    }

    /**
     * Planes a la venta, en el orden que eligió la administración.
     *
     * Para quien ya tiene acceso, cada plan dice hasta qué fecha llegaría si lo paga:
     * es más claro que explicar que "los días se suman".
     *
     * @return list<array<string, mixed>>
     */
    private function activePlans(User $user): array
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->orderBy('id')
            ->get();

        $bestValueId = $this->bestValuePlanId($plans);

        // La fecha la calcula el servicio de membresía, que es quien aplica la regla al aprobar el pago:
        // así lo que promete cada plan no puede apartarse de lo que después se otorga.
        $user->loadMissing('subscriptions');
        $hasAccess = $this->membershipService->currentSubscription($user) !== null;

        return $plans
            ->map(fn (SubscriptionPlan $p) => $this->formatPlan(
                $p,
                $p->id === $bestValueId,
                $hasAccess ? $this->membershipService->previewExpiry($user, (int) $p->duration_days) : null,
            ))
            ->values()
            ->all();
    }

    /**
     * El plan con el menor precio por día, si hay al menos dos y uno gana solo.
     * Es un dato calculado: reemplaza a la etiqueta "Más popular", que no medía nada.
     *
     * @param  Collection<int, SubscriptionPlan>  $plans
     */
    private function bestValuePlanId(Collection $plans): ?int
    {
        $perDay = $plans
            ->filter(fn (SubscriptionPlan $p) => (int) $p->duration_days > 0 && $p->currentPrice() > 0)
            ->mapWithKeys(fn (SubscriptionPlan $p) => [$p->id => $p->currentPrice() / (int) $p->duration_days]);

        if ($perDay->count() < 2) {
            return null;
        }

        $lowest = $perDay->min();
        $winners = $perDay->filter(fn (float $value) => abs($value - $lowest) < 0.000001);

        return $winners->count() === 1 ? (int) $winners->keys()->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPlan(SubscriptionPlan $plan, bool $bestValue = false, ?CarbonInterface $extendsUntil = null): array
    {
        // price, price_formatted y qr_image_url son siempre los de "ahora": con precio especial vigente
        // la página muestra el monto y el QR correctos sin lógica propia.
        $price = $plan->currentPrice();
        $days = (int) $plan->duration_days;

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => $price,
            'price_formatted' => HumanDate::money($price),
            'duration_days' => $days,
            'duration_label' => HumanDate::duration($days),
            'per_month_label' => $this->perMonthLabel($price, $days),
            'best_value' => $bestValue,
            // Solo con acceso vigente: sin él, el plazo empieza el día que se aprueba el pago.
            'extends_until' => HumanDate::long($extendsUntil),
            'description' => $plan->description ?? '',
            'qr_image_url' => $plan->currentQrImageUrl(),
            'special' => $plan->hasActiveSpecialPrice() ? [
                'label' => $plan->special_label ?: 'Precio especial',
                'regular_price_formatted' => HumanDate::money((float) $plan->price),
                'ends_label' => HumanDate::until($plan->special_ends_at),
            ] : null,
        ];
    }

    /**
     * "Bs 17 al mes" para planes de dos meses o más. Redondeado a bolivianos enteros: es una referencia.
     */
    private function perMonthLabel(float $price, int $days): ?string
    {
        if ($price <= 0 || $days <= 0) {
            return null;
        }

        $months = $days % 365 === 0 ? intdiv($days, 365) * 12 : $days / 30;

        if ($months < 2) {
            return null;
        }

        return HumanDate::money((float) round($price / $months)).' al mes';
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentRequest(PaymentRequest $request): array
    {
        $statusLabels = [
            PaymentRequest::STATUS_PENDING => 'En revisión',
            PaymentRequest::STATUS_APPROVED => 'Aprobado',
            PaymentRequest::STATUS_REJECTED => 'No aprobado',
        ];

        $isPending = $request->status === PaymentRequest::STATUS_PENDING;

        return [
            'id' => $request->id,
            'plan_name' => $request->plan?->name ?? 'Plan no disponible',
            'amount_formatted' => HumanDate::money((float) $request->amount),
            'special_price_label' => $request->special_price_label,
            'status' => $request->status,
            'status_label' => $statusLabels[$request->status] ?? $request->status,
            'reference_text' => $request->reference_text ?? '',
            'admin_notes' => $request->admin_notes ?? '',
            // QA: las fechas salían en UTC (4 horas adelantadas a Bolivia).
            'created_at' => HumanDate::dateTime($request->created_at),
            'reviewed_at' => HumanDate::dateTime($request->reviewed_at),
            'proof_url' => route('membership.proof', $request->id),
            // Solo un envío que nadie revisó se puede retirar.
            'cancel_url' => $isPending ? route('membership.payment.cancel', $request->id) : null,
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
            'plan_name' => $code->plan?->name ?? HumanDate::duration((int) $code->duration_days).' de acceso',
            'duration_days' => (int) $code->duration_days,
            'duration_label' => HumanDate::duration((int) $code->duration_days),
            'redeemed_at' => HumanDate::dateTime($code->redeemed_at),
        ];
    }
}
