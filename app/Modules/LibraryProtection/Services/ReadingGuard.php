<?php

namespace App\Modules\LibraryProtection\Services;

use App\Models\BookEdition;
use App\Models\User;
use App\Modules\LibraryProtection\Models\BookPageView;
use App\Modules\LibraryProtection\Models\ReadingHold;
use App\Support\HumanDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Decide si se entrega una página del libro y deja el registro de quién la vio.
 *
 * Orden de las revisiones (la primera que falla responde):
 *  1. Origen: la imagen solo se pide desde el lector (Sec-Fetch-*), no abierta en otra pestaña.
 *  2. Pausa vigente de la cuenta.
 *  3. Edición publicada y página dentro del libro (si no, cuenta como pedido trampa).
 *  4. Un dispositivo a la vez.
 *  5. Permiso: muestra gratuita o membresía de esa edición (si no, pedido trampa).
 *  6. Tope diario de páginas distintas.
 *  7. Registro en book_page_views.
 *  8. Velocidad no humana: la primera vez, un aviso ("vas muy rápido") y la cuenta empieza de
 *     cero; si sigue a ese ritmo, pausa automática y aviso al administrador.
 *
 * El personal (SuperAdmin/Admin) no tiene tope, pausa ni límite de dispositivos, pero lo que
 * lee queda registrado y sus páginas también llevan marca de agua.
 */
final class ReadingGuard
{
    public function __construct(
        private readonly ReaderDevices $devices,
        private readonly ReadingAlerts $alerts,
    ) {}

    public function check(User $user, BookEdition $edition, int $page, Request $request): ReadingDecision
    {
        $isStaff = $user->hasAnyRole('SuperAdmin', 'Admin');

        if (! $this->comesFromReader($request)) {
            return ReadingDecision::deny(403, 'fuera_del_lector', 'Las páginas del libro solo se pueden ver dentro del lector de la biblioteca.');
        }

        if (! $isStaff && ($hold = $this->activeHold($user)) !== null) {
            return $this->pausedDecision($hold);
        }

        $edition->loadMissing('book');
        $book = $edition->book;
        $isAvailable = $book !== null
            && ! $book->trashed()
            && $book->is_published
            && $edition->status === BookEdition::STATUS_READY;

        // No cuenta como pedido trampa: el administrador puede despublicar un libro mientras
        // alguien lo tiene abierto, y esa persona reintentaría con todo derecho.
        if (! $isStaff && ! $isAvailable) {
            return ReadingDecision::deny(404, 'no_disponible', 'Esta edición no está disponible para lectura en este momento.');
        }

        if ($page < 1 || $page > (int) $edition->total_pages) {
            return $this->trap($user, 'pagina_fuera_de_rango', 404, 'no_disponible', 'Esa página no existe en esta edición.', $isStaff);
        }

        if (! $isStaff && ! $this->devices->allows($user, $request)) {
            return ReadingDecision::deny(409, 'otro_dispositivo', 'Solo se puede leer en un dispositivo a la vez. Si sigues leyendo aquí, el otro se detendrá.');
        }

        if (! $user->canReadEditionPage($edition, $page)) {
            return $this->trap($user, 'pagina_sin_membresia', 403, 'membresia', 'Para leer más allá de las páginas de muestra necesitas una membresía activa.', $isStaff);
        }

        $today = $this->today();
        $limit = max(1, (int) config('library.protection.daily_page_limit', 300));

        $alreadySeenToday = BookPageView::query()
            ->where('user_id', $user->id)
            ->where('book_edition_id', $edition->id)
            ->where('page', $page)
            ->where('viewed_on', $today)
            ->exists();

        $seenToday = BookPageView::query()
            ->where('user_id', $user->id)
            ->where('viewed_on', $today)
            ->count();

        if (! $isStaff && ! $alreadySeenToday && $seenToday >= $limit) {
            return ReadingDecision::deny(
                429,
                'limite_diario',
                "El límite es de {$limit} páginas distintas por día. Mañana puedes seguir leyendo desde aquí; las páginas que ya viste hoy las puedes volver a abrir.",
                $this->startOfTomorrow(),
            );
        }

        $recordedViewId = $this->record($user, $edition, $page, $today, $request, $alreadySeenToday);

        if (! $isStaff && ! $alreadySeenToday && ($tooFast = $this->checkVelocity($user)) !== null) {
            return $tooFast;
        }

        $seenAfter = $alreadySeenToday ? $seenToday : $seenToday + 1;

        return ReadingDecision::allow($isStaff ? null : max(0, $limit - $seenAfter), $recordedViewId);
    }

