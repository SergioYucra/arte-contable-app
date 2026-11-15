<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\QuickLink;
use App\Models\QuickLinkCategory;
use Illuminate\View\View;

class QuickLinkStudentController extends Controller
{
    /**
     * Muestra el directorio de Accesos Rápidos Oficiales agrupados por categoría activa.
     */
    public function index(): View
    {
        $categories = QuickLinkCategory::active()
            ->select(['id', 'name', 'slug', 'sort_order'])
            ->with([
                'quickLinks' => fn ($q) => $q
                    ->active()
                    ->select(['id', 'quick_link_category_id', 'title', 'description', 'url', 'logo_path', 'sort_order'])
                    ->orderBy('sort_order')
                    ->orderBy('title'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (QuickLinkCategory $category): array {
                $links = $category->quickLinks->map(function (QuickLink $link) use ($category): array {
                    $host = parse_url($link->url, PHP_URL_HOST) ?: '';

                    return [
                        'id' => $link->id,
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'title' => $link->title,
                        'description' => $link->description ?? '',
                        'url' => $link->url,
                        'logo_url' => $link->logo_url,
                        'domain' => preg_replace('/^www\./i', '', (string) $host) ?: $link->url,
                    ];
                })->values()->all();

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'links' => $links,
                ];
            })
            ->filter(fn (array $category): bool => ! empty($category['links']))
            ->values()
            ->all();

        return view('quick-links.index', [
            'initialCategories' => $categories,
        ]);
    }
}
