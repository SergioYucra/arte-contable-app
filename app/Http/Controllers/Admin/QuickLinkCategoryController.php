<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuickLinkCategoryRequest;
use App\Models\QuickLinkCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickLinkCategoryController extends Controller
{
    /**
     * Crea una nueva categoría de accesos rápidos.
     */
    public function store(QuickLinkCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = DB::transaction(function () use ($validated, $request): QuickLinkCategory {
            $created = QuickLinkCategory::create([
                'name' => $validated['name'],
                'slug' => QuickLinkCategory::resolveUniqueSlug($validated['name']),
                'sort_order' => $validated['sort_order'],
                'is_active' => $validated['is_active'],
            ]);

            activity('quick_link_categories')
                ->causedBy($request->user())
                ->performedOn($created)
                ->log("Categoría de accesos rápidos '{$created->name}' creada");

            return $created;
        });

        return response()->json([
            'success' => true,
            'message' => "La categoría «{$category->name}» fue creada correctamente.",
            'category' => self::formatCategory($category),
        ], 201);
    }

    /**
     * Actualiza los datos de una categoría existente.
     */
    public function update(QuickLinkCategoryRequest $request, QuickLinkCategory $category): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($category, $validated, $request): void {
            $attributes = [
                'name' => $validated['name'],
                'sort_order' => $validated['sort_order'],
                'is_active' => $validated['is_active'],
            ];

            if ($category->name !== $validated['name']) {
                $attributes['slug'] = QuickLinkCategory::resolveUniqueSlug($validated['name'], $category->id);
            }

            $category->update($attributes);

            activity('quick_link_categories')
                ->causedBy($request->user())
                ->performedOn($category)
                ->log("Categoría de accesos rápidos '{$category->name}' actualizada");
        });

        $category->loadCount([
            'quickLinks as total_links_count' => fn ($q) => $q->withTrashed(),
            'quickLinks as active_links_count' => fn ($q) => $q->where('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => "La categoría «{$category->name}» fue actualizada.",
            'category' => self::formatCategory($category),
        ]);
    }

    /**
     * Cambia rápidamente el estado visible/oculto (is_active) de una categoría.
     */
    public function toggleActive(Request $request, QuickLinkCategory $category): JsonResponse
    {
        DB::transaction(function () use ($category, $request): void {
            $newState = ! $category->is_active;
            $category->update(['is_active' => $newState]);

            $stateLabel = $newState ? 'visible' : 'oculta';

            activity('quick_link_categories')
                ->causedBy($request->user())
                ->performedOn($category)
                ->log("Categoría '{$category->name}' marcada como {$stateLabel}");
        });

        $category->loadCount([
            'quickLinks as total_links_count' => fn ($q) => $q->withTrashed(),
            'quickLinks as active_links_count' => fn ($q) => $q->where('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => $category->is_active
                ? "La categoría «{$category->name}» ahora está visible para los estudiantes."
                : "La categoría «{$category->name}» fue ocultada del catálogo de estudiantes.",
            'category' => self::formatCategory($category),
        ]);
    }

    /**
     * Archiva (SoftDelete) una categoría únicamente si no tiene enlaces asociados.
     */
    public function destroy(Request $request, QuickLinkCategory $category): JsonResponse
    {
        $associatedCount = $category->quickLinks()->withTrashed()->count();

        if ($associatedCount > 0) {
            $noun = $associatedCount === 1 ? '1 enlace asociado' : "{$associatedCount} enlaces asociados";

            return response()->json([
                'success' => false,
                'message' => "No se puede archivar la categoría «{$category->name}» porque aún tiene {$noun}. Reasigna o elimina primero sus enlaces antes de archivarla.",
            ], 422);
        }

        DB::transaction(function () use ($category, $request): void {
            if ($category->is_active) {
                $category->forceFill(['is_active' => false])->saveQuietly();
            }

            $category->delete();

            activity('quick_link_categories')
                ->causedBy($request->user())
                ->performedOn($category)
                ->log("Categoría de accesos rápidos '{$category->name}' archivada");
        });

        $archived = QuickLinkCategory::withTrashed()
            ->withCount([
                'quickLinks as total_links_count' => fn ($q) => $q->withTrashed(),
                'quickLinks as active_links_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->findOrFail($category->id);

        return response()->json([
            'success' => true,
            'message' => "La categoría «{$archived->name}» fue archivada.",
            'category' => self::formatCategory($archived),
        ]);
    }

    /**
     * Restaura una categoría previamente archivada.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $category = QuickLinkCategory::onlyTrashed()->findOrFail($id);

        DB::transaction(function () use ($category, $request): void {
            $category->restore();

            activity('quick_link_categories')
                ->causedBy($request->user())
                ->performedOn($category)
                ->log("Categoría de accesos rápidos '{$category->name}' restaurada");
        });

        $category->loadCount([
            'quickLinks as total_links_count' => fn ($q) => $q->withTrashed(),
            'quickLinks as active_links_count' => fn ($q) => $q->where('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => "La categoría «{$category->name}» fue restaurada correctamente.",
            'category' => self::formatCategory($category),
        ]);
    }

    /**
     * Formatea una categoría para el consumo seguro en la Isla Vue 3.
     *
     * @return array<string, mixed>
     */
    public static function formatCategory(QuickLinkCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'is_archived' => $category->trashed(),
            'total_links_count' => (int) ($category->total_links_count ?? 0),
            'active_links_count' => (int) ($category->active_links_count ?? 0),
            'update_url' => route('admin.quick-links.categories.update', $category->id),
            'toggle_url' => route('admin.quick-links.categories.toggle-active', $category->id),
            'destroy_url' => route('admin.quick-links.categories.destroy', $category->id),
            'restore_url' => route('admin.quick-links.categories.restore', $category->id),
        ];
    }
}
