<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import {
    AlertTriangle,
    Check,
    Copy,
    Printer,
    RotateCcw,
} from 'lucide-vue-next';
import ReciboDocumento from './ReciboDocumento.vue';
import { numeroALetras } from './numeroALetras.js';
import {
    derivarDatosRecibo,
    descomponerFecha,
    formatImporteRecibo,
} from './reciboHelpers.js';

function createEmptyRecibo() {
    return {
        empresa: '',
        fecha: '',
        tipo: 'EGRESO',
        numero: '',
        importe: '',
        receptor: '',
        concepto: '',
    };
}

const form = reactive(createEmptyRecibo());
const copied = ref(false);
let copyTimer = null;

const haInteractuado = computed(() => {
    return Boolean(
        form.empresa ||
            form.fecha ||
            form.numero !== '' ||
            form.importe !== '' ||
            form.receptor ||
            form.concepto
    );
});

const errores = computed(() => {
    const list = [];

    if (!String(form.empresa || '').trim()) {
        list.push('El nombre de la empresa es obligatorio.');
    }
    if (!form.fecha) {
        list.push('La fecha es obligatoria.');
    }

    const num = Number(form.numero);
    if (form.numero === '' || form.numero === null || !Number.isInteger(num) || num < 1) {
        list.push('El número de recibo debe ser un entero mayor o igual a 1.');
    }

    const imp = Number(form.importe);
    if (form.importe === '' || form.importe === null || !Number.isFinite(imp) || imp <= 0 || imp > 999999999.99) {
        list.push('El importe debe ser mayor a 0 y menor o igual a 999,999,999.99.');
    }

    if (!String(form.receptor || '').trim()) {
        list.push('El receptor es obligatorio.');
    }
    if (!String(form.concepto || '').trim()) {
        list.push('El concepto es obligatorio.');
    }

    return list;
});

const puedeImprimir = computed(() => errores.value.length === 0);

onBeforeUnmount(() => {
    if (copyTimer) clearTimeout(copyTimer);
});

function imprimir() {
    if (!puedeImprimir.value) return;
    const prevTitle = document.title;
    const empresa = (form.empresa || 'EMPRESA').trim();
    const nro = (form.numero || 'S-N').toString().trim();
    document.title = `${empresa} RECIBO - ${nro}`;

    window.print();

    setTimeout(() => {
        document.title = prevTitle;
    }, 300);
}

function limpiar() {
    Object.assign(form, createEmptyRecibo());
}

async function copiarTexto() {
    const derivado = derivarDatosRecibo(form.tipo);
    const { dia, mes, anio } = descomponerFecha(form.fecha);
    const imp = Number(form.importe);
    const impFmt = Number.isFinite(imp) && imp > 0 ? formatImporteRecibo(imp) : '0.00';
    const impLetras = Number.isFinite(imp) && imp > 0 ? `${numeroALetras(imp)} Bolivianos` : '—';

    const resumen = [
        `${derivado.titulo} N° ${form.numero || '-'}`,
        `Empresa: ${form.empresa || '-'}`,
        `Fecha: ${dia}/${mes}/${anio}`,
        `${derivado.primeraLineaLabel} ${form.receptor || '-'}`,
        `Importe: Bs ${impFmt} (${impLetras})`,
        `Por concepto de: ${form.concepto || '-'}`,
    ].join('\n');

    try {
        await navigator.clipboard.writeText(resumen);
        copied.value = true;
        if (copyTimer) clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Fallback silencioso
    }
}
</script>

