<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Fechas y duraciones en palabras para personas, en hora de Bolivia.
 *
 * La aplicación guarda y calcula en UTC (config/app.php); aquí solo se convierte para mostrar.
 */
final class HumanDate
{
    public const TIMEZONE = 'America/La_Paz';

    /** "14 de mayo de 2027" */
    public static function long(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone(self::TIMEZONE)->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }

    /** "14 may 2027, 10:30" */
    public static function dateTime(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = $date->copy()->setTimezone(self::TIMEZONE)->locale('es');

        return rtrim($local->translatedFormat('j M Y'), '.').', '.$local->format('H:i');
    }

    /**
     * Para frases con "hasta …": un fin a medianoche nombra el último día completo
     * ("el 19 de noviembre de 2026"); con otra hora, la dice ("el 20 de noviembre de 2026 a las 18:00").
     *
     * Sin esto, algo que termina el día 20 a las 00:00 se leía "hasta el 20" cuando ese día ya no vale.
     */
    public static function until(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = $date->copy()->setTimezone(self::TIMEZONE)->locale('es');

        if ($local->format('H:i') === '00:00') {
            return 'el '.$local->copy()->subDay()->translatedFormat('j \d\e F \d\e Y');
        }

        return 'el '.$local->translatedFormat('j \d\e F \d\e Y').' a las '.$local->format('H:i');
    }

    /** "hace 3 horas" */
    public static function relative(?CarbonInterface $date): ?string
    {
        return $date?->copy()->locale('es')->diffForHumans();
    }

    /** 365 → "1 año", 180 → "6 meses", 30 → "1 mes", 45 → "45 días" */
    public static function duration(int $days): string
    {
        if ($days > 0 && $days % 365 === 0) {
            $years = intdiv($days, 365);

            return $years === 1 ? '1 año' : "{$years} años";
        }

        if ($days > 0 && $days % 30 === 0) {
            $months = intdiv($days, 30);

            return $months === 1 ? '1 mes' : "{$months} meses";
        }

        return $days === 1 ? '1 día' : "{$days} días";
    }

    /** 200.0 → "Bs 200", 120.5 → "Bs 120,50" */
    public static function money(float $amount): string
    {
        $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return 'Bs '.number_format($amount, $decimals, ',', '.');
    }
}
