import { ACTIVOS_FIJOS, ACTIVOS_INTANGIBLES } from '../shared/catalogos.js';
import { formatBs, formatearAsientoTSV, round2 } from '../shared/formatters.js';

export { formatBs, formatearAsientoTSV, round2 };

function calcularLineal({ tipo, catalogo, nombre, valorLibros, meses = 12, fechaCalculo = '' }) {
    const item = catalogo.find((entry) => entry.nombre === nombre) || null;
    if (!item) {
        return {
            calculable: false,
            esNoDepreciable: false,
            aviso: '',
            errors: ['Selecciona un activo válido del catálogo.'],
            vidaUtil: null,
            coeficiente: 0,
            anualRaw: 0,
            periodo: 0,
            asiento: null,
        };
    }

    // Caso especial Terrenos (vidaUtil: null): no se deprecian y no generan asiento.
    if (item.vidaUtil === null || item.vidaUtil <= 0) {
        return {
            calculable: true,
            esNoDepreciable: true,
            aviso: 'Los terrenos no se deprecian.',
            errors: [],
            vidaUtil: null,
            coeficiente: 0,
            anualRaw: 0,
            periodo: 0,
            asiento: null,
        };
    }

    const errors = [];
    const v = Number(valorLibros);
    if (valorLibros === '' || valorLibros === null || !Number.isFinite(v) || v <= 0) {
        errors.push('Ingresa un valor en libros mayor a 0.');
    }

    const m = Number(meses);
    if (meses === '' || meses === null || !Number.isInteger(m) || m < 1 || m > 12) {
        errors.push('Los meses de uso deben ser un número entero entre 1 y 12.');
    }

    if (!fechaCalculo) {
        errors.push('Selecciona una fecha de cálculo.');
    }

    if (errors.length > 0) {
        return {
            calculable: false,
            esNoDepreciable: false,
            aviso: '',
            errors,
            vidaUtil: item.vidaUtil,
            coeficiente: item.coeficiente,
            anualRaw: 0,
            periodo: 0,
            asiento: null,
        };
    }

    // Sin redondeo en pasos intermedios; se redondea únicamente el monto final del período.
    const anualRaw = v / item.vidaUtil;
    const periodoRaw = (anualRaw / 12) * m;
    const periodo = round2(periodoRaw);

    const esIntangible = tipo === 'intangible';
    const cuentaGasto = esIntangible
        ? `Amortizacion ${item.nombre}`
        : `Depreciacion ${item.nombre}`;
    const cuentaAcumulada = esIntangible
        ? `Amortizacion Acumulada ${item.nombre}`
        : `Depreciacion Acumulada ${item.nombre}`;
    const glosa = esIntangible
        ? 'POR LA AMORTIZACION DE LOS ACTIVOS INTANGIBLES'
        : 'POR LA DEPRECIACION DE LOS ACTIVOS FIJOS';

    const lineas = [
        { detalle: cuentaGasto, debe: periodo, haber: null },
        { detalle: cuentaAcumulada, debe: null, haber: periodo },
    ];

    return {
        calculable: true,
        esNoDepreciable: false,
        aviso: '',
        errors: [],
        vidaUtil: item.vidaUtil,
        coeficiente: item.coeficiente,
        anualRaw,
        periodo,
        asiento: {
            titulo: 'ASIENTO X',
            fecha: fechaCalculo,
            lineas,
            totalDebe: periodo,
            totalHaber: periodo,
            cuadrado: true,
            glosa,
        },
    };
}

/**
 * Calcula la depreciación lineal de un activo fijo.
 */
export function calcularDepreciacionFijo({ nombre, valorLibros, meses = 12, fechaCalculo = '' }) {
    return calcularLineal({
        tipo: 'fijo',
        catalogo: ACTIVOS_FIJOS,
        nombre,
        valorLibros,
        meses,
        fechaCalculo,
    });
}

/**
 * Calcula la amortización lineal de un activo intangible.
 */
export function calcularAmortizacionIntangible({ nombre, valorLibros, meses = 12, fechaCalculo = '' }) {
    return calcularLineal({
        tipo: 'intangible',
        catalogo: ACTIVOS_INTANGIBLES,
        nombre,
        valorLibros,
        meses,
        fechaCalculo,
    });
}
