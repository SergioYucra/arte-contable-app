/**
 * Funciones puras auxiliares para el generador de recibos.
 */

/**
 * Descompone una fecha ISO (`YYYY-MM-DD`) en `{ dia, mes, anio }` sin usar `new Date()`
 * para evitar desfases por zona horaria UTC.
 */
export function descomponerFecha(fechaISO) {
    if (typeof fechaISO !== 'string') {
        return { dia: '--', mes: '--', anio: '----' };
    }
    const match = fechaISO.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        return { dia: '--', mes: '--', anio: '----' };
    }
    const [, anio, mes, dia] = match;
    return { dia, mes, anio };
}

/**
 * Formatea un importe con separador de miles (coma) y 2 decimales (punto): `5,000.00`.
 */
export function formatImporteRecibo(valor) {
    const num = Number(valor);
    if (!Number.isFinite(num)) return '0.00';
    const redondeado = Math.round((num + Number.EPSILON) * 100) / 100;
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(redondeado);
}

/**
 * Deriva título, etiqueta de primera línea y leyendas de firmas según el tipo de recibo.
 * En EGRESO: izquierda = tercero ("Recibí conforme"), derecha = empresa ("Entregué conforme").
 * En INGRESO: izquierda = tercero ("Entregué conforme"), derecha = empresa ("Recibí conforme").
 */
export function derivarDatosRecibo(tipo) {
    const esIngreso = String(tipo).toUpperCase() === 'INGRESO';

    return {
        tipoNormalizado: esIngreso ? 'INGRESO' : 'EGRESO',
        titulo: esIngreso ? 'RECIBO DE INGRESO' : 'RECIBO DE EGRESO',
        primeraLineaLabel: esIngreso ? 'Recibi de:' : 'Entregue A:',
        firmaIzquierdaLeyenda: esIngreso ? 'Entregué conforme' : 'Recibí conforme',
        firmaDerechaLeyenda: esIngreso ? 'Recibí conforme' : 'Entregué conforme',
    };
}
