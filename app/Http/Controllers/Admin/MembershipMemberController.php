<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Subscription;
use App\Models\SubscriptionGrant;
use App\Models\User;
use App\Services\MembershipService;
use App\Support\HumanDate;
use App\Support\LikePattern;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Personas con acceso": quién tiene o tuvo membresía, su historial y quitar el acceso.
 */
class MembershipMemberController extends Controller
{
    public const PAGE_SIZE = 50;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        private readonly MembershipService $membershipService,
    ) {}

    /**
     * Lista paginada con búsqueda por nombre o correo y filtro por estado.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,active,expired,revoked,expiring'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $now = Carbon::now();
        $status = $validated['status'] ?? 'active';

        $hasActive = fn (Builder $q) => $q
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', $now);

        // Estado de la suscripción más reciente de cada persona (para distinguir vencido de retirado).
        $latestStatus = '(select s.status from subscriptions s where s.user_id = users.id order by s.id desc limit 1)';
        $latestExpiry = '(select max(s.expires_at) from subscriptions s where s.user_id = users.id)';

        // Sin relación nueva en User (ese archivo lo modifica fix/revision-3): el conteo va por subconsulta.
        $query = User::query()
            ->select('users.*')
            ->addSelect(['subscription_grants_count' => SubscriptionGrant::selectRaw('count(*)')
                ->whereColumn('subscription_grants.user_id', 'users.id')])
            ->whereHas('subscriptions')
            ->with(['subscriptions' => fn ($q) => $q->latest('id')]);

        match ($status) {
            'active' => $query->whereHas('subscriptions', $hasActive),
            'expiring' => $query->whereHas('subscriptions', fn (Builder $q) => $hasActive($q)
                ->where('expires_at', '<=', $now->copy()->addDays(15))),
            'expired' => $query->whereDoesntHave('subscriptions', $hasActive)
                ->whereRaw("{$latestStatus} = ?", [Subscription::STATUS_ACTIVE]),
            'revoked' => $query->whereDoesntHave('subscriptions', $hasActive)
                ->whereRaw("{$latestStatus} = ?", [Subscription::STATUS_REVOKED]),
            default => null,
        };

        if (filled($validated['q'] ?? null)) {
            $pattern = LikePattern::contains($validated['q']);
            $escape = LikePattern::ESCAPE_CLAUSE;

            $query->where(fn (Builder $q) => $q
                ->whereRaw("name LIKE ? {$escape}", [$pattern])
                ->orWhereRaw("email LIKE ? {$escape}", [$pattern]));
        }

        // Activos: primero quienes vencen antes. Resto: lo más reciente primero.
        in_array($status, ['active', 'expiring'], true)
            ? $query->orderByRaw("{$latestExpiry} asc")
            : $query->orderByRaw("{$latestExpiry} desc");

        $page = $query->orderBy('users.id')->paginate(self::PAGE_SIZE);

        return response()->json([
            'items' => collect($page->items())->map(fn (User $u) => $this->formatMember($u))->values()->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'total' => $page->total(),
        ]);
    }

    /**
     * Historial de una persona: cada acceso otorgado y cada vez que se le retiró.
     */
    public function history(User $user): JsonResponse
    {
        $grants = SubscriptionGrant::with(['plan', 'grantor', 'accessCode'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn (SubscriptionGrant $g) => [
                'type' => 'grant',
                'source' => $g->source(),
                'title' => $g->source() === SubscriptionGrant::SOURCE_CODE
                    ? 'Usó un código'
                    : 'Pago aprobado',
                'detail' => trim(implode(' · ', array_filter([
                    $g->plan?->name,
                    '+'.HumanDate::duration($g->days),
                    $g->source() === SubscriptionGrant::SOURCE_CODE ? $g->accessCode?->code : null,
                ]))),
                'previous_until' => HumanDate::long($g->previous_expires_at),
                'new_until' => HumanDate::long($g->new_expires_at),
                'by' => $g->source() === SubscriptionGrant::SOURCE_PAYMENT ? $g->grantor?->name : null,
                'at' => HumanDate::dateTime($g->created_at),
                'sort' => $g->created_at?->getTimestamp() ?? 0,
            ]);

        $revocations = Activity::query()
            ->where('log_name', 'subscriptions')
            ->where('subject_type', (new Subscription)->getMorphClass())
            ->whereIn('subject_id', $user->subscriptions()->pluck('id'))
            ->where('description', 'like', 'Acceso de % retirado')
            ->with('causer')
            ->get()
            ->map(fn (Activity $a) => [
                'type' => 'revoke',
                'source' => null,
                'title' => 'Acceso retirado',
                'detail' => (string) ($a->properties['reason'] ?? ''),
                'previous_until' => null,
                'new_until' => null,
                'by' => $a->causer?->name,
                'at' => HumanDate::dateTime($a->created_at),
                'sort' => $a->created_at?->getTimestamp() ?? 0,
            ]);

        $events = $grants->concat($revocations)
            ->sortByDesc('sort')
            ->values()
            ->map(fn (array $e) => collect($e)->except('sort')->all());

        return response()->json([
            'member' => $this->formatMember($this->withMemberData($user)),
            'events' => $events,
        ]);
    }

    /**
     * Quita el acceso vigente. Exige motivo y queda en la bitácora.
     */
    public function revoke(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'Escribe por qué se quita el acceso. Queda guardado en el historial.',
            'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
        ]);

        DB::transaction(fn () => $this->membershipService->revoke($user, $request->user(), trim($validated['reason'])));

        return response()->json([
            'success' => true,
            'message' => "{$user->name} ya no tiene acceso a lo de pago. Sus apuntes y avance se conservan.",
            'member' => $this->formatMember($this->withMemberData($user)),
        ]);
    }

    private function withMemberData(User $user): User
    {
        $user->load(['subscriptions' => fn ($q) => $q->latest('id')]);
        $user->setAttribute('subscription_grants_count', SubscriptionGrant::where('user_id', $user->id)->count());

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMember(User $user): array
    {
        $current = $this->membershipService->currentSubscription($user);
        $latest = $user->subscriptions->sortByDesc('id')->first();

        $status = match (true) {
            $current !== null => self::STATUS_ACTIVE,
            $latest?->status === Subscription::STATUS_REVOKED => self::STATUS_REVOKED,
            default => self::STATUS_EXPIRED,
        };

        $until = $current?->expires_at ?? $latest?->expires_at;
        $daysLeft = $current !== null ? (int) ceil(Carbon::now()->floatDiffInDays($current->expires_at)) : 0;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $status,
            'status_label' => match ($status) {
                self::STATUS_ACTIVE => 'Con acceso',
                self::STATUS_REVOKED => 'Acceso retirado',
                default => 'Vencido',
            },
            'until' => HumanDate::long($until),
            'days_left' => $daysLeft,
            'expiring_soon' => $status === self::STATUS_ACTIVE && $daysLeft <= 15,
            'grants_count' => (int) ($user->subscription_grants_count ?? 0),
            'history_url' => route('admin.subscriptions.members.history', $user->id),
            'revoke_url' => route('admin.subscriptions.members.revoke', $user->id),
        ];
    }
}
