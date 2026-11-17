<script setup>
import { defineAsyncComponent } from 'vue';
import { Calculator } from 'lucide-vue-next';
import UtilityModuleCatalog from './utilities/UtilityModuleCatalog.vue';

defineProps({
    initialItems: {
        type: Array,
        default: () => [],
    },
});

/**
 * Registro de componentes de calculadoras.
 * Al implementar cada calculadora, importa su componente y regístralo aquí
 * usando su `component` o `slug` como clave.
 */
const calculatorComponents = {
    // Cada calculadora se descarga recién al abrir su tarjeta.
    RcIvaCalculator: defineAsyncComponent(() => import('./calculators/RcIvaCalculator.vue')),
    BonoAntiguedadCalculator: defineAsyncComponent(() => import('./calculators/BonoAntiguedadCalculator.vue')),
};
</script>

<template>
    <UtilityModuleCatalog
        module-title="Calculadoras"
        module-description="Cálculos contables, fiscales y laborales de ejecución inmediata."
        search-placeholder="Buscar calculadora por nombre..."
        item-action-label="Abrir"
        :initial-items="initialItems"
        :component-map="calculatorComponents"
        :module-icon="Calculator"
    />
</template>
