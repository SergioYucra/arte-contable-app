import { CUENTAS_PATRIMONIALES } from './catalogos.js';

/**
 * Redondea un número a 2 decimales (centavos de Boliviano).
 */
export function round2(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return 0;
    return Math.round((num + Number.EPSILON) * 100) / 100;
}

/**
 * Redondea un número a 5 decimales (precisión estándar de UFV).
 */
export function round5(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return 0;
    return Math.round((num + Number.EPSILON) * 100000) / 100000;
}

/**
 * Formatea un monto numérico en formato boliviano (`1.234,56`).
 */
export function formatBs(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return '0,00';
    return new Intl.NumberFormat('es-BO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(num);
}

/**
 * Valida entradas comunes de fechas y UFVs.
 *
 * @returns {{ valid: boolean, errors: string[] }}
 */
export function validarEntradasBase({ ufvInicial, ufvFinal, fechaInicial, fechaAjuste }) {
    const errors = [];

    if (fechaInicial && fechaAjuste && fechaAjuste < fechaInicial) {
        errors.push('La fecha de ajuste debe ser mayor o igual a la fecha inicial.');
    }

    const ui = Number(ufvInicial);
    const uf = Number(ufvFinal);

    if (ufvInicial === null || ufvInicial === '' || !Number.isFinite(ui) || ui <= 0) {
        errors.push('Ingresa una UFV inicial válida mayor a 0 para calcular.');
    }

    if (ufvFinal === null || ufvFinal === '' || !Number.isFinite(uf) || uf <= 0) {
        errors.push('Ingresa una UFV final válida mayor a 0 para calcular.');
    }

    return {
        valid: errors.length === 0,
        errors,
    };
}

/**
 * Calcula la actualización de Activo Fijo o Activo Intangible (NC 3).
 *
 * @param {object} params
 * @param {'fijo' | 'intangible'} [params.tipo='fijo']
 * @param {string} params.nombre
 * @param {number} params.valorLibros
 * @param {number} params.acumulada
 * @param {number|string|null} params.ufvInicial
 * @param {number|string|null} params.ufvFinal
 * @param {string} [params.fechaInicial]
 * @param {string} [params.fechaAjuste]
 */
export function calcularActivoConAcumulada({
    tipo = 'fijo',
    nombre,
    valorLibros,
    acumulada = 0,
    ufvInicial,
    ufvFinal,
    fechaInicial = '',
    fechaAjuste = '',
}) {
    const base = validarEntradasBase({ ufvInicial, ufvFinal, fechaInicial, fechaAjuste });
    const warnings = [];
    const errors = [...base.errors];

    const v = Number(valorLibros);
    const da = Number(acumulada);

    if (!Number.isFinite(v) || v < 0) {
        errors.push('El valor en libros debe ser un número mayor o igual a 0.');
    }
    if (!Number.isFinite(da) || da < 0) {
        errors.push(
            tipo === 'intangible'
                ? 'La amortización acumulada debe ser un número mayor o igual a 0.'
                : 'La depreciación acumulada debe ser un número mayor o igual a 0.'
        );
    }

    if (Number.isFinite(v) && Number.isFinite(da) && da > v) {
        warnings.push(
            tipo === 'intangible'
                ? 'La amortización acumulada supera el valor en libros.'
                : 'La depreciación acumulada supera el valor en libros.'
        );
    }

    if (errors.length > 0) {
        return {
            calculable: false,
            errors,
            warnings,
            filas: [],
            asiento: null,
        };
    }

    const ui = Number(ufvInicial);
    const uf = Number(ufvFinal);
    const factor = uf / ui;

    const vActualizado = round2(v * factor);
    const incActivo = round2(vActualizado - v);

    const daActualizada = round2(da * factor);
    const incAcumulada = round2(daActualizada - da);

    // Se calcula REI con los montos ya redondeados para garantizar Debe = Haber.
    const rei = round2(incActivo - incAcumulada);

    const etiquetaAcumulada =
        tipo === 'intangible'
            ? `Amortizacion Acumulada ${nombre}`
            : `Depreciacion Acumulada ${nombre}`;

    const glosa =
        tipo === 'intangible'
            ? 'POR ACTUALIZACION DEL ACTIVO INTANGIBLE'
            : 'POR ACTUALIZACION DEL ACTIVO FIJO S/G NC.3';

    const filas = [
        {
            cuenta: nombre,
            valorLibros: round2(v),
            ufvInicial: ui,
            ufvFinal: uf,
            valorActualizado: vActualizado,
            incremento: incActivo,
        },
        {
            cuenta: etiquetaAcumulada,
            valorLibros: round2(da),
            ufvInicial: ui,
            ufvFinal: uf,
            valorActualizado: daActualizada,
            incremento: incAcumulada,
        },
    ];

    const lineasAsiento = [
        { detalle: nombre, debe: incActivo, haber: null },
        { detalle: 'Resultados por Exposicion a la Inflacion', debe: null, haber: rei },
        { detalle: etiquetaAcumulada, debe: null, haber: incAcumulada },
    ];

    const totalDebe = round2(lineasAsiento.reduce((sum, l) => sum + (l.debe || 0), 0));
    const totalHaber = round2(lineasAsiento.reduce((sum, l) => sum + (l.haber || 0), 0));

    return {
        calculable: true,
        errors: [],
        warnings,
        reiNegativo: rei < 0,
        filas,
        asiento: {
            titulo: 'ASIENTO X',
            fecha: fechaAjuste,
            lineas: lineasAsiento,
            totalDebe,
            totalHaber,
            cuadrado: Math.abs(totalDebe - totalHaber) < 0.005,
            glosa,
        },
    };
}

/**
 * Calcula la actualización de un Rubro Patrimonial (NC 3).
 *
 * @param {object} params
 * @param {string} params.cuenta
 * @param {number} params.valorLibros
 * @param {number|string|null} params.ufvInicial
 * @param {number|string|null} params.ufvFinal
 * @param {string} [params.fechaInicial]
 * @param {string} [params.fechaAjuste]
 */
export function calcularRubroPatrimonial({
    cuenta,
    valorLibros,
    ufvInicial,
    ufvFinal,
    fechaInicial = '',
    fechaAjuste = '',
}) {
    const base = validarEntradasBase({ ufvInicial, ufvFinal, fechaInicial, fechaAjuste });
    const errors = [...base.errors];

    const v = Number(valorLibros);
    if (!Number.isFinite(v) || v < 0) {
        errors.push('El valor en libros debe ser un número mayor o igual a 0.');
    }

    if (errors.length > 0) {
        return {
            calculable: false,
            errors,
            warnings: [],
            filas: [],
            asiento: null,
        };
    }

    const ui = Number(ufvInicial);
    const uf = Number(ufvFinal);
    const vActualizado = round2(v * (uf / ui));
    const incremento = round2(vActualizado - v);

    const catalogoItem = CUENTAS_PATRIMONIALES.find((c) => c.cuenta === cuenta);
    const cuentaAjuste = catalogoItem?.cuentaAjuste || cuenta;

    const filas = [
        {
            cuenta,
            valorLibros: round2(v),
            ufvInicial: ui,
            ufvFinal: uf,
            valorActualizado: vActualizado,
            incremento,
        },
    ];

    const lineasAsiento = [
        { detalle: 'Resultados por Exposicion a la Inflacion', debe: incremento, haber: null },
        { detalle: cuentaAjuste, debe: null, haber: incremento },
    ];

    const totalDebe = round2(lineasAsiento.reduce((sum, l) => sum + (l.debe || 0), 0));
    const totalHaber = round2(lineasAsiento.reduce((sum, l) => sum + (l.haber || 0), 0));

    return {
        calculable: true,
        errors: [],
        warnings: [],
        reiNegativo: false,
        filas,
        asiento: {
            titulo: 'ASIENTO X',
            fecha: fechaAjuste,
            lineas: lineasAsiento,
            totalDebe,
            totalHaber,
            cuadrado: Math.abs(totalDebe - totalHaber) < 0.005,
            glosa: 'POR ACTUALIZACION DEL RUBRO PATRIMONIAL',
        },
    };
}

/**
 * Genera texto tabulado (TSV) del asiento contable para pegar en Excel.
 */
export function formatearAsientoTSV(asiento) {
    if (!asiento) return '';

    const rows = [
        [`Fecha: ${asiento.fecha || '-'}`, asiento.titulo, ''],
        ['Detalle', 'Debe', 'Haber'],
        ...asiento.lineas.map((l) => [
            l.detalle,
            l.debe !== null && l.debe !== undefined ? formatBs(l.debe) : '',
            l.haber !== null && l.haber !== undefined ? formatBs(l.haber) : '',
        ]),
        ['Totales', formatBs(asiento.totalDebe), formatBs(asiento.totalHaber)],
        [`Glosa: ${asiento.glosa}`, '', ''],
    ];

    return rows.map((r) => r.join('\t')).join('\n');
}
