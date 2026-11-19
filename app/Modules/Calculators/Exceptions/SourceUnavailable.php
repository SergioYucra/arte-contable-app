<?php

namespace App\Modules\Calculators\Exceptions;

use RuntimeException;

/**
 * No se pudo revisar una página de boliviaimpuestos.com: no respondió, la bloqueó
 * Cloudflare, cambió tanto que ya no encontramos su cálculo, etc.
 * El mensaje está en lenguaje llano: va tal cual en el correo al desarrollador líder.
 */
class SourceUnavailable extends RuntimeException {}
