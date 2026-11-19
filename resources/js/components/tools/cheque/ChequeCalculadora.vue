<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import {
    AlertTriangle,
    Check,
    Copy,
    Plus,
    Printer,
    RotateCcw,
    Trash2,
} from 'lucide-vue-next';
import ChequeDocumento from './ChequeDocumento.vue';
import { numeroALetras } from '../recibo/numeroALetras.js';
import {
    capitalPorDepartamento,
    DEPARTAMENTOS_LISTA,
    formatImporteCheque,
    formatearFechaLargaEs,
    incrementarNumeroCheque,
} from './chequeHelpers.js';

function createEmptyCheque() {
    return {
        empresa: '',
        departamento: 'LA PAZ',
        lugar: 'La Paz',
        fecha: '',
        cuenta: '',
        numeroCheque: '',
        importe: '',
        receptor: '',
        concepto: '',
        direccion: '',
        lineaCodificacion: '',
        ocultarPlaceholderLogo: false,
        marcaModelo: true,
    };
}

const form = reactive(createEmptyCheque());
const logoUrl = ref('');
const logoError = ref('');
const logoInputRef = ref(null);
const copied = ref(false);
let copyTimer = null;

// Escalado proporcional para móviles sin romper la tarjeta de 680px
const previewContainerRef = ref(null);
const previewScale = ref(1);
let resizeObserver = null;

function updatePreviewScale() {
    const el = previewContainerRef.value;
    if (!el) return;
    const available = el.clientWidth;
    previewScale.value = available > 0 && available < 680 ? available / 680 : 1;
}

onMounted(() => {
    updatePreviewScale();
    if (typeof ResizeObserver !== 'undefined' && previewContainerRef.value) {
        resizeObserver = new ResizeObserver(() => updatePreviewScale());
        resizeObserver.observe(previewContainerRef.value);
    }
});

onBeforeUnmount(() => {
    if (copyTimer) clearTimeout(copyTimer);
    if (resizeObserver) resizeObserver.disconnect();
    if (logoUrl.value) URL.revokeObjectURL(logoUrl.value);
});

