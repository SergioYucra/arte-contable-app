<script setup>
import { formatBs, longDate } from '../../calculators/shared/formatters';

/**
 * El balance de apertura como documento: es lo que se ve en la vista previa y lo que se imprime.
 * Siempre en papel blanco (también en modo oscuro), para que la vista previa sea igual a la impresión.
 */
defineProps({
    empresa: { type: Object, required: true }, // { razonSocial, nit, ciudad, fecha, tipo }
    balance: { type: Object, required: true }, // resultado de armarBalance()
});
</script>

<template>
    <article class="balance-doc rounded-sm bg-white px-4 py-8 text-slate-900 sm:px-10 sm:py-10">
        <header class="text-center">
            <p class="font-serif text-lg uppercase leading-snug tracking-wide sm:text-xl">{{ empresa.razonSocial || '—' }}</p>
            <p class="mt-1 text-xs text-slate-600">NIT: {{ empresa.nit || '—' }} · {{ empresa.ciudad }}</p>
            <h2 class="mt-6 font-serif text-xl font-semibold tracking-[0.12em] sm:text-2xl">BALANCE DE APERTURA</h2>
            <p class="mt-1 text-sm">Al {{ longDate(empresa.fecha) }}</p>
            <p class="text-xs text-slate-500">(Expresado en bolivianos)</p>
        </header>

        <table class="mt-8 w-full text-[13px] tabular-nums sm:text-sm">
            <tbody>
                <tr v-for="(fila, i) in balance.filas" :key="i">
                    <td
                        class="py-1.5 pe-4"
                        :class="{
                            'pt-4 font-bold uppercase tracking-wide': fila.nivel === 'grupo',
                            'ps-3 font-semibold sm:ps-4': fila.nivel === 'subgrupo',
                            'ps-6 sm:ps-8': fila.nivel === 'cuenta',
                        }"
                    >
                        {{ fila.texto }}
                    </td>
                    <td class="w-24 py-1.5 text-end sm:w-32" :class="fila.nivel === 'cuenta' ? '' : 'font-semibold'">
                        <span
                            :class="{
                                'border-b-[3px] border-double border-slate-900 pb-0.5 font-bold': fila.nivel === 'grupo',
                                'text-rose-700': fila.monto < 0,
                            }"
                        >{{ formatBs(fila.monto) }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <footer class="mt-20 grid grid-cols-2 gap-10 text-center text-xs text-slate-600">
            <div>
                <div class="border-t border-slate-400 pt-1.5">{{ empresa.tipo === 'srl' ? 'Representante legal' : 'Propietario(a)' }}</div>
            </div>
            <div>
                <div class="border-t border-slate-400 pt-1.5">Contador(a)</div>
            </div>
        </footer>
    </article>
</template>
