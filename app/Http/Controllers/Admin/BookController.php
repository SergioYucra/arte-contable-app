<?php

namespace App\Http\Controllers\Admin;

use App\Core\Storage\PrivateVault;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookEdition;
use App\Services\BookStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookController extends Controller
{
    public function __construct(
        private readonly BookStorageService $storageService
    ) {}

    /**
     * Muestra el catálogo de libros y sus ediciones en una única vista reactiva sin recargas.
     */
    public function index(): View
    {
        $books = Book::withTrashed()
            ->with([
                'editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id'),
            ])
            ->orderByRaw('deleted_at IS NOT NULL')
            ->latest('updated_at')
            ->get()
            ->map(fn (Book $book): array => $this->formatBook($book))
            ->values()
            ->all();

        return view('admin.books.index', [
            'books' => $books,
        ]);
    }

    /**
     * Crea un nuevo libro junto con su primera edición vigente lista para cargar páginas.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBookPayload($request);
        $coverPath = $request->hasFile('cover')
            ? $this->storageService->storeCover($request->file('cover'))
            : null;

        try {
            $book = DB::transaction(function () use ($data, $coverPath, $request): Book {
                $book = Book::create([
                    'title' => $data['title'],
                    'subtitle' => $data['subtitle'],
                    'slug' => $this->resolveUniqueSlug($data['title']),
                    'author_name' => $data['author_name'],
                    'description' => $data['description'],
                    'cover_path' => $coverPath,
                    'is_published' => $data['is_published'],
                ]);

                BookEdition::create([
                    'book_id' => $book->id,
                    'edition_name' => 'Edición '.now()->year,
                    'total_pages' => 0,
                    'sample_pages_count' => 12,
                    'toc_json' => [],
                    'status' => BookEdition::STATUS_DRAFT,
                    'is_current' => true,
                ]);

                activity('books')
                    ->causedBy($request->user())
                    ->performedOn($book)
                    ->log("Libro '{$book->title}' registrado en el catálogo");

                return $book->load(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')]);
            });
        } catch (\Throwable $e) {
            $this->storageService->deleteCover($coverPath);
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => "El libro «{$book->title}» fue creado correctamente.",
            'book' => $this->formatBook($book),
        ], 201);
    }

    /**
     * Actualiza la ficha del libro y reemplaza o limpia la portada liberando el archivo anterior.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $book = Book::withTrashed()->findOrFail($id);
        $data = $this->validateBookPayload($request, $book->id);

        $oldCoverPath = $book->cover_path;
        $newCoverPath = null;
        $deleteOldCover = false;

        if ($request->hasFile('cover')) {
            $newCoverPath = $this->storageService->storeCover($request->file('cover'));
            $deleteOldCover = ! empty($oldCoverPath);
        } elseif ($data['remove_cover'] && ! empty($oldCoverPath)) {
            $deleteOldCover = true;
        }

        try {
            DB::transaction(function () use ($book, $data, $request, $newCoverPath): void {
                $attributes = [
                    'title' => $data['title'],
                    'subtitle' => $data['subtitle'],
                    'author_name' => $data['author_name'],
                    'description' => $data['description'],
                    'is_published' => $data['is_published'],
                ];

                if ($book->title !== $data['title']) {
                    $attributes['slug'] = $this->resolveUniqueSlug($data['title'], $book->id);
                }

                if ($newCoverPath !== null) {
                    $attributes['cover_path'] = $newCoverPath;
                } elseif ($data['remove_cover']) {
                    $attributes['cover_path'] = null;
                }

                $book->update($attributes);

                activity('books')
                    ->causedBy($request->user())
                    ->performedOn($book)
                    ->log("Ficha editorial de '{$book->title}' actualizada");
            });
        } catch (\Throwable $e) {
            if ($newCoverPath !== null) {
                $this->storageService->deleteCover($newCoverPath);
            }
            throw $e;
        }

        if ($deleteOldCover && $oldCoverPath !== $newCoverPath) {
            $this->storageService->deleteCover($oldCoverPath);
        }

        $book->load(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')]);

        return response()->json([
            'success' => true,
            'message' => 'Los datos del libro se guardaron correctamente.',
            'book' => $this->formatBook($book),
        ]);
    }

    /**
     * Archiva lógicamente un libro ocultándolo del catálogo público.
     */
    public function destroy(Request $request, Book $book): JsonResponse
    {
        DB::transaction(function () use ($book, $request): void {
            if ($book->is_published) {
                $book->forceFill(['is_published' => false])->saveQuietly();
            }

            $book->delete();

            activity('books')
                ->causedBy($request->user())
                ->performedOn($book)
                ->log("Libro '{$book->title}' archivado");
        });

        $archived = Book::withTrashed()
            ->with(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')])
            ->findOrFail($book->id);

        return response()->json([
            'success' => true,
            'message' => "El libro «{$archived->title}» fue archivado.",
            'book' => $this->formatBook($archived),
        ]);
    }

    /**
     * Restaura un libro archivado.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $book = Book::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($book, $request): void {
            $book->restore();

            activity('books')
                ->causedBy($request->user())
                ->performedOn($book)
                ->log("Libro '{$book->title}' restaurado desde archivo");
        });

        $book->load(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')]);

        return response()->json([
            'success' => true,
            'message' => "El libro «{$book->title}» fue restaurado.",
            'book' => $this->formatBook($book),
        ]);
    }

    /**
     * Registra una nueva edición para un libro.
     */
    public function storeEdition(Request $request, Book $book): JsonResponse
    {
        $data = $this->validateEditionPayload($request, $book->id);

        $edition = DB::transaction(function () use ($book, $data, $request): BookEdition {
            $makeCurrent = $data['is_current'] || ! $book->editions()->exists();

            if ($makeCurrent) {
                $book->editions()->where('is_current', true)->update(['is_current' => false]);
            }

            $created = BookEdition::create([
                'book_id' => $book->id,
                'edition_name' => $data['edition_name'],
                'total_pages' => 0,
                'sample_pages_count' => $data['sample_pages_count'],
                'toc_json' => [],
                'status' => BookEdition::STATUS_DRAFT,
                'is_current' => $makeCurrent,
            ]);

            activity('book_editions')
                ->causedBy($request->user())
                ->performedOn($created)
                ->log("Edición '{$created->edition_name}' creada para '{$book->title}'");

            return $created;
        });

        $book->load(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')]);

        return response()->json([
            'success' => true,
            'message' => "Se agregó la edición «{$edition->edition_name}».",
            'selected_edition_id' => $edition->id,
            'book' => $this->formatBook($book),
        ], 201);
    }

    /**
     * Actualiza configuración básica de una edición asegurando unicidad de edición vigente.
     */
    public function updateEdition(Request $request, BookEdition $edition): JsonResponse
    {
        $data = $this->validateEditionPayload($request, $edition->book_id, $edition->id);

        DB::transaction(function () use ($edition, $data): void {
            $makeCurrent = $data['is_current'];

            if ($makeCurrent) {
                BookEdition::where('book_id', $edition->book_id)
                    ->where('id', '!=', $edition->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            } elseif ($edition->is_current) {
                $otherExists = BookEdition::where('book_id', $edition->book_id)
                    ->where('id', '!=', $edition->id)
                    ->exists();

                if (! $otherExists) {
                    $makeCurrent = true;
                }
            }

            $edition->update([
                'edition_name' => $data['edition_name'],
                'sample_pages_count' => $data['sample_pages_count'],
                'is_current' => $makeCurrent,
            ]);
        });

        $book = Book::withTrashed()
            ->with(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')])
            ->findOrFail($edition->book_id);

        return response()->json([
            'success' => true,
            'message' => 'Configuración de la edición actualizada.',
            'book' => $this->formatBook($book),
        ]);
    }

    /**
     * Actualiza y ordena automáticamente el índice de temas (toc_json) de la edición.
     */
    public function updateToc(Request $request, BookEdition $edition): JsonResponse
    {
        $rawToc = is_array($request->input('toc')) ? $request->input('toc') : null;
        if ($rawToc === null) {
            throw ValidationException::withMessages(['toc' => 'El formato del índice no es válido.']);
        }

        $sanitized = [];
        foreach ($rawToc as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sanitized[] = [
                'title' => $this->sanitizeSingleLine($item['title'] ?? ''),
                'page' => $item['page'] ?? null,
            ];
        }

        $request->merge(['toc' => $sanitized]);

        $validated = $request->validate([
            'toc' => ['present', 'array', 'max:250'],
            'toc.*.title' => ['required', 'string', 'min:1', 'max:180'],
            'toc.*.page' => ['required', 'integer', 'min:1', 'max:2000'],
        ], [
            'toc.*.title.required' => 'Cada fila del índice debe incluir el título del tema.',
            'toc.*.page.required' => 'Indica el número de página de inicio para cada tema.',
        ]);

        $normalized = [];
        foreach ($validated['toc'] as $idx => $item) {
            $page = (int) $item['page'];
            if ($edition->total_pages > 0 && $page > $edition->total_pages) {
                throw ValidationException::withMessages([
                    "toc.{$idx}.page" => "El tema apunta a la página {$page}, pero esta edición tiene {$edition->total_pages} páginas cargadas.",
                ]);
            }

            $normalized[] = [
                'title' => (string) $item['title'],
                'page' => $page,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['page'] <=> $b['page']);

        $edition->update(['toc_json' => array_values($normalized)]);

        activity('book_editions')
            ->causedBy($request->user())
            ->performedOn($edition)
            ->log("Índice de temas actualizado ({$edition->edition_name})");

        return response()->json([
            'success' => true,
            'message' => 'El índice de temas se guardó y ordenó correctamente.',
            'edition' => $this->formatEdition($edition->fresh()),
        ]);
    }

    /**
     * Recibe un lote de hasta 5 páginas WebP validadas y las almacena en private_vault.
     */
    public function uploadPages(Request $request, BookEdition $edition): JsonResponse
    {
        $request->merge([
            'is_first_batch' => filter_var($request->input('is_first_batch', false), FILTER_VALIDATE_BOOLEAN),
            'is_final_batch' => filter_var($request->input('is_final_batch', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $request->validate([
            'total_pages' => ['required', 'integer', 'min:1', 'max:2000'],
            'is_first_batch' => ['required', 'boolean'],
            'is_final_batch' => ['required', 'boolean'],
            'pages' => ['required', 'array', 'min:1', 'max:5'],
            'pages.*.number' => ['required', 'integer', 'min:1', 'max:2000', 'distinct'],
            'pages.*.file' => ['required', 'file', 'mimetypes:image/webp', 'mimes:webp', 'max:2048'],
        ], [
            'pages.max' => 'Cada lote admite como máximo 5 páginas simultáneas.',
            'pages.*.file.mimetypes' => 'Solo se admiten páginas optimizadas en formato WebP.',
            'pages.*.file.max' => 'Una de las páginas supera el límite de 2 MB.',
        ]);

        $totalPages = (int) $request->integer('total_pages');
        $isFirstBatch = (bool) $request->boolean('is_first_batch');
        $isFinalBatch = (bool) $request->boolean('is_final_batch');

        $pagesBatch = [];
        foreach ($request->input('pages', []) as $index => $pageItem) {
            $pageNumber = (int) $pageItem['number'];
            if ($pageNumber > $totalPages) {
                throw ValidationException::withMessages([
                    "pages.{$index}.number" => "El número de página ({$pageNumber}) excede el total ({$totalPages}).",
                ]);
            }

            /** @var UploadedFile $file */
            $file = $request->file("pages.{$index}.file");
            $pagesBatch[] = [
                'number' => $pageNumber,
                'file' => $file,
            ];
        }

        if ($isFirstBatch && $edition->status !== BookEdition::STATUS_DRAFT) {
            $edition->forceFill(['status' => BookEdition::STATUS_DRAFT])->saveQuietly();
        }

        $stats = $this->storageService->storePagesBatch($edition->id, $pagesBatch, $totalPages, $isFinalBatch);

        if ($isFinalBatch) {
            $edition->update([
                'total_pages' => $totalPages,
                'status' => $stats['stored_pages_count'] >= $totalPages
                    ? BookEdition::STATUS_READY
                    : BookEdition::STATUS_DRAFT,
            ]);

            activity('book_editions')
                ->causedBy($request->user())
                ->performedOn($edition)
                ->withProperties([
                    'total_pages' => $totalPages,
                    'stored_pages' => $stats['stored_pages_count'],
                    'storage_bytes' => $stats['storage_bytes'],
                ])
                ->log("Carga de páginas finalizada para '{$edition->edition_name}' ({$stats['stored_pages_count']} páginas)");
        }

        return response()->json([
            'success' => true,
            'edition' => $this->formatEdition($edition->fresh(), $stats),
        ]);
    }

    /**
     * Libera el espacio en disco de las páginas de una edición cuando no está activa en un libro publicado.
     */
    public function purgePages(Request $request, BookEdition $edition): JsonResponse
    {
        $edition->loadMissing('book');

        if ($edition->is_current && $edition->book?->is_published) {
            throw ValidationException::withMessages([
                'edition' => 'Oculta el libro del catálogo público o cambia la edición vigente antes de vaciar sus páginas.',
            ]);
        }

        $this->storageService->purgeEditionPages($edition->id);

        $edition->update([
            'total_pages' => 0,
            'status' => BookEdition::STATUS_DRAFT,
        ]);

        activity('book_editions')
            ->causedBy($request->user())
            ->performedOn($edition)
            ->log("Archivos de páginas eliminados para liberar espacio ({$edition->edition_name})");

        return response()->json([
            'success' => true,
            'message' => 'Se liberó el espacio ocupado por las páginas de esta edición.',
            'edition' => $this->formatEdition($edition->fresh(), ['stored_pages_count' => 0, 'storage_bytes' => 0]),
        ]);
    }

    /**
     * Archiva una edición secundaria y libera sus archivos físicos.
     */
    public function destroyEdition(Request $request, BookEdition $edition): JsonResponse
    {
        if ($edition->is_current) {
            throw ValidationException::withMessages([
                'edition' => 'No puedes archivar la edición vigente. Marca otra edición como vigente primero.',
            ]);
        }

        $bookId = $edition->book_id;
        $editionId = $edition->id;

        DB::transaction(function () use ($edition, $request): void {
            $edition->delete();

            activity('book_editions')
                ->causedBy($request->user())
                ->performedOn($edition)
                ->log("Edición '{$edition->edition_name}' archivada");
        });

        $this->storageService->purgeEditionPages($editionId);

        $book = Book::withTrashed()
            ->with(['editions' => fn ($q) => $q->orderByDesc('is_current')->latest('id')])
            ->findOrFail($bookId);

        return response()->json([
            'success' => true,
            'message' => 'La edición fue archivada y su espacio en disco liberado.',
            'book' => $this->formatBook($book),
        ]);
    }

    /**
     * Transmite de forma segura en línea una página guardada para vista previa del administrador.
     */
    public function previewPage(BookEdition $edition, int $page): StreamedResponse
    {
        if ($page < 1 || ($edition->total_pages > 0 && $page > $edition->total_pages)) {
            abort(404, 'Número de página fuera de rango.');
        }

        return PrivateVault::streamInline(
            "editions/{$edition->id}/page-{$page}.webp",
            "edicion-{$edition->id}-pagina-{$page}.webp"
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBookPayload(Request $request, ?int $ignoreBookId = null): array
    {
        $title = $this->sanitizeSingleLine($request->input('title'));
        $subtitle = $this->sanitizeSingleLine($request->input('subtitle'));
        $authorName = $this->sanitizeSingleLine($request->input('author_name'));
        $description = is_string($request->input('description'))
            ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $request->input('description')) ?? '')
            : '';

        $request->merge([
            'title' => $title,
            'subtitle' => $subtitle !== '' ? $subtitle : null,
            'author_name' => $authorName,
            'description' => $description !== '' ? $description : null,
            'is_published' => filter_var($request->input('is_published', false), FILTER_VALIDATE_BOOLEAN),
            'remove_cover' => filter_var($request->input('remove_cover', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        return $request->validate([
            'title' => [
                'required',
                'string',
                'min:3',
                'max:180',
                Rule::unique('books', 'title')->ignore($ignoreBookId),
            ],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author_name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_published' => ['required', 'boolean'],
            'remove_cover' => ['required', 'boolean'],
            'cover' => ['nullable', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'mimes:webp,jpg,jpeg,png', 'max:2048'],
        ], [
            'title.required' => 'Ingresa el título del libro.',
            'title.unique' => 'Ya existe un libro registrado con este mismo título.',
            'author_name.required' => 'Ingresa el nombre del autor o equipo editorial.',
            'cover.mimetypes' => 'La portada debe ser una imagen WebP, JPG o PNG.',
            'cover.max' => 'La imagen de portada no debe superar los 2 MB.',
        ]);
    }

    /**
     * @return array{edition_name: string, sample_pages_count: int, is_current: bool}
     */
    private function validateEditionPayload(Request $request, int $bookId, ?int $ignoreEditionId = null): array
    {
        $request->merge([
            'edition_name' => $this->sanitizeSingleLine($request->input('edition_name')),
            'sample_pages_count' => $request->input('sample_pages_count', 12),
            'is_current' => filter_var($request->input('is_current', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        $validated = $request->validate([
            'edition_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                Rule::unique('book_editions', 'edition_name')
                    ->where('book_id', $bookId)
                    ->ignore($ignoreEditionId),
            ],
            'sample_pages_count' => ['required', 'integer', 'min:0', 'max:500'],
            'is_current' => ['required', 'boolean'],
        ], [
            'edition_name.required' => 'Ingresa el nombre de la edición.',
            'edition_name.unique' => 'Este libro ya cuenta con una edición con ese nombre.',
        ]);

        return [
            'edition_name' => (string) $validated['edition_name'],
            'sample_pages_count' => (int) $validated['sample_pages_count'],
            'is_current' => (bool) $validated['is_current'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'subtitle' => $book->subtitle ?? '',
            'author_name' => $book->author_name,
            'description' => $book->description ?? '',
            'is_published' => (bool) $book->is_published,
            'is_archived' => $book->trashed(),
            'cover_url' => ! empty($book->cover_path) ? Storage::disk('public')->url($book->cover_path) : null,
            'update_url' => route('admin.books.update', $book->id),
            'destroy_url' => route('admin.books.destroy', $book->id),
            'restore_url' => route('admin.books.restore', $book->id),
            'create_edition_url' => route('admin.books.editions.store', $book->id),
            'editions' => $book->editions
                ->map(fn (BookEdition $ed): array => $this->formatEdition($ed))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{stored_pages_count: int, storage_bytes: int}|null  $precomputedStorage
     * @return array<string, mixed>
     */
    private function formatEdition(BookEdition $edition, ?array $precomputedStorage = null): array
    {
        $stats = $precomputedStorage ?? $this->storageService->inspectEditionStorage($edition->id);

        return [
            'id' => $edition->id,
            'book_id' => $edition->book_id,
            'edition_name' => $edition->edition_name,
            'total_pages' => (int) $edition->total_pages,
            'sample_pages_count' => (int) $edition->sample_pages_count,
            'status' => $edition->status,
            'status_label' => $edition->status === BookEdition::STATUS_READY ? 'Lista para lectura' : 'En preparación',
            'is_current' => (bool) $edition->is_current,
            'toc' => is_array($edition->toc_json) ? array_values($edition->toc_json) : [],
            'stored_pages_count' => $stats['stored_pages_count'],
            'storage_bytes' => $stats['storage_bytes'],
            'storage_human' => BookStorageService::formatBytes($stats['storage_bytes']),
            'update_url' => route('admin.books.editions.update', $edition->id),
            'update_toc_url' => route('admin.books.editions.toc', $edition->id),
            'upload_pages_url' => route('admin.books.editions.upload-pages', $edition->id),
            'purge_pages_url' => route('admin.books.editions.purge-pages', $edition->id),
            'destroy_url' => route('admin.books.editions.destroy', $edition->id),
            'preview_page_url_template' => route('admin.books.editions.preview-page', [
                'edition' => $edition->id,
                'page' => '__PAGE__',
            ]),
        ];
    }

    private function sanitizeSingleLine(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    }

    private function resolveUniqueSlug(string $title, ?int $ignoreBookId = null): string
    {
        $base = Str::slug($title) ?: 'libro-contable';
        $candidate = $base;
        $suffix = 2;

        while (
            Book::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreBookId !== null, fn ($q) => $q->where('id', '!=', $ignoreBookId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
