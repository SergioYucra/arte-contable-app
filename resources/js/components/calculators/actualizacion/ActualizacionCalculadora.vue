<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import {
    AlertTriangle,
    Calculator,
    Check,
    CheckCircle2,
    Copy,
    Loader2,
    Printer,
    RefreshCw,
    RotateCcw,
} from 'lucide-vue-next';
import {
    ACTIVOS_FIJOS,
    ACTIVOS_INTANGIBLES,
    CUENTAS_PATRIMONIALES,
} from './catalogos.js';
import { getUfv, UfvApiError } from './ufvService.js';
import {
    calcularActivoConAcumulada,
    calcularRubroPatrimonial,
    formatBs,
    formatearAsientoTSV,
} from './useActualizacion.js';

const TABS = [
    { id: 'fijo', label: 'Activo Fijo' },
    { id: 'intangible', label: 'Activo Intangible' },
    { id: 'patrimonial', label: 'Rubro Patrimonial' },
];

const activeTab = ref('fijo');
const copied = ref(false);
let copyTimer = null;

function createEmptyForm(tabId) {
    if (tabId === 'patrimonial') {
        return {
            cuenta: '',
            valorLibros: '',
            fechaInicial: '',
            fechaAjuste: '',
            ufvInicial: '',
            ufvFinal: '',
        };
    }

    return {
        nombre: '',
        valorLibros: '',
        acumulada: '',
        fechaInicial: '',
        fechaAjuste: '',
        ufvInicial: '',
        ufvFinal: '',
    };
}

const forms = reactive({
    fijo: createEmptyForm('fijo'),
    intangible: createEmptyForm('intangible'),
    patrimonial: createEmptyForm('patrimonial'),
});

const ufvState = reactive({
    fijo: { loadingIni: false, loadingFin: false, errorIni: '', errorFin: '' },
    intangible: { loadingIni: false, loadingFin: false, errorIni: '', errorFin: '' },
    patrimonial: { loadingIni: false, loadingFin: false, errorIni: '', errorFin: '' },
});

const debounceTimers = {};
const activeControllers = {};

const currentForm = computed(() => forms[activeTab.value]);
const currentUfvState = computed(() => ufvState[activeTab.value]);

const selectedActivoRef = computed(() => {
    if (activeTab.value === 'fijo') {
        return ACTIVOS_FIJOS.find((a) => a.nombre === forms.fijo.nombre) || null;
    }
    if (activeTab.value === 'intangible') {
        return ACTIVOS_INTANGIBLES.find((a) => a.nombre === forms.intangible.nombre) || null;
    }
    return null;
});

const selectedPatrimonialRef = computed(() => {
    if (activeTab.value !== 'patrimonial') return null;
    return CUENTAS_PATRIMONIALES.find((c) => c.cuenta === forms.patrimonial.cuenta) || null;
});

const hasAnyInput = computed(() => {
    const f = currentForm.value;
    const baseHas =
        f.valorLibros !== '' ||
        f.fechaInicial !== '' ||
        f.fechaAjuste !== '' ||
        f.ufvInicial !== '' ||
        f.ufvFinal !== '';

    if (activeTab.value === 'patrimonial') {
        return baseHas || f.cuenta !== '';
    }
    return baseHas || f.nombre !== '' || f.acumulada !== '';
});

const isReadyForCalculation = computed(() => {
    const f = currentForm.value;
    const hasAccount = activeTab.value === 'patrimonial' ? Boolean(f.cuenta) : Boolean(f.nombre);
    return hasAccount && f.valorLibros !== '' && f.ufvInicial !== '' && f.ufvFinal !== '';
});

const resultado = computed(() => {
    const f = currentForm.value;
    if (!isReadyForCalculation.value) {
        const errors = [];
        if (f.fechaInicial && f.fechaAjuste && f.fechaAjuste < f.fechaInicial) {
            errors.push('La fecha de ajuste debe ser mayor o igual a la fecha inicial.');
        }
        if (f.ufvInicial !== '' && Number(f.ufvInicial) <= 0) {
            errors.push('La UFV inicial debe ser mayor a 0.');
        }
        return {
            calculable: false,
            errors,
            warnings: [],
            filas: [],
            asiento: null,
        };
    }

    if (activeTab.value === 'fijo') {
        return calcularActivoConAcumulada({
            tipo: 'fijo',
            nombre: f.nombre,
            valorLibros: Number(f.valorLibros),
            acumulada: f.acumulada === '' ? 0 : Number(f.acumulada),
            ufvInicial: f.ufvInicial,
            ufvFinal: f.ufvFinal,
            fechaInicial: f.fechaInicial,
            fechaAjuste: f.fechaAjuste,
        });
    }
    if (activeTab.value === 'intangible') {
        return calcularActivoConAcumulada({
            tipo: 'intangible',
            nombre: f.nombre,
            valorLibros: Number(f.valorLibros),
            acumulada: f.acumulada === '' ? 0 : Number(f.acumulada),
            ufvInicial: f.ufvInicial,
            ufvFinal: f.ufvFinal,
            fechaInicial: f.fechaInicial,
            fechaAjuste: f.fechaAjuste,
        });
    }
    return calcularRubroPatrimonial({
        cuenta: f.cuenta,
        valorLibros: Number(f.valorLibros),
        ufvInicial: f.ufvInicial,
        ufvFinal: f.ufvFinal,
        fechaInicial: f.fechaInicial,
        fechaAjuste: f.fechaAjuste,
    });
});

