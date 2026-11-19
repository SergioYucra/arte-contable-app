<?php

namespace App\Modules\Calculators\Services;

use App\Modules\Calculators\Exceptions\SourceUnavailable;
use JsonException;
use RuntimeException;

/**
 * Lee las tasas y tablas del cálculo de boliviaimpuestos.com (sin ejecutar su código)
 * y las compara con las nuestras (resources/js/components/calculators/shared/parametros.json).
 *
 * Las dos partes se llevan a la misma forma para poder compararlas:
 *  - rc_iva: aporte_afp, aporte_solidario[{desde, hasta, tasa}], tasa, minimo_no_imponible_smn,
 *            compensacion (factor sobre el salario mínimo), tasa_facturas
 *  - bono_antiguedad: escala[{desde, hasta, porcentaje}], salarios{gestion: {monto, norma}},
 *            bases[salarios mínimos], dias_del_mes
 */
class FormulaReader
{
    public const PARAMETERS_PATH = 'js/components/calculators/shared/parametros.json';

    // ------------------------------------------------------------------
    // Lo que dice su página
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function fromPage(string $source, string $script, string $html): array
    {
        return match ($source) {
            'rc_iva' => self::rcIvaFromScript($script),
            'bono_antiguedad' => self::bonoFromScript($script, $html),
            default => throw new RuntimeException("Calculadora desconocida: {$source}"),
        };
    }

    /** @return array<string, mixed> */
    private static function rcIvaFromScript(string $script): array
    {
        preg_match_all(
            '/var\s+t(\d)\s*=\s*(?:Math\.min\(\s*)?Math\.max\(\s*s\s*-\s*([\d.]+)\s*,\s*0\s*\)\s*(?:,\s*([\d.]+)\s*\))?\s*,\s*a\1\s*=\s*t\1\s*\*\s*([\d.]+)/',
            $script,
            $tramos,
            PREG_SET_ORDER,
        );
        if ($tramos === []) {
            throw new SourceUnavailable('ya no encontramos los tramos del aporte solidario en su cálculo de RC-IVA');
        }

        return [
            'aporte_afp' => self::number($script, '/\bafp\s*=\s*g\s*\*\s*([\d.]+)/', 'el aporte a la AFP'),
            'aporte_solidario' => array_map(fn (array $t) => [
                'desde' => (float) $t[2],
                'hasta' => ($t[3] ?? '') === '' ? null : (float) $t[2] + (float) $t[3],
                'tasa' => (float) $t[4],
            ], $tramos),
            'tasa' => self::number($script, '/\bimp\s*=\s*base\s*\*\s*([\d.]+)/', 'la tasa del impuesto'),
            'minimo_no_imponible_smn' => self::number($script, '/\bmni\s*=\s*smn\s*\*\s*([\d.]+)/', 'el mínimo no imponible'),
            'compensacion' => self::number($script, '/\bcompAuto\s*=\s*smn\s*\*\s*([\d.]+)/', 'el descuento automático'),
            'tasa_facturas' => self::number($script, '/\bcompFac\s*=\s*fac\s*\*\s*([\d.]+)/', 'el crédito por facturas'),
        ];
    }

    /** @return array<string, mixed> */
    private static function bonoFromScript(string $script, string $html): array
    {
        $escala = self::jsArray($script, 'ESC', 'la escala del bono');
        $salarios = self::jsArray($script, 'SMN', 'la tabla de salarios mínimos');

        $bases = [];
        if (preg_match('#id="bBase"[^>]*>(.*?)</div>#s', $html, $block)) {
            preg_match_all('#data-s="([\d.]+)"#', $block[1], $found);
            $bases = array_map('floatval', $found[1]);
        }
        if ($bases === []) {
            throw new SourceUnavailable('ya no encontramos las opciones de base (3 o 1 salario mínimo) en su página del bono');
        }

        $result = [
            'escala' => [],
            'salarios' => [],
            'bases' => $bases,
            'dias_del_mes' => (int) self::number($script, '/\bbono\s*=\s*bonoPleno\s*\*\s*dias\s*\/\s*(\d+)/', 'el cálculo proporcional por días'),
        ];
        foreach ($escala as $row) {
            if (! is_array($row) || count($row) !== 3) {
                throw new SourceUnavailable('la escala del bono tiene un formato que no reconocemos');
            }
            $result['escala'][] = ['desde' => (int) $row[0], 'hasta' => $row[1] === null ? null : (int) $row[1], 'porcentaje' => (float) $row[2]];
        }
        foreach ($salarios as $row) {
            if (! is_array($row) || count($row) !== 3) {
                throw new SourceUnavailable('la tabla de salarios mínimos tiene un formato que no reconocemos');
            }
            $result['salarios'][(int) $row[0]] = ['monto' => (float) $row[1], 'norma' => self::norma((string) $row[2])];
        }

        return $result;
    }

