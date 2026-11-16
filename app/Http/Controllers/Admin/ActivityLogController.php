<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\Activity;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\PaymentRequest;
use App\Models\QuickLink;
use App\Models\QuickLinkCategory;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\HumanDate;
use App\Support\LikePattern;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visor de bitácora de auditoría (spatie/laravel-activitylog).
 * Acceso restringido exclusivamente al rol SuperAdmin.
 */
class ActivityLogController extends Controller
{
    public const PAGE_SIZE = 25;

    /**
     * Etiquetas legibles por módulo (log_name).
     *
     * @var array<string, string>
     */
    private const LOG_LABELS = [
        'users' => 'Usuarios',
        'security' => 'Seguridad',
        'books' => 'Libros',
        'book_editions' => 'Ediciones',
        'subscriptions' => 'Suscripciones',
        'subscription_plans' => 'Planes',
        'payment_requests' => 'Solicitudes de pago',
        'access_codes' => 'Códigos de acceso',
        'quick_links' => 'Accesos rápidos',
        'quick_link_categories' => 'Categorías de accesos',
        'default' => 'General',
    ];

    /**
     * Etiquetas legibles por tipo de evento.
     *
     * @var array<string, string>
     */
    private const EVENT_LABELS = [
        'created' => 'Creado',
        'updated' => 'Actualizado',
        'deleted' => 'Eliminado',
        'restored' => 'Restaurado',
    ];

    /**
     * Claves sensibles que nunca deben exponerse en el visor de propiedades.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Renderiza la vista principal de la bitácora para SuperAdmin.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasRole('SuperAdmin'), 403);

        $initialPayload = $this->buildPaginatedPayload($request);

        return view('admin.activity-logs.index', [
            'initialData' => $initialPayload,
            'logOptions' => $this->availableLogOptions(),
            'eventOptions' => $this->availableEventOptions(),
        ]);
    }

    /**
     * Endpoint JSON paginado para búsqueda y filtros en tiempo real.
     */
    public function list(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('SuperAdmin'), 403);

