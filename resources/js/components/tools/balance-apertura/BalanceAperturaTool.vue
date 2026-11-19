<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, shallowRef, useId, watch } from 'vue';
import { Check, Info, Plus, Printer, X } from 'lucide-vue-next';
import BaseAlert from '../../ui/BaseAlert.vue';
import MoneyField from '../../calculators/shared/MoneyField.vue';
import BalanceDocument from './BalanceDocument.vue';
import { armarBalance, porcentajeTexto } from './balanceApertura';
import { CIUDADES, GRUPOS_ACTIVO_FIJO, LIMITES, TIPOS_EMPRESA } from './catalogos';
import { bs, parseAmount, parseIsoDate, parseWhole, todayIso } from '../../calculators/shared/formatters';
import { btn, errorText, field, hint, label } from '../../subscriptions/shared';

/**
 * Herramienta "Crear balance de apertura" (catálogo de Herramientas).
 * Arma el balance en el navegador con el mismo cálculo que boliviaimpuestos.com (ver balanceApertura.js).
 * El resultado (estado del cuadre, cifras y documento) y la impresión siguen el diseño de su página:
 * se imprime la pantalla completa, sin menú ni botones. No se envía ni se guarda nada.
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
    // Un porcentaje inválido no entra al cálculo (la fila dice "no cuenta").
    return { vacia: false, porcentaje: errores.porcentaje ? 0 : porcentaje, errores };
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

/** Las cuatro cifras del resultado, con los colores de la página original. */
const stats = computed(() => {
    const r = result.value;
    if (!r) return [];
    return [
        { label: 'Activo disponible (caja)', value: r.caja, color: r.caja < 0 ? 'text-[#e24b4a]' : 'text-[#006ba6] dark:text-sky-400' },
        { label: 'Activo fijo', value: r.activoFijo, color: 'text-[#C77E12] dark:text-amber-400' },
        { label: 'Total activo', value: r.activo, color: 'text-[#2d3663] dark:text-slate-200' },
        { label: 'Patrimonio (capital)', value: r.capital, color: 'text-[#1E9E6E] dark:text-emerald-400' },
    ];
});

/** Lo que falta para que el balance se pueda imprimir, en palabras (con el cálculo actual). */
const pending = computed(() => {
    const r = fresh.value;
    const list = [];
    if (capitalError.value) list.push('Escribe el capital total.');
    if (!form.razonSocial.trim()) list.push('Escribe el nombre o razón social de la empresa.');
    if (!fechaOk.value) list.push('Elige la fecha del balance.');
    if (badRows.value) list.push(badRows.value === 1 ? 'Completa o quita la fila marcada en rojo.' : `Completa o quita las ${badRows.value} filas marcadas en rojo.`);
    if (r?.activosSuperanCapital) list.push(`Los activos fijos (${bs(r.activoFijo)}) superan el capital: sube el capital o quita activos.`);
    if (r && form.tipo === 'srl' && !r.porcentajesOk) list.push(`Los porcentajes de los socios suman ${porcentajeTexto(r.sumaPorcentajes)}: deben sumar 100%.`);
    return list;
});

const ready = computed(() => !stale.value && pending.value.length === 0);

// ---------------------------------------------------------------------
// Imprimir o guardar como PDF: la pantalla completa, como la página original
// (sin menú, barra superior ni botones) y siempre en colores claros.
// También funciona con Ctrl+P.
// ---------------------------------------------------------------------
const FONT_HREF = 'https://fonts.bunny.net/css?family=niramit:400,500,600,700&display=swap';
let savedTitle = null;
let wasDark = false;

