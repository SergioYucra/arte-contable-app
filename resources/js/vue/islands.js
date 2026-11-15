/**
 * Montador seguro de "Islas Vue 3" sobre Blade (Cumplimiento Seguridad H6).
 *
 * - Usa exclusivamente el runtime precompilado de Vue 3 (sin compilador en navegador ni 'unsafe-eval').
 * - Monta cada contenedor `[data-vue-island="NombreComponente"]` leyendo sus propiedades desde
 *   el atributo JSON `data-props` y limpiándolo tras el montaje.
 * - Todas las islas comparten UNA instancia global de Pinia.
 * - Marca cada isla con `x-ignore` para aislarla de Alpine.js.
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';

export const ISLAND_SELECTOR = '[data-vue-island]';

export const pinia = createPinia();

// Registro perezoso (code-splitting por isla): solo se descarga el chunk de la isla presente en la página.
const componentLoaders = import.meta.glob('../components/**/*.vue');

const componentRegistry = new Map();

function toKebabCase(str) {
    return str
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2')
        .toLowerCase();
}

for (const [path, loader] of Object.entries(componentLoaders)) {
    const pascalName = path.split('/').pop().replace(/\.vue$/, '');
    componentRegistry.set(pascalName, loader);
    componentRegistry.set(toKebabCase(pascalName), loader);
}

function parseIslandProps(el) {
    const rawProps = el.dataset.props;
    if (!rawProps) {
        return {};
    }

    try {
        const parsed = JSON.parse(rawProps);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        console.error('Error al interpretar las propiedades iniciales del componente interactivo.');
        return {};
    }
}

export function mountVueIslands(root = document) {
    const islands = root.querySelectorAll(ISLAND_SELECTOR);

    islands.forEach((el) => {
        // Evita montar dos veces o montar islas anidadas dentro de otra isla.
        if (el.__vue_app__ || el.parentElement?.closest(ISLAND_SELECTOR)) {
            return;
        }

        const componentName = (el.dataset.vueIsland || '').trim();
        const loadComponent = componentRegistry.get(componentName);

        if (!loadComponent) {
            return;
        }

        const props = parseIslandProps(el);

        // Marcar x-ignore de forma síncrona antes de Alpine.start(), aunque el chunk cargue asíncrono.
        el.setAttribute('x-ignore', '');
        el.removeAttribute('data-props');
        el.__vue_app__ = true;

        loadComponent()
            .then((module) => {
                const rootComponent = module?.default;
                if (!rootComponent) {
                    return;
                }
                const app = createApp(rootComponent, props);
                app.use(pinia);
                app.mount(el);
            })
            .catch((error) => {
                el.__vue_app__ = false;
                console.error(`Error al cargar la isla "${componentName}":`, error);
            });
    });
}
