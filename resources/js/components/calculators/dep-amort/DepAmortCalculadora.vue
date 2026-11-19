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
import { ACTIVOS_FIJOS, ACTIVOS_INTANGIBLES } from '../shared/catalogos.js';
import {
    calcularAmortizacionIntangible,
    calcularDepreciacionFijo,
    formatBs,
    formatearAsientoTSV,
} from './useDepAmort.js';

const TABS = [
    { id: 'fijo', label: 'Depreciación de Activos Fijos' },
    { id: 'intangible', label: 'Amortización de Activos Intangibles' },
];

const activeTab = ref('fijo');
const copied = ref(false);
let copyTimer = null;

function createEmptyForm() {
    return {
        nombre: '',
        valorLibros: '',
        meses: '',
        fechaCalculo: '',
    };
}

const forms = reactive({
    fijo: createEmptyForm(),
    intangible: createEmptyForm(),
});

const currentForm = computed(() => forms[activeTab.value]);
const currentCatalog = computed(() =>
    activeTab.value === 'fijo' ? ACTIVOS_FIJOS : ACTIVOS_INTANGIBLES
);

const selectedActivoRef = computed(() => {
    return currentCatalog.value.find((a) => a.nombre === currentForm.value.nombre) || null;
});

const resultado = computed(() => {
    const f = currentForm.value;
    if (activeTab.value === 'fijo') {
        return calcularDepreciacionFijo({
            nombre: f.nombre,
            valorLibros: f.valorLibros,
            meses: f.meses,
            fechaCalculo: f.fechaCalculo,
        });
    }
    return calcularAmortizacionIntangible({
        nombre: f.nombre,
        valorLibros: f.valorLibros,
        meses: f.meses,
        fechaCalculo: f.fechaCalculo,
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
</script>

<template>
    <div class="space-y-6 dep-amort-print-root">
        <!-- Pestañas y acción limpiar -->
        <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
            <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Tipo de cálculo">
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
                        Método lineal: completa los campos para calcular el período y generar el asiento.
                    </p>
                </div>
                <span class="rounded-full bg-primary/10 border border-primary/20 px-2.5 py-0.5 text-[11px] font-bold text-primary">
                    Método Lineal
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
                <!-- Selección de activo y valor en libros -->
                <div class="lg:col-span-7 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            {{ activeTab === 'fijo' ? 'Activo fijo' : 'Activo intangible' }}
                        </label>
                        <select
                            v-model="currentForm.nombre"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        >
                            <option value="" disabled>
                                {{ activeTab === 'fijo' ? 'Selecciona un activo fijo...' : 'Selecciona un activo intangible...' }}
                            </option>
                            <option
                                v-for="item in currentCatalog"
                                :key="item.nombre"
                                :value="item.nombre"
                            >
                                {{ item.nombre }}
                            </option>
                        </select>
                        <p v-if="selectedActivoRef" class="text-[11px] text-muted">
                            {{ activeTab === 'fijo' ? 'Vida útil:' : 'Años de distribución:' }}
                            <span class="font-semibold text-foreground">
                                {{ selectedActivoRef.vidaUtil === null ? 'Indefinido' : `${selectedActivoRef.vidaUtil} años` }}
                            </span>
                            · Coeficiente:
                            <span class="font-semibold text-foreground">
                                {{ (selectedActivoRef.coeficiente * 100).toFixed(1).replace('.0', '') }}%
                            </span>
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            Valor en libros (Bs)
                        </label>
                        <input
                            v-model="currentForm.valorLibros"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0,00"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                    </div>
                </div>

                <!-- Meses de uso y fecha de cálculo -->
                <div class="lg:col-span-5 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            {{ activeTab === 'fijo' ? 'Meses de uso (1–12)' : 'Meses utilizados (1–12)' }}
                        </label>
                        <input
                            v-model.number="currentForm.meses"
                            type="number"
                            min="1"
                            max="12"
                            step="1"
                            placeholder="12"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            Fecha de cálculo
                        </label>
                        <input
                            v-model="currentForm.fechaCalculo"
                            type="date"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        />
                    </div>
                </div>
            </div>
        </section>

        <!-- Errores de validación -->
        <div
            v-if="resultado.errors.length > 0 && currentForm.nombre !== ''"
            class="no-print rounded-brand border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-700 dark:text-rose-300 space-y-1"
        >
            <p v-for="err in resultado.errors" :key="err" class="flex items-center gap-2 font-medium">
                <AlertTriangle class="h-4 w-4 shrink-0" />
                <span>{{ err }}</span>
            </p>
        </div>

        <!-- Caso especial Terrenos -->
        <div
            v-if="resultado.esNoDepreciable"
            class="rounded-brand border border-sky-500/30 bg-sky-500/10 p-4 flex items-start gap-3 text-xs sm:text-sm text-sky-900 dark:text-sky-200"
        >
            <Info class="h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400 mt-0.5" />
            <div class="space-y-1">
                <p class="font-bold">{{ resultado.aviso }}</p>
                <p class="text-xs opacity-90">
                    Depreciación del período: <strong class="tabular-nums">0,00 Bs</strong> · No se genera asiento contable.
                </p>
            </div>
        </div>

        <!-- Área imprimible: Cálculo paso a paso + Registro Contable -->
        <div v-else-if="resultado.calculable" class="print-section space-y-5">
            <!-- Encabezado solo visible al imprimir -->
            <div class="hidden print:block border-b border-border pb-2">
                <h2 class="text-lg font-bold">
                    {{ activeTab === 'fijo' ? 'Depreciación de Activos Fijos' : 'Amortización de Activos Intangibles' }}
                </h2>
                <p class="text-xs text-muted">
                    Fecha de cálculo: {{ formatFechaDisplay(currentForm.fechaCalculo) }}
                </p>
            </div>

            <!-- Cálculo paso a paso -->
            <div class="rounded-brand border border-border bg-background/40 overflow-hidden">
                <div class="px-4 py-3 border-b border-border bg-surface flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-bold text-foreground">
                        Cálculo paso a paso
                    </h2>
                    <span class="text-[11px] font-semibold text-muted">
                        {{ activeTab === 'fijo' ? 'Vida útil:' : 'Distribución:' }}
                        {{ resultado.vidaUtil }} años · Coeficiente: {{ (resultado.coeficiente * 100).toFixed(1).replace('.0', '') }}%
                    </span>
                </div>

                <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-brand border border-border bg-surface p-4 space-y-1.5">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-muted">
                            1. {{ activeTab === 'fijo' ? 'Depreciación anual' : 'Amortización anual' }}
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            {{ formatBs(currentForm.valorLibros) }} ÷ {{ resultado.vidaUtil }} años
                        </p>
                        <p class="text-lg sm:text-xl font-bold tabular-nums text-foreground">
                            {{ formatBs(resultado.anualRaw) }} Bs
                        </p>
                    </div>

                    <div class="rounded-brand border border-primary/25 bg-surface p-4 space-y-1.5">
                        <span class="block text-[11px] font-bold uppercase tracking-wider text-primary">
                            2. {{ activeTab === 'fijo' ? 'Depreciación por meses' : 'Amortización por meses' }}
                        </span>
                        <p class="text-xs text-muted tabular-nums">
                            ({{ formatBs(resultado.anualRaw) }} ÷ 12) × {{ currentForm.meses }} {{ Number(currentForm.meses) === 1 ? 'mes' : 'meses' }}
                        </p>
                        <p class="text-lg sm:text-xl font-black tabular-nums text-primary">
                            {{ formatBs(resultado.periodo) }} Bs
                        </p>
                    </div>
                </div>
            </div>

            <!-- Registro Contable -->
            <div v-if="resultado.asiento" class="rounded-brand border border-border bg-background/40 overflow-hidden">
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

        <!-- Estado vacío tras limpiar -->
        <div
            v-else-if="currentForm.nombre === ''"
            class="no-print rounded-brand border border-dashed border-border bg-background/40 p-6 text-center space-y-1.5"
        >
            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <Calculator class="h-5 w-5" />
            </div>
            <p class="text-xs sm:text-sm font-semibold text-foreground">
                Selecciona un activo e ingresa su valor en libros
            </p>
            <p class="text-xs text-muted">
                El cálculo paso a paso y el registro contable se generarán automáticamente.
            </p>
        </div>
    </div>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .dep-amort-print-root,
    .dep-amort-print-root .print-section,
    .dep-amort-print-root .print-section * {
        visibility: visible;
    }
    .dep-amort-print-root .no-print {
        display: none !important;
    }
    .dep-amort-print-root .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
}
</style>