function loadFont() {
    if (document.querySelector('link[data-fuente="niramit"]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = FONT_HREF;
    link.dataset.fuente = 'niramit';
    document.head.appendChild(link);
}

function onBeforePrint() {
    if (savedTitle !== null) return;
    savedTitle = document.title;
    // El navegador propone el título como nombre del PDF.
    if (empresa.value.razonSocial) document.title = `Balance de apertura - ${empresa.value.razonSocial}`.slice(0, 120);
    // El papel es blanco: en modo oscuro se imprime igual en claro. Sin transiciones, para que
    // los colores claros se apliquen de inmediato y no a mitad de la animación.
    document.documentElement.classList.add('balance-sin-transiciones');
    wasDark = document.documentElement.classList.contains('dark');
    document.documentElement.classList.remove('dark');
    void document.body.offsetHeight;
}

function onAfterPrint() {
    if (savedTitle === null) return;
    document.title = savedTitle;
    savedTitle = null;
    if (wasDark) document.documentElement.classList.add('dark');
    wasDark = false;
    document.documentElement.classList.remove('balance-sin-transiciones');
}

async function printBalance() {
    if (!ready.value) return;
    // La letra del balance tiene que estar cargada antes de imprimir (máximo 2 s de espera).
    try {
        await Promise.race([document.fonts.load('600 13px Niramit'), new Promise((resolve) => setTimeout(resolve, 2000))]);
    } catch {
        // Si no carga, se imprime con la letra de respaldo.
    }
    onBeforePrint();
    window.print();
}

// "afterprint" no llega en todos los navegadores (p. ej. iOS): también se escucha el modo impresión.
const printMedia = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('print') : null;
const onPrintMedia = (event) => (event.matches ? onBeforePrint() : onAfterPrint());

onMounted(() => {
    loadFont();
    document.body.classList.add('con-balance-apertura');
    window.addEventListener('beforeprint', onBeforePrint);
    window.addEventListener('afterprint', onAfterPrint);
    printMedia?.addEventListener?.('change', onPrintMedia);
});

onBeforeUnmount(() => {
    document.body.classList.remove('con-balance-apertura');
    window.removeEventListener('beforeprint', onBeforePrint);
    window.removeEventListener('afterprint', onAfterPrint);
    printMedia?.removeEventListener?.('change', onPrintMedia);
    onAfterPrint();
});

const segment = (active) =>
    `rounded-md px-3 py-2 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 ${
        active ? 'bg-surface font-semibold text-primary shadow-xs' : 'text-muted hover:text-foreground'
    }`;
const rowField = (error) => [field, 'min-w-0', error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30'];
const rowMessage = (read) => Object.values(read.errores).join('; ');
const removeBtn =
    'inline-flex h-9 w-9 items-center justify-center rounded-brand text-muted transition-colors hover:bg-rose-500/10 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 sm:h-8 sm:w-8 print:hidden';
const addBtn =
    'inline-flex items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:border-primary hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 disabled:opacity-50 print:hidden';
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-12 lg:gap-8">
        <!-- ============================ DATOS ============================ -->
        <form class="space-y-6 lg:col-span-5" novalidate @submit.prevent>
            <!-- Empresa (mismo orden que la página original) -->
            <fieldset class="space-y-4">
                <legend class="text-base font-semibold text-foreground">Datos de la empresa</legend>

                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_11rem]">
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
                    <label :for="`${uid}-razon`" :class="label">Nombre / razón social</label>
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
                        <label :for="`${uid}-nit`" :class="label">NIT</label>
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
                        <label :for="`${uid}-ciudad`" :class="label">Ciudad</label>
                        <select :id="`${uid}-ciudad`" v-model="form.ciudad" :class="field" class="mt-1.5">
                            <option v-for="c in CIUDADES" :key="c" :value="c">{{ c }}</option>
                        </select>
                    </div>
                </div>

                <MoneyField :id="`${uid}-capital`" v-model="form.capital" label="Capital total (Bs)" :error="capitalError" />
            </fieldset>

            <!-- Socios (solo S.R.L.) -->
            <fieldset v-if="form.tipo === 'srl'" class="space-y-2">
                <legend class="mb-2 text-base font-semibold text-foreground">Socios y aportes</legend>

                <div v-for="(socio, i) in form.socios" :key="socio.key">
                    <div class="grid grid-cols-[minmax(0,1fr)_4.75rem_minmax(5.5rem,auto)_auto] items-center gap-1.5">
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
                                placeholder="%"
                                :aria-label="`Porcentaje del socio ${i + 1}`"
                                :class="rowField(sociosRead[i].errores.porcentaje)"
                                class="pe-6 text-end tabular-nums"
                            />
                            <span class="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-xs text-muted" aria-hidden="true">%</span>
                        </div>
                        <span class="text-end text-xs tabular-nums text-muted">{{ bs(fresh?.socios[i]?.aporte ?? 0) }}</span>
                        <button type="button" :class="removeBtn" :aria-label="`Quitar socio ${i + 1}`" @click="form.socios.splice(i, 1)">
                            <X class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>
                    <p v-if="Object.keys(sociosRead[i].errores).length" class="mt-1" :class="errorText">Esta fila no cuenta: {{ rowMessage(sociosRead[i]) }}.</p>
                </div>

                <button type="button" :class="addBtn" :disabled="form.socios.length >= LIMITES.socios" @click="addSocio">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Agregar socio
                </button>
                <p
                    v-if="fresh"
                    class="text-xs font-medium"
                    :class="fresh.porcentajesOk ? 'text-[#1E9E6E] dark:text-emerald-400' : 'text-[#e24b4a]'"
                >
                    {{ fresh.porcentajesOk ? '✓ Aportes suman 100%' : `Los % suman ${porcentajeTexto(fresh.sumaPorcentajes)} (deben sumar 100%)` }}
                </p>
            </fieldset>

            <!-- Activos fijos -->
            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold text-foreground">Activos fijos</legend>

                <section v-for="g in GRUPOS_ACTIVO_FIJO" :key="g.clave" class="rounded-brand border border-border bg-background p-3" :aria-label="g.nombre">
                    <div class="mb-2 flex items-baseline justify-between gap-3">
                        <h3 class="text-sm font-semibold text-foreground">{{ g.nombre }}</h3>
                        <span class="text-xs font-semibold tabular-nums text-[#006ba6] dark:text-sky-400">{{ bs(subtotal(g.clave)) }}</span>
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
                                placeholder="P. unit."
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

                <p class="flex gap-2 rounded-brand border border-border bg-background p-3 text-xs leading-relaxed text-muted">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>
                        La <strong class="font-semibold text-foreground">Caja/Bancos</strong> se calcula como capital − activos fijos, para que el
                        <strong class="font-semibold text-foreground">Activo iguale al Patrimonio</strong> (capital) en la apertura.
                    </span>
                </p>
            </fieldset>
        </form>

        <!-- ============================ RESULTADO (diseño de la página original) ============================ -->
        <section class="space-y-4 font-['Niramit',ui-sans-serif,system-ui,sans-serif] lg:col-span-7" aria-label="Balance de apertura">
            <BaseAlert v-if="stale" type="warning" title="Revisa el capital">
                Corrige el capital. Mientras tanto ves el último balance válido, atenuado.
            </BaseAlert>

            <div v-if="result" class="space-y-4 motion-safe:transition-opacity" :class="stale && 'opacity-50'" :aria-hidden="stale ? 'true' : undefined">
                <!-- Estado del cuadre -->
                <div class="balance-color relative overflow-hidden rounded-[14px] bg-[#2d3663] px-5 py-5 text-white sm:px-6" aria-live="polite">
                    <span class="pointer-events-none absolute -end-12 -top-12 h-44 w-44 rounded-full bg-[#2f7fd0]/45 blur-3xl" aria-hidden="true"></span>
                    <p class="relative flex items-center gap-2 text-[13px] text-white/85">
                        <span class="h-2 w-2 rounded-full" :class="result.cuadra ? 'bg-[#57D08F]' : 'bg-[#e24b4a]'" aria-hidden="true"></span>
                        Estado del cuadre
                    </p>
                    <p class="relative mt-1 text-4xl font-bold tracking-tight sm:text-[42px]">{{ result.cuadra ? 'CUADRA' : 'NO CUADRA' }}</p>
                    <p
                        v-if="result.cuadra"
                        class="relative mt-3 inline-flex items-center gap-1.5 rounded-lg bg-[#E3F5EC] px-3 py-1.5 text-sm font-semibold text-[#12805A]"
                    >
                        <Check class="h-4 w-4" aria-hidden="true" /> Activo = Patrimonio = {{ bs(result.capital) }}
                    </p>
                    <p v-else class="relative mt-3 inline-block rounded-lg bg-[#FDECE0] px-3 py-1.5 text-sm font-semibold text-[#B4520E]">
                        Los activos fijos ({{ bs(result.activoFijo) }}) superan el capital. Aumenta el capital.
                    </p>
                </div>

                <!-- Las cuatro cifras -->
                <dl class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                    <div v-for="s in stats" :key="s.label" class="rounded-[12px] border border-border bg-surface px-3 py-2.5">
                        <dt class="text-[11.5px] leading-snug text-muted">{{ s.label }}</dt>
                        <dd class="mt-0.5 text-[15px] font-semibold tabular-nums" :class="s.color">{{ bs(s.value) }}</dd>
                    </div>
                </dl>

                <BaseAlert v-if="pending.length" type="warning" title="Antes de imprimir" class="print:hidden">
                    <ul class="list-disc space-y-0.5 ps-4">
                        <li v-for="(p, i) in pending" :key="i">{{ p }}</li>
                    </ul>
                </BaseAlert>

                <div class="flex flex-col gap-2 font-sans sm:flex-row sm:items-center sm:justify-between print:hidden">
                    <p class="text-xs leading-relaxed text-muted">
                        Se abre la ventana de impresión: elige <strong class="font-semibold text-foreground">Guardar como PDF</strong> para descargarlo.
                    </p>
                    <button type="button" :class="btn.primary" class="shrink-0" :disabled="!ready" @click="printBalance">
                        <Printer class="h-4 w-4" aria-hidden="true" /> Imprimir o guardar PDF
                    </button>
                </div>

                <!-- El balance, como en la página original -->
                <div class="balance-avoid-break rounded-[14px] border border-[#E6ECF4] bg-white px-5 pb-5 pt-[18px]">
                    <h3 class="mb-3 text-[15px] font-bold text-[#16305A]">Balance de apertura</h3>
                    <BalanceDocument :empresa="empresa" :balance="result" />
                </div>

                <p class="font-sans text-xs leading-relaxed text-muted print:hidden">
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
</template>

<style>
.balance-sin-transiciones *,
.balance-sin-transiciones *::before,
.balance-sin-transiciones *::after {
    transition: none !important;
    animation: none !important;
}

/*
 * Impresión, como la página original: la pantalla completa de la herramienta, sin el menú
 * lateral, la barra superior ni los botones. Global a propósito; solo actúa mientras la
 * herramienta está abierta (clase "con-balance-apertura" en <body>).
 */
@media print {
    @page {
        size: letter;
        margin: 12mm;
    }

    body.con-balance-apertura #app-sidebar,
    body.con-balance-apertura .app-shell > .sticky,
    body.con-balance-apertura .app-shell > header {
        display: none !important;
    }

    body.con-balance-apertura,
    body.con-balance-apertura .app-shell {
        padding-left: 0 !important;
        margin-left: 0 !important;
        background: #fff !important;
    }

    /* Los fondos de color (filas del balance, estado del cuadre) se imprimen aunque el navegador quite fondos. */
    body.con-balance-apertura .balance-color {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    body.con-balance-apertura .balance-avoid-break {
        break-inside: avoid;
    }

    body.con-balance-apertura * {
        transition: none !important;
    }
}
</style>