async function fetchUfvsForTab(tabId) {
    const form = forms[tabId];
    const state = ufvState[tabId];

    if (activeControllers[tabId]) {
        activeControllers[tabId].abort();
    }

    const fIni = form.fechaInicial;
    const fFin = form.fechaAjuste;

    if (!fIni && !fFin) {
        state.loadingIni = false;
        state.loadingFin = false;
        state.errorIni = '';
        state.errorFin = '';
        return;
    }

    const controller = new AbortController();
    activeControllers[tabId] = controller;

    state.loadingIni = Boolean(fIni);
    state.loadingFin = Boolean(fFin);
    state.errorIni = '';
    state.errorFin = '';

    const fetchSingle = async (fecha, targetField, errorField, loadingField) => {
        if (!fecha) {
            state[loadingField] = false;
            return;
        }
        try {
            const val = await getUfv(fecha, { signal: controller.signal });
            if (controller.signal.aborted) return;

            if (val === null) {
                form[targetField] = '';
                state[errorField] = 'No hay UFV publicada para esa fecha, ingrésala manualmente.';
            } else {
                form[targetField] = val;
                state[errorField] = '';
            }
        } catch (err) {
            if (err?.name === 'AbortError') return;
            state[errorField] =
                err instanceof UfvApiError
                    ? 'No se pudo consultar la API del BCB.'
                    : 'Error al obtener la UFV.';
        } finally {
            if (!controller.signal.aborted) {
                state[loadingField] = false;
            }
        }
    };

    await Promise.all([
        fetchSingle(fIni, 'ufvInicial', 'errorIni', 'loadingIni'),
        fetchSingle(fFin, 'ufvFinal', 'errorFin', 'loadingFin'),
    ]);
}

function scheduleUfvFetch(tabId, immediate = false) {
    if (debounceTimers[tabId]) {
        clearTimeout(debounceTimers[tabId]);
    }
    if (immediate) {
        fetchUfvsForTab(tabId);
        return;
    }
    debounceTimers[tabId] = setTimeout(() => {
        fetchUfvsForTab(tabId);
    }, 400);
}

watch(
    () => [forms.fijo.fechaInicial, forms.fijo.fechaAjuste],
    () => scheduleUfvFetch('fijo')
);
watch(
    () => [forms.intangible.fechaInicial, forms.intangible.fechaAjuste],
    () => scheduleUfvFetch('intangible')
);
watch(
    () => [forms.patrimonial.fechaInicial, forms.patrimonial.fechaAjuste],
    () => scheduleUfvFetch('patrimonial')
);

