<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ToolController extends Controller
{
    /**
     * Muestra la página principal del módulo de Herramientas.
     *
     * Opera sin base de datos: cada herramienta utiliza lógica interna,
     * utilidades de navegador o integraciones API directas.
     */
    public function index(): View
    {
        $items = [
            [
                'id' => 'crear-factura',
                'slug' => 'crear-factura',
                'component' => 'CrearFacturaTool',
                'title' => 'Crear Factura',
                'description' => 'Asistente para estructurar facturas comerciales con desglose de importes, subtotales e impuestos.',
                'badge' => 'Documento',
                'icon' => 'file-text',
                'status' => 'ready',
            ],
            [
                'id' => 'crear-recibo',
                'slug' => 'crear-recibo',
                'component' => 'CrearReciboTool',
                'title' => 'Crear Recibo',
                'description' => 'Emisión de recibos de caja, cobro o entrega con conversión de monto literal y detalle.',
                'badge' => 'Comprobante',
                'icon' => 'receipt',
                'status' => 'ready',
            ],
            [
                'id' => 'crear-cheque',
                'slug' => 'crear-cheque',
                'component' => 'CrearChequeTool',
                'title' => 'Crear Cheque',
                'description' => 'Simulador y formato de llenado de cheques bancarios con validación de fecha, orden y literal.',
                'badge' => 'Bancario',
                'icon' => 'banknote',
                'status' => 'ready',
            ],
            [
                'id' => 'crear-balance-apertura',
                'slug' => 'crear-balance-apertura',
                'component' => 'CrearBalanceAperturaTool',
                'title' => 'Crear Balance de apertura',
                'description' => 'Constructor de balance inicial clasificado en Activo, Pasivo y Patrimonio con verificación de partida doble.',
                'badge' => 'Contable',
                'icon' => 'scale',
                'status' => 'ready',
            ],
        ];

        return view('tools.index', [
            'initialItems' => $items,
        ]);
    }
}
