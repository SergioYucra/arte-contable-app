<script setup>
import { defineAsyncComponent } from 'vue';
import { Calculator } from 'lucide-vue-next';
import UtilityModuleCatalog from './utilities/UtilityModuleCatalog.vue';
import ActualizacionCalculadora from './calculators/actualizacion/ActualizacionCalculadora.vue';
import DepAmortCalculadora from './calculators/dep-amort/DepAmortCalculadora.vue';
import ReexpresionCalculadora from './calculators/reexpresion/ReexpresionCalculadora.vue';

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
    ActualizacionCuentasNc3: ActualizacionCalculadora,
    DepreciacionAmortizaciones: DepAmortCalculadora,
    ReexpresionMonedaExtranjera: ReexpresionCalculadora,
    RcIvaCalculator: defineAsyncComponent(() => import('./calculators/RcIvaCalculator.vue')),
    BonoAntiguedadCalculator: defineAsyncComponent(() => import('./calculators/BonoAntiguedadCalculator.vue')),
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
