<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\BookEdition;
use App\Models\StudyNote;
use App\Models\User;
use App\Policies\StudyNotePolicy;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudyNoteController extends Controller
{
    private const PUBLIC_COLUMNS = ['id', 'book_edition_id', 'page_number', 'title', 'body', 'created_at', 'updated_at'];

    /**
     * GET /biblioteca/ediciones/{edition}/apuntes
     * Todas las notas del usuario en la edición, ordenadas por página (apuntes generales primero).
     */
    public function index(Request $request, BookEdition $edition): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($unavailable = $this->ensureEditionAvailable($user, $edition)) {
            return $unavailable;
        }

        if ($denied = $this->denyUnless(Gate::inspect('viewAny', [StudyNote::class, $edition]))) {
            return $denied;
        }

        $maxNotes = $this->maxNotesPerEdition();

        $notes = StudyNote::query()
            ->select(self::PUBLIC_COLUMNS)
            ->where('user_id', $user->id)
            ->where('book_edition_id', $edition->id)
            ->orderBy('page_number') // NULL (apuntes generales) primero en MySQL y SQLite
            ->orderByDesc('updated_at')
            ->limit($maxNotes)
            ->get()
            ->map(fn (StudyNote $note): array => $this->present($note));

        return response()->json([
            'success' => true,
            'data' => $notes,
            'meta' => [
                'count' => $notes->count(),
                'max' => $maxNotes,
                'total_pages' => (int) $edition->total_pages,
            ],
        ]);
    }

    /**
     * POST /biblioteca/ediciones/{edition}/apuntes
     */
    public function store(Request $request, BookEdition $edition): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($unavailable = $this->ensureEditionAvailable($user, $edition)) {
            return $unavailable;
        }

        if ($denied = $this->denyUnless(Gate::inspect('create', [StudyNote::class, $edition]))) {
            return $denied;
        }

        $validated = $request->validate(
            $this->rules((int) $edition->total_pages),
            $this->messages((int) $edition->total_pages)
        );

        $maxNotes = $this->maxNotesPerEdition();
        $currentCount = StudyNote::where('user_id', $user->id)
            ->where('book_edition_id', $edition->id)
            ->count();

        if ($currentCount >= $maxNotes) {
            return response()->json([
                'success' => false,
                'code' => 'notes_limit_reached',
                'message' => "Alcanzaste el máximo de {$maxNotes} apuntes en este libro. Puedes editar o unir apuntes existentes.",
            ], 422);
        }

        $pageNumber = isset($validated['page_number']) ? (int) $validated['page_number'] : null;

        // user_id y book_edition_id se fijan en servidor: nunca se aceptan desde el request.
        $note = new StudyNote;
        $note->user_id = $user->id;
        $note->book_edition_id = $edition->id;
        $note->page_number = $pageNumber;
        $note->title = $this->resolveTitle($validated['title'] ?? null, $pageNumber);
        $note->body = (string) ($validated['body'] ?? '');
        $note->save();

        return response()->json([
            'success' => true,
            'message' => 'Apunte creado.',
            'data' => $this->present($note),
            'meta' => ['count' => $currentCount + 1, 'max' => $maxNotes],
        ], 201);
    }

    /**
     * PUT /biblioteca/apuntes/{note}  (autoguardado con debounce desde el editor)
     */
    public function update(Request $request, StudyNote $note): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->abortUnlessOwner($user, $note);

        if ($denied = $this->denyUnless(Gate::inspect('update', $note))) {
            return $denied;
        }

        // total_pages solo se consulta si el cliente intenta mover la nota de página.
        $totalPages = $request->has('page_number')
            ? (int) BookEdition::whereKey($note->book_edition_id)->value('total_pages')
            : 0;

        $validated = $request->validate(
            $this->rules($totalPages, partial: true),
            $this->messages($totalPages)
        );

        if (array_key_exists('page_number', $validated)) {
            $note->page_number = $validated['page_number'] !== null ? (int) $validated['page_number'] : null;
        }

        if (array_key_exists('title', $validated)) {
            $note->title = $this->resolveTitle($validated['title'], $note->page_number);
        }

        if (array_key_exists('body', $validated)) {
            $note->body = (string) ($validated['body'] ?? '');
        }

        // Si nada cambió (doble disparo del debounce) no se ejecuta UPDATE.
        if ($note->isDirty()) {
            $note->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Guardado',
            'data' => [
                'id' => $note->id,
                'page_number' => $note->page_number,
                'title' => $note->title,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * DELETE /biblioteca/apuntes/{note}
     */
    public function destroy(Request $request, StudyNote $note): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->abortUnlessOwner($user, $note);

        if ($denied = $this->denyUnless(Gate::inspect('delete', $note))) {
            return $denied;
        }

        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Apunte eliminado.',
            'data' => ['id' => $note->id],
        ]);
    }

    /**
     * Privacidad estricta: nadie (ni SuperAdmin vía Gate::before) accede a notas ajenas.
     * Se responde 404 para no revelar la existencia del recurso.
     */
    private function abortUnlessOwner(User $user, StudyNote $note): void
    {
        abort_unless((int) $note->user_id === (int) $user->id, 404, 'El apunte solicitado no existe.');
    }

    /**
     * Convierte una denegación de la Policy en JSON uniforme para el frontend.
     */
    private function denyUnless(AccessResponse $decision): ?JsonResponse
    {
        if ($decision->allowed()) {
            return null;
        }

        $payload = [
            'success' => false,
            'code' => $decision->code(),
            'message' => $decision->message(),
        ];

        if ($decision->code() === StudyNotePolicy::DENY_CODE_SUBSCRIPTION) {
            $payload['membership_url'] = route('membership.index');
        }

        return response()->json($payload, $decision->status() ?? 403);
    }

    /**
     * Misma regla de disponibilidad que el visor (LibraryController): libro publicado y edición lista.
     * El staff con 'manage-users' queda exento para poder revisar ediciones en preparación.
     */
    private function ensureEditionAvailable(User $user, BookEdition $edition): ?JsonResponse
    {
        if ($user->can('manage-users')) {
            return null;
        }

        $edition->loadMissing('book:id,is_published,deleted_at');
        $book = $edition->book;

        if ($book === null || $book->trashed() || ! $book->is_published || $edition->status !== BookEdition::STATUS_READY) {
            return response()->json([
                'success' => false,
                'code' => 'edition_unavailable',
                'message' => 'Esta edición no se encuentra disponible.',
            ], 404);
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(int $totalPages, bool $partial = false): array
    {
        // En autoguardado (partial) solo se validan los campos enviados.
        $base = $partial ? ['sometimes', 'nullable'] : ['nullable'];
        $titleMax = (int) config('library.study_notes.title_max_length', 255);
        $bodyMax = (int) config('library.study_notes.body_max_length', 10000);

        return [
            'title' => [...$base, 'string', 'max:'.$titleMax],
            'body' => [...$base, 'string', 'max:'.$bodyMax],
            'page_number' => [...$base, 'integer', 'min:1', 'max:'.max(1, $totalPages)],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(int $totalPages): array
    {
        $titleMax = (int) config('library.study_notes.title_max_length', 255);
        $bodyMax = (int) config('library.study_notes.body_max_length', 10000);

        return [
            'title.max' => "El título no puede superar los {$titleMax} caracteres.",
            'body.max' => "El apunte no puede superar los {$bodyMax} caracteres.",
            'page_number.integer' => 'El número de página debe ser un entero válido.',
            'page_number.min' => 'La página mínima es 1.',
            'page_number.max' => "La página no puede superar el total de {$totalPages} páginas.",
        ];
    }

    private function resolveTitle(?string $title, ?int $pageNumber): string
    {
        $clean = trim((string) $title);

        if ($clean !== '') {
            return $clean;
        }

        return $pageNumber !== null ? "Apunte · Página {$pageNumber}" : 'Apunte general';
    }

    private function maxNotesPerEdition(): int
    {
        return max(1, (int) config('library.study_notes.max_per_edition', 500));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StudyNote $note): array
    {
        return [
            'id' => $note->id,
            'book_edition_id' => $note->book_edition_id,
            'page_number' => $note->page_number,
            'title' => $note->title,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }
}
