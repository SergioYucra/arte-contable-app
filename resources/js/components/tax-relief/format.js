/**
 * Formatos de la calculadora de alivio tributario (convención boliviana).
 */

const moneyFormatter = new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** 12352.15 → "Bs 12.352,15" */
export function bs(value) {
    const n = Number(value);
    return Number.isFinite(n) ? `Bs ${moneyFormatter.format(n)}` : '—';
}

/** 3.37471 → "3,37471" */
export function decimals(value, digits = 2) {
    const n = Number(value);
    return Number.isFinite(n)
        ? n.toLocaleString('es-BO', { minimumFractionDigits: digits, maximumFractionDigits: digits })
        : '—';
}

/** "2026-11-15" → "15/11/2026" */
export function shortDate(iso) {
    if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '—';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

/** "2019-01" → "enero de 2019" */
export function periodLabel(period) {
    if (typeof period !== 'string' || !/^\d{4}-\d{2}$/.test(period)) return period || '';
    const [y, m] = period.split('-');
    return `${months[Number(m) - 1]} de ${y}`;
}

/**
 * Interpreta lo que escribe la persona: "10000", "10.000", "10.000,50", "10000.5".
 * Devuelve un texto numérico con punto decimal, o '' si no se entiende.
 */
export function parseAmount(raw) {
    let text = String(raw ?? '').replace(/\s|Bs/gi, '');
    if (text === '') return '';

    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    } else if (/^\d{1,3}(\.\d{3})+$/.test(text)) {
        text = text.replace(/\./g, '');
    }

    return /^\d+(\.\d{1,2})?$/.test(text) ? text : '';
}
