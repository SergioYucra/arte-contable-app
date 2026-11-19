import { formatImporteRecibo } from '../recibo/reciboHelpers.js';

export const DEPARTAMENTOS_CAPITAL = {
    'LA PAZ': 'La Paz',
    'SANTA CRUZ': 'Santa Cruz',
    COCHABAMBA: 'Cochabamba',
    ORURO: 'Oruro',
    POTOSÍ: 'Potosí',
    CHUQUISACA: 'Sucre',
    TARIJA: 'Tarija',
    BENI: 'Trinidad',
    PANDO: 'Cobija',
};

export const DEPARTAMENTOS_LISTA = Object.keys(DEPARTAMENTOS_CAPITAL);

export function capitalPorDepartamento(departamento) {
    const key = String(departamento || '').trim().toUpperCase();
    return DEPARTAMENTOS_CAPITAL[key] || '';
}

/**
 * Formatea una fecha ISO (`YYYY-MM-DD`) en español largo local:
 * ej. `2026-11-16` -> `"lunes, 16 de noviembre de 2026"`.
 * Evita el desfase UTC construyendo Date(y, m - 1, d).
 */
export function formatearFechaLargaEs(fechaISO) {
    if (typeof fechaISO !== 'string') return '';
    const match = fechaISO.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return '';

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const localDate = new Date(year, month - 1, day);

    if (
        Number.isNaN(localDate.getTime()) ||
        localDate.getFullYear() !== year ||
        localDate.getMonth() !== month - 1 ||
        localDate.getDate() !== day
    ) {
        return '';
    }

    return new Intl.DateTimeFormat('es-BO', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(localDate);
}

/**
 * Incrementa un número de cheque en texto conservando ceros a la izquierda y largo.
 * Ej: `'0099'` -> `'0100'`, `'1234'` -> `'1235'`.
 */
export function incrementarNumeroCheque(valor) {
    const raw = String(valor || '').replace(/\D/g, '');
    if (!raw) return '1';
    const next = BigInt(raw) + 1n;
    return next.toString().padStart(raw.length, '0').slice(0, 12);
}

export function formatImporteCheque(valor) {
    return formatImporteRecibo(valor);
}
