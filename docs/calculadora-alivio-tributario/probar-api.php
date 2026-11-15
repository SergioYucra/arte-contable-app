<?php
/**
 * Prueba de la API de boliviaimpuestos.com (calculadora "Deuda con Alivio Tributario / FAP").
 *
 * Llama a sus endpoints desde PHP, como lo haría nuestro backend, y compara las
 * respuestas con valores ya verificados (ver ANALISIS-FAP-ALIVIO.md).
 * Solo pide cálculos: no crea, modifica ni envía nada. ~15 llamadas en total.
 *
 * Uso (PHP 8.3 en Docker, desde Git Bash):
 *   MSYS_NO_PATHCONV=1 docker run --rm -v "D:/Dexidi/arte-contable-app/docs/calculadora-alivio-tributario:/w" arte-php83:local php /w/probar-api.php
 *
 * Código de salida: 0 si todo pasa, 1 si algo falla (sirve después como monitor diario).
 */
declare(strict_types=1);

const BASE    = 'https://boliviaimpuestos.com/maletin/';
const TIMEOUT = 15;          // segundos por llamada
const LENTO   = 5000;        // ms: más que esto se marca como lento

$fallos = 0;
$avisos = 0;
$tiempos = [];

function llamar(string $metodo, string $ruta, ?array $cuerpo = null, array $headersExtra = []): array
{
    global $tiempos;
    $headers = array_merge(['Accept: application/json'], $headersExtra);
    $ch = curl_init(BASE . $ruta);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; generic-client)',
        CURLOPT_HEADER         => true,
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
        $headers[] = 'Content-Type: application/json';
    } elseif ($metodo !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $metodo;
        $opts[CURLOPT_NOBODY] = true;
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $t0  = microtime(true);
    $raw = curl_exec($ch);
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    $rawHeaders = is_string($raw) ? substr($raw, 0, $hsize) : '';
    $body       = is_string($raw) ? substr($raw, $hsize) : '';
    if ($metodo !== 'OPTIONS') {
        $tiempos[] = $ms;
    }

    return [
        'http'    => $http,
        'ms'      => $ms,
        'json'    => json_decode($body, true),
        'err'     => $err,
        'headers' => strtolower($rawHeaders),
        'body'    => substr($body, 0, 200),
    ];
}

/** Imprime un caso con sus comprobaciones. Cada check: [texto, ok(bool), detalle, esAviso?] */
function caso(string $titulo, array $r, array $checks): void
{
    global $fallos, $avisos;
    $base = $r['http'] === 200 && is_array($r['json']);
    $estado = $base ? 'HTTP 200' : "HTTP {$r['http']} {$r['err']}";
    printf("\n%s  (%s, %d ms)\n", $titulo, $estado, $r['ms']);
    if (!$base) {
        $fallos++;
        echo "   ❌ no llegó un JSON válido: " . $r['body'] . "\n";
        return;
    }
    foreach ($checks as $c) {
        [$texto, $ok, $detalle] = $c;
        $esAviso = $c[3] ?? false;
        if ($ok) {
            echo "   ✅ $texto\n";
        } elseif ($esAviso) {
            $avisos++;
            echo "   ⚠️  $texto — $detalle\n";
        } else {
            $fallos++;
            echo "   ❌ $texto — $detalle\n";
        }
    }
}

function igual($real, $esperado, float $tol = 0.005): bool
{
    return is_numeric($real) && abs((float) $real - (float) $esperado) <= $tol;
}

function ck(string $etiqueta, $real, $esperado, bool $aviso = false): array
{
    $ok = is_numeric($esperado) ? igual($real, $esperado) : $real === $esperado;
    $fmt = fn ($v) => $v === null ? 'null' : (is_bool($v) ? var_export($v, true) : (string) $v);
    return ["$etiqueta = " . $fmt($esperado), $ok, 'llegó ' . $fmt($real), $aviso];
}

function faltantes(array $obj, array $campos): array
{
    return array_values(array_filter($campos, fn ($c) => !array_key_exists($c, $obj)));
}

echo "Prueba de la API de boliviaimpuestos.com\n";
echo "Servidor: " . BASE . "  (único servidor que tienen: producción; solo cálculos de lectura)\n";
echo "Fecha de la prueba: " . date('Y-m-d H:i') . " UTC\n";
echo str_repeat('─', 78) . "\n";

