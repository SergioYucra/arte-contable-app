<?php

/*
|--------------------------------------------------------------------------
| El Arte de la Contabilidad — Identidad de Marca y Contenidos
|--------------------------------------------------------------------------
|
| Textos institucionales, eslogan y propuesta de valor de la plataforma.
| Los colores se configuran en resources/css/app.css (:root y .dark).
|
*/

return [

    'name' => env('APP_NAME', 'El Arte de la Contabilidad'),

    'book_title' => 'El Arte de Contabilizar desde 0',

    'tagline' => env('BRAND_TAGLINE', 'Tu espacio digital para estudiar y practicar'),

    'description' => env(
        'BRAND_DESCRIPTION',
        'Domina los principios contables con la metodología del libro "El Arte de Contabilizar desde 0", en tu propio espacio de estudio.'
    ),

    // Ruta al logotipo oficial
    'logo' => env('BRAND_LOGO', 'images/logo_contable.png'),

    'features' => [
        'Acompañamiento didáctico basado en el libro "El Arte de Contabilizar desde 0"',
        'Contenido organizado por módulos, siguiendo el plan de estudios del libro',
        'Plataforma segura, accesible y optimizada para estudiantes y profesionales',
    ],

];
