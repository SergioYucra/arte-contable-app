<?php

/*
|--------------------------------------------------------------------------
| Calculadoras RC-IVA y Bono de antigüedad
|--------------------------------------------------------------------------
|
| Las dos calculadoras copian las fórmulas de boliviaimpuestos.com, que no tiene
| una API para estos cálculos (todo ocurre en el JavaScript de sus páginas).
| Los valores que usamos viven en resources/js/components/calculators/parametros.json.
|
| La revisión diaria (php artisan calculators:check) descarga sus dos páginas,
| lee sus tasas y tablas, y avisa al desarrollador líder si dejan de coincidir
| con las nuestras o si cambia el código de su cálculo (la "huella").
|
*/

return [

    'timezone' => 'America/La_Paz',

    'monitor' => [
        // Apagar la revisión sin desplegar: CALCULATORS_CHECK_ENABLED=false.
        'enabled' => (bool) env('CALCULATORS_CHECK_ENABLED', true),

        // Desarrollador líder; varios separados por coma. Vacío = el aviso solo queda en el log.
        'alert_to' => env('CALCULATORS_ALERT_EMAIL', ''),

        // Hora de Bolivia.
        'daily_at' => env('CALCULATORS_CHECK_AT', '07:15'),

        'timeout_seconds' => 10,
        'max_bytes' => 1_000_000,
    ],

    /*
    | Páginas que se revisan. La URL es fija (no viene de nadie de afuera) y
    | `marker` es un texto que solo aparece en el <script> que calcula.
    | `fingerprint` es la huella de ese script cuando copiamos la fórmula:
    | se obtiene con php artisan calculators:check --show-fingerprint.
    */
    'sources' => [
        'rc_iva' => [
            'name' => 'RC-IVA',
            'url' => 'https://boliviaimpuestos.com/maletin/rc-iva.php',
            'marker' => 'rSMN',
            'fingerprint' => 'f38aa9386005d6164350fb70a50663ed2072943792bfaef58e38e47d71a004ea',
        ],
        'bono_antiguedad' => [
            'name' => 'Bono de antigüedad',
            'url' => 'https://boliviaimpuestos.com/maletin/bono-antiguedad.php',
            'marker' => 'ESC=',
            'fingerprint' => '7c6ec25da2f22667f3011642ba297b4367bcc60255e4ceb4f991ed4f6f94f2d3',
        ],
    ],

];
