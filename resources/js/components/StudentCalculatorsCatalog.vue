<script setup>
import { Calculator } from 'lucide-vue-next';
import UtilityModuleCatalog from './utilities/UtilityModuleCatalog.vue';
import { lazyItem } from './utilities/lazyItem';

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
 * CalculatorController. Cada una se descarga recién al abrir su tarjeta (lazyItem muestra
 * "Cargando…" y un aviso si falla la descarga).
 */
const calculatorComponents = {
    ActualizacionCuentasNc3: lazyItem(() => import('./calculators/actualizacion/ActualizacionCalculadora.vue')),
    DepreciacionAmortizaciones: lazyItem(() => import('./calculators/dep-amort/DepAmortCalculadora.vue')),
    ReexpresionMonedaExtranjera: lazyItem(() => import('./calculators/reexpresion/ReexpresionCalculadora.vue')),
    RcIvaCalculator: lazyItem(() => import('./calculators/rc-iva/RcIvaCalculator.vue')),
    BonoAntiguedadCalculator: lazyItem(() => import('./calculators/bono-antiguedad/BonoAntiguedadCalculator.vue')),
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
