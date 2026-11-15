<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuickLinkRequest;
use App\Models\QuickLink;
use App\Models\QuickLinkCategory;
use App\Services\BookStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuickLinkController extends Controller
{
    /**
     * Muestra el panel administrativo unificado de Accesos Rápidos y sus Categorías.
     */
    public function index(): View
    {
        $categories = QuickLinkCategory::withTrashed()
            ->withCount([
                'quickLinks as total_links_count' => fn ($q) => $q->withTrashed(),
                'quickLinks as active_links_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (QuickLinkCategory $cat): array => QuickLinkCategoryController::formatCategory($cat))
            ->values()
            ->all();

        $links = QuickLink::withTrashed()
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (QuickLink $link): array => $this->formatLink($link))
            ->values()
            ->all();

        return view('admin.quick-links.index', [
            'initialCategories' => $categories,
            'initialLinks' => $links,
        ]);
    }

    /**
     * Registra un nuevo enlace rápido oficial junto con su logotipo WebP opcional.
     */
    public function store(QuickLinkRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $logoPath = $request->hasFile('logo')
            ? $this->storeStandardizedLogo($request->file('logo'))
            : null;

        try {
            $link = DB::transaction(function () use ($validated, $logoPath, $request): QuickLink {
                $created = QuickLink::create([
                    'quick_link_category_id' => $validated['quick_link_category_id'],
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'url' => $validated['url'],
                    'logo_path' => $logoPath,
                    'sort_order' => $validated['sort_order'],
                    'is_active' => $validated['is_active'],
                ]);

                activity('quick_links')
                    ->causedBy($request->user())
                    ->performedOn($created)
                    ->log("Acceso rápido '{$created->title}' registrado");

                return $created->load(['category' => fn ($q) => $q->withTrashed()]);
            });
        } catch (\Throwable $e) {
            $this->deleteLogo($logoPath);
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => "El acceso rápido «{$link->title}» fue creado correctamente.",
            'link' => $this->formatLink($link),
        ], 201);
    }

    /**
     * Actualiza los datos de un acceso rápido existente y gestiona el reemplazo o limpieza de su logotipo.
     */
    public function update(QuickLinkRequest $request, int $id): JsonResponse
    {
        $quickLink = QuickLink::withTrashed()->findOrFail($id);
        $validated = $request->validated();

        $oldLogoPath = $quickLink->logo_path;
        $newLogoPath = null;
        $deleteOldLogo = false;

        if ($request->hasFile('logo')) {
            $newLogoPath = $this->storeStandardizedLogo($request->file('logo'));
            $deleteOldLogo = ! empty($oldLogoPath);
        } elseif ($validated['remove_logo'] && ! empty($oldLogoPath)) {
            $deleteOldLogo = true;
        }

        try {
            DB::transaction(function () use ($quickLink, $validated, $newLogoPath, $request): void {
                $attributes = [
                    'quick_link_category_id' => $validated['quick_link_category_id'],
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'url' => $validated['url'],
                    'sort_order' => $validated['sort_order'],
                    'is_active' => $validated['is_active'],
                ];

                if ($newLogoPath !== null) {
                    $attributes['logo_path'] = $newLogoPath;
                } elseif ($validated['remove_logo']) {
                    $attributes['logo_path'] = null;
                }

                $quickLink->update($attributes);

                activity('quick_links')
                    ->causedBy($request->user())
                    ->performedOn($quickLink)
                    ->log("Acceso rápido '{$quickLink->title}' actualizado");
            });
        } catch (\Throwable $e) {
            if ($newLogoPath !== null) {
                $this->deleteLogo($newLogoPath);
            }
            throw $e;
        }

        if ($deleteOldLogo && $oldLogoPath !== $newLogoPath) {
            $this->deleteLogo($oldLogoPath);
        }

        $quickLink->load(['category' => fn ($q) => $q->withTrashed()]);

        return response()->json([
            'success' => true,
            'message' => "El acceso rápido «{$quickLink->title}» fue actualizado.",
            'link' => $this->formatLink($quickLink),
        ]);
    }

    /**
     * Cambia rápidamente el estado visible/oculto (is_active) de un acceso rápido.
     */
    public function toggleActive(Request $request, QuickLink $quickLink): JsonResponse
    {
        DB::transaction(function () use ($quickLink, $request): void {
            $newState = ! $quickLink->is_active;
            $quickLink->update(['is_active' => $newState]);

            $stateLabel = $newState ? 'visible' : 'oculto';

            activity('quick_links')
                ->causedBy($request->user())
                ->performedOn($quickLink)
                ->log("Acceso rápido '{$quickLink->title}' marcado como {$stateLabel}");
        });

        $quickLink->load(['category' => fn ($q) => $q->withTrashed()]);

        return response()->json([
            'success' => true,
            'message' => $quickLink->is_active
                ? "El enlace «{$quickLink->title}» ahora está visible para los estudiantes."
                : "El enlace «{$quickLink->title}» fue ocultado del catálogo de estudiantes.",
            'link' => $this->formatLink($quickLink),
        ]);
    }

    /**
     * Archiva lógicamente (SoftDelete) un acceso rápido.
     */
    public function destroy(Request $request, QuickLink $quickLink): JsonResponse
    {
        DB::transaction(function () use ($quickLink, $request): void {
            if ($quickLink->is_active) {
                $quickLink->forceFill(['is_active' => false])->saveQuietly();
            }

            $quickLink->delete();

            activity('quick_links')
                ->causedBy($request->user())
                ->performedOn($quickLink)
                ->log("Acceso rápido '{$quickLink->title}' archivado");
        });

        $archived = QuickLink::withTrashed()
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->findOrFail($quickLink->id);

        return response()->json([
            'success' => true,
            'message' => "El acceso rápido «{$archived->title}» fue archivado.",
            'link' => $this->formatLink($archived),
        ]);
    }

    /**
     * Restaura un acceso rápido previamente archivado.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $quickLink = QuickLink::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($quickLink, $request): void {
            $quickLink->restore();

            activity('quick_links')
                ->causedBy($request->user())
                ->performedOn($quickLink)
                ->log("Acceso rápido '{$quickLink->title}' restaurado");
        });

        $quickLink->load(['category' => fn ($q) => $q->withTrashed()]);

        return response()->json([
            'success' => true,
            'message' => "El acceso rápido «{$quickLink->title}» fue restaurado correctamente.",
            'link' => $this->formatLink($quickLink),
        ]);
    }

    /**
     * Verifica la firma binaria RIFF/WEBP y almacena el logotipo estandarizado en el disco público.
     */
    private function storeStandardizedLogo(UploadedFile $file): string
    {
        if (($file->getMimeType() ?: '') !== 'image/webp' || ! BookStorageService::hasValidWebpSignature($file)) {
            throw ValidationException::withMessages([
                'logo' => 'El archivo recibido no corresponde a una imagen WebP válida.',
            ]);
        }

        $fileName = Str::uuid()->toString().'.webp';
        Storage::disk('public')->putFileAs('quick-links', $file, $fileName);

        return 'quick-links/'.$fileName;
    }

    /**
     * Elimina de forma segura un logotipo anterior para evitar archivos huérfanos en disco.
     */
    private function deleteLogo(?string $logoPath): void
    {
        if (
            blank($logoPath) ||
            str_contains($logoPath, '..') ||
            ! str_starts_with($logoPath, 'quick-links/')
        ) {
            return;
        }

        if (Storage::disk('public')->exists($logoPath)) {
            Storage::disk('public')->delete($logoPath);
        }
    }

    /**
     * Formatea un enlace rápido para el consumo en la Isla Vue 3.
     *
     * @return array<string, mixed>
     */
    private function formatLink(QuickLink $link): array
    {
        $host = parse_url($link->url, PHP_URL_HOST) ?: '';

        return [
            'id' => $link->id,
            'quick_link_category_id' => (int) $link->quick_link_category_id,
            'category_name' => $link->category?->name ?? 'Sin categoría',
            'category_is_active' => (bool) ($link->category?->is_active ?? false),
            'title' => $link->title,
            'description' => $link->description ?? '',
            'url' => $link->url,
            'logo_url' => $link->logo_url,
            'domain' => preg_replace('/^www\./i', '', (string) $host) ?: $link->url,
            'sort_order' => (int) $link->sort_order,
            'is_active' => (bool) $link->is_active,
            'is_archived' => $link->trashed(),
            'updated_at' => $link->updated_at?->format('d/m/Y H:i') ?? '',
            'update_url' => route('admin.quick-links.update', $link->id),
            'toggle_url' => route('admin.quick-links.toggle-active', $link->id),
            'destroy_url' => route('admin.quick-links.destroy', $link->id),
            'restore_url' => route('admin.quick-links.restore', $link->id),
        ];
    }
}