const haInteractuado = computed(() => {
    return Boolean(
        form.empresa ||
            form.fecha ||
            form.cuenta ||
            form.numeroCheque ||
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

    const cta = String(form.cuenta || '').trim();
    if (!/^\d{1,20}$/.test(cta)) {
        list.push('La cuenta debe tener entre 1 y 20 dígitos.');
    }

    const num = String(form.numeroCheque || '').trim();
    if (!/^\d{1,12}$/.test(num)) {
        list.push('El número de cheque debe tener entre 1 y 12 dígitos.');
    }

    const imp = Number(form.importe);
    if (form.importe === '' || form.importe === null || !Number.isFinite(imp) || imp <= 0 || imp > 999999999.99) {
        list.push('El importe debe ser mayor a 0 y menor o igual a 999,999,999.99.');
    }

    if (!String(form.receptor || '').trim()) {
        list.push('El receptor es obligatorio.');
    }

    return list;
});

const puedeImprimir = computed(() => errores.value.length === 0);

function onDepartamentoChange() {
    form.lugar = capitalPorDepartamento(form.departamento);
}

function onCuentaInput(e) {
    form.cuenta = String(e.target.value || '').replace(/\D/g, '').slice(0, 20);
}

function onNumeroChequeInput(e) {
    form.numeroCheque = String(e.target.value || '').replace(/\D/g, '').slice(0, 12);
}

function onCodificacionInput(e) {
    form.lineaCodificacion = String(e.target.value || '').replace(/\D/g, '').slice(0, 32);
}

function incrementarCheque() {
    form.numeroCheque = incrementarNumeroCheque(form.numeroCheque);
}

function onLogoChange(e) {
    logoError.value = '';
    const file = e.target.files?.[0];
    if (!file) return;

    const validTypes = ['image/png', 'image/jpeg', 'image/svg+xml'];
    if (!validTypes.includes(file.type)) {
        logoError.value = 'Formato inválido. Usa PNG, JPG o SVG.';
        return;
    }
    if (file.size > 1024 * 1024) {
        logoError.value = 'La imagen no debe superar 1 MB.';
        return;
    }

    if (logoUrl.value) {
        URL.revokeObjectURL(logoUrl.value);
    }
    logoUrl.value = URL.createObjectURL(file);
}

function quitarLogo() {
    if (logoUrl.value) {
        URL.revokeObjectURL(logoUrl.value);
        logoUrl.value = '';
    }
    logoError.value = '';
    if (logoInputRef.value) {
        logoInputRef.value.value = '';
    }
}

function imprimir() {
    if (!puedeImprimir.value) return;
    const prevTitle = document.title;
    const empresa = (form.empresa || 'EMPRESA').trim();
    const nro = (form.numeroCheque || 'S-N').trim();
    document.title = `${empresa} CHEQUE - ${nro}`;

    window.print();

    setTimeout(() => {
        document.title = prevTitle;
    }, 300);
}

function limpiar() {
    Object.assign(form, createEmptyCheque());
    quitarLogo();
}

async function copiarDatos() {
    const imp = Number(form.importe);
    const impFmt = Number.isFinite(imp) && imp > 0 ? formatImporteCheque(imp) : '0.00';
    const impLetras = Number.isFinite(imp) && imp > 0 ? `${numeroALetras(imp, { caso: 'upper' })} bolivianos` : '—';
    const fechaTxt = formatearFechaLargaEs(form.fecha) || '-';

    const resumen = [
        `Empresa: ${form.empresa || '-'}`,
        `Cta. Cte.: ${form.cuenta || '-'}`,
        `Cheque N°: ${form.numeroCheque || '-'}`,
        `Lugar y fecha: ${form.lugar || '-'}, ${fechaTxt}`,
        `Páguese a la orden de: ${form.receptor || '-'}`,
        `Importe: Bs. ${impFmt} (${impLetras})`,
        `Concepto: ${form.concepto || '-'}`,
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
    <div class="space-y-6 cheque-print-root">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Columna izquierda: Formulario de entrada -->
            <section
                aria-label="Datos del cheque"
                class="no-print lg:col-span-5 rounded-2xl border-2 border-primary/25 bg-background/90 p-4 sm:p-5 shadow-sm ring-1 ring-primary/10 space-y-4"
            >
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h2 class="text-sm sm:text-base font-bold tracking-tight text-foreground">
                        Datos del cheque
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
                                DEPARTAMENTO
                            </label>
                            <select
                                v-model="form.departamento"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                @change="onDepartamentoChange"
                            >
                                <option v-for="dep in DEPARTAMENTOS_LISTA" :key="dep" :value="dep">
                                    {{ dep }}
                                </option>
                            </select>
                        </div>

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
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                CUENTA
                            </label>
                            <input
                                :value="form.cuenta"
                                type="text"
                                inputmode="numeric"
                                maxlength="20"
                                placeholder="Ej. 1000000651616"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold tabular-nums text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                @input="onCuentaInput"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                NUMERO DE CHEQUE
                            </label>
                            <div class="flex items-stretch gap-1.5">
                                <input
                                    :value="form.numeroCheque"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="12"
                                    placeholder="Ej. 1234"
                                    class="min-w-0 flex-1 rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm font-semibold tabular-nums text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    @input="onNumeroChequeInput"
                                />
                                <button
                                    type="button"
                                    title="Incrementar número de cheque (+1)"
                                    class="inline-flex shrink-0 items-center gap-0.5 rounded-brand border border-border bg-background px-2.5 text-xs font-bold text-primary hover:border-primary/40 hover:bg-primary/5 transition-colors"
                                    @click="incrementarCheque"
                                >
                                    <Plus class="h-3 w-3" />
                                    <span>1</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                RECEPTOR
                            </label>
                            <input
                                v-model="form.receptor"
                                type="text"
                                maxlength="60"
                                placeholder="Nombre del beneficiario"
                                class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                            CONCEPTO <span class="font-normal text-muted normal-case">(solo registro, no se imprime)</span>
                        </label>
                        <input
                            v-model="form.concepto"
                            type="text"
                            maxlength="120"
                            placeholder="Ej. COMPRA DE CHAMARRAS"
                            class="w-full rounded-brand border border-border bg-background px-3 py-2 text-xs sm:text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>
                </div>

                <!-- Opcionales -->
                <details class="rounded-brand border border-border bg-surface p-3.5 group">
                    <summary class="cursor-pointer text-xs font-bold uppercase tracking-wide text-muted hover:text-foreground select-none">
                        Opcionales (lugar, dirección, codificación, logo)
                    </summary>

                    <div class="mt-3 space-y-3 pt-3 border-t border-border/70">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                    LUGAR DE EMISIÓN
                                </label>
                                <input
                                    v-model="form.lugar"
                                    type="text"
                                    maxlength="40"
                                    class="w-full rounded-brand border border-border bg-background px-3 py-1.5 text-xs text-foreground focus:border-primary focus:outline-none"
                                />
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                    DIRECCIÓN EMPRESA
                                </label>
                                <input
                                    v-model="form.direccion"
                                    type="text"
                                    maxlength="60"
                                    placeholder="Ej. Av. Central # 123"
                                    class="w-full rounded-brand border border-border bg-background px-3 py-1.5 text-xs text-foreground focus:border-primary focus:outline-none"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground mb-1">
                                LÍNEA DE CODIFICACIÓN
                            </label>
                            <input
                                :value="form.lineaCodificacion"
                                type="text"
                                inputmode="numeric"
                                placeholder="Ej. 985400000651651"
                                class="w-full rounded-brand border border-border bg-background px-3 py-1.5 text-xs font-mono text-foreground focus:border-primary focus:outline-none"
                                @input="onCodificacionInput"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold uppercase tracking-wide text-foreground">
                                    LOGO DEL BANCO (PNG/JPG/SVG, máx. 1 MB)
                                </label>
                                <button
                                    v-if="logoUrl"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-600 hover:underline"
                                    @click="quitarLogo"
                                >
                                    <Trash2 class="h-3 w-3" />
                                    <span>Quitar</span>
                                </button>
                            </div>
                            <input
                                ref="logoInputRef"
                                type="file"
                                accept="image/png,image/jpeg,image/svg+xml"
                                class="block w-full text-xs text-muted file:mr-2.5 file:rounded-md file:border file:border-border file:bg-background file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-foreground hover:file:border-primary/40"
                                @change="onLogoChange"
                            />
                            <p v-if="logoError" class="text-[11px] font-medium text-rose-600">
                                {{ logoError }}
                            </p>
                        </div>

                        <div class="space-y-1.5 pt-1">
                            <label class="flex items-center gap-2 text-xs font-medium text-foreground cursor-pointer">
                                <input
                                    v-model="form.marcaModelo"
                                    type="checkbox"
                                    class="rounded border-border text-primary focus:ring-primary"
                                />
                                <span>Mostrar marca de agua "MODELO – SIN VALOR"</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs font-medium text-foreground cursor-pointer">
                                <input
                                    v-model="form.ocultarPlaceholderLogo"
                                    type="checkbox"
                                    class="rounded border-border text-primary focus:ring-primary"
                                />
                                <span>Ocultar placeholder "LOGO BANCO" al imprimir</span>
                            </label>
                        </div>
                    </div>
                </details>

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
                        @click="copiarDatos"
                    >
                        <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-500" />
                        <Copy v-else class="h-3.5 w-3.5" />
                        <span>{{ copied ? 'Copiado' : 'Copiar datos' }}</span>
                    </button>
                </div>
            </section>

            <!-- Columna derecha: Vista previa del cheque -->
            <section class="print-section lg:col-span-7 space-y-2">
                <div class="no-print flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-muted">
                        Vista previa en vivo
                    </span>
                    <span class="text-[11px] text-muted">
                        Formato didáctico (680 × 250 px)
                    </span>
                </div>

                <div ref="previewContainerRef" class="w-full overflow-hidden">
                    <div
                        class="cheque-scale-inner origin-top-left"
                        :style="{
                            width: '680px',
                            transform: `scale(${previewScale})`,
                            height: `${Math.round(256 * previewScale)}px`,
                        }"
                    >
                        <ChequeDocumento
                            :empresa="form.empresa"
                            :departamento="form.departamento"
                            :lugar="form.lugar"
                            :fecha="form.fecha"
                            :cuenta="form.cuenta"
                            :numero-cheque="form.numeroCheque"
                            :importe="form.importe"
                            :receptor="form.receptor"
                            :direccion="form.direccion"
                            :linea-codificacion="form.lineaCodificacion"
                            :logo-url="logoUrl"
                            :ocultar-placeholder-logo="form.ocultarPlaceholderLogo"
                            :marca-modelo="form.marcaModelo"
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
    .cheque-print-root,
    .cheque-print-root .print-section,
    .cheque-print-root .print-section * {
        visibility: visible;
    }
    .cheque-print-root .no-print {
        display: none !important;
    }
    .cheque-print-root .print-section {
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        display: flex;
        justify-content: center;
    }
    .cheque-print-root .cheque-scale-inner {
        transform: none !important;
        height: auto !important;
    }
}
</style>
