<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cuaderno de Estudio (study_notes)
    |--------------------------------------------------------------------------
    |
    | Límites de protección del servidor. El tope por edición evita abuso por
    | scripts, payloads gigantes en el listado global y bucles accidentales de
    | creación desde el frontend. Un alumno real rara vez supera 150 apuntes.
    |
    */

    'study_notes' => [
        'max_per_edition' => (int) env('STUDY_NOTES_MAX_PER_EDITION', 500),
        'title_max_length' => 255,
        'body_max_length' => (int) env('STUDY_NOTES_BODY_MAX_LENGTH', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protección de los libros (app/Modules/LibraryProtection)
    |--------------------------------------------------------------------------
    |
    | Frenos contra la descarga masiva de páginas. Un estudiante real lee una
    | página de contabilidad en uno a tres minutos; estos valores dejan lugar de
    | sobra para estudiar y vuelven lento, visible y rastreable el copiado.
    | El análisis completo está en docs/seguridad-libros/ANALISIS.md.
    |
    */

    'protection' => [
        // El "día" del tope diario empieza a medianoche de Bolivia, no en UTC.
        'timezone' => 'America/La_Paz',

        // Pedidos de página por minuto y por persona (incluye volver a una página ya vista).
        'pages_per_minute' => (int) env('LIBRARY_PAGES_PER_MINUTE', 30),

        // Páginas distintas por día y por persona. Volver a una página ya vista ese día no cuenta.
        'daily_page_limit' => (int) env('LIBRARY_DAILY_PAGE_LIMIT', 300),

        // Lectura más rápida de lo humano: N páginas nuevas en M minutos pausan la lectura.
        // Holgado para quien hojea buscando algo (80 en 5 min = una página nueva cada ~4 s
        // durante 5 minutos seguidos); un script al límite por minuto llega en menos de 3.
        'velocity_rules' => [
            ['pages' => 80, 'minutes' => 5],
            ['pages' => 150, 'minutes' => 30],
        ],

        // Pedidos que el lector nunca hace (página fuera de rango, pasada la muestra sin
        // membresía). Con este número en la ventana, se pausa.
        'trap_strikes' => [
            'limit' => 5,
            'window_minutes' => 30,
        ],

        // Horas que dura una pausa automática (el administrador puede reactivar antes).
        'pause_hours' => (int) env('LIBRARY_PAUSE_HOURS', 6),

        // Un dispositivo a la vez: si el que tiene el turno no pide páginas en estos
        // minutos, otro dispositivo de la misma cuenta lo toma solo.
        'device_idle_minutes' => 10,

        // Aviso (sin pausa) de posible cuenta compartida en la ventana indicada.
        'sharing_alert' => [
            'device_switches' => 6,
            'distinct_ips' => 5,
            'window_hours' => 24,
        ],

        // Un mismo aviso por correo al administrador no se repite antes de estos minutos.
        'alert_repeat_minutes' => 60,

        // Días que se guarda el registro de páginas vistas (quién vio qué).
        'retention_days' => (int) env('LIBRARY_PAGE_VIEWS_RETENTION_DAYS', 180),

        'watermark' => [
            'font' => resource_path('fonts/watermark/Niramit-SemiBold.ttf'),
            // SHA-256 de la fuente descargada de google/fonts (commit cc5661d4).
            'font_sha256' => 'b37c5e9d01592e3c1d8e381917fe6e2e96aabfa1064cd05e4f1b3ad349ee8e7c',
            'quality' => 80,
        ],
    ],
];
