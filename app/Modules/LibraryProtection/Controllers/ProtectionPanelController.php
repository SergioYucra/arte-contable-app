<?php

namespace App\Modules\LibraryProtection\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\BookEdition;
use App\Models\User;
use App\Modules\LibraryProtection\Models\BookPageView;
use App\Modules\LibraryProtection\Models\ReadingHold;
use App\Modules\LibraryProtection\Services\ReaderCodes;
use App\Modules\LibraryProtection\Services\ReadingGuard;
use App\Support\HumanDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Panel "Protección de libros" del administrador (/admin/libros/proteccion).
 *
 * - Lecturas pausadas: reactivar o quitar el acceso (reutiliza la ruta de Membresías).
 * - ¿Quién filtró esta página?: buscar el código impreso en una captura.
 * - Avisos de cuenta compartida y lectores de hoy.
 *
 * Cada acción del administrador queda en la bitácora de seguridad.
 */
class ProtectionPanelController extends Controller
{
    private const RECENT_DAYS = 14;

    private const LOOKUP_DAYS = 30;

    public function __construct(private readonly ReadingGuard $guard) {}

    public function index(): View
    {
        return view('admin.books.protection', [
            'initialData' => $this->payload(),
            'urls' => [
                'data' => route('admin.library-protection.data'),
                'lookup' => route('admin.library-protection.lookup'),
                'books' => route('admin.books.index'),
            ],
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function release(Request $request, ReadingHold $hold): JsonResponse
    {
        $hold->loadMissing('user');

        if (! $hold->isActive()) {
            return response()->json([
                'message' => 'Esa pausa ya había terminado. Actualizamos la lista.',
                'data' => $this->payload(),
            ], 422);
        }

        /** @var User $admin */
        $admin = $request->user();

        $hold->forceFill([
            'released_at' => Carbon::now(),
            'released_by' => $admin->id,
        ])->save();

        // Empieza de cero: ni las marcas de pedidos trampa ni el aviso de ritmo cuentan ya; si
        // vuelve a leer muy rápido, primero se le avisa otra vez.
        $this->guard->startOver((int) $hold->user_id);

        activity('security')
            ->causedBy($admin)
            ->performedOn($hold->user)
            ->event('lectura_reactivada')
            ->withProperties(['pausa_id' => $hold->id, 'motivo' => $hold->reason])
            ->log('El administrador reactivó la lectura de '.($hold->user?->name ?? 'una cuenta').'.');

        return response()->json([
            'success' => true,
            'message' => ($hold->user?->name ?? 'La cuenta').' ya puede volver a leer.',
            'data' => $this->payload(),
        ]);
    }

    public function lookup(Request $request, ReaderCodes $codes): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ], [
            'code.required' => 'Escribe el código que aparece en la página (por ejemplo, K7QM-3XPA).',
            'code.max' => 'El código tiene 8 letras y números, por ejemplo K7QM-3XPA.',
        ]);

        $readerCode = $codes->find($validated['code']);
        if ($readerCode === null || $readerCode->user === null) {
            return response()->json([
                'message' => 'No encontramos ese código. Revisa que esté bien escrito: son 8 letras y números, como K7QM-3XPA.',
            ], 404);
        }

        $student = $readerCode->user;

        /** @var User $admin */
        $admin = $request->user();

        activity('security')
            ->causedBy($admin)
            ->performedOn($student)
            ->event('busqueda_codigo_lector')
            ->withProperties(['codigo' => $readerCode->code])
            ->log("El administrador buscó el código {$readerCode->code} (pertenece a {$student->name}).");

        $activeHold = ReadingHold::active()->where('user_id', $student->id)->latest('ends_at')->first();

        return response()->json([
            'code' => $readerCode->code,
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'is_deactivated' => $student->trashed(),
                'has_membership' => $student->hasActiveSubscription(),
                'reader_since' => HumanDate::long($readerCode->created_at),
            ],
            'active_hold' => $activeHold ? $this->formatHold($activeHold->setRelation('user', $student)) : null,
            'revoke_url' => $student->trashed() ? null : route('admin.subscriptions.members.revoke', $student->id),
            'days' => $this->readingByDay($student),
            'lookup_days' => self::LOOKUP_DAYS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $timezone = (string) config('library.protection.timezone', 'America/La_Paz');
        $today = Carbon::now($timezone)->toDateString();
        $since = Carbon::now()->subDays(self::RECENT_DAYS);

        $activeHolds = ReadingHold::active()->with('user')->latest('starts_at')->get();

        $pastHolds = ReadingHold::query()
            ->with(['user', 'releasedBy'])
            ->where('starts_at', '>=', $since)
            ->where(fn ($q) => $q->whereNotNull('released_at')->orWhere('ends_at', '<=', Carbon::now()))
            ->latest('starts_at')
            ->limit(20)
            ->get();

        $todayRows = BookPageView::query()
            ->where('viewed_on', $today)
            ->selectRaw('user_id, COUNT(*) as pages, MAX(last_viewed_at) as last_seen')
            ->groupBy('user_id')
            ->orderByDesc('pages')
            ->limit(10)
            ->get();

        $readersById = User::withTrashed()->whereIn('id', $todayRows->pluck('user_id'))->get()->keyBy('id');
        $limit = max(1, (int) config('library.protection.daily_page_limit', 300));

        return [
            'stats' => [
                'pages_today' => BookPageView::where('viewed_on', $today)->count(),
                'readers_today' => BookPageView::where('viewed_on', $today)->distinct()->count('user_id'),
                'active_holds' => $activeHolds->count(),
            ],
            'settings' => [
                'daily_page_limit' => $limit,
                'pages_per_minute' => (int) config('library.protection.pages_per_minute', 30),
                'pause_hours' => (int) config('library.protection.pause_hours', 6),
                'velocity_rules' => array_values(array_map(fn ($rule) => [
                    'pages' => (int) ($rule['pages'] ?? 0),
                    'minutes' => (int) ($rule['minutes'] ?? 0),
                ], (array) config('library.protection.velocity_rules', []))),
            ],
            'active_holds' => $activeHolds->map(fn (ReadingHold $hold) => $this->formatHold($hold))->values(),
            'past_holds' => $pastHolds->map(fn (ReadingHold $hold) => $this->formatHold($hold))->values(),
            'sharing_alerts' => $this->sharingAlerts($since),
            'readers_today' => $todayRows->map(function ($row) use ($readersById, $limit) {
                $reader = $readersById->get($row->user_id);

                return [
                    'user_id' => (int) $row->user_id,
                    'name' => $reader?->name ?? 'Cuenta eliminada',
                    'email' => $reader?->email ?? '',
                    'pages' => (int) $row->pages,
                    'percent_of_limit' => min(100, (int) round(((int) $row->pages / $limit) * 100)),
                    'last_seen' => HumanDate::relative(Carbon::parse($row->last_seen)),
                ];
            })->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatHold(ReadingHold $hold): array
    {
        $student = $hold->user;

        return [
            'id' => $hold->id,
            'student' => [
                'id' => $student?->id,
                'name' => $student?->name ?? 'Cuenta eliminada',
                'email' => $student?->email ?? '',
            ],
            'reason' => $hold->reason,
            'reason_text' => $hold->reasonText(),
            'started' => HumanDate::dateTime($hold->starts_at),
            'started_relative' => HumanDate::relative($hold->starts_at),
            'ends' => HumanDate::until($hold->ends_at),
            'is_active' => $hold->isActive(),
            'released_by' => $hold->releasedBy?->name,
            'released' => $hold->released_at ? HumanDate::dateTime($hold->released_at) : null,
            'release_url' => route('admin.library-protection.holds.release', $hold->id),
            'revoke_url' => $student && ! $student->trashed()
                ? route('admin.subscriptions.members.revoke', $student->id)
                : null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sharingAlerts(Carbon $since): Collection
    {
        $alerts = Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'posible_cuenta_compartida')
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->limit(20)
            ->get();

        $users = User::withTrashed()
            ->whereIn('id', $alerts->where('causer_type', (new User)->getMorphClass())->pluck('causer_id'))
            ->get()
            ->keyBy('id');

        return $alerts->map(function (Activity $alert) use ($users) {
            $student = $users->get($alert->causer_id);
            $properties = $alert->properties;

            return [
                'id' => $alert->id,
                'student' => [
                    'id' => $student?->id,
                    'name' => $student?->name ?? 'Cuenta eliminada',
                    'email' => $student?->email ?? '',
                ],
                'device_switches' => (int) $properties->get('cambios_de_dispositivo', 0),
                'distinct_ips' => (int) $properties->get('conexiones_distintas', 0),
                'window_hours' => (int) $properties->get('ventana_horas', 24),
                'when' => HumanDate::dateTime($alert->created_at),
                'revoke_url' => $student && ! $student->trashed()
                    ? route('admin.subscriptions.members.revoke', $student->id)
                    : null,
            ];
        })->values();
    }

    /**
     * Lo que leyó la persona en los últimos días, agrupado por día y libro, con rangos de
     * páginas ("1–12, 15") y las conexiones (IP) desde donde leyó.
     *
     * @return list<array<string, mixed>>
     */
    private function readingByDay(User $student): array
    {
        $timezone = (string) config('library.protection.timezone', 'America/La_Paz');
        $from = Carbon::now($timezone)->subDays(self::LOOKUP_DAYS)->toDateString();

        $rows = BookPageView::query()
            ->where('user_id', $student->id)
            ->where('viewed_on', '>=', $from)
            ->orderByDesc('viewed_on')
            ->orderBy('book_edition_id')
            ->orderBy('page')
            ->limit(5000)
            ->get(['viewed_on', 'book_edition_id', 'page', 'ip_address']);

        $editions = BookEdition::withTrashed()
            ->with(['book' => fn ($q) => $q->withTrashed()])
            ->whereIn('id', $rows->pluck('book_edition_id')->unique())
            ->get()
            ->keyBy('id');

        $days = [];
        foreach ($rows->groupBy('viewed_on') as $day => $dayRows) {
            $books = [];
            foreach ($dayRows->groupBy('book_edition_id') as $editionId => $editionRows) {
                $edition = $editions->get($editionId);
                $books[] = [
                    'title' => $edition?->book?->title ?? 'Libro eliminado',
                    'edition' => $edition?->edition_name ?? '',
                    'pages_count' => $editionRows->count(),
                    'pages' => $this->compactRanges($editionRows->pluck('page')->map(fn ($p) => (int) $p)->all()),
                ];
            }

            $days[] = [
                'date' => HumanDate::long(Carbon::parse((string) $day, $timezone)),
                'pages_count' => $dayRows->count(),
                'books' => $books,
                'ips' => $dayRows->pluck('ip_address')->filter()->unique()->values()->all(),
            ];
        }

        return $days;
    }

    /**
     * [1,2,3,5,7,8] → "1–3, 5, 7–8"
     *
     * @param  list<int>  $pages
     */
    private function compactRanges(array $pages): string
    {
        sort($pages);
        $parts = [];
        $start = $prev = null;

        foreach ($pages as $page) {
            if ($start === null) {
                $start = $prev = $page;

                continue;
            }
            if ($page === $prev + 1) {
                $prev = $page;

                continue;
            }
            $parts[] = $start === $prev ? (string) $start : "{$start}–{$prev}";
            $start = $prev = $page;
        }

        if ($start !== null) {
            $parts[] = $start === $prev ? (string) $start : "{$start}–{$prev}";
        }

        return implode(', ', $parts);
    }
}
