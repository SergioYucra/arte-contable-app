<?php

namespace App\Http\Controllers\Student;

use App\Core\Storage\PrivateVault;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\ReadingState;
use App\Models\StudyNote;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryController extends Controller
{
    /**
     * Muestra la Biblioteca y Catálogo Editorial del Estudiante (/biblioteca).
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->getMembershipSummary($user);
        $hasFullAccess = $membership['is_active'];

        // 1. Cargar libros publicados con sus ediciones (priorizando listas y vigentes)
        $booksCollection = Book::where('is_published', true)
            ->with([
                'editions' => fn ($q) => $q
                    ->orderByRaw("CASE WHEN status = 'ready' THEN 0 ELSE 1 END")
                    ->orderByDesc('is_current')
                    ->latest('id'),
            ])
            ->latest('updated_at')
            ->get();

        $editionIds = $booksCollection
            ->pluck('editions')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        // 2. Progreso de lectura y conteo de apuntes en bloque (Cero N+1)
        $readingStatesByEdition = [];
        $notesCountByEdition = [];

        if (! empty($editionIds)) {
            $readingStatesByEdition = ReadingState::where('user_id', $user->id)
                ->whereIn('book_edition_id', $editionIds)
                ->get()
                ->keyBy('book_edition_id');

            $notesCountByEdition = StudyNote::where('user_id', $user->id)
                ->whereIn('book_edition_id', $editionIds)
                ->selectRaw('book_edition_id, COUNT(*) as total_notes')
                ->groupBy('book_edition_id')
                ->pluck('total_notes', 'book_edition_id')
                ->all();
        }

        // 3. Formatear catálogo y detectar el libro en curso más reciente (Hero "Continuar Lectura")
        $continueReading = null;
        $latestReadingTimestamp = null;

        $books = $booksCollection->map(function (Book $book) use (
            $readingStatesByEdition,
            $notesCountByEdition,
            $hasFullAccess,
            &$continueReading,
            &$latestReadingTimestamp
        ): array {
            $coverUrl = ! empty($book->cover_path)
                ? Storage::disk('public')->url($book->cover_path)
                : null;

            $formattedEditions = $book->editions->map(function (BookEdition $ed) use (
                $book,
                $coverUrl,
                $readingStatesByEdition,
                $notesCountByEdition,
                $hasFullAccess,
                &$continueReading,
                &$latestReadingTimestamp
            ): array {
                /** @var ReadingState|null $state */
                $state = $readingStatesByEdition[$ed->id] ?? null;
                $lastPage = $state ? (int) $state->last_page : 0;
                $totalPages = (int) $ed->total_pages;
                $samplePages = (int) $ed->sample_pages_count;
                $toc = is_array($ed->toc_json) ? array_values($ed->toc_json) : [];

                $progressPercent = ($totalPages > 0 && $lastPage > 0)
                    ? min(100, (int) round(($lastPage / $totalPages) * 100))
                    : 0;

                $currentChapter = $this->resolveChapterForPage($toc, $lastPage);
                $updatedAtHuman = $state?->updated_at
                    ? Carbon::parse($state->updated_at)->diffForHumans()
                    : null;

                // Evaluar si esta edición es la lectura activa más reciente del estudiante
                if ($state && $lastPage > 0 && $ed->status === BookEdition::STATUS_READY) {
                    $stateTimestamp = $state->updated_at ? Carbon::parse($state->updated_at)->getTimestamp() : 0;
                    if ($latestReadingTimestamp === null || $stateTimestamp >= $latestReadingTimestamp) {
                        $latestReadingTimestamp = $stateTimestamp;
                        $continueReading = [
                            'book_id' => $book->id,
                            'book_title' => $book->title,
                            'book_subtitle' => $book->subtitle ?? '',
                            'author_name' => $book->author_name,
                            'cover_url' => $coverUrl,
                            'edition_id' => $ed->id,
                            'edition_name' => $ed->edition_name,
                            'total_pages' => $totalPages,
                            'sample_pages_count' => $samplePages,
                            'last_page' => $lastPage,
                            'progress_percent' => $progressPercent,
                            'current_chapter' => $currentChapter,
                            'updated_at_human' => $updatedAtHuman ?: 'Reciente',
                            'notes_count' => (int) ($notesCountByEdition[$ed->id] ?? 0),
                        ];
                    }
                }

                return [
                    'id' => $ed->id,
                    'book_id' => $book->id,
                    'edition_name' => $ed->edition_name,
                    'total_pages' => $totalPages,
                    'sample_pages_count' => $samplePages,
                    'max_allowed_page' => $hasFullAccess ? $totalPages : min($totalPages, $samplePages),
                    'status' => $ed->status,
                    'is_ready' => $ed->status === BookEdition::STATUS_READY && $totalPages > 0,
                    'is_current' => (bool) $ed->is_current,
                    'toc' => $toc,
                    'last_page' => $lastPage,
                    'progress_percent' => $progressPercent,
                    'current_chapter' => $currentChapter,
                    'updated_at_human' => $updatedAtHuman,
                    'notes_count' => (int) ($notesCountByEdition[$ed->id] ?? 0),
                    'page_url_template' => route('library.editions.page', [
                        'edition' => $ed->id,
                        'page' => '__PAGE__',
                    ]),
                    'save_progress_url' => route('library.editions.progress', $ed->id),
                ];
            })->values()->all();

            return [
                'id' => $book->id,
                'title' => $book->title,
                'subtitle' => $book->subtitle ?? '',
                'slug' => $book->slug,
                'author_name' => $book->author_name,
                'description' => $book->description ?? '',
                'cover_url' => $coverUrl,
                'editions' => $formattedEditions,
            ];
        })->values()->all();

        $openReader = null;
        if ($request->filled('edition')) {
            $openReader = [
                'edition_id' => (int) $request->query('edition'),
                'page' => max(1, (int) $request->query('page', 1)),
            ];
        }

        return view('library.index', [
            'initialBooks' => $books,
            'initialContinueReading' => $continueReading,
            'initialMembership' => $membership,
            'initialOpenReader' => $openReader,
            'membershipUrl' => route('membership.index'),
        ]);
    }

    /**
     * Acceso directo al lector de una edición (/leer/{edition}?page={last_page}).
     */
    public function read(Request $request, BookEdition $edition): View
    {
        $requestedPage = max(1, (int) $request->query('page', 1));
        $request->merge([
            'edition' => $edition->id,
            'page' => $requestedPage,
        ]);

        return $this->index($request);
    }

    /**
     * Transmite de forma protegida una página WebP de la edición aplicando el Paywall en servidor.
     */
    public function streamPage(Request $request, BookEdition $edition, int $page): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->can('manage-users');

        $edition->loadMissing('book');
        $book = $edition->book;

        if (! $isAdmin) {
            if (
                $book === null ||
                $book->trashed() ||
                ! $book->is_published ||
                $edition->status !== BookEdition::STATUS_READY
            ) {
                abort(404, 'Esta edición no se encuentra disponible para lectura.');
            }
        }

        $totalPages = (int) $edition->total_pages;
        if ($page < 1 || $totalPages < 1 || $page > $totalPages) {
            abort(404, 'Número de página fuera de rango.');
        }

        $hasFullAccess = $isAdmin || $user->hasActiveSubscription();
        $maxAllowedPage = $hasFullAccess ? $totalPages : (int) $edition->sample_pages_count;

        if ($page > $maxAllowedPage) {
            abort(403, 'Se requiere una membresía activa para leer más allá de las páginas de muestra gratuita.');
        }

        return PrivateVault::streamInline(
            "editions/{$edition->id}/page-{$page}.webp",
            "edicion-{$edition->id}-pagina-{$page}.webp"
        );
    }

    /**
     * Guarda o actualiza la última página leída por el estudiante en una edición (ReadingState).
     */
    public function saveProgress(Request $request, BookEdition $edition): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->can('manage-users');

        $edition->loadMissing('book');
        $book = $edition->book;

        if (
            ! $isAdmin &&
            ($book === null || $book->trashed() || ! $book->is_published || $edition->status !== BookEdition::STATUS_READY)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Esta edición no se encuentra disponible.',
            ], 404);
        }

        $totalPages = max(1, (int) $edition->total_pages);

        $validated = $request->validate([
            'last_page' => ['required', 'integer', 'min:1', 'max:'.$totalPages],
        ], [
            'last_page.required' => 'Indica el número de página actual.',
            'last_page.integer' => 'El número de página debe ser un entero válido.',
            'last_page.min' => 'La página mínima es 1.',
            'last_page.max' => "La página no puede superar el total de {$totalPages} páginas.",
        ]);

        $lastPage = (int) $validated['last_page'];
        $hasFullAccess = $isAdmin || $user->hasActiveSubscription();
        $maxAllowedPage = $hasFullAccess ? $totalPages : (int) $edition->sample_pages_count;

        if ($lastPage > $maxAllowedPage) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere una membresía activa para registrar lectura más allá de las páginas de muestra.',
            ], 403);
        }

        $state = ReadingState::updateOrCreate(
            [
                'user_id' => $user->id,
                'book_edition_id' => $edition->id,
            ],
            [
                'last_page' => $lastPage,
                'updated_at' => Carbon::now(),
            ]
        );

        $toc = is_array($edition->toc_json) ? array_values($edition->toc_json) : [];
        $progressPercent = min(100, (int) round(($lastPage / $totalPages) * 100));
        $currentChapter = $this->resolveChapterForPage($toc, $lastPage);

        return response()->json([
            'success' => true,
            'reading_state' => [
                'edition_id' => $edition->id,
                'last_page' => (int) $state->last_page,
                'progress_percent' => $progressPercent,
                'current_chapter' => $currentChapter,
                'updated_at_human' => 'Hace unos segundos',
            ],
        ]);
    }

    /**
     * Determina el título del capítulo vigente según la página actual y el índice (toc_json).
     *
     * @param  array<int, array<string, mixed>>  $toc
     */
    private function resolveChapterForPage(array $toc, int $page): ?string
    {
        if ($page < 1 || empty($toc)) {
            return null;
        }

        $matchedTitle = null;
        foreach ($toc as $entry) {
            $entryPage = isset($entry['page']) ? (int) $entry['page'] : 0;
            $entryTitle = isset($entry['title']) && is_string($entry['title']) ? trim($entry['title']) : '';

            if ($entryTitle !== '' && $entryPage <= $page) {
                $matchedTitle = $entryTitle;
            }
        }

        if ($matchedTitle === null && isset($toc[0]['title']) && is_string($toc[0]['title'])) {
            return trim($toc[0]['title']);
        }

        return $matchedTitle;
    }

    /**
     * Resumen del estado de membresía del estudiante para el catálogo.
     *
     * @return array<string, mixed>
     */
    private function getMembershipSummary(User $user): array
    {
        $now = Carbon::now();

        /** @var Subscription|null $active */
        $active = $user->subscriptions()
            ->with('plan')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->latest('expires_at')
            ->first();

        if (! $active && $user->can('manage-users')) {
            return [
                'is_active' => true,
                'has_active_membership' => true,
                'status_label' => 'Acceso Editorial Completo',
                'plan_name' => 'Cuenta Administrativa',
                'days_remaining' => 365,
                'expires_at' => $now->copy()->addYear()->format('d/m/Y'),
            ];
        }

        if (! $active) {
            return [
                'is_active' => false,
                'has_active_membership' => false,
                'status_label' => 'Muestra Gratuita',
                'plan_name' => 'Plan Gratuito',
                'days_remaining' => 0,
                'expires_at' => null,
            ];
        }

        $expiresAt = Carbon::parse($active->expires_at);
        $daysRemaining = max(0, (int) ceil($now->floatDiffInDays($expiresAt, false)));

        return [
            'is_active' => true,
            'has_active_membership' => true,
            'status_label' => 'Membresía Activa',
            'plan_name' => $active->plan?->name ?? 'Suscripción Completa',
            'days_remaining' => $daysRemaining,
            'expires_at' => $expiresAt->format('d/m/Y'),
        ];
    }
}

