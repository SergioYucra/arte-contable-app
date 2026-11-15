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
];
