<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, useId, watch } from 'vue';
import { Plus, Printer, X } from 'lucide-vue-next';
import BaseAlert from '../../ui/BaseAlert.vue';
import MoneyField from '../../calculators/shared/MoneyField.vue';
import BalanceDocument from './BalanceDocument.vue';
import { armarBalance, porcentajeTexto } from './balanceApertura';
import { CIUDADES, GRUPOS_ACTIVO_FIJO, LIMITES, TIPOS_EMPRESA } from './catalogos';
import { parseIsoDate } from '../../calculators/bono-antiguedad/bonoAntiguedad';
import { bs, parseAmount, parseWhole, todayIso } from '../../calculators/shared/formatters';
import { btn, errorText, field, hint, label } from '../../subscriptions/shared';

/**
 * Herramienta "Crear balance de apertura" (catálogo de Herramientas).
 * Arma el balance en el navegador con el mismo cálculo que boliviaimpuestos.com (ver balanceApertura.js)
 * y lo imprime o guarda como PDF desde el navegador. No se envía ni se guarda nada.
 */
defineProps({
    item: { type: Object, default: null },
});

const uid = useId();
let nextKey = 1;
const newItem = () => ({ key: nextKey++, descripcion: '', cantidad: '', precio: '' });
const newSocio = () => ({ key: nextKey++, nombre: '', porcentaje: '' });

const form = reactive({
    tipo: 'unipersonal',
    fecha: todayIso(),
    razonSocial: '',
    nit: '',
    ciudad: CIUDADES[0],
    capital: '300.000',
    socios: [],
    activos: Object.fromEntries(GRUPOS_ACTIVO_FIJO.map((g) => [g.clave, [newItem()]])),
});

// ---------------------------------------------------------------------
// Acciones del formulario
// ---------------------------------------------------------------------
function setTipo(tipo) {
    form.tipo = tipo;
    // Como en la página original: una S.R.L. empieza con dos socios para llenar.
    if (tipo === 'srl' && form.socios.length === 0) form.socios.push(newSocio(), newSocio());
}

function addItem(clave) {
    if (form.activos[clave].length < LIMITES.itemsPorGrupo) form.activos[clave].push(newItem());
}

function removeItem(clave, index) {
    form.activos[clave].splice(index, 1);
    if (form.activos[clave].length === 0) form.activos[clave].push(newItem());
}

function addSocio() {
    if (form.socios.length < LIMITES.socios) form.socios.push(newSocio());
}

function onNit(event) {
    form.nit = event.target.value.replace(/\D/g, '').slice(0, LIMITES.nit);
    event.target.value = form.nit;
}

// ---------------------------------------------------------------------
// Lectura de cada fila (una fila vacía no cuenta y no molesta)
// ---------------------------------------------------------------------
const isBlank = (...values) => values.every((v) => String(v ?? '').trim() === '');

function readItem(row) {
    if (isBlank(row.descripcion, row.cantidad, row.precio)) return { vacia: true, cantidad: 0, precio: 0, errores: {} };
    const cantidad = parseWhole(row.cantidad, 1, 9999);
    const precio = parseAmount(row.precio);
    const errores = {};
    if (!row.descripcion.trim()) errores.descripcion = 'falta la descripción';
    if (cantidad === null) errores.cantidad = 'la cantidad va en números enteros, de 1 a 9.999';
    if (precio === null || precio <= 0) errores.precio = 'el precio va como 1.500 o 1.500,50';
    return { vacia: false, cantidad: cantidad ?? 0, precio: precio ?? 0, errores };
}

function readSocio(row) {
    if (isBlank(row.nombre, row.porcentaje)) return { vacia: true, porcentaje: 0, errores: {} };
    const porcentaje = parseAmount(row.porcentaje);
    const errores = {};
    if (!row.nombre.trim()) errores.nombre = 'falta el nombre';
    if (porcentaje === null || porcentaje <= 0 || porcentaje > 100) errores.porcentaje = 'el porcentaje va de 0,01 a 100';
    return { vacia: false, porcentaje: porcentaje ?? 0, errores };
}

const itemsRead = computed(() => Object.fromEntries(GRUPOS_ACTIVO_FIJO.map((g) => [g.clave, form.activos[g.clave].map(readItem)])));
const sociosRead = computed(() => form.socios.map(readSocio));
const hasErrors = (r) => Object.keys(r.errores).length > 0;
const badRows = computed(
    () =>
        Object.values(itemsRead.value).flat().filter(hasErrors).length +
        (form.tipo === 'srl' ? sociosRead.value.filter(hasErrors).length : 0),
);

