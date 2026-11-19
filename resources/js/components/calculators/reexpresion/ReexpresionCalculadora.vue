<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import {
    AlertTriangle,
    Calculator,
    Check,
    CheckCircle2,
    Copy,
    Info,
    Printer,
    RotateCcw,
} from 'lucide-vue-next';
import {
    calcularReexpresion,
    CUENTA_REEXPRESION,
    formatBs,
    formatearAsientoTSV,
} from './useReexpresion.js';

const TABS = [
    { id: 1, label: 'Método 1 (dos tipos de cambio)' },
    { id: 2, label: 'Método 2 (diferencia directa)' },
];

const activeTab = ref(1);
const copied = ref(false);
let copyTimer = null;

function createEmptyForm() {
    return {
        montoME: '',
        tcFinal: '',
        tcInicial: '',
        fechaAjuste: '',
    };
}

const forms = reactive({
    1: createEmptyForm(),
    2: createEmptyForm(),
});

const currentForm = computed(() => forms[activeTab.value]);

const resultado = computed(() => {
    const f = currentForm.value;
    return calcularReexpresion({
        metodo: activeTab.value,
        montoME: f.montoME,
        tcFinal: f.tcFinal,
        tcInicial: f.tcInicial,
        fechaAjuste: f.fechaAjuste,
    });
});

onBeforeUnmount(() => {
    if (copyTimer) clearTimeout(copyTimer);
});

async function copiarAsiento() {
    if (!resultado.value.asiento) return;
    const text = formatearAsientoTSV(resultado.value.asiento);
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        if (copyTimer) clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Fallback silencioso si el navegador restringe clipboard
    }
}

function imprimir() {
    window.print();
}

function limpiar() {
    Object.assign(forms[activeTab.value], createEmptyForm());
}