// ---------------------------------------------------------------------------
// 1) UFV
// ---------------------------------------------------------------------------
$u1 = llamar('GET', 'deudas.php?action=get_ufv&fecha=2026-05-27');
caso('1. UFV de la publicación de la Ley 1733 (27/05/2026)', $u1, [ck('ufv', $u1['json']['ufv'] ?? null, 3.25566)]);

$u2 = llamar('GET', 'deudas.php?action=get_ufv&fecha=2019-02-15');
caso('2. UFV histórica (15/02/2019)', $u2, [ck('ufv', $u2['json']['ufv'] ?? null, 2.29505)]);

$u3 = llamar('GET', 'deudas.php?action=get_ufv&fecha=2030-01-01');
caso('3. UFV de una fecha sin publicar (01/01/2030)', $u3, [
    ck('ufv', is_array($u3['json']) && array_key_exists('ufv', $u3['json']) ? $u3['json']['ufv'] : '(campo ausente)', null),
]);

// ---------------------------------------------------------------------------
// 2) Vencimientos sugeridos
// ---------------------------------------------------------------------------
$v1 = llamar('GET', 'deudas.php?action=vencimiento&periodo=2019-01&impuesto=IVA&nit=2');
caso('4. Vencimiento IVA 2019-01, NIT terminado en 2', $v1, [ck('fecha', $v1['json']['fecha'] ?? null, '2019-02-15')]);

$v2 = llamar('GET', 'deudas.php?action=vencimiento&periodo=2026-01&impuesto=IVA&nit=3');
caso('5. Vencimiento que cae en Carnaval (IVA 2026-01, NIT 3)', $v2, [ck('fecha', $v2['json']['fecha'] ?? null, '2026-02-18')]);

$v3 = llamar('GET', 'deudas.php?action=vencimiento&periodo=2019-12&impuesto=IUE&nit=0');
caso('6. Vencimiento IUE gestión 2019 (prórroga pandemia)', $v3, [ck('fecha', $v3['json']['fecha'] ?? null, '2020-07-31')]);

// ---------------------------------------------------------------------------
// 3) Plan FAP — caso de la captura
// ---------------------------------------------------------------------------
$deudaBase = ['impuesto' => 'IVA', 'periodo' => '2019-01', 'fechaVenc' => '2019-02-15', 'tributoOmitido' => 10000, 'cuotas' => 12];
$planBase  = ['fechaSolicitud' => '2026-11-15', 'mesPrimera' => 2, 'tipoGarantia' => 'efectivo', 'modo' => 'regularizacion', 'deudas' => [$deudaBase]];

$f1 = llamar('POST', 'fap.php?action=fap', $planBase);
$j  = $f1['json'] ?? [];
$cron = $j['cronograma'] ?? [];
$sumaOk = count($cron) > 0;
foreach ($cron as $c) {
    $sumaOk = $sumaOk && igual($c['totalCuota'] ?? null, ($c['compTO'] ?? 0) + ($c['compMV'] ?? 0) + ($c['compInt'] ?? 0), 0.02);
}
caso('7. Plan FAP con alivio: IVA Bs 10.000, 12 cuotas (caso de la captura)', $f1, [
    ck('error', $j['error'] ?? null, false),
    ck('Deuda sin alivio (totalNormal)', $j['totalNormal'] ?? null, 21400.77),
    ck('Al contado (totalContado)', $j['totalContado'] ?? null, 12352.15),
    ck('Base FAP (totalConsolidado)', $j['totalConsolidado'] ?? null, 14185.57),
    ck('Pago inicial 5%', $j['pagoInicial'] ?? null, 709.28),
    ck('Garantía 5%', $j['garantia'] ?? null, 709.28),
    ck('Saldo en cuotas', $j['saldoTotal'] ?? null, 12767.01),
    ck('Cuotas usadas (maxCuotas)', $j['maxCuotas'] ?? null, 12),
    ck('Máximo permitido', $j['maxCuotasPermitidas'] ?? null, 18),
    ck('Filas del cronograma', count($cron), 12),
    ck('Capital por cuota', $cron[0]['compTO'] ?? null, 1063.92),
    ck('Fecha 1.ª cuota', $cron[0]['fecha'] ?? null, '2027-01-29'),
    ['Cada cuota = capital + mant. valor + interés', $sumaOk, 'alguna cuota no cuadra'],
    // La cuota 1 usa UFV proyectada: cambia cuando el BCB publica UFV nuevas. Solo avisa.
    ck('Cuota 1 total (UFV proyectada, puede variar)', $cron[0]['totalCuota'] ?? null, 1157.02, true),
]);