const capital = computed(() => parseAmount(form.capital));
const capitalError = computed(() => (capital.value === null || capital.value <= 0 ? 'Escribe el capital total, por ejemplo 300.000.' : ''));
const fechaOk = computed(() => parseIsoDate(form.fecha) !== null && form.fecha >= '1990-01-01' && form.fecha <= '2100-12-31');

// ---------------------------------------------------------------------
// Balance
// ---------------------------------------------------------------------
const fresh = computed(() => {
    if (capitalError.value) return null;
    return armarBalance(
        {
            tipo: form.tipo,
            capital: capital.value,
            activos: Object.fromEntries(
                GRUPOS_ACTIVO_FIJO.map((g) => [
                    g.clave,
                    form.activos[g.clave].map((row, i) => ({
                        descripcion: row.descripcion,
                        cantidad: itemsRead.value[g.clave][i].cantidad,
                        precio: itemsRead.value[g.clave][i].precio,
                    })),
                ]),
            ),
            socios: form.socios.map((row, i) => ({ nombre: row.nombre, porcentaje: sociosRead.value[i].porcentaje })),
        },
        GRUPOS_ACTIVO_FIJO,
    );
});

// Mientras el capital está a medio escribir, se sigue viendo el último balance (atenuado).
const lastGood = shallowRef(null);
watch(fresh, (r) => { if (r) lastGood.value = r; }, { immediate: true });
const result = computed(() => fresh.value ?? lastGood.value);
const stale = computed(() => !fresh.value);

const empresa = computed(() => ({
    razonSocial: form.razonSocial.trim(),
    nit: form.nit,
    ciudad: form.ciudad,
    fecha: form.fecha,
    tipo: form.tipo,
}));

const subtotal = (clave) => result.value?.categorias.find((c) => c.clave === clave)?.subtotal ?? 0;

/** Lo que falta para que el balance se pueda imprimir, en palabras. */
const pending = computed(() => {
    const r = result.value;
    const list = [];
    if (capitalError.value) list.push('Escribe el capital total.');
    if (!form.razonSocial.trim()) list.push('Escribe el nombre o razón social de la empresa.');
    if (!fechaOk.value) list.push('Elige la fecha del balance.');
    if (badRows.value) list.push(badRows.value === 1 ? 'Completa o quita la fila marcada en rojo.' : `Completa o quita las ${badRows.value} filas marcadas en rojo.`);
    if (r?.activosSuperanCapital) list.push(`Los activos fijos (${bs(r.activoFijo)}) superan el capital: sube el capital o quita activos.`);
    if (r && form.tipo === 'srl' && !r.porcentajesOk) list.push(`Los porcentajes de los socios suman ${porcentajeTexto(r.sumaPorcentajes)}: deben sumar 100%.`);
    return list;
});

const ready = computed(() => !stale.value && result.value?.cuadra && pending.value.length === 0);

// ---------------------------------------------------------------------
// Imprimir o guardar como PDF (solo el documento, en tamaño carta)
// ---------------------------------------------------------------------
const printing = ref(false);
let savedTitle = '';

function onAfterPrint() {
    if (!printing.value) return;
    printing.value = false;
    document.body.classList.remove('imprimiendo-balance');
    document.title = savedTitle;
}

async function printBalance() {
    if (!ready.value) return;
    printing.value = true;
    document.body.classList.add('imprimiendo-balance');
    // El navegador propone el título como nombre del PDF.
    savedTitle = document.title;
    document.title = `Balance de apertura - ${empresa.value.razonSocial}`.slice(0, 120);
    await nextTick();
    window.print();
}

onMounted(() => window.addEventListener('afterprint', onAfterPrint));
onBeforeUnmount(() => {
    window.removeEventListener('afterprint', onAfterPrint);
    onAfterPrint();
});

const segment = (active) =>
    `rounded-md px-3 py-2 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 ${
        active ? 'bg-surface font-semibold text-primary shadow-xs' : 'text-muted hover:text-foreground'
    }`;
