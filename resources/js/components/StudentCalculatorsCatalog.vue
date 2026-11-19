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
 * Al implementar cada calculadora, créala en su propia carpeta de calculators/ (las piezas
 * comunes están en calculators/shared/) y regístrala aquí con la clave `component` de
 * CalculatorController. Cada una se descarga recién al abrir su tarjeta.
 */
const calculatorComponents = {
    ActualizacionCuentasNc3: defineAsyncComponent(() => import('./calculators/actualizacion/ActualizacionCalculadora.vue')),
    DepreciacionAmortizaciones: defineAsyncComponent(() => import('./calculators/dep-amort/DepAmortCalculadora.vue')),
    ReexpresionMonedaExtranjera: defineAsyncComponent(() => import('./calculators/reexpresion/ReexpresionCalculadora.vue')),
    RcIvaCalculator: defineAsyncComponent(() => import('./calculators/rc-iva/RcIvaCalculator.vue')),
    BonoAntiguedadCalculator: defineAsyncComponent(() => import('./calculators/bono-antiguedad/BonoAntiguedadCalculator.vue')),
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
