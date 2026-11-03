<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Facturación y Planes de Acceso
    |--------------------------------------------------------------------------
    |
    | Define la tarifa y duración de la suscripción anual global que desbloquea
    | el catálogo editorial completo y el visor de lectura protegido.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'BOB'),

    'annual_plan' => [
        'name' => 'Suscripción Anual Completa',
        'price' => (float) env('BILLING_ANNUAL_PRICE', 20.00),
        'duration_days' => (int) env('BILLING_ANNUAL_DAYS', 365),
    ],
];