        return response()->json($this->buildPaginatedPayload($request));
    }

    /**
     * Construye la consulta filtrada y retorna el payload paginado.
     *
     * @return array<string, mixed>
     */
    private function buildPaginatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'log_name' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $query = Activity::query()
            ->with([
                'causer' => function (MorphTo $morphTo): void {
                    if (method_exists($morphTo, 'withTrashed')) {
                        $morphTo->withTrashed();
                    }
                },
            ])
            ->orderByDesc('id');

        if (filled($validated['log_name'] ?? null) && $validated['log_name'] !== 'all') {
            $query->where('log_name', $validated['log_name']);
        }

        if (filled($validated['event'] ?? null) && $validated['event'] !== 'all') {
            if ($validated['event'] === 'manual') {
                $query->whereNull('event');
            } else {
                $query->where('event', $validated['event']);
            }
        }

        if (filled($validated['date_from'] ?? null)) {
            $fromUtc = Carbon::createFromFormat('Y-m-d', $validated['date_from'], HumanDate::TIMEZONE)
                ->startOfDay()
                ->utc();
            $query->where('created_at', '>=', $fromUtc);
        }

        if (filled($validated['date_to'] ?? null)) {
            $toUtc = Carbon::createFromFormat('Y-m-d', $validated['date_to'], HumanDate::TIMEZONE)
                ->endOfDay()
                ->utc();
            $query->where('created_at', '<=', $toUtc);
        }

        if (filled($validated['q'] ?? null)) {
            $term = trim($validated['q']);
            $pattern = LikePattern::contains($term);
            $escape = LikePattern::ESCAPE_CLAUSE;

            $query->where(function (Builder $q) use ($pattern, $escape, $term): void {
                $q->whereRaw("description LIKE ? {$escape}", [$pattern])
                    ->orWhereRaw("log_name LIKE ? {$escape}", [$pattern])
                    ->orWhereHasMorph('causer', [User::class], function (Builder $userQuery) use ($pattern, $escape): void {
                        $userQuery->withTrashed()
                            ->where(function (Builder $sub) use ($pattern, $escape): void {
                                $sub->whereRaw("name LIKE ? {$escape}", [$pattern])
                                    ->orWhereRaw("email LIKE ? {$escape}", [$pattern]);
                            });
                    });

                if (ctype_digit($term)) {
                    $q->orWhere('subject_id', (int) $term)
                        ->orWhere('id', (int) $term);
                }
            });
        }

        $page = $query->paginate(self::PAGE_SIZE);

        return [
            'items' => collect($page->items())
                ->map(fn (Activity $activity) => $this->formatActivity($activity))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem() ?? 0,
                'to' => $page->lastItem() ?? 0,
            ],
        ];
    }

    /**
     * Formatea un registro de actividad para el frontend.
     *
     * @return array<string, mixed>
     */
    private function formatActivity(Activity $activity): array
    {
        $logName = $activity->log_name ?: 'default';
        $event = $activity->event;
        $causer = $activity->causer;

        $properties = $this->sanitizeProperties(
            is_array($activity->properties?->toArray()) ? $activity->properties->toArray() : []
        );

        return [
            'id' => $activity->id,
            'log_name' => $logName,
            'log_label' => self::LOG_LABELS[$logName] ?? ucfirst(str_replace('_', ' ', $logName)),
            'event' => $event,
            'event_label' => $event !== null
                ? (self::EVENT_LABELS[$event] ?? ucfirst($event))
                : 'Registro',
            'description' => $activity->description,
            'subject_type_label' => $this->formatSubjectType($activity->subject_type),
            'subject_id' => $activity->subject_id,
            'causer' => $causer instanceof User ? [
                'id' => $causer->id,
                'name' => $causer->name,
                'email' => $causer->email,
                'deleted' => $causer->trashed(),
            ] : null,
            'properties' => $properties,
            'has_properties' => count($properties) > 0,
            'created_at_human' => HumanDate::dateTime($activity->created_at),
            'created_at_relative' => HumanDate::relative($activity->created_at),
        ];
    }

    /**
     * Traduce el morph class del sujeto a un nombre legible en español.
     */
    private function formatSubjectType(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        return match ($subjectType) {
            User::class, (new User)->getMorphClass() => 'Usuario',
            Book::class, (new Book)->getMorphClass() => 'Libro',
            BookEdition::class, (new BookEdition)->getMorphClass() => 'Edición',
            Subscription::class, (new Subscription)->getMorphClass() => 'Suscripción',
            SubscriptionPlan::class, (new SubscriptionPlan)->getMorphClass() => 'Plan',
            PaymentRequest::class, (new PaymentRequest)->getMorphClass() => 'Solicitud de pago',
            AccessCode::class, (new AccessCode)->getMorphClass() => 'Código de acceso',
            QuickLink::class, (new QuickLink)->getMorphClass() => 'Acceso rápido',
            QuickLinkCategory::class, (new QuickLinkCategory)->getMorphClass() => 'Categoría de acceso',
            default => class_basename($subjectType),
        };
    }

    /**
     * Oculta claves sensibles recursivamente dentro de properties.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        $sanitized = [];

        foreach ($properties as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $sanitized[$key] = '[oculto]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProperties($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Lista de módulos disponibles para el selector de filtro.
     *
     * @return list<array{value: string, label: string}>
     */
    private function availableLogOptions(): array
    {
        $existingLogs = Activity::query()
            ->select('log_name')
            ->distinct()
            ->pluck('log_name')
            ->filter()
            ->values()
            ->all();

        $allKeys = array_values(array_unique(array_merge(array_keys(self::LOG_LABELS), $existingLogs)));

        return array_map(fn (string $key) => [
            'value' => $key,
            'label' => self::LOG_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
        ], $allKeys);
    }

    /**
     * Lista de tipos de evento para el selector de filtro.
     *
     * @return list<array{value: string, label: string}>
     */
    private function availableEventOptions(): array
    {
        return [
            ['value' => 'created', 'label' => 'Creado'],
            ['value' => 'updated', 'label' => 'Actualizado'],
            ['value' => 'deleted', 'label' => 'Eliminado'],
            ['value' => 'restored', 'label' => 'Restaurado'],
            ['value' => 'manual', 'label' => 'Registro manual'],
        ];
    }
}