onBeforeUnmount(() => {
    Object.values(debounceTimers).forEach((t) => clearTimeout(t));
    Object.values(activeControllers).forEach((c) => c?.abort());
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
    const tabId = activeTab.value;
    if (debounceTimers[tabId]) {
        clearTimeout(debounceTimers[tabId]);
    }
    if (activeControllers[tabId]) {
        activeControllers[tabId].abort();
    }
    Object.assign(forms[tabId], createEmptyForm(tabId));
    ufvState[tabId].loadingIni = false;
    ufvState[tabId].loadingFin = false;
    ufvState[tabId].errorIni = '';
    ufvState[tabId].errorFin = '';
}

function formatFechaDisplay(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '-';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}
</script>

<template>
    <div class="space-y-6 actualizacion-print-root">
        <!-- Barra de pestañas y acciones -->
        <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
            <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Tipos de actualización">
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

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="!currentForm.fechaInicial && !currentForm.fechaAjuste"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-muted hover:text-foreground hover:border-primary/40 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    @click="scheduleUfvFetch(activeTab, true)"
                    title="Volver a consultar UFV al BCB"
                >
                    <RefreshCw class="h-3.5 w-3.5" />
                    <span>Actualizar UFV</span>
                </button>
                <button
                    type="button"
                    :disabled="!hasAnyInput"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-muted hover:text-foreground hover:border-primary/40 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    @click="limpiar"
                >
                    <RotateCcw class="h-3.5 w-3.5" />
                    <span>Limpiar</span>
                </button>
            </div>
        </div>

        <!-- Recuadro resaltado de entrada de datos -->
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
                        Completa los campos para calcular la actualización en vivo.
                    </p>
                </div>
                <span class="rounded-full bg-primary/10 border border-primary/20 px-2.5 py-0.5 text-[11px] font-bold text-primary">
                    NC 3 · UFV
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
                <!-- Cuenta y montos -->
                <div class="lg:col-span-7 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div v-if="activeTab === 'fijo' || activeTab === 'intangible'" class="space-y-1.5">
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
                                v-for="item in activeTab === 'fijo' ? ACTIVOS_FIJOS : ACTIVOS_INTANGIBLES"
                                :key="item.nombre"
                                :value="item.nombre"
                            >
                                {{ item.nombre }}
                            </option>
                        </select>
                        <p v-if="selectedActivoRef" class="text-[11px] text-muted">
                            Referencia:
                            <span class="font-semibold text-foreground">
                                {{ selectedActivoRef.vidaUtil === null ? 'Vida útil indefinida' : `${selectedActivoRef.vidaUtil} años` }}
                            </span>
                            · Coeficiente:
                            <span class="font-semibold text-foreground">
                                {{ (selectedActivoRef.coeficiente * 100).toFixed(1).replace('.0', '') }}%
                            </span>
                        </p>
                    </div>

                    <div v-else class="space-y-1.5">
                        <label class="block text-xs font-bold text-foreground">
                            Cuenta patrimonial
                        </label>
                        <select
                            v-model="currentForm.cuenta"
                            class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                        >
                            <option value="" disabled>
                                Selecciona una cuenta patrimonial...
                            </option>
                            <option
                                v-for="item in CUENTAS_PATRIMONIALES"
                                :key="item.cuenta"
                                :value="item.cuenta"
                            >
                                {{ item.cuenta }}
                            </option>
                        </select>
                        <p v-if="selectedPatrimonialRef" class="text-[11px] text-muted">
                            Cuenta de ajuste en asiento:
                            <span class="font-semibold text-foreground">{{ selectedPatrimonialRef.cuentaAjuste }}</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
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

                        <div v-if="activeTab !== 'patrimonial'" class="space-y-1.5">
                            <label class="block text-xs font-bold text-foreground">
                                {{ activeTab === 'intangible' ? 'Amortización acumulada (Bs)' : 'Depreciación acumulada (Bs)' }}
                            </label>
                            <input
                                v-model="currentForm.acumulada"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0,00"
                                class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                            />
                        </div>
                    </div>
                </div>

                <!-- Fechas y UFVs (API BCB + Override manual) -->
                <div class="lg:col-span-5 rounded-brand border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-2xs">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-foreground">
                                Fecha inicial
                            </label>
                            <input
                                v-model="currentForm.fechaInicial"
                                type="date"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-foreground">
                                Fecha de ajuste
                            </label>
                            <input
                                v-model="currentForm.fechaAjuste"
                                type="date"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2.5 text-xs sm:text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-foreground">UFV INICIAL</label>
                                <Loader2
                                    v-if="currentUfvState.loadingIni"
                                    class="h-3.5 w-3.5 animate-spin text-primary"
                                    aria-label="Consultando UFV inicial"
                                />
                            </div>
                            <input
                                v-model="currentForm.ufvInicial"
                                type="number"
                                min="0"
                                step="0.00001"
                                placeholder="0,00000"
                                class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                            />
                            <p v-if="currentUfvState.errorIni" class="text-[11px] text-amber-600 dark:text-amber-400 leading-snug">
                                {{ currentUfvState.errorIni }}
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold text-foreground">UFV FINAL</label>
                                <Loader2
                                    v-if="currentUfvState.loadingFin"
                                    class="h-3.5 w-3.5 animate-spin text-primary"
                                    aria-label="Consultando UFV final"
                                />
                            </div>
                            <input
                                v-model="currentForm.ufvFinal"
                                type="number"
                                min="0"
                                step="0.00001"
                                placeholder="0,00000"
                                class="w-full rounded-brand border border-border bg-background px-3.5 py-2.5 text-xs sm:text-sm font-semibold tabular-nums text-foreground placeholder:text-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"
                            />
                            <p v-if="currentUfvState.errorFin" class="text-[11px] text-amber-600 dark:text-amber-400 leading-snug">
                                {{ currentUfvState.errorFin }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Mensajes de validación y advertencias -->
        <div v-if="resultado.errors.length > 0" class="no-print rounded-brand border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-700 dark:text-rose-300 space-y-1">
            <p v-for="err in resultado.errors" :key="err" class="flex items-center gap-2 font-medium">
                <AlertTriangle class="h-4 w-4 shrink-0" />
                <span>{{ err }}</span>
            </p>
        </div>

        <div v-if="resultado.warnings.length > 0" class="no-print rounded-brand border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-700 dark:text-amber-300 space-y-1">
            <p v-for="warn in resultado.warnings" :key="warn" class="flex items-center gap-2 font-medium">
                <AlertTriangle class="h-4 w-4 shrink-0" />
                <span>{{ warn }}</span>
            </p>
        </div>

        <!-- Área imprimible: Cálculo + Asiento contable -->
        <div v-if="resultado.calculable" class="print-section space-y-5">
            <!-- Encabezado solo visible al imprimir -->
            <div class="hidden print:block border-b border-border pb-2">
                <h2 class="text-lg font-bold">Actualización por UFV (Norma Contable 3)</h2>
                <p class="text-xs text-muted">
                    Periodo: {{ formatFechaDisplay(currentForm.fechaInicial) }} al {{ formatFechaDisplay(currentForm.fechaAjuste) }}
                </p>
            </div>

            <!-- Tabla de cálculo de actualización -->
            <div class="rounded-brand border border-border bg-background/40 overflow-hidden">
                <div class="px-4 py-3 border-b border-border bg-surface flex items-center justify-between">
                    <h2 class="text-sm font-bold text-foreground">
                        Cuadro de actualización por UFV
                    </h2>
                    <span class="text-[11px] font-semibold text-muted tabular-nums">
                        Factor: {{ (Number(currentForm.ufvFinal) / Number(currentForm.ufvInicial)).toFixed(6) }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-border bg-surface/60 text-left text-[11px] font-bold uppercase tracking-wider text-muted">
                                <th class="px-4 py-2.5 border-r border-border">Cuenta</th>
                                <th class="px-4 py-2.5 text-right border-r border-border">Valor en libros</th>
                                <th class="px-4 py-2.5 text-right border-r border-border">UFV ini</th>
                                <th class="px-4 py-2.5 text-right border-r border-border">UFV fin</th>
                                <th class="px-4 py-2.5 text-right border-r border-border">Valor actualizado</th>
                                <th class="px-4 py-2.5 text-right">Incremento</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr v-for="fila in resultado.filas" :key="fila.cuenta" class="hover:bg-surface/50">
                                <td class="px-4 py-2.5 font-semibold text-foreground border-r border-border">{{ fila.cuenta }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-foreground border-r border-border">{{ formatBs(fila.valorLibros) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted border-r border-border">{{ fila.ufvInicial.toFixed(5) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted border-r border-border">{{ fila.ufvFinal.toFixed(5) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-foreground border-r border-border">{{ formatBs(fila.valorActualizado) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-bold text-primary">{{ formatBs(fila.incremento) }}</td>
                            </tr>
                        </tbody>
                    </table>
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

                <div v-if="resultado.reiNegativo" class="px-4 py-2.5 border-t border-border bg-surface/50">
                    <p class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                        Resultado negativo: revisa la {{ activeTab === 'intangible' ? 'amortización' : 'depreciación' }} acumulada.
                    </p>
                </div>
            </div>
        </div>

        <!-- Estado inicial vacío antes de completar los datos -->
        <div
            v-else-if="resultado.errors.length === 0"
            class="no-print rounded-brand border border-dashed border-border bg-background/40 p-6 text-center space-y-1.5"
        >
            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <Calculator class="h-5 w-5" />
            </div>
            <p class="text-xs sm:text-sm font-semibold text-foreground">
                Completa los datos para generar el cálculo y el asiento contable
            </p>
            <p class="text-xs text-muted">
                Al elegir las fechas, la UFV se consultará automáticamente o podrás ingresarla manualmente.
            </p>
        </div>
    </div>
</template>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .actualizacion-print-root,
    .actualizacion-print-root .print-section,
    .actualizacion-print-root .print-section * {
        visibility: visible;
    }
    .actualizacion-print-root .no-print {
        display: none !important;
    }
    .actualizacion-print-root .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
}
</style>
