<?php

namespace App\Support;

class NumeroALetras
{
    private const UNIDADES = [
        '', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
    ];

    private const ESPECIALES = [
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
        'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
    ];

    private const VEINTIS = [
        'veinte', 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro',
        'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const DECENAS = [
        '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta',
        'sesenta', 'setenta', 'ochenta', 'noventa',
    ];

    private const CENTENAS = [
        '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos',
        'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
    ];

    public static function convertir(float|int|string $valor): string
    {
        $num = is_numeric($valor) ? (float) $valor : 0.0;
        if ($num < 0 || ! is_finite($num)) {
            return 'Cero 00/100 Bolivianos';
        }

        $redondeado = round($num, 2);
        $entero = (int) floor($redondeado);
        $centavos = (int) round(($redondeado - $entero) * 100);

        $texto = self::convertirEntero($entero);
        $capitalizado = mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
        $centavosStr = str_pad((string) $centavos, 2, '0', STR_PAD_LEFT);

        return "{$capitalizado} {$centavosStr}/100 Bolivianos";
    }

    private static function convertirGrupo(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n === 100) {
            return 'cien';
        }

        $c = intdiv($n, 100);
        $resto = $n % 100;
        $d = intdiv($resto, 10);
        $u = $resto % 10;

        $texto = $c > 0 ? self::CENTENAS[$c] : '';

        if ($resto > 0) {
            if ($texto !== '') {
                $texto .= ' ';
            }
            if ($resto < 10) {
                $texto .= self::UNIDADES[$resto];
            } elseif ($resto < 20) {
                $texto .= self::ESPECIALES[$resto - 10];
            } elseif ($resto < 30) {
                $texto .= self::VEINTIS[$resto - 20];
            } else {
                $texto .= self::DECENAS[$d];
                if ($u > 0) {
                    $texto .= ' y ' . self::UNIDADES[$u];
                }
            }
        }

        return $texto;
    }

    private static function convertirEntero(int $entero): string
    {
        if ($entero === 0) {
            return 'cero';
        }

        $millones = intdiv($entero, 1000000);
        $miles = intdiv($entero % 1000000, 1000);
        $unidades = $entero % 1000;

        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1
                ? 'un millón'
                : self::convertirGrupo($millones) . ' millones';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1
                ? 'mil'
                : self::convertirGrupo($miles) . ' mil';
        }

        if ($unidades > 0) {
            $grupo = self::convertirGrupo($unidades);
            if ($grupo === 'un') {
                $grupo = 'uno';
            } elseif ($grupo === 'veintiún') {
                $grupo = 'veintiuno';
            } elseif (str_ends_with($grupo, ' veintiún')) {
                $grupo = preg_replace('/ veintiún$/', ' veintiuno', $grupo);
            } elseif (str_ends_with($grupo, ' y un')) {
                $grupo = preg_replace('/ y un$/', ' y uno', $grupo);
            }
            $partes[] = $grupo;
        }

        return trim(implode(' ', $partes));
    }
}
