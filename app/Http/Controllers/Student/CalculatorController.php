<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class CalculatorController extends Controller
{
    /**
     * Muestra la página principal del módulo de Calculadoras.
     *
     * Opera sin base de datos: cada calculadora se ejecuta con lógica
     * interna o endpoints API dedicados.
     */
    public function index(): View
    {
        $items = [
            [
                'id' => 'actualizacion-cuentas-nc3',
                'slug' => 'actualizacion-cuentas-nc3',
                'component' => 'ActualizacionCuentasNc3',
                'title' => 'Actualización de Cuentas (NC 3)',
                'description' => 'Ajuste por inflación y tenencia de bienes conforme a la Norma Contable N° 3 mediante variación de UFV.',
                'badge' => 'NC 3',
                'icon' => 'calculator',
                'status' => 'ready',
            ],
            [
                'id' => 'depreciacion-amortizaciones',
                'slug' => 'depreciacion-amortizaciones',
                'component' => 'DepreciacionAmortizaciones',
                'title' => 'Depreciación y amortizaciones',
                'description' => 'Cálculo de depreciación de activos fijos y amortización acumulada según vida útil y coeficientes.',
                'badge' => 'Activos',
                'icon' => 'trending-down',
                'status' => 'ready',
            ],
            [
                'id' => 'reexpresion-moneda-extranjera',
                'slug' => 'reexpresion-moneda-extranjera',
                'component' => 'ReexpresionMonedaExtranjera',
                'title' => 'Reexpresión de la moneda extranjera',
                'description' => 'Determinación de diferencias de cambio y actualización de saldos en moneda extranjera.',
                'badge' => 'TC / ME',
                'icon' => 'coins',
                'status' => 'ready',
            ],
            [
                'id' => 'rc-iva',
                'slug' => 'rc-iva',
                'component' => 'RcIvaCalculator',
                'title' => 'RC-IVA',
                'description' => 'Liquidación del Régimen Complementario al IVA, deducciones, mínimo no imponible y saldo a favor.',
                'badge' => 'Tributario',
                'icon' => 'percent',
                'status' => 'ready',
            ],
            [
                'id' => 'bono-antiguedad',
                'slug' => 'bono-antiguedad',
                'component' => 'BonoAntiguedadCalculator',
                'title' => 'Bono Antigüedad',
                'description' => 'Cálculo porcentual del bono de antigüedad sobre salarios mínimos nacionales según años de servicio.',
                'badge' => 'Laboral',
                'icon' => 'users',
                'status' => 'ready',
            ],
        ];

        return view('calculators.index', [
            'initialItems' => $items,
        ]);
    }
}