const rowField = (error) => [field, 'min-w-0', error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30'];
const rowMessage = (read) => Object.values(read.errores).join('; ');
const removeBtn =
    'inline-flex h-9 w-9 items-center justify-center rounded-brand text-muted transition-colors hover:bg-rose-500/10 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 sm:h-8 sm:w-8';
const addBtn =
    'inline-flex items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:border-primary hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 disabled:opacity-50';
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-12 lg:gap-8">
        <!-- ============================ DATOS ============================ -->
        <form class="space-y-6 lg:col-span-5" novalidate @submit.prevent>
            <!-- Empresa -->
            <fieldset class="space-y-4">
                <legend class="text-base font-semibold text-foreground">Datos de la empresa</legend>

                <div>
                    <span :id="`${uid}-tipo`" :class="label">Tipo de empresa</span>
                    <div class="mt-1.5 grid grid-cols-2 gap-1 rounded-lg border border-border bg-background p-1" role="group" :aria-labelledby="`${uid}-tipo`">
                        <button
                            v-for="t in TIPOS_EMPRESA"
                            :key="t.valor"
                            type="button"
                            :class="segment(form.tipo === t.valor)"
                            :aria-pressed="form.tipo === t.valor"
                            @click="setTipo(t.valor)"
                        >
                            {{ t.nombre }}
                        </button>
                    </div>
                </div>

                <div>
                    <label :for="`${uid}-razon`" :class="label">Nombre o razón social</label>
                    <input
                        :id="`${uid}-razon`"
                        v-model="form.razonSocial"
                        type="text"
                        :maxlength="LIMITES.razonSocial"
                        autocomplete="organization"
                        :placeholder="form.tipo === 'srl' ? 'Mi Empresa S.R.L.' : 'Mi Empresa'"
                        :class="field"
                        class="mt-1.5"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label :for="`${uid}-nit`" :class="label">NIT (opcional)</label>
                        <input
                            :id="`${uid}-nit`"
                            :value="form.nit"
                            type="text"
                            inputmode="numeric"
                            :maxlength="LIMITES.nit"
                            autocomplete="off"
                            placeholder="1023456789"
                            :class="field"
                            class="mt-1.5 tabular-nums"
                            @input="onNit"
                        />
                    </div>
                    <div>
                        <label :for="`${uid}-fecha`" :class="label">Fecha del balance</label>
                        <input
                            :id="`${uid}-fecha`"
                            v-model="form.fecha"
                            type="date"
                            min="1990-01-01"
                            max="2100-12-31"
                            :class="[field, !fechaOk && 'border-rose-500']"
                            class="mt-1.5"
                            :aria-invalid="!fechaOk"
                        />
                    </div>
                </div>

                <div>
                    <label :for="`${uid}-ciudad`" :class="label">Ciudad</label>
                    <select :id="`${uid}-ciudad`" v-model="form.ciudad" :class="field" class="mt-1.5">
                        <option v-for="c in CIUDADES" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>

                <MoneyField
                    :id="`${uid}-capital`"
                    v-model="form.capital"
                    label="Capital total"
                    hint="Todo lo que aportan para empezar: dinero y bienes."
                    :error="capitalError"
                />
            </fieldset>

            <!-- Socios (solo S.R.L.) -->
            <fieldset v-if="form.tipo === 'srl'" class="space-y-3">
                <legend class="text-base font-semibold text-foreground">Socios y aportes</legend>
                <p :class="hint">El aporte de cada socio es su porcentaje del capital. Entre todos deben sumar 100%.</p>

                <div v-for="(socio, i) in form.socios" :key="socio.key">
                    <div class="grid grid-cols-[minmax(0,1fr)_5.5rem_auto] items-center gap-1.5">
                        <input
                            v-model="socio.nombre"
                            type="text"
                            :maxlength="LIMITES.texto"
                            :placeholder="`Nombre del socio ${i + 1}`"
                            :aria-label="`Nombre del socio ${i + 1}`"
                            :class="rowField(sociosRead[i].errores.nombre)"
                        />
                        <div class="relative">
                            <input
                                v-model="socio.porcentaje"
                                type="text"
                                inputmode="decimal"
                                maxlength="6"
                                placeholder="0"
                                :aria-label="`Porcentaje del socio ${i + 1}`"
                                :class="rowField(sociosRead[i].errores.porcentaje)"
                                class="pe-7 text-end tabular-nums"
                            />
                            <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-muted" aria-hidden="true">%</span>
                        </div>
                        <button type="button" :class="removeBtn" :aria-label="`Quitar socio ${i + 1}`" @click="form.socios.splice(i, 1)">
                            <X class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>
                    <p v-if="Object.keys(sociosRead[i].errores).length" class="mt-1" :class="errorText">Esta fila no cuenta: {{ rowMessage(sociosRead[i]) }}.</p>
                    <p v-else-if="result && result.socios[i]?.enBalance" class="mt-1 text-xs tabular-nums text-muted">Aporta {{ bs(result.socios[i].aporte) }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <button type="button" :class="addBtn" :disabled="form.socios.length >= LIMITES.socios" @click="addSocio">
                        <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Agregar socio
                    </button>
                    <p
                        v-if="result"
                        class="text-xs font-medium"
                        :class="result.porcentajesOk ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                    >
                        {{ result.porcentajesOk ? 'Los aportes suman 100%' : `Suman ${porcentajeTexto(result.sumaPorcentajes)}: deben sumar 100%` }}
                    </p>
                </div>
            </fieldset>

            <!-- Activos fijos -->
            <fieldset class="space-y-3">
                <legend class="text-base font-semibold text-foreground">Activos fijos</legend>
                <p :class="hint">
                    Los bienes con que empieza la empresa. La Caja/Bancos se calcula sola: capital menos activos fijos, así el Activo
                    iguala al Patrimonio.
                </p>

                <section v-for="g in GRUPOS_ACTIVO_FIJO" :key="g.clave" class="rounded-brand border border-border p-3" :aria-label="g.nombre">
                    <div class="mb-2 flex items-baseline justify-between gap-3">
                        <h3 class="text-sm font-semibold text-foreground">{{ g.nombre }}</h3>
                        <span class="text-xs font-semibold tabular-nums text-primary">{{ bs(subtotal(g.clave)) }}</span>
                    </div>

                    <div v-for="(row, i) in form.activos[g.clave]" :key="row.key" class="mb-2">
                        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-1.5 sm:grid-cols-[minmax(0,1fr)_4.25rem_6.75rem_auto]">
                            <input
                                v-model="row.descripcion"
                                type="text"
                                :maxlength="LIMITES.texto"
                                placeholder="Descripción"
                                :aria-label="`${g.nombre}: descripción del ítem ${i + 1}`"
                                :class="rowField(itemsRead[g.clave][i].errores.descripcion)"
                                class="col-span-3 sm:col-span-1"
                            />
                            <input
                                v-model.trim="row.cantidad"
                                type="text"
                                inputmode="numeric"
                                maxlength="4"
                                placeholder="Cant."
                                :aria-label="`${g.nombre}: cantidad del ítem ${i + 1}`"
                                :class="rowField(itemsRead[g.clave][i].errores.cantidad)"
                                class="text-end tabular-nums"
                            />
                            <input
                                v-model="row.precio"
                                type="text"
                                inputmode="decimal"
                                maxlength="14"
                                placeholder="P. unitario"
                                :aria-label="`${g.nombre}: precio unitario del ítem ${i + 1}, en bolivianos`"
                                :class="rowField(itemsRead[g.clave][i].errores.precio)"
                                class="text-end tabular-nums"
                            />
                            <button type="button" :class="removeBtn" :aria-label="`Quitar ítem ${i + 1} de ${g.nombre}`" @click="removeItem(g.clave, i)">
                                <X class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                        <p v-if="Object.keys(itemsRead[g.clave][i].errores).length" class="mt-1" :class="errorText">
                            Esta fila no se suma: {{ rowMessage(itemsRead[g.clave][i]) }}.
                        </p>
                        <p v-else-if="!itemsRead[g.clave][i].vacia" class="mt-1 text-xs tabular-nums text-muted">
                            {{ itemsRead[g.clave][i].cantidad }} × {{ bs(itemsRead[g.clave][i].precio) }} = {{ bs(itemsRead[g.clave][i].cantidad * itemsRead[g.clave][i].precio) }}
                        </p>
                    </div>

                    <button type="button" :class="addBtn" :disabled="form.activos[g.clave].length >= LIMITES.itemsPorGrupo" @click="addItem(g.clave)">
                        <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Agregar ítem
                    </button>
                </section>
            </fieldset>
        </form>

        <!-- ============================ BALANCE ============================ -->
        <section class="space-y-4 lg:col-span-7" aria-label="Balance de apertura">
            <BaseAlert v-if="stale" type="warning" title="Revisa el capital">
                Corrige el capital. Mientras tanto ves el último balance válido, atenuado.
            </BaseAlert>

            <div v-if="result" class="space-y-4 motion-safe:transition-opacity" :class="stale && 'opacity-50'" :aria-hidden="stale ? 'true' : undefined">
                <!-- Cómo está el cuadre -->
                <div class="rounded-brand border border-border bg-paper p-5 sm:p-6" aria-live="polite">
                    <p class="text-sm text-muted">{{ result.cuadra ? 'El balance cuadra' : 'El balance no cuadra' }}</p>
                    <p
                        class="mt-1 font-serif text-2xl leading-tight tracking-tight sm:text-3xl"
                        :class="result.cuadra ? 'text-foreground' : 'text-rose-700 dark:text-rose-400'"
                    >
                        Activo {{ result.cuadra ? '=' : '≠' }} Patrimonio
                    </p>
                    <dl class="mt-4 divide-y divide-border/70 text-sm">
                        <div class="flex items-baseline justify-between gap-4 py-1.5">
                            <dt class="text-muted">Caja / Bancos (capital menos activos fijos)</dt>
                            <dd class="whitespace-nowrap tabular-nums" :class="result.caja < 0 ? 'font-semibold text-rose-700 dark:text-rose-400' : 'text-foreground'">{{ bs(result.caja) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-1.5">
                            <dt class="text-muted">Activo fijo</dt>
                            <dd class="whitespace-nowrap tabular-nums text-foreground">{{ bs(result.activoFijo) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-1.5">
                            <dt class="font-semibold text-foreground">Total activo</dt>
                            <dd class="whitespace-nowrap font-semibold tabular-nums text-foreground">{{ bs(result.activo) }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-1.5">
                            <dt class="font-semibold text-foreground">Patrimonio (capital)</dt>
                            <dd class="whitespace-nowrap font-semibold tabular-nums text-foreground">{{ bs(result.capital) }}</dd>
                        </div>
                    </dl>
                </div>

                <BaseAlert v-if="pending.length" type="warning" title="Antes de imprimir">
                    <ul class="list-disc space-y-0.5 ps-4">
                        <li v-for="(p, i) in pending" :key="i">{{ p }}</li>
                    </ul>
                </BaseAlert>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-relaxed text-muted">
                        Se abre la ventana de impresión: elige <strong class="font-semibold text-foreground">Guardar como PDF</strong> para descargarlo.
                    </p>
                    <button type="button" :class="btn.primary" class="shrink-0" :disabled="!ready" @click="printBalance">
                        <Printer class="h-4 w-4" aria-hidden="true" /> Imprimir o guardar PDF
                    </button>
                </div>

                <!-- Vista previa del documento -->
                <div class="rounded-brand border border-border bg-slate-100 p-3 sm:p-6 dark:bg-slate-800/60">
                    <BalanceDocument :empresa="empresa" :balance="result" class="mx-auto max-w-[42rem] shadow-md" />
                </div>

                <p class="text-xs leading-relaxed text-muted">
                    Usamos el mismo cálculo que el
                    <a
                        href="https://boliviaimpuestos.com/maletin/balance-apertura.php"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    >balance de apertura de boliviaimpuestos.com</a>.
                    Nada de lo que escribes sale de tu navegador.
                </p>
            </div>
        </section>
    </div>

    <!-- Solo para imprimir: el documento solo, fuera del resto de la página -->
    <Teleport to="body">
        <div v-if="printing && result" class="balance-print">
            <BalanceDocument :empresa="empresa" :balance="result" />
        </div>
    </Teleport>
</template>

<style>
/* Global a propósito: al imprimir se oculta todo lo que no sea el balance. */
.balance-print {
    display: none;
}

@media print {
    @page {
        size: letter;
        margin: 18mm 16mm;
    }

    body.imprimiendo-balance > *:not(.balance-print) {
        display: none !important;
    }

    body.imprimiendo-balance {
        background: #fff !important;
    }

    body.imprimiendo-balance .balance-print {
        display: block;
    }

    .balance-print .balance-doc {
        max-width: 160mm;
        margin: 0 auto;
        padding: 0;
        border-radius: 0;
        box-shadow: none;
    }
}
</style>