// ---------------------------------------------------------------------------
// 4) Memoria de cálculo Art. 47
// ---------------------------------------------------------------------------
$calcBody = ['fechaVenc' => '2019-02-15', 'fechaPago' => '2026-11-15', 'tributoOmitido' => 10000, 'impuesto' => 'IVA', 'periodo' => '2019-01', 'sancionOmision' => false, 'precisionFactor' => 'completa'];
$c1 = llamar('POST', 'deudas.php?action=calc', $calcBody);
$cj = $c1['json'] ?? [];
$tr = $cj['tramos'] ?? [];
caso('8. Desglose Art. 47 (mant. de valor + interés por tramos)', $c1, [
    ck('Mant. de valor', $cj['mvBs'] ?? null, 4704.30),
    ck('Interés', $cj['interesBs'] ?? null, 6696.47),
    ck('Deuda total', $cj['deudaBs'] ?? null, 21400.77),
    ck('Tramo 4% (días)', $tr[0]['dias'] ?? null, 1461),
    ck('Tramo 6% (días)', $tr[1]['dias'] ?? null, 1096),
    ck('Tramo 10% (días)', $tr[2]['dias'] ?? null, 273),
]);

$c2 = llamar('POST', 'deudas.php?action=calc', ['sancionOmision' => true] + $calcBody);
caso('9. Con sanción por omisión 60% (determinación)', $c2, [ck('Sanción', $c2['json']['sancionBs'] ?? null, 8822.58)]);

// ---------------------------------------------------------------------------
// 5) Variantes de negocio
// ---------------------------------------------------------------------------
$f2 = llamar('POST', 'fap.php?action=fap', ['tipoGarantia' => 'hipotecaria'] + $planBase);
caso('10. Garantía hipotecaria (no se paga en efectivo)', $f2, [
    ck('Garantía', $f2['json']['garantia'] ?? null, 0),
    ck('Saldo = base − inicial', $f2['json']['saldoTotal'] ?? null, 13476.29),
]);

$f3 = llamar('POST', 'fap.php?action=fap', ['deudas' => [
    ['impuesto' => 'IVA', 'periodo' => '2017-12', 'fechaVenc' => '2018-01-15', 'tributoOmitido' => 10000, 'cuotas' => 12],
    ['impuesto' => 'IT',  'periodo' => '2020-06', 'fechaVenc' => '2020-07-15', 'tributoOmitido' => 10000, 'cuotas' => 12],
    $deudaBase,
]] + $planBase);
$trat = array_map(fn ($d) => $d['tratamiento'] ?? '?', $f3['json']['deudas'] ?? []);
caso('11. Condonación por periodo (2017-12 y 2020-06 se perdonan; 2019-01 no)', $f3, [
    ck('Periodo 2017-12', $trat[0] ?? null, 'condonado'),
    ck('Periodo 2020-06', $trat[1] ?? null, 'condonado'),
    ck('Periodo 2019-01', $trat[2] ?? null, 'regularizable'),
    ck('Base FAP solo de la regularizable', $f3['json']['totalConsolidado'] ?? null, 14185.57),
]);

$f4 = llamar('POST', 'fap.php?action=fap', ['tipoGarantia' => 'hipotecaria', 'deudas' => [
    $deudaBase,
    ['impuesto' => 'IT', 'periodo' => '2022-05', 'fechaVenc' => '2022-06-15', 'tributoOmitido' => 2500, 'cuotas' => 12],
]] + $planBase);
$fj = $f4['json'] ?? [];
caso('12. Varias deudas en un solo plan (IVA + IT)', $f4, [
    ck('Base FAP sumada', $fj['totalConsolidado'] ?? null, 17601.41),
    ck('Al contado sumado', $fj['totalContado'] ?? null, 15372.52),
    ck('Máximo permitido (cuota mín. sobre el saldo total)', $fj['maxCuotasPermitidas'] ?? null, 24),
    ck('Deudas en la cuota 1', $fj['cronograma'][0]['deudasActivas'] ?? null, 2),
]);

