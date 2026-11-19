import { defineAsyncComponent, h } from 'vue';
import ItemLoadState from './ItemLoadState.vue';

/**
 * Registra una calculadora o herramienta para que se descargue recién al abrir su tarjeta,
 * con "Cargando…" mientras llega y un aviso para recargar si la descarga falla.
 *
 * Uso en un catálogo: { MiCalculadora: lazyItem(() => import('./calculators/mi/MiCalculadora.vue')) }
 */
export function lazyItem(loader) {
    return defineAsyncComponent({
        loader,
        loadingComponent: { render: () => h(ItemLoadState) },
        errorComponent: { render: () => h(ItemLoadState, { failed: true }) },
        delay: 200,
        timeout: 30000,
    });
}