    private static function number(string $script, string $pattern, string $what): float
    {
        if (! preg_match($pattern, $script, $m) || ! is_numeric($m[1])) {
            throw new SourceUnavailable("ya no encontramos {$what} en su cálculo");
        }

        return (float) $m[1];
    }

    /** Lee un arreglo literal "var NOMBRE=[[...]];" de su script (con comillas simples) como JSON. */
    private static function jsArray(string $script, string $name, string $what): array
    {
        if (! preg_match('/var\s+'.$name.'\s*=\s*(\[\[.*?\]\])\s*;/s', $script, $m)) {
            throw new SourceUnavailable("ya no encontramos {$what} en su cálculo");
        }

        try {
            $data = json_decode(str_replace("'", '"', $m[1]), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceUnavailable("{$what} tiene un formato que no reconocemos");
        }

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------
    // Lo que usamos nosotros (parametros.json)
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function ours(?string $path = null): array
    {
        $json = json_decode((string) file_get_contents($path ?? resource_path(self::PARAMETERS_PATH)), true, 16, JSON_THROW_ON_ERROR);

        $rc = $json['rcIva'];
        $bono = $json['bonoAntiguedad'];

        $salarios = [];
        foreach ($json['salariosMinimos'] as $s) {
            $salarios[(int) $s['gestion']] = ['monto' => (float) $s['monto'], 'norma' => self::norma((string) $s['norma'])];
        }

        return [
            'rc_iva' => [
                'aporte_afp' => (float) $rc['aporteAfp'],
                'aporte_solidario' => array_map(fn (array $t) => [
                    'desde' => (float) $t['desde'],
                    'hasta' => $t['hasta'] === null ? null : (float) $t['hasta'],
                    'tasa' => (float) $t['tasa'],
                ], $rc['aporteSolidario']),
                'tasa' => (float) $rc['tasa'],
                'minimo_no_imponible_smn' => (float) $rc['minimoNoImponibleSmn'],
                'compensacion' => (float) $rc['compensacionSmn'] * (float) $rc['tasa'],
                'tasa_facturas' => (float) $rc['tasa'],
            ],
            'bono_antiguedad' => [
                'escala' => array_map(fn (array $t) => [
                    'desde' => (int) $t['desde'],
                    'hasta' => $t['hasta'] === null ? null : (int) $t['hasta'],
                    'porcentaje' => (float) $t['porcentaje'],
                ], $bono['escala']),
                'salarios' => $salarios,
                'bases' => array_map(fn (array $b) => (float) $b['salarios'], $bono['bases']),
                'dias_del_mes' => (int) $bono['diasDelMes'],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Diferencias, en palabras
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return list<string>
     */
    public static function differences(string $source, array $ours, array $theirs): array
    {
        return $source === 'rc_iva' ? self::rcIvaDifferences($ours, $theirs) : self::bonoDifferences($ours, $theirs);
    }

    /** @return list<string> */
    private static function rcIvaDifferences(array $ours, array $theirs): array
    {
        $diff = [];
        $rates = [
            'aporte_afp' => 'Aporte a la AFP',
            'tasa' => 'Tasa del impuesto',
            'tasa_facturas' => 'Crédito por facturas',
        ];
        foreach ($rates as $key => $label) {
            if (! self::same($ours[$key], $theirs[$key])) {
                $diff[] = "{$label}: usamos ".self::pct($ours[$key]).' y su página ahora usa '.self::pct($theirs[$key]).'.';
            }
        }
        if (! self::same($ours['minimo_no_imponible_smn'], $theirs['minimo_no_imponible_smn'])) {
            $diff[] = 'Parte del sueldo que no paga impuesto: usamos '.self::plain($ours['minimo_no_imponible_smn']).' salarios mínimos y su página ahora usa '.self::plain($theirs['minimo_no_imponible_smn']).'.';
        }
        if (! self::same($ours['compensacion'], $theirs['compensacion'])) {
            $diff[] = 'Descuento automático: usamos salario mínimo × '.self::plain($ours['compensacion']).' y su página ahora usa salario mínimo × '.self::plain($theirs['compensacion']).'.';
        }
        if (self::tramos($ours['aporte_solidario']) !== self::tramos($theirs['aporte_solidario'])) {
            $diff[] = 'Tramos del aporte solidario: usamos '.self::tramos($ours['aporte_solidario']).' y su página ahora usa '.self::tramos($theirs['aporte_solidario']).'.';
        }

        return $diff;
    }

    /** @return list<string> */
    private static function bonoDifferences(array $ours, array $theirs): array
    {
        $diff = [];
        if (self::escala($ours['escala']) !== self::escala($theirs['escala'])) {
            $diff[] = 'Escala del bono: usamos '.self::escala($ours['escala']).' y su página ahora usa '.self::escala($theirs['escala']).'.';
        }

        foreach ($theirs['salarios'] as $year => $row) {
            $mine = $ours['salarios'][$year] ?? null;
            if ($mine === null) {
                $diff[] = "Agregaron el salario mínimo de {$year}: ".self::bs($row['monto'])." ({$row['norma']}).";
            } elseif (! self::same($mine['monto'], $row['monto']) || $mine['norma'] !== $row['norma']) {
                $diff[] = "Salario mínimo de {$year}: usamos ".self::bs($mine['monto'])." ({$mine['norma']}) y su página ahora dice ".self::bs($row['monto'])." ({$row['norma']}).";
            }
        }
        foreach (array_diff_key($ours['salarios'], $theirs['salarios']) as $year => $row) {
            $diff[] = "Su página ya no muestra el salario mínimo de {$year} (usamos ".self::bs($row['monto']).').';
        }

        $bases = fn (array $list) => implode(' y ', array_map(fn (float $b) => self::plain($b), $list));
        if ($bases($ours['bases']) !== $bases($theirs['bases'])) {
            $diff[] = 'Bases del bono (en salarios mínimos): usamos '.$bases($ours['bases']).' y su página ahora ofrece '.$bases($theirs['bases']).'.';
        }
        if ($ours['dias_del_mes'] !== $theirs['dias_del_mes']) {
            $diff[] = "Días del mes para el bono proporcional: usamos {$ours['dias_del_mes']} y su página ahora usa {$theirs['dias_del_mes']}.";
        }

        return $diff;
    }

    /** Mismo recorte de los dos lados (el texto viaja al correo): si no, nunca coincidirían. */
    private static function norma(string $text): string
    {
        return mb_substr(trim($text), 0, 80);
    }

    private static function same(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-9;
    }

    /** 0.1271 → "12,71%" */
    private static function pct(float $rate): string
    {
        return self::plain($rate * 100).'%';
    }

    /** 3300 → "Bs 3.300"; 3000.5 → "Bs 3.000,50" */
    private static function bs(float $amount): string
    {
        $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return 'Bs '.number_format($amount, $decimals, ',', '.');
    }

    /** 2 → "2"; 0.13 → "0,13"; 12.71 → "12,71" */
    private static function plain(float $n): string
    {
        $text = number_format($n, 4, ',', '.');

        return rtrim(rtrim($text, '0'), ',');
    }

    /** @param list<array{desde: float, hasta: ?float, tasa: float}> $tramos */
    private static function tramos(array $tramos): string
    {
        return implode('; ', array_map(fn (array $t) => ($t['hasta'] === null
            ? 'desde '.self::bs($t['desde'])
            : 'de '.self::bs($t['desde']).' a '.self::bs($t['hasta'])).' al '.self::pct($t['tasa']), $tramos));
    }

    /** @param list<array{desde: int, hasta: ?int, porcentaje: float}> $escala */
    private static function escala(array $escala): string
    {
        return implode('; ', array_map(fn (array $t) => ($t['hasta'] === null
            ? "{$t['desde']} años o más"
            : "{$t['desde']} a {$t['hasta']} años").' '.self::plain($t['porcentaje']).'%', $escala));
    }
}
