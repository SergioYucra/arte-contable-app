<script setup>
import { Wrench } from 'lucide-vue-next';
import UtilityModuleCatalog from './utilities/UtilityModuleCatalog.vue';
import { lazyItem } from './utilities/lazyItem';

defineProps({
    initialItems: {
        type: Array,
        default: () => [],
    },
});

/**
 * Registro de componentes de herramientas.
 * Al implementar cada herramienta, créala en su propia carpeta de tools/ y regístrala aquí con
 * la clave `component` de ToolController. Cada una se descarga recién al abrir su tarjeta (lazyItem muestra
 * "Cargando…" y un aviso si falla la descarga).
 */
const toolComponents = {
    CrearBalanceAperturaTool: lazyItem(() => import('./tools/balance-apertura/BalanceAperturaTool.vue')),
};
</script>

<template>
    <UtilityModuleCatalog
        module-title="Herramientas"
        module-description="Generadores y asistentes prácticos para documentos y registros contables."
        search-placeholder="Buscar herramienta por nombre..."
        item-action-label="Abrir"
        :initial-items="initialItems"
        :component-map="toolComponents"
        :module-icon="Wrench"
    />
</template>
