<?php

/*
|--------------------------------------------------------------------------
| Master Boilerplate: Identidad de marca (textos)
|--------------------------------------------------------------------------
|
| Textos del panel de marca de las pantallas de autenticación y SEO.
| Los COLORES se configuran en resources/css/app.css (bloque :root).
|
*/

return [

    'name' => env('APP_NAME', 'Laravel'),

    'tagline' => env('BRAND_TAGLINE', 'Tu plataforma, lista para crecer.'),

    'description' => env(
        'BRAND_DESCRIPTION',
        'Gestiona tu operación desde un único lugar: seguro, rápido y diseñado para escalar.'
    ),

    // Ruta pública a un logo propio (p. ej. '/images/logo.svg'). Null = logo por defecto.
    'logo' => env('BRAND_LOGO'),

    'features' => [
        'Acceso seguro con roles y permisos granulares',
        'Auditoría completa de cada acción',
        'Interfaz rápida, moderna y adaptable',
    ],

];