<template>
    <div class="space-y-6 recibo-print-root">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Columna izquierda: Formulario de entrada -->
            <section
                aria-label="Datos del recibo"
                class="no-print lg:col-span-5 rounded-2xl border-2 border-primary/25 bg-background/90 p-4 sm:p-5 shadow-sm ring-1 ring-primary/10 space-y-4"
            >
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h2 class="text-sm sm:text-base font-bold tracking-tight text-foreground">
                        Datos del recibo
                    </h2>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 py-1 text-xs font-semibold text-muted hover:text-foreground hover:border-primary/40 transition-colors"
                        @click="limpiar"
                    >
                        <RotateCcw class="h-3.5 w-3.5" />
                        <span>Limpiar</span>
                    </button>
                </div>

                <div class="space-y-3 rounded-brand border border-border bg-surface p-4">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                            NOMBRE EMPRESA
                        </label>
                        <input
                            v-model="form.empresa"
                            type="text"
                            maxlength="60"
                            placeholder="Ej. DEXIDI SRL."
                            class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                FECHA
                            </label>
                            <input
                                v-model="form.fecha"
                                type="date"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                TIPO DE RECIBO
                            </label>
                            <select
                                v-model="form.tipo"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            >
                                <option value="EGRESO">EGRESO</option>
                                <option value="INGRESO">INGRESO</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                NUMERO DE RECIBO
                            </label>
                            <input
                                v-model.number="form.numero"
                                type="number"
                                min="1"
                                step="1"
                                placeholder="1"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold tabular-nums text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                IMPORTE (Bs)
                            </label>
                            <input
                                v-model.number="form.importe"
                                type="number"
                                min="0.01"
                                max="999999999.99"
                                step="0.01"
                                placeholder="0.00"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold tabular-nums text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                            RECEPTOR
                        </label>
                        <input
                            v-model="form.receptor"
                            type="text"
                            maxlength="60"
                            placeholder="Nombre completo"
                            class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                            CONCEPTO
                        </label>
                        <textarea
                            v-model="form.concepto"
                            rows="2"
                            maxlength="120"
                            placeholder="Detalle del concepto"
                            class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 resize-none"
                        ></textarea>
                    </div>
                </div>

                <!-- Errores de validación -->
                <div
                    v-if="haInteractuado && errores.length > 0"
                    class="rounded-brand border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-300 space-y-1"
                >
                    <p v-for="err in errores" :key="err" class="flex items-center gap-1.5 font-medium">
                        <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                        <span>{{ err }}</span>
                    </p>
                </div>

                <!-- Acciones -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :disabled="!puedeImprimir"
                        class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-bold text-primary-foreground shadow-xs hover:bg-primary-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        @click="imprimir"
                    >
                        <Printer class="h-3.5 w-3.5" />
                        <span>Imprimir / Guardar como PDF</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-2 text-xs font-semibold text-foreground hover:border-primary/40 hover:text-primary transition-colors"
                        @click="copiarTexto"
                    >
                        <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-500" />
                        <Copy v-else class="h-3.5 w-3.5" />
                        <span>{{ copied ? 'Copiado' : 'Copiar texto del recibo' }}</span>
                    </button>
                </div>
            </section>

            <!-- Columna derecha: Vista previa del recibo -->
            <section class="print-section lg:col-span-7 space-y-2">
                <div class="no-print flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-muted">
                        Vista previa en vivo
                    </span>
                    <span class="text-[11px] text-muted">
                        Tamaño real 600 px (desliza horizontalmente en pantallas pequeñas)
                    </span>
                </div>

                <div class="recibo-preview-scroll overflow-x-auto pb-2">
                    <div class="recibo-print-center inline-block">
                        <ReciboDocumento
                            :empresa="form.empresa"
                            :fecha="form.fecha"
                            :tipo="form.tipo"
                            :numero="form.numero"
                            :importe="form.importe"
                            :receptor="form.receptor"
                            :concepto="form.concepto"
                        />
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style>
@page {
    size: letter portrait;
    margin: 1.5cm;
}

@media print {
    body * {
        visibility: hidden;
    }
    .recibo-print-root,
    .recibo-print-root .print-section,
    .recibo-print-root .print-section * {
        visibility: visible;
    }
    .recibo-print-root .no-print {
        display: none !important;
    }
    .recibo-print-root .print-section {
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        display: flex;
        justify-content: center;
    }
    .recibo-print-root .recibo-preview-scroll {
        overflow: visible !important;
    }
}
</style>