    /**
     * Deshace el registro de una página que al final no se entregó (por ejemplo, porque no se
     * le pudo poner la marca de agua): no cuenta para el tope de hoy ni para el ritmo.
     */
    public function forgetView(ReadingDecision $decision): void
    {
        if ($decision->recordedViewId !== null) {
            BookPageView::query()->whereKey($decision->recordedViewId)->delete();
        }
    }

    /** Al reactivar una lectura, la cuenta empieza de cero: sin marcas de pedidos trampa ni aviso de ritmo. */
    public function startOver(int $userId): void
    {
        Cache::forget($this->strikesKey($userId));
        Cache::forget($this->speedWarningKey($userId));
    }

    /**
     * Las páginas se piden con fetch() desde el lector (Sec-Fetch-Site: same-origin,
     * Sec-Fetch-Dest: empty). Abrir la URL en otra pestaña o incrustarla en otro sitio se
     * rechaza. Los navegadores antiguos que no envían estas cabeceras se aceptan.
     */
    private function comesFromReader(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            return false;
        }

        $destination = $request->headers->get('Sec-Fetch-Dest');

        return $destination === null
            || ! in_array($destination, ['document', 'iframe', 'frame', 'embed', 'object'], true);
    }

    private function activeHold(User $user): ?ReadingHold
    {
        return ReadingHold::active()->where('user_id', $user->id)->latest('ends_at')->first();
    }

    private function pausedDecision(ReadingHold $hold): ReadingDecision
    {
        return ReadingDecision::deny(
            423,
            'lectura_pausada',
            'Notamos un ritmo de lectura fuera de lo normal y la pausamos por un rato. Se reanuda sola '
                .HumanDate::until($hold->ends_at).'. Si crees que es un error, escribe al administrador.',
            $hold->ends_at,
        );
    }

    /**
     * Un pedido que el lector real nunca hace suma una marca; con `trap_strikes.limit` marcas
     * en la ventana, la lectura se pausa.
     */
    private function trap(User $user, string $kind, int $status, string $code, string $message, bool $isStaff = false): ReadingDecision
    {
        if ($isStaff) {
            return ReadingDecision::deny($status, $code, $message);
        }

        $config = (array) config('library.protection.trap_strikes', []);
        $limit = max(1, (int) ($config['limit'] ?? 5));
        $window = max(1, (int) ($config['window_minutes'] ?? 30));
        $key = $this->strikesKey($user->id);

        Cache::add($key, 0, now()->addMinutes($window));
        $strikes = (int) Cache::increment($key);

        if ($strikes >= $limit) {
            Cache::forget($key);

            $hold = $this->pause($user, ReadingHold::REASON_TRAPS, [
                'strikes' => $strikes,
                'ultimo' => $kind,
                'ventana_minutos' => $window,
            ]);

            return $this->pausedDecision($hold);
        }

        return ReadingDecision::deny($status, $code, $message);
    }

    /**
     * Ritmo de lectura no humano. La primera vez solo se avisa (quien hojea buscando un tema
     * puede pasarse sin querer); si sigue a ese ritmo después del aviso, se pausa.
     */
    private function checkVelocity(User $user): ?ReadingDecision
    {
        // Lo leído antes de que terminara la última pausa ya se juzgó: si el administrador la
        // reactiva, la cuenta empieza de cero y no vuelve a pausarse con las mismas páginas.
        $lastHold = ReadingHold::where('user_id', $user->id)->latest('id')->first();
        $lastHoldEnd = $lastHold === null ? null : ($lastHold->released_at ?? $lastHold->ends_at);

        // Lo mismo después del aviso: solo cuentan las páginas nuevas pedidas desde entonces.
        $warning = Cache::get($this->speedWarningKey($user->id));
        $warning = is_array($warning) ? $warning : null;

        foreach ((array) config('library.protection.velocity_rules', []) as $rule) {
            $pages = max(1, (int) ($rule['pages'] ?? 0));
            $minutes = max(1, (int) ($rule['minutes'] ?? 0));

            $since = Carbon::now()->subMinutes($minutes);
            if ($lastHoldEnd !== null && $lastHoldEnd->greaterThan($since)) {
                $since = $lastHoldEnd;
            }

            $newPages = BookPageView::query()
                ->where('user_id', $user->id)
                ->where('first_viewed_at', '>=', $since)
                ->when($warning !== null, fn ($query) => $query->where('id', '>', (int) ($warning['after_view_id'] ?? 0)))
                ->count();

            if ($newPages < $pages) {
                continue;
            }

            if ($warning === null) {
                return $this->warnAboutSpeed($user, $newPages, $minutes);
            }

            $hold = $this->pause($user, ReadingHold::REASON_VELOCITY, [
                'pages' => $newPages,
                'minutes' => $minutes,
                'aviso_previo' => $warning['at'] ?? null,
            ]);

            return $this->pausedDecision($hold);
        }

        return null;
    }

    /**
     * Primer aviso de ritmo: no pausa ni escribe al administrador. La página que lo provocó ya
     * quedó registrada, así que "Seguir leyendo" la entrega sin volver a contarla.
     */
    private function warnAboutSpeed(User $user, int $pages, int $minutes): ReadingDecision
    {
        $now = Carbon::now();
        $hours = max(1, (int) config('library.protection.velocity_warning_hours', 24));

        // add() y no put(): si dos pedidos cruzan el ritmo a la vez, vale el primer aviso.
        Cache::add($this->speedWarningKey($user->id), [
            'at' => $now->toIso8601String(),
            'after_view_id' => (int) BookPageView::query()->where('user_id', $user->id)->max('id'),
        ], $now->copy()->addHours($hours));

        activity('security')
            ->causedBy($user)
            ->event('aviso_de_ritmo')
            ->withProperties(['pages' => $pages, 'minutes' => $minutes])
            ->log(sprintf(
                'Se le avisó que pasaba páginas muy rápido (%d en %d minutos). Si sigue a ese ritmo, la lectura se pausa.',
                $pages,
                $minutes,
            ));

        $pauseHours = max(1, (int) config('library.protection.pause_hours', 6));

        return ReadingDecision::deny(
            429,
            'ritmo_rapido',
            "Estás pasando páginas más rápido de lo que se alcanza a leer. Sigue con calma: si continúa este ritmo, la lectura se pausará por {$pauseHours} horas. Para ir a un tema, usa el temario o escribe el número de página.",
        );
    }

    private function strikesKey(int $userId): string
    {
        return "library-protection:strikes:{$userId}";
    }

    private function speedWarningKey(int $userId): string
    {
        return "library-protection:speed-warning:{$userId}";
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function pause(User $user, string $reason, array $details): ReadingHold
    {
        $now = Carbon::now();

        $hold = ReadingHold::create([
            'user_id' => $user->id,
            'reason' => $reason,
            'details' => $details,
            'starts_at' => $now,
            'ends_at' => $now->copy()->addHours(max(1, (int) config('library.protection.pause_hours', 6))),
        ]);

        activity('security')
            ->causedBy($user)
            ->event('lectura_pausada')
            ->withProperties([
                'motivo' => $reason,
                'detalle' => $details,
                'hasta' => $hold->ends_at->toIso8601String(),
            ])
            ->log('Lectura pausada automáticamente. '.$hold->reasonText());

        $this->alerts->paused($user, $hold);

        return $hold;
    }

    /** Devuelve el id del registro nuevo, o null si solo sumó una visita a uno existente. */
    private function record(User $user, BookEdition $edition, int $page, string $today, Request $request, bool $alreadySeenToday): ?int
    {
        $now = Carbon::now();
        $key = [
            'user_id' => $user->id,
            'book_edition_id' => $edition->id,
            'page' => $page,
            'viewed_on' => $today,
        ];

        if (! $alreadySeenToday) {
            try {
                return (int) BookPageView::create($key + [
                    'hits' => 1,
                    'first_viewed_at' => $now,
                    'last_viewed_at' => $now,
                    'ip_address' => $request->ip(),
                ])->getKey();
            } catch (UniqueConstraintViolationException) {
                // Dos pedidos simultáneos de la misma página: el segundo suma una visita.
            }
        }

        BookPageView::query()->where($key)->increment('hits', 1, [
            'last_viewed_at' => $now,
            'ip_address' => $request->ip(),
        ]);

        return null;
    }

    private function today(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    private function startOfTomorrow(): Carbon
    {
        return Carbon::now($this->timezone())->addDay()->startOfDay()->utc();
    }

    private function timezone(): string
    {
        return (string) config('library.protection.timezone', 'America/La_Paz');
    }
}
