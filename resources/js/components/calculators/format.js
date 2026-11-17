/**
 * Formatos de las calculadoras (convención boliviana). Sin Vue ni DOM: también los usan las pruebas.
 */

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Monto más alto que aceptan los campos de dinero. */
export const MAX_AMOUNT = 9999999.99;

/**
 * 1906.5 → "Bs 1.906,50"; con digits = 0 → "Bs 9.900".
 * Entre "Bs" y el número va un espacio que no se parte: el monto nunca queda en dos líneas.
 */
export function bs(value, digits = 2) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '—';
    return `Bs\u00A0${n.toLocaleString('es-BO', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`;
}

/** Sin decimales si el monto es entero: 3300 → "Bs 3.300"; 3000.5 → "Bs 3.000,50"; 13.000000000000002 → "Bs 13". */
export function bsCompact(value) {
    const n = Math.round(Number(value) * 100) / 100;
    return bs(n, Number.isInteger(n) ? 0 : 2);
}

/** 0.1271 → "12,71%"; 5 (ya en por ciento) con isPercent → "5%". */
export function percent(value, { isPercent = false } = {}) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '—';
    const pct = isPercent ? n : n * 100;
    return `${pct.toLocaleString('es-BO', { maximumFractionDigits: 2 })}%`;
}

/** "2028-11-17" → "17 de noviembre de 2028". */
export function longDate(iso) {
    const m = typeof iso === 'string' && iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) return '—';
    return `${Number(m[3])} de ${MONTHS[Number(m[2]) - 1]} de ${m[1]}`;
}

/** Fecha local de hoy en formato ISO ("2026-11-17"). */
export function todayIso(date = new Date()) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Interpreta lo que escribe la persona: "10000", "10.000", "10.000,50", "10000.5", "Bs 2.500".
 * Devuelve un número, o null si no se entiende o pasa del máximo.
 */
export function parseAmount(raw) {
    let text = String(raw ?? '').replace(/\s|Bs\.?/gi, '');
    if (text === '') return null;

    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    } else if (/^\d{1,3}(\.\d{3})+$/.test(text)) {
        text = text.replace(/\./g, '');
    }

    if (!/^\d+(\.\d{1,2})?$/.test(text)) return null;
    const n = Number(text);
    return n <= MAX_AMOUNT ? n : null;
}

/** Entero entre min y max escrito por la persona, o null. */
export function parseWhole(raw, min, max) {
    const text = String(raw ?? '').trim();
    if (!/^\d{1,4}$/.test(text)) return null;
    const n = Number(text);
    return n >= min && n <= max ? n : null;
}