function formatFechaDisplay(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '-';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

function formatTc(val) {
    const num = Number(val);
    if (!Number.isFinite(num)) return '0,00';
    return new Intl.NumberFormat('es-BO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(num);
}
</script>

<template>
    <div class="space-y-6 reexpresion-print-root">
        <!-- Pestañas y botón limpiar -->
        <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
            <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Método de reexpresión">
                <button
                    v-for="tab in TABS"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.id"
                    class="px-3.5 py-2 rounded-lg text-xs sm:text-sm font-semibold transition-all"
                    :class="
                        activeTab === tab.id
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'bg-background text-muted hover:text-foreground border border-border'
                    "
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                </button>
            </div>

            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-muted hover:text-foreground hover:border-primary/40 transition-colors"
                @click="limpiar"
            >
                <RotateCcw class="h-3.5 w-3.5" />
                <span>Limpiar</span>
            </button>
        </div>

        <!-- Recuadro resaltado de ingreso de datos -->
        <section
            aria-label="Ingreso de datos"
            class="no-print rounded-2xl border-2 border-primary/25 bg-background/90 p-4 sm:p-6 shadow-sm ring-1 ring-primary/10"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-4 border-b border-border/80">
                <div>
                    <h2 class="text-sm sm:text-base font-bold tracking-tight text-foreground">
                        Ingreso de datos
                    </h2>
                    <p class="text-xs text-muted">
                        Ambos métodos dan el mismo resultado con los mismos datos.
                    </p>
                </div>
                <span class="rounded-full bg-primary/10 border border-primary/20 px-2.5 py-0.5 text-[11px] font-bold text-primary">
                    Cuenta: {{ CUENTA_REEXPRESION }}
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
                <!-- Monto en dólares y fecha de ajuste -->
                <div class="lg:col-span-6 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div class="space-y-1.5">
                        <label
                            class="block text-xs font-bold text-foreground"
                            title="Colocar importe de los dólares"
                        >
                            Monto en M/E ($)
                        </label>
                        <input
                            v-model="currentForm.montoME"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0,00"
                            title="Colocar importe de los dólares"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                        <p class="text-[11px] text-muted">Colocar importe de los dólares</p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            Fecha de ajuste
                        </label>
                        <input
                            v-model="currentForm.fechaAjuste"
                            type="date"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                    </div>
                </div>

                <!-- Tipos de cambio -->
                <div class="lg:col-span-6 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div class="space-y-1.5">
                        <label
                            class="block text-xs font-bold text-foreground"
                            title="T/C a la fecha de ajuste"
                        >
                            {{ activeTab === 1 ? 'T/C al ajuste (cierre)' : 'T/C final' }}
                        </label>
                        <input
                            v-model="currentForm.tcFinal"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="0,0000"
                            title="T/C a la fecha de ajuste"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                        <p class="text-[11px] text-muted">T/C a la fecha de ajuste</p>
                    </div>

                    <div class="space-y-1.5">
                        <label
                            class="block text-xs font-bold text-foreground"
                            title="T/C a la fecha de adquisición o inicial"
                        >
                            {{ activeTab === 1 ? 'T/C de adquisición (inicial)' : 'T/C inicial' }}
                        </label>
                        <input
                            v-model="currentForm.tcInicial"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="0,0000"
                            title="T/C a la fecha de adquisición o inicial"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                        <p class="text-[11px] text-muted">T/C a la fecha de adquisición o inicial</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Errores de validación -->
        <div
            v-if="resultado.errors.length > 0 && (currentForm.montoME !== '' || currentForm.tcFinal !== '' || currentForm.tcInicial !== '')"
            class="no-print rounded-brand border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-700 dark:text-rose-300 space-y-1"
        >
            <p v-for="err in resultado.errors" :key="err" class="flex items-center gap-2 font-medium">
                <AlertTriangle class="h-4 w-4 shrink-0" />
                <span>{{ err }}</span>
            </p>
        </div>

        <!-- Área imprimible: Cálculo paso a paso + Registro Contable -->
        <div v-if="resultado.calculable" class="print-section space-y-5">
            <!-- Encabezado solo visible al imprimir -->
            <div class="hidden print:block border-b border-border pb-2">
                <h2 class="text-lg font-bold">
                    Reexpresión de Moneda Extranjera — Método {{ activeTab }}
                </h2>
                <p class="text-xs text-muted">
                    Cuenta: {{ CUENTA_REEXPRESION }} · Fecha: {{ formatFechaDisplay(currentForm.fechaAjuste) }}
                </p>
            </div>

            <!-- Cálculo paso a paso -->
            <div class="rounded-brand border border-border bg-background/40 overflow-hidden">
                <div class="px-4 py-3 border-b border-border bg-surface flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-foreground">
                        Cálculo paso a paso — Método {{ activeTab }}
                    </h2>
                    <span
                        v-if="!resultado.sinDiferencia && resultado.resultado"
                        class="rounded-full px-2.5 py-0.5 text-[11px] font-bold border"
                        :class="
                            resultado.resultado === 'INGRESO'
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/25'
                                : 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/25'
                        "
                    >
                        {{ resultado.resultado }}
                    </span>
                </div>

                <!-- Método 1: 2 valuaciones + diferencia -->
                <div v-if="activeTab === 1" class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-brand border border-border bg-surface p-4 space-y-1">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-muted">
                            1. Valor al ajuste
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            $ {{ formatBs(currentForm.montoME) }} × {{ formatTc(currentForm.tcFinal) }}
                        </p>
                        <p class="text-lg font-bold tabular-nums text-foreground">
                            {{ formatBs(resultado.valorAjuste) }} Bs
                        </p>
                    </div>

                    <div class="rounded-brand border border-border bg-surface p-4 space-y-1">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-muted">
                            2. Valor de adquisición
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            $ {{ formatBs(currentForm.montoME) }} × {{ formatTc(currentForm.tcInicial) }}
                        </p>
                        <p class="text-lg font-bold tabular-nums text-foreground">
                            {{ formatBs(resultado.valorAdquisicion) }} Bs
                        </p>
                    </div>

                    <div class="rounded-brand border border-primary/25 bg-surface p-4 space-y-1">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-primary">
                            3. Diferencia de cambio
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            {{ formatBs(resultado.valorAjuste) }} − {{ formatBs(resultado.valorAdquisicion) }}
                        </p>
                        <p class="text-lg font-black tabular-nums text-primary">
                            {{ formatBs(resultado.diferencia) }} Bs
                        </p>
                    </div>
                </div>

                <!-- Método 2: diferencia directa -->
                <div v-else class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-brand border border-border bg-surface p-4 space-y-1">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-muted">
                            1. Variación de T/C
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            {{ formatTc(currentForm.tcFinal) }} − {{ formatTc(currentForm.tcInicial) }}
                        </p>
                        <p class="text-lg font-bold tabular-nums text-foreground">
                            {{ formatTc(Number(currentForm.tcFinal) - Number(currentForm.tcInicial)) }}
                        </p>
                    </div>

                    <div class="rounded-brand border border-primary/25 bg-surface p-4 space-y-1">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-primary">
                            2. Diferencia de cambio
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            $ {{ formatBs(currentForm.montoME) }} × ({{ formatTc(currentForm.tcFinal) }} − {{ formatTc(currentForm.tcInicial) }})
                        </p>
                        <p class="text-lg font-black tabular-nums text-primary">
                            {{ formatBs(resultado.diferencia) }} Bs
                        </p>
                    </div>
                </div>
            </div>

            <!-- Caso diferencia = 0 -->
            <div
                v-if="resultado.sinDiferencia"
                class="rounded-brand border border-sky-500/30 bg-sky-500/10 p-4 flex items-start gap-3 text-xs sm:text-sm text-sky-900 dark:text-sky-200"
            >
                <Info class="h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400 mt-0.5" />
                <div>
                    <p class="font-bold">Sin diferencia de cambio</p>
                    <p class="text-xs opacity-90">
                        Los tipos de cambio son iguales y la diferencia es 0,00 Bs. No corresponde registrar asiento de ajuste.
                    </p>
                </div>
            </div>

            <!-- Registro Contable -->
            <div v-else-if="resultado.asiento" class="rounded-brand border border-border bg-background/40 overflow-hidden">
                <div class="px-4 py-3 border-b border-border bg-surface flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <h2 class="text-sm font-bold text-foreground">
                            Registro Contable
                        </h2>
                        <span
                            v-if="resultado.asiento.cuadrado"
                            class="inline-flex items-center gap-1 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400"
                        >
                            <CheckCircle2 class="h-3 w-3" />
                            <span>Asiento cuadrado ✓</span>
                        </span>
                    </div>

                    <div class="no-print flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:border-primary/40 hover:text-primary transition-colors"
                            @click="copiarAsiento"
                        >
                            <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-500" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            <span>{{ copied ? 'Copiado' : 'Copiar asiento' }}</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:border-primary/40 hover:text-primary transition-colors"
                            @click="imprimir"
                        >
                            <Printer class="h-3.5 w-3.5" />
                            <span>Imprimir</span>
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-border bg-surface/60 text-[11px] font-bold uppercase tracking-wider text-muted">
                                <th class="px-4 py-2.5 text-center border-r border-border w-28 sm:w-32">Fecha</th>
                                <th class="px-4 py-2.5 text-center border-r border-border">Detalle</th>
                                <th class="px-4 py-2.5 text-right border-r border-border w-32 sm:w-36">Debe</th>
                                <th class="px-4 py-2.5 text-right w-32 sm:w-36">Haber</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td
                                    :rowspan="resultado.asiento.lineas.length + 1"
                                    class="px-4 py-2.5 align-top text-right tabular-nums font-medium text-foreground border-r border-border"
                                >
                                    {{ formatFechaDisplay(resultado.asiento.fecha) }}
                                </td>
                                <td class="px-4 pt-2.5 pb-1 text-center font-bold text-foreground border-r border-border">
                                    {{ resultado.asiento.titulo }}
                                </td>
                                <td class="px-4 pt-2.5 pb-1 border-r border-border"></td>
                                <td class="px-4 pt-2.5 pb-1"></td>
                            </tr>
                            <tr
                                v-for="(linea, idx) in resultado.asiento.lineas"
                                :key="idx"
                                class="hover:bg-surface/50"
                            >
                                <td
                                    class="px-4 py-2 font-medium text-foreground border-r border-border"
                                    :class="linea.haber !== null ? 'pl-8 sm:pl-10' : ''"
                                >
                                    <span class="underline decoration-foreground/40 underline-offset-4">
                                        {{ linea.detalle }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums font-semibold text-foreground border-r border-border">
                                    {{ linea.debe !== null ? formatBs(linea.debe) : '' }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums font-semibold text-foreground">
                                    {{ linea.haber !== null ? formatBs(linea.haber) : '' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-border bg-surface/80 font-bold text-foreground">
                                <td colspan="2" class="px-4 py-2.5 text-xs border-r border-border">
                                    <span class="text-muted">GLOSA.</span> {{ resultado.asiento.glosa }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums border-r border-border">
                                    {{ formatBs(resultado.asiento.totalDebe) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ formatBs(resultado.asiento.totalHaber) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Estado vacío tras limpiar / inicio -->
        <div
            v-else-if="currentForm.montoME === '' && currentForm.tcFinal === '' && currentForm.tcInicial === ''"
            class="no-print rounded-brand border border-dashed border-border bg-background/40 p-6 text-center space-y-1.5"
        >
            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <Calculator class="h-5 w-5" />
            </div>
            <p class="text-xs sm:text-sm font-semibold text-foreground">
                Ingresa el monto en dólares y los tipos de cambio
            </p>
            <p class="text-xs text-muted">
                La diferencia de cambio y el registro contable se calcularán automáticamente.
            </p>
        </div>
    </div>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .reexpresion-print-root,
    .reexpresion-print-root .print-section,
    .reexpresion-print-root .print-section * {
        visibility: visible;
    }
    .reexpresion-print-root .no-print {
        display: none !important;
    }
    .reexpresion-print-root .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
}
</style>
