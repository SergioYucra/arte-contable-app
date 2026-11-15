<?php

/*
|--------------------------------------------------------------------------
| Calculadora de alivio tributario (Ley 1733: al contado o en cuotas)
|--------------------------------------------------------------------------
|
| El cálculo lo hace boliviaimpuestos.com (fap.php / deudas.php). Nuestro
| backend es el único que los llama: el navegador del estudiante habla solo
| con nosotros. Análisis completo en docs/calculadora-alivio-tributario/.
|
| Todas las variables tienen un valor por defecto seguro; ninguna es
| obligatoria en el .env.
|
*/

return [

    // Interruptor general: false apaga la calculadora al instante (oculta el
    // menú y responde "no disponible"), sin desplegar código.
    'enabled' => (bool) env('TAX_RELIEF_ENABLED', true),

    // Servidor de cálculo. Fijo por configuración: el usuario nunca elige la URL.
    // Solo se acepta https (ver TaxReliefServiceProvider).
    'base_url' => env('TAX_RELIEF_API_URL', 'https://boliviaimpuestos.com/maletin/'),

    // Tiempos máximos de espera (segundos).
    'connect_timeout' => (int) env('TAX_RELIEF_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('TAX_RELIEF_TIMEOUT', 10),

    // Respuestas más grandes que esto se descartan (bytes).
    'max_response_bytes' => 1024 * 1024,

    // Zona horaria para "hoy": el caché de un cálculo dura hasta fin del día en Bolivia.
    'timezone' => 'America/La_Paz',

    // La 1.ª cuota vence a fin del mes "solicitud + N" (igual que su página).
    'first_installment_month_offset' => 2,

    // Máximos que aceptamos en el formulario.
    'max_debts' => 10,
    'max_installments' => 36,
    'max_amount' => 100_000_000,

    /*
    | Límites de uso (de nuestro lado)
    |
    | 1) Por estudiante: cuántas veces puede pedir un cálculo.
    | 2) Global: cuántas llamadas reales salen hacia boliviaimpuestos entre
    |    TODOS los usuarios. Los resultados repetidos salen del caché y no cuentan.
    | 3) Cortacircuito: si su servidor falla varias veces seguidas, dejamos de
    |    llamarlo un rato en vez de insistir.
    */
    'limits' => [
        'per_user_per_minute' => (int) env('TAX_RELIEF_USER_PER_MINUTE', 10),
        'per_user_per_day' => (int) env('TAX_RELIEF_USER_PER_DAY', 150),
        'lookups_per_user_per_minute' => (int) env('TAX_RELIEF_LOOKUPS_PER_MINUTE', 20),
        'upstream_per_minute' => (int) env('TAX_RELIEF_UPSTREAM_PER_MINUTE', 30),
        'upstream_per_hour' => (int) env('TAX_RELIEF_UPSTREAM_PER_HOUR', 400),
        'breaker_failures' => 5,
        'breaker_pause_seconds' => 300,
    ],

    // Caché de resultados.
    'cache' => [
        'plan_max_seconds' => 6 * 3600,      // nunca más allá de fin del día
        'due_date_seconds' => 30 * 24 * 3600,
    ],

    // Monitor diario (tax-relief:check). Si no hay correo propio, avisa al SuperAdmin.
    'monitor' => [
        'alert_email' => env('TAX_RELIEF_ALERT_EMAIL', env('SUPERADMIN_EMAIL')),
        'daily_at' => env('TAX_RELIEF_CHECK_AT', '07:00'),
    ],

];
