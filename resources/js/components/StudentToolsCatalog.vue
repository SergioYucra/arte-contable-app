<script setup>
import { Wrench } from 'lucide-vue-next';
import UtilityModuleCatalog from './utilities/UtilityModuleCatalog.vue';
import FacturadorTool from './tools/factura/FacturadorTool.vue';

defineProps({
    initialItems: {
        type: Array,
        default: () => [],
    },
});

/**
 * Registro de componentes de herramientas.
 * Al implementar cada herramienta, importa su componente y regístralo aquí
 * usando su `component` o `slug` como clave.
 */
const toolComponents = {
    CrearFacturaTool: FacturadorTool,
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
