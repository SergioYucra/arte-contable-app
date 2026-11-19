<script setup>
import { formatBs, longDate } from '../../calculators/shared/formatters';

/**
 * El balance de apertura como documento, con el mismo diseño que el de boliviaimpuestos.com
 * (encabezado centrado, filas de grupo en azul marino y de subgrupo en gris azulado, letra Niramit).
 * Siempre en colores claros, también en modo oscuro, para que la vista previa sea igual a la impresión.
 */
defineProps({
    empresa: { type: Object, required: true }, // { razonSocial, nit, ciudad, fecha, tipo }
    balance: { type: Object, required: true }, // resultado de armarBalance()
});
</script>

<template>
    <div class="balance-doc balance-color font-['Niramit',ui-sans-serif,system-ui,sans-serif] text-[13px] [font-variant-ligatures:none] text-[#16305A]">
        <header class="mb-2.5 border-b-2 border-[#2d3663] pb-3 text-center">
            <p class="text-[15px] font-bold uppercase tracking-[0.3px] text-[#2d3663]">{{ empresa.razonSocial || '—' }}</p>
            <p class="text-[11.5px] text-[#5B6B82]">NIT: {{ empresa.nit || '—' }} · {{ empresa.ciudad }}</p>
            <p class="mt-1.5 font-bold tracking-[0.5px] text-[#006ba6]">BALANCE DE APERTURA</p>
            <p class="text-[11.5px] text-[#5B6B82]">Al {{ longDate(empresa.fecha) }} — (expresado en bolivianos)</p>
        </header>

        <table class="w-full border-collapse">
            <tbody>
                <tr
                    v-for="(fila, i) in balance.filas"
                    :key="i"
                    :class="{
                        'bg-[#2d3663] font-bold tracking-[0.4px] text-white': fila.nivel === 'grupo',
                        'bg-[#E7EAF3] font-semibold text-[#2d3663]': fila.nivel === 'subgrupo',
                    }"
                >
                    <td class="border-b border-[#EEF2F7] px-2 py-[5px]" :class="fila.nivel === 'cuenta' && 'ps-4'">{{ fila.texto }}</td>
                    <td class="border-b border-[#EEF2F7] px-2 py-[5px] text-end tabular-nums">{{ formatBs(fila.monto) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
