<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookEdition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder idempotente del catálogo editorial inicial para el MVP
 * de "El Arte de la Contabilidad".
 */
class MvpCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $bookTitle = (string) config('brand.book_title', 'El Arte de Contabilizar desde 0');
        $bookSlug = Str::slug($bookTitle);

        $book = Book::withTrashed()->updateOrCreate(
            ['slug' => $bookSlug],
            [
                'title' => $bookTitle,
                'subtitle' => 'Fundamentos, Práctica y Criterio Profesional para el Estudiante y Contador Moderno',
                'author_name' => 'Equipo Editorial El Arte de la Contabilidad',
                'description' => 'Obra integral de contabilidad general y financiera diseñada para el aprendizaje práctico, '
                    .'con estudio interactivo en pantalla dividida, análisis de asientos contables y preparación de estados financieros.',
                'is_published' => true,
            ]
        );

        if ($book->trashed()) {
            $book->restore();
        }

        $edition = BookEdition::withTrashed()->updateOrCreate(
            [
                'book_id' => $book->id,
                'edition_name' => 'Edición 2026',
            ],
            [
                'sample_pages_count' => 12,
                'is_current' => true,
                'toc_json' => [
                    ['title' => 'Prólogo y Metodología de Estudio', 'page' => 1],
                    ['title' => 'Capítulo 1: Fundamentos y la Ecuación Contable', 'page' => 7],
                    ['title' => 'Capítulo 2: Teoría del Cargo y el Abono (Partida Doble)', 'page' => 15],
                    ['title' => 'Capítulo 3: El Ciclo Contable: Libro Diario y Mayor', 'page' => 38],
                    ['title' => 'Capítulo 4: Asientos de Ajuste y Balance de Comprobación', 'page' => 68],
                    ['title' => 'Capítulo 5: Estados Financieros Básicos y Cierre Contable', 'page' => 102],
                ],
            ]
        );

        if ($edition->trashed()) {
            $edition->restore();
        }

        // Asegura que ninguna otra edición del mismo libro quede marcada como vigente simultáneamente.
        BookEdition::where('book_id', $book->id)
            ->where('id', '!=', $edition->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);
    }
}
