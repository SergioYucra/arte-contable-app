/**
 * Carga de páginas del lector protegido.
 *
 * Las páginas se piden con fetch() (no con <img src>) para:
 * - leer el motivo cuando el servidor no entrega la página (otro dispositivo, límite de hoy,
 *   pausa…) y mostrar un aviso claro en vez de una imagen rota;
 * - que el servidor rechace la imagen abierta fuera del lector (Sec-Fetch-Dest: document);
 * - guardar en memoria las últimas páginas para volver atrás sin pedirlas de nuevo, liberando
 *   las que ya no se usan (URL.revokeObjectURL).
 *
 * Los textos siguen la fórmula problema → causa → qué hacer, en palabras de cualquier persona.
 */

export const LOW_PAGES_WARNING = 30;

/** Aviso por código de respuesta del servidor. `action` es lo que hace el botón principal. */
export const READER_NOTICES = {
    otro_dispositivo: {
        tone: 'info',
        title: 'Tu cuenta está abierta en otro dispositivo',
        message: 'Solo se puede leer en un dispositivo a la vez. Si sigues aquí, el otro se detendrá.',
        action: 'claim',
        actionLabel: 'Leer aquí',
    },
    limite_diario: {
        tone: 'warning',
        title: 'Llegaste al límite de páginas de hoy',
        message: 'Mañana puedes seguir leyendo desde aquí. Las páginas que ya viste hoy las puedes volver a abrir.',
        action: 'close',
        actionLabel: 'Volver a la biblioteca',
    },
    lectura_pausada: {
        tone: 'warning',
        title: 'Tu lectura está en pausa',
        message: 'Notamos un ritmo de lectura fuera de lo normal y la pausamos por un rato. Si crees que es un error, escribe al administrador.',
        action: 'close',
        actionLabel: 'Volver a la biblioteca',
    },
    muy_rapido: {
        tone: 'info',
        title: 'Vas pasando páginas muy rápido',
        message: 'Espera unos segundos y sigue leyendo.',
        action: 'retry',
        actionLabel: 'Seguir leyendo',
    },
    // Primer aviso de ritmo no humano: todavía no hay pausa; si sigue a ese ritmo, la habrá.
    ritmo_rapido: {
        tone: 'warning',
        title: 'Vas más rápido de lo que se puede leer',
        message:
            'Sigue con calma: si continúa este ritmo, la lectura se pausará por unas horas. Para ir a un tema, usa el temario o escribe el número de página.',
        action: 'retry',
        actionLabel: 'Seguir leyendo',
    },
    fuera_del_lector: {
        tone: 'info',
        title: 'Las páginas se leen dentro del lector',
        message: 'Abre el libro desde la biblioteca para seguir leyendo.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
    no_disponible: {
        tone: 'info',
        title: 'Esta página no está disponible',
        message: 'Puede que todavía se esté preparando. Intenta de nuevo en un momento.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
    // Solo cuando el servidor lo dice con este código: entonces sí avisó al administrador.
    lector_detenido: {
        tone: 'error',
        title: 'El lector no está disponible ahora',
        message: 'Ya avisamos al administrador. Intenta de nuevo más tarde.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
    // Un 502/503/504 sin código viene del hosting o de un mantenimiento, no del lector:
    // nadie avisó al administrador, así que el aviso no lo promete.
    servidor_ocupado: {
        tone: 'error',
        title: 'El servidor no responde ahora',
        message: 'Puede estar ocupado o en mantenimiento. Intenta de nuevo en unos minutos.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
    sin_conexion: {
        tone: 'error',
        title: 'No hay conexión',
        message: 'Revisa tu internet y vuelve a intentarlo.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
    sesion_vencida: {
        tone: 'info',
        title: 'Tu sesión terminó',
        message: 'Vuelve a entrar para seguir leyendo donde te quedaste.',
        action: 'reload',
        actionLabel: 'Volver a entrar',
    },
    error: {
        tone: 'error',
        title: 'No pudimos mostrar esta página',
        message: 'Intenta de nuevo en unos segundos.',
        action: 'retry',
        actionLabel: 'Intentar de nuevo',
    },
};

const CODE_BY_STATUS = {
    401: 'sesion_vencida',
    419: 'sesion_vencida',
    404: 'no_disponible',
    409: 'otro_dispositivo',
    423: 'lectura_pausada',
    429: 'muy_rapido',
    502: 'servidor_ocupado',
    503: 'servidor_ocupado',
    504: 'servidor_ocupado',
};

/** "membresia" no es un aviso: el lector muestra la cortina de la membresía. */
export function codeFor(status, serverCode) {
    if (serverCode === 'membresia' || (typeof serverCode === 'string' && READER_NOTICES[serverCode])) {
        return serverCode;
    }
    return CODE_BY_STATUS[status] || 'error';
}

/**
 * @param {string|null} detail Motivo técnico: el servidor solo lo manda a administradores.
 */
export function noticeFor(code, serverMessage = null, retryAtText = null, detail = null) {
    const base = READER_NOTICES[code] || READER_NOTICES.error;
    return {
        code,
        ...base,
        // El servidor ya habla en palabras llanas y suele saber más (la hora en que vuelve).
        message: typeof serverMessage === 'string' && serverMessage.trim() !== '' ? serverMessage : base.message,
        retryAtText: retryAtText || null,
        detail: typeof detail === 'string' && detail.trim() !== '' ? detail : null,
    };
}

export function createPageLoader({
    fetchImpl = (...args) => globalThis.fetch(...args),
    urlApi = globalThis.URL,
    cacheSize = 6,
} = {}) {
    const cache = new Map();

    function remember(url, src) {
        cache.delete(url);
        cache.set(url, src);
        while (cache.size > cacheSize) {
            const [oldestUrl, oldestSrc] = cache.entries().next().value;
            cache.delete(oldestUrl);
            urlApi.revokeObjectURL(oldestSrc);
        }
    }

    async function load(url, { signal } = {}) {
        if (cache.has(url)) {
            const src = cache.get(url);
            remember(url, src);
            return { ok: true, src, remaining: null, fromCache: true };
        }

        let response;
        try {
            response = await fetchImpl(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json, image/webp;q=0.9',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal,
            });
        } catch (error) {
            if (error?.name === 'AbortError') return { ok: false, aborted: true };
            return { ok: false, status: 0, ...noticeFor('sin_conexion') };
        }

        const type = response.headers.get('Content-Type') || '';

        if (response.ok && type.startsWith('image/')) {
            let blob;
            try {
                blob = await response.blob();
            } catch (error) {
                if (error?.name === 'AbortError') return { ok: false, aborted: true };
                return { ok: false, status: 0, ...noticeFor('sin_conexion') };
            }
            if (signal?.aborted) return { ok: false, aborted: true };

            const src = urlApi.createObjectURL(blob);
            remember(url, src);

            const header = response.headers.get('X-Paginas-Restantes');
            const remaining = header === null || header === '' || Number.isNaN(Number(header)) ? null : Number(header);

            return { ok: true, src, remaining, fromCache: false };
        }

        // Una respuesta 200 que no es imagen es la página de inicio de sesión.
        if (response.ok) {
            return { ok: false, status: response.status, ...noticeFor('sesion_vencida') };
        }

        let body = null;
        try {
            body = await response.json();
        } catch {
            body = null;
        }

        const code = codeFor(response.status, body?.code);
        return { ok: false, status: response.status, ...noticeFor(code, body?.message, body?.retry_at_text, body?.detail) };
    }

    /** Libera todas las páginas guardadas (al cerrar el lector o cambiar de libro). */
    function clear() {
        for (const src of cache.values()) {
            urlApi.revokeObjectURL(src);
        }
        cache.clear();
    }

    return {
        load,
        clear,
        get size() {
            return cache.size;
        },
    };
}
