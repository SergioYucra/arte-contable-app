/**
 * Piezas compartidas del panel de membresías: estilos de botón, lectura de errores y
 * la clave con la que el panel raíz comparte su contexto (provide/inject).
 */

export const PANEL_CONTEXT = Symbol('subscription-panel');

const focusRing =
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30';
const press =
    'transition-colors disabled:opacity-50 disabled:pointer-events-none';
const base = `inline-flex items-center justify-center gap-1.5 rounded-brand ${press} ${focusRing}`;

export const btn = {
    primary: `${base} px-4 py-2.5 sm:py-2 text-xs sm:text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs`,
    secondary: `${base} px-3.5 py-2.5 sm:py-2 text-xs font-semibold bg-background border border-border text-foreground hover:border-primary/40`,
    danger: `${base} px-4 py-2.5 sm:py-2 text-xs font-semibold bg-rose-600 text-white hover:bg-rose-700 shadow-xs`,
    dangerQuiet: `${base} px-3 py-2.5 sm:py-2 text-xs font-medium bg-background border border-border text-muted hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-500/10`,
    quiet: `${base} px-3 py-2 sm:py-1.5 text-xs font-semibold text-primary hover:bg-primary/10`,
    small: `${base} px-3 py-2 sm:py-1.5 text-xs font-semibold bg-background border border-border text-foreground hover:border-primary/40`,
};

export const field =
    'w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary';

export const label = 'block text-xs font-medium text-foreground';
export const hint = 'text-[11px] text-muted leading-relaxed';
export const errorText = 'text-xs text-rose-600 dark:text-rose-400';

/**
 * Convierte un error de axios en un mensaje para la persona y errores por campo.
 */
export function readError(error, fallback = 'No se pudo completar. Revisa tu conexión e inténtalo de nuevo.') {
    const data = error?.response?.data;
    const fields = {};

    if (data?.errors && typeof data.errors === 'object') {
        for (const [key, messages] of Object.entries(data.errors)) {
            fields[key] = Array.isArray(messages) ? messages[0] : String(messages);
        }
    }

    if (error?.response?.status === 429) {
        return { message: 'Demasiados intentos seguidos. Espera un minuto y vuelve a intentarlo.', fields };
    }

    if (error?.response?.status === 419) {
        return { message: 'Tu sesión expiró. Recarga la página para continuar.', fields };
    }

    // Lo que se quería abrir o cambiar ya no existe (p. ej. un envío que la persona retiró).
    if (error?.response?.status === 404) {
        return { message: 'Eso ya no está disponible. Actualiza la página para ver los datos al día.', fields };
    }

    // La petición salió pero no hubo respuesta del servidor: se cortó la conexión.
    if (error?.isAxiosError && !error.response) {
        return { message: 'No hay conexión. Revisa tu internet e inténtalo de nuevo.', fields };
    }

    const first = Object.values(fields)[0];
    const message = first || (typeof data?.message === 'string' && data.message) || fallback;

    return { message, fields };
}

/** 365 → "1 año", 180 → "6 meses", 45 → "45 días" (igual que App\Support\HumanDate::duration). */
export function durationLabel(days) {
    const d = Number(days) || 0;
    if (d > 0 && d % 365 === 0) return d === 365 ? '1 año' : `${d / 365} años`;
    if (d > 0 && d % 30 === 0) return d === 30 ? '1 mes' : `${d / 30} meses`;
    return d === 1 ? '1 día' : `${d} días`;
}

export function plural(count, one, many) {
    return `${count} ${count === 1 ? one : many}`;
}

/** Descarga texto como archivo .txt sin pasar por el servidor. */
export function downloadText(filename, text) {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