$e1 = llamar('POST', 'fap.php?action=fap', ['deudas' => []] + $planBase);
caso('13. Datos incompletos: devuelve un error entendible', $e1, [
    ['Trae un mensaje de error', is_string($e1['json']['error'] ?? null), 'no trae mensaje'],
]);

// ---------------------------------------------------------------------------
// 6) Contrato: que existan todos los campos que vamos a usar
// ---------------------------------------------------------------------------
$camposPlan = ['error', 'modo', 'fechaSolicitud', 'ufvSolicitud', 'cuotaMinimaUFV', 'cuotaMinimaBs', 'maxCuotasPermitidas', 'topeCuotas', 'pubLey', 'inflacionAnualEstim', 'ufvUltimaFecha', 'hoy', 'avisos', 'totalConsolidado', 'totalNormal', 'totalCondonado', 'ahorroTotal', 'totalContado', 'nCondonadas', 'nRegularizables', 'porcentajePagoInicial', 'porcentajeGarantia', 'pagoInicial', 'garantia', 'saldoTotal', 'maxCuotas', 'deudas', 'cronograma'];
$camposDeuda = ['impuesto', 'periodo', 'venc', 'tratamiento', 'tributoOmitido', 'mv', 'interes', 'totalNormal', 'total', 'contado', 'pagoInicial', 'garantia', 'saldo', 'cuotas', 'maxCuotasDeuda'];
$camposCuota = ['n', 'fecha', 'ufv', 'proyectada', 'vencida', 'totalCuota', 'compTO', 'compMV', 'compInt', 'deudasActivas', 'detalle'];
$camposCalc  = ['ufvVenc', 'ufvPago', 'cUFV', 'toActualizadoBs', 'mvBs', 'interesBs', 'deudaBs', 'sancionBs', 'tramos'];
$m1 = faltantes($j, $camposPlan);
$m2 = faltantes($j['deudas'][0] ?? [], $camposDeuda);
$m3 = faltantes($cron[0] ?? [], $camposCuota);
$m4 = faltantes($cj, $camposCalc);
caso('14. Contrato: están todos los campos que usaríamos', $f1, [
    ['Plan: ' . count($camposPlan) . ' campos', !$m1, 'faltan: ' . implode(', ', $m1)],
    ['Deuda: ' . count($camposDeuda) . ' campos', !$m2, 'faltan: ' . implode(', ', $m2)],
    ['Cuota: ' . count($camposCuota) . ' campos', !$m3, 'faltan: ' . implode(', ', $m3)],
    ['Desglose Art. 47: ' . count($camposCalc) . ' campos', !$m4, 'faltan: ' . implode(', ', $m4)],
]);

// ---------------------------------------------------------------------------
// 7) Navegador vs servidor (CORS)
// ---------------------------------------------------------------------------
$o1 = llamar('OPTIONS', 'fap.php?action=fap', null, ['Origin: https://nuestro-sistema.example', 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type']);
$cors = str_contains($o1['headers'], 'access-control-allow-origin');
printf("\n15. ¿El navegador de un usuario puede llamarla directo? (CORS, %d ms)\n", $o1['ms']);
echo $cors
    ? "   ⚠️  Ahora SÍ envían CORS — cambió respecto al 15/11/2026, revisar.\n"
    : "   ✅ No envían CORS, como se esperaba → hay que llamarla desde nuestro backend.\n";
if ($cors) {
    $avisos++;
}

// ---------------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------------
sort($tiempos);
$n = count($tiempos);
$prom = $n ? (int) round(array_sum($tiempos) / $n) : 0;
$max = $n ? max($tiempos) : 0;
echo "\n" . str_repeat('─', 78) . "\n";
printf("Velocidad: %d llamadas · mín %d ms · promedio %d ms · máx %d ms%s\n", $n, $tiempos[0] ?? 0, $prom, $max, $max > LENTO ? '  ⚠️ alguna fue lenta' : '');
printf("Resultado: %s  (%d fallos, %d avisos)\n", $fallos ? '❌ HAY FALLOS' : '✅ TODO OK', $fallos, $avisos);
exit($fallos ? 1 : 0);
