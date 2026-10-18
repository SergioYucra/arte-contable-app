/**
 * Montador de "Islas Vue" sobre Blade.
 *
 * - Monta una app Vue independiente en cada contenedor `#app` o `[data-vue-island]`.
 * - Todas las islas comparten UNA instancia de Pinia (estado global entre islas).
 * - Registra automáticamente todos los componentes de `resources/js/components/**`
 *   usando el nombre del archivo: `SystemStatusBadge.vue` => <system-status-badge>.
 * - Marca cada isla con `x-ignore` para que Alpine.js no la procese (evita conflictos
 *   entre la sintaxis `@click` / `:attr` de Alpine y la de Vue).
 *
 * SEGURIDAD: el contenido de una isla se compila como plantilla Vue. NO imprimir
 * datos controlados por usuarios con `{{ }}` de Blade dentro de una isla
 * (riesgo de inyección de plantillas). Pasarlos como props: :prop="@js($dato)".
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';

export const ISLAND_SELECTOR = '#app, [data-vue-island]';

export const pinia = createPinia();

// Descubrimiento automático de componentes (se resuelve en build, sin coste en runtime).
const componentModules = import.meta.glob('../components/**/*.vue', { eager: true });

function registerComponents(app) {
    for (const [path, module] of Object.entries(componentModules)) {
        const name = path.split('/').pop().replace(/\.vue$/, '');
        app.component(name, module.default);
    }
}

export function mountVueIslands(root = document) {
    const islands = root.querySelectorAll(ISLAND_SELECTOR);

    islands.forEach((el) => {
        // Evita montar dos veces o montar islas anidadas dentro de otra isla.
        if (el.__vue_app__ || el.parentElement?.closest(ISLAND_SELECTOR)) {
            return;
        }

        el.setAttribute('x-ignore', '');

        const app = createApp({});
        app.use(pinia);
        registerComponents(app);
        app.mount(el);
    });
}
