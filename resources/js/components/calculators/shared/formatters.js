/**
 * Utilidades puras de redondeo y formato monetario boliviano (es-BO).
 */

export function round2(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return 0;
    return Math.round((num + Number.EPSILON) * 100) / 100;
}

export function round5(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return 0;
    return Math.round((num + Number.EPSILON) * 100000) / 100000;
}

export function formatBs(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return '0,00';
    return new Intl.NumberFormat('es-BO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(num);
}

/**
 * Genera texto tabulado (TSV) de un asiento contable para pegar en Excel.
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
