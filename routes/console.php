<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * P6 (H12): backups diarios de la base de datos (incluida la bitácora de auditoría) y de los archivos
 * de la app, con limpieza de copias viejas y revisión de que el último backup sea reciente.
 * Necesita el cron del servidor: `* * * * * php /ruta/a/artisan schedule:run >> /dev/null 2>&1`.
 */
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');
Schedule::command('backup:monitor')->dailyAt('03:00');
