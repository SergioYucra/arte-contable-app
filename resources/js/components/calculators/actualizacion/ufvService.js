/**
 * Servicio de consulta de UFV a través del endpoint proxy interno de Laravel.
 * La URL del proveedor externo permanece oculta en el servidor.
 */
export const UFV_API_URL = '/api/ufv';
export const UFV_TIMEOUT_MS = 8000;

export class UfvApiError extends Error {
    constructor(message = 'No se pudo consultar la API del BCB', cause = null) {
        super(message);
        this.name = 'UfvApiError';
        this.cause = cause;
    }
}

const ufvCache = new Map();

export function clearUfvCache() {
    ufvCache.clear();
}

export function roundUfv5(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return null;
    return Math.round((num + Number.EPSILON) * 100000) / 100000;
}

/**
 * Consulta la UFV para una fecha ISO (`YYYY-MM-DD`).
 *
 * @param {string} fechaISO
 * @param {{ signal?: AbortSignal, fetchImpl?: typeof fetch }} [options]
 * @returns {Promise<number | null>}
 */
export async function getUfv(fechaISO, options = {}) {
    if (!fechaISO || !/^\d{4}-\d{2}-\d{2}$/.test(fechaISO)) {
        return null;
    }

    if (ufvCache.has(fechaISO)) {
        return ufvCache.get(fechaISO);
    }

    const fetchFn = options.fetchImpl || globalThis.fetch;
    if (typeof fetchFn !== 'function') {
        throw new UfvApiError('No hay implementación de fetch disponible.');
    }

    const timeoutController = new AbortController();
    const timeoutId = setTimeout(() => timeoutController.abort(), UFV_TIMEOUT_MS);

    const externalSignal = options.signal;
    const onExternalAbort = () => timeoutController.abort();
    if (externalSignal) {
        if (externalSignal.aborted) {
            clearTimeout(timeoutId);
            throw new DOMException('Aborted', 'AbortError');
        }
        externalSignal.addEventListener('abort', onExternalAbort, { once: true });
    }

    try {
        const response = await fetchFn(`${UFV_API_URL}?fecha=${encodeURIComponent(fechaISO)}`, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: timeoutController.signal,
        });

        if (!response.ok) {
            throw new UfvApiError(`Respuesta HTTP ${response.status} del servicio UFV`);
        }

        const data = await response.json();
        const ufvValue = data?.ufv === null || data?.ufv === undefined ? null : roundUfv5(data.ufv);

        ufvCache.set(fechaISO, ufvValue);
        return ufvValue;
    } catch (error) {
        if (error?.name === 'AbortError' && externalSignal?.aborted) {
            throw error;
        }
        if (error instanceof UfvApiError) {
            throw error;
        }
        throw new UfvApiError('No se pudo consultar la API del BCB', error);
    } finally {
        clearTimeout(timeoutId);
        if (externalSignal) {
            externalSignal.removeEventListener('abort', onExternalAbort);
        }
    }
}
