<?php

namespace Database\Seeders;

use App\Models\QuickLink;
use App\Models\QuickLinkCategory;
use Illuminate\Database\Seeder;

/**
 * Seeder idempotente del directorio inicial de Accesos Rápidos Oficiales
 * para "El Arte de la Contabilidad".
 */
class QuickLinksSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'slug' => 'impuestos-y-tributos',
                'name' => 'Impuestos y Tributos',
                'sort_order' => 1,
                'links' => [
                    [
                        'title' => 'SIAT en Línea — Impuestos Nacionales',
                        'description' => 'Declaraciones juradas, Registro de Compras y Ventas (RCV), facturación en línea y buzón tributario.',
                        'url' => 'https://siat.impuestos.gob.bo',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Portal del Servicio de Impuestos Nacionales (SIN)',
                        'description' => 'Normativa tributaria vigente, resoluciones normativas de directorio (RND), vencimientos y guías.',
                        'url' => 'https://www.impuestos.gob.bo',
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'slug' => 'laboral-y-subsidios',
                'name' => 'Laboral y Subsidios',
                'sort_order' => 2,
                'links' => [
                    [
                        'title' => 'Oficina Virtual de Trámites — Ministerio de Trabajo',
                        'description' => 'Presentación de planillas mensuales de sueldos, aguinaldos, ROE y trámites laborales obligatorios.',
                        'url' => 'https://ovt.mintrabajo.gob.bo',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'SEDEM — Subsidios Prenatal y de Lactancia',
                        'description' => 'Plataforma oficial para el registro de empleadores, beneficiarios y gestión de paquetes de subsidios.',
                        'url' => 'https://sedem.gob.bo',
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'slug' => 'seguridad-social',
                'name' => 'Seguridad Social',
                'sort_order' => 3,
                'links' => [
                    [
                        'title' => 'Gestora Pública de la Seguridad Social de Largo Plazo',
                        'description' => 'Generación de formularios de pago de contribuciones SIP, altas y bajas de asegurados dependientes.',
                        'url' => 'https://www.gestora.bo',
                        'sort_order' => 1,
                    ],
                ],
            ],
            [
                'slug' => 'registro-comercial',
                'name' => 'Registro Comercial',
                'sort_order' => 4,
                'links' => [
                    [
                        'title' => 'SEPREC — Registro de Comercio de Bolivia',
                        'description' => 'Inscripción de empresas, renovación anual de matrícula de comercio y actos mercantiles.',
                        'url' => 'https://www.seprec.gob.bo',
                        'sort_order' => 1,
                    ],
                ],
            ],
        ];

        foreach ($catalog as $categoryData) {
            $category = QuickLinkCategory::withTrashed()->updateOrCreate(
                ['slug' => $categoryData['slug']],
                [
                    'name' => $categoryData['name'],
                    'sort_order' => $categoryData['sort_order'],
                    'is_active' => true,
                ]
            );

            if ($category->trashed()) {
                $category->restore();
            }

            foreach ($categoryData['links'] as $linkData) {
                $link = QuickLink::withTrashed()->updateOrCreate(
                    [
                        'quick_link_category_id' => $category->id,
                        'title' => $linkData['title'],
                    ],
                    [
                        'description' => $linkData['description'],
                        'url' => $linkData['url'],
                        'sort_order' => $linkData['sort_order'],
                        'is_active' => true,
                    ]
                );

                if ($link->trashed()) {
                    $link->restore();
                }
            }
        }
    }
}
