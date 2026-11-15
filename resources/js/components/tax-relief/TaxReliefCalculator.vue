<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import axios from 'axios';
import { CalendarCheck, ChevronDown, Loader2, Plus, Scale, Trash2 } from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';
import DebtBreakdown from './DebtBreakdown.vue';
import { bs, decimals, parseAmount, periodLabel, shortDate } from './format';

/**
 * Calculadora de alivio tributario (Ley 1733) para estudiantes.
 * Habla solo con nuestro backend; el backend es quien consulta a boliviaimpuestos.com.
 */
const props = defineProps({
    enabled: { type: Boolean, default: true },
    today: { type: String, required: true },
    maxDebts: { type: Number, default: 10 },
    maxInstallments: { type: Number, default: 36 },
    calculateUrl: { type: String, required: true },
    breakdownUrl: { type: String, required: true },
    dueDateUrl: { type: String, required: true },
});

const TAXES = ['IVA', 'IT', 'IUE', 'RC-IVA', 'Otro'];

let nextKey = 1;
function newDebt() {
    return { key: nextKey++, impuesto: 'IVA', periodo: '', nit_digito: '', fecha_vencimiento: '', tributo_omitido: '', dueSuggested: false, dueLookup: '' };
}

const form = reactive({
    fecha_solicitud: props.today,
    garantia: 'efectivo',
    cuotas: Math.min(12, props.maxInstallments),
    deudas: [newDebt()],
});

const plan = ref(null);
const sentRequest = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const errorDetail = ref('');
const fieldErrors = ref({});
const showSchedule = ref(false);
const resultsRef = ref(null);
const breakdownOpen = ref(false);
const breakdownIndex = ref(0);

// ---------------------------------------------------------------------
// Formulario
// ---------------------------------------------------------------------
function err(path) {
    return fieldErrors.value[path]?.[0] ?? '';
}

function addDebt() {
    if (form.deudas.length < props.maxDebts) form.deudas.push(newDebt());
}

function removeDebt(index) {
    if (form.deudas.length > 1) form.deudas.splice(index, 1);
}

function onNitInput(debt, event) {
    debt.nit_digito = String(event.target.value).replace(/\D/g, '').slice(-1);
    event.target.value = debt.nit_digito;
    scheduleDueLookup(debt);
}

function onDueDateInput(debt) {
    debt.dueSuggested = false;
}

// Vencimiento sugerido según el calendario del SIN (solo con el último dígito del NIT).
const lookupTimers = new Map();
function scheduleDueLookup(debt) {
    clearTimeout(lookupTimers.get(debt.key));
    debt.dueLookup = '';
    if (debt.impuesto === 'Otro' || !/^\d{4}-\d{2}$/.test(debt.periodo) || !/^\d$/.test(debt.nit_digito)) return;
    lookupTimers.set(debt.key, setTimeout(() => lookupDueDate(debt), 400));
}

async function lookupDueDate(debt) {
    debt.dueLookup = 'loading';
    try {
        const { data } = await axios.get(props.dueDateUrl, {
            params: { impuesto: debt.impuesto, periodo: debt.periodo, nit_digito: debt.nit_digito },
        });
        if (data.data?.date) {
            if (!debt.fecha_vencimiento || debt.dueSuggested) {
                debt.fecha_vencimiento = data.data.date;
                debt.dueSuggested = true;
            }
            debt.dueLookup = '';
        } else {
            debt.dueLookup = 'none';
        }
    } catch {
        debt.dueLookup = 'error';
    }
}

function buildPayload() {
    return {
        fecha_solicitud: form.fecha_solicitud,
        garantia: form.garantia,
        cuotas: Number(form.cuotas),
        // Solo estos 4 campos por deuda: el backend rechaza cualquier otro.
        deudas: form.deudas.map((d) => ({
            impuesto: d.impuesto,
            periodo: d.periodo,
            fecha_vencimiento: d.fecha_vencimiento,
            tributo_omitido: parseAmount(d.tributo_omitido) || d.tributo_omitido,
        })),
    };
}

async function calculate({ scroll = true } = {}) {
    if (loading.value) return;
    loading.value = true;
    errorMessage.value = '';
    errorDetail.value = '';
    fieldErrors.value = {};

    const payload = buildPayload();
    try {
        const { data } = await axios.post(props.calculateUrl, payload);
        plan.value = data.data;
        // Mostrar siempre las cuotas con las que REALMENTE se calculó.
        if (plan.value.eligible_count > 0 && plan.value.installments !== Number(form.cuotas)) {
            form.cuotas = plan.value.installments;
        }
        sentRequest.value = { ...payload, cuotas: Number(form.cuotas) };
        if (scroll) {
            await nextTick();
            const top = resultsRef.value?.getBoundingClientRect().top ?? 0;
            if (top > window.innerHeight * 0.6) {
                const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                resultsRef.value.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
            }
        }
    } catch (e) {
        const status = e.response?.status;
        if (status === 422 && e.response.data?.errors) {
            fieldErrors.value = e.response.data.errors;
            errorMessage.value = 'Revisa los datos marcados en rojo.';
        } else if (status === 419 || status === 401) {
            errorMessage.value = 'Tu sesión expiró. Recarga la página para seguir calculando.';
        } else if (!e.response) {
            errorMessage.value = 'No hay conexión con el sistema. Revisa tu internet y vuelve a calcular.';
        } else {
            errorMessage.value = e.response.data?.message || 'El sistema tuvo un problema al calcular. Intenta de nuevo en unos minutos.';
            errorDetail.value = e.response.data?.detail || '';
        }
    } finally {
        loading.value = false;
    }
}

function onInstallmentsChange() {
    if (plan.value && plan.value.eligible_count > 0) calculate({ scroll: false });
}

const sliderMax = computed(() => {
    const allowed = plan.value?.max_installments_allowed;
    return allowed ? Math.max(1, Math.min(props.maxInstallments, allowed)) : props.maxInstallments;
});

// ---------------------------------------------------------------------
// Resultados
// ---------------------------------------------------------------------
const sums = computed(() => {
    const debts = plan.value?.debts ?? [];
    const total = (key) => debts.reduce((acc, d) => acc + (Number(d[key]) || 0), 0);
    const eligibleTax = debts.filter((d) => d.treatment !== 'condonado').reduce((acc, d) => acc + (Number(d.omitted_tax) || 0), 0);
    return { tax: total('omitted_tax'), adjustment: total('value_adjustment'), interest: total('interest'), eligibleTax };
});

// El resultado en pantalla ya no corresponde a lo que dice el formulario.
const isStale = computed(() => !!plan.value && !!sentRequest.value
    && JSON.stringify(buildPayload()) !== JSON.stringify(sentRequest.value));

const allForgiven = computed(() => plan.value && plan.value.eligible_count === 0);
const scaleMax = computed(() => Math.max(1, plan.value?.total_without_relief ?? 0, plan.value?.plan_final_total ?? 0));
const width = (value) => `${(Math.max(0, value) / scaleMax.value) * 100}%`;
const afterLawDebts = computed(() => (plan.value?.debts ?? []).filter((d) => d.due_after_law && d.treatment !== 'condonado'));

function openBreakdown(index) {
    breakdownIndex.value = index;
    breakdownOpen.value = true;
}
</script>

<template>
    <BaseAlert v-if="!enabled" type="warning" title="Calculadora desactivada">
        La calculadora está desactivada por el momento. Vuelve a intentarlo más tarde.
    </BaseAlert>

    <div v-else class="grid gap-5 lg:grid-cols-12 lg:items-start">
        <!-- ============================ FORMULARIO ============================ -->
        <form class="lg:col-span-5 rounded-brand border border-border bg-surface shadow-xs" novalidate @submit.prevent="calculate()">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-foreground">Tu deuda con impuestos</h2>
                <p class="mt-0.5 text-xs text-muted">Con estos datos comparamos lo que pagarías al contado y en cuotas.</p>
            </div>

            <div class="space-y-5 p-5">
                <div>
                    <label for="tr-request-date" class="block text-sm font-medium text-foreground">Fecha en que pagarías o pedirías el plan</label>
                    <input id="tr-request-date" v-model="form.fecha_solicitud" type="date" class="mt-1.5 block w-full rounded-lg border-border bg-background text-sm text-foreground focus:border-primary focus:ring-primary" :aria-invalid="!!err('fecha_solicitud')" />
                    <p v-if="err('fecha_solicitud')" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ err('fecha_solicitud') }}</p>
                </div>

                <!-- Deudas -->
                <fieldset v-for="(debt, i) in form.deudas" :key="debt.key" class="rounded-brand border border-border bg-background/60 p-4">
                    <legend class="flex w-full items-center justify-between px-1 text-sm font-semibold text-foreground">
                        <span>{{ form.deudas.length > 1 ? `Deuda ${i + 1}` : 'La deuda' }}</span>
                    </legend>
                    <button
                        v-if="form.deudas.length > 1"
                        type="button"
                        class="float-right -mt-1 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted hover:bg-rose-500/10 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400/50"
                        @click="removeDebt(i)"
                    >
                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /> Quitar
                    </button>

                    <div class="grid grid-cols-2 gap-3 clear-both">
                        <div>
                            <label :for="`tr-tax-${debt.key}`" class="block text-xs font-medium text-foreground">Impuesto</label>
                            <select :id="`tr-tax-${debt.key}`" v-model="debt.impuesto" class="mt-1 block w-full rounded-lg border-border bg-surface text-sm text-foreground focus:border-primary focus:ring-primary" @change="scheduleDueLookup(debt)">
                                <option v-for="tax in TAXES" :key="tax" :value="tax">{{ tax }}</option>
                            </select>
                            <p v-if="err(`deudas.${i}.impuesto`)" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ err(`deudas.${i}.impuesto`) }}</p>
                        </div>
                        <div>
                            <label :for="`tr-period-${debt.key}`" class="block text-xs font-medium text-foreground">Periodo (mes)</label>
                            <input :id="`tr-period-${debt.key}`" v-model="debt.periodo" type="month" placeholder="2019-01" class="mt-1 block w-full rounded-lg border-border bg-surface text-sm text-foreground focus:border-primary focus:ring-primary" :aria-invalid="!!err(`deudas.${i}.periodo`)" @change="scheduleDueLookup(debt)" />
                            <p v-if="err(`deudas.${i}.periodo`)" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ err(`deudas.${i}.periodo`) }}</p>
                        </div>
                        <div>
                            <label :for="`tr-nit-${debt.key}`" class="block text-xs font-medium text-foreground">Último dígito del NIT</label>
                            <input :id="`tr-nit-${debt.key}`" :value="debt.nit_digito" type="text" inputmode="numeric" maxlength="1" autocomplete="off" placeholder="Opcional" class="mt-1 block w-full rounded-lg border-border bg-surface text-sm text-foreground focus:border-primary focus:ring-primary" :disabled="debt.impuesto === 'Otro'" @input="onNitInput(debt, $event)" />
                        </div>
                        <div>
                            <label :for="`tr-due-${debt.key}`" class="block text-xs font-medium text-foreground">Fecha de vencimiento</label>
                            <input :id="`tr-due-${debt.key}`" v-model="debt.fecha_vencimiento" type="date" :max="form.fecha_solicitud || undefined" class="mt-1 block w-full rounded-lg border-border bg-surface text-sm text-foreground focus:border-primary focus:ring-primary" :aria-invalid="!!err(`deudas.${i}.fecha_vencimiento`)" @input="onDueDateInput(debt)" />
                        </div>
                    </div>
                    <p class="mt-1.5 min-h-[1rem] text-[11px] leading-snug" aria-live="polite">
                        <span v-if="err(`deudas.${i}.fecha_vencimiento`)" class="text-rose-600 dark:text-rose-400">{{ err(`deudas.${i}.fecha_vencimiento`) }}</span>
                        <span v-else-if="debt.dueLookup === 'loading'" class="text-muted">Buscando la fecha en el calendario del SIN…</span>
                        <span v-else-if="debt.dueSuggested" class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><CalendarCheck class="h-3 w-3" aria-hidden="true" /> Fecha según el calendario del SIN</span>
                        <span v-else-if="debt.dueLookup === 'none'" class="text-muted">No encontramos la fecha: escríbela tú.</span>
                        <span v-else-if="debt.dueLookup === 'error'" class="text-muted">No pudimos buscar la fecha ahora: escríbela tú.</span>
                        <span v-else-if="debt.impuesto !== 'Otro'" class="text-muted">Con el último dígito del NIT te sugerimos la fecha. Solo usamos ese dígito.</span>
                    </p>

                    <div class="mt-2">
                        <label :for="`tr-amount-${debt.key}`" class="block text-xs font-medium text-foreground">Tributo omitido (lo que no se pagó)</label>
                        <div class="relative mt-1">
                            <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-muted">Bs</span>
                            <input :id="`tr-amount-${debt.key}`" v-model="debt.tributo_omitido" type="text" inputmode="decimal" placeholder="10.000" autocomplete="off" class="block w-full rounded-lg border-border bg-surface ps-10 text-sm text-foreground tabular-nums focus:border-primary focus:ring-primary" :aria-invalid="!!err(`deudas.${i}.tributo_omitido`)" />
                        </div>
                        <p v-if="err(`deudas.${i}.tributo_omitido`)" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ err(`deudas.${i}.tributo_omitido`) }}</p>
                    </div>
                </fieldset>

                <button
                    v-if="form.deudas.length < maxDebts"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted hover:border-primary hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    @click="addDebt"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" /> Agregar otra deuda
                </button>
                <p v-if="err('deudas')" class="text-xs text-rose-600 dark:text-rose-400">{{ err('deudas') }}</p>

                <!-- Garantía -->
                <div>
                    <span id="tr-guarantee" class="block text-sm font-medium text-foreground">Si pagas en cuotas, la garantía sería</span>
                    <div class="mt-1.5 grid grid-cols-2 gap-1 rounded-lg border border-border bg-background p-1" role="radiogroup" aria-labelledby="tr-guarantee">
                        <button
                            v-for="option in [{ value: 'efectivo', label: 'En efectivo (5%)' }, { value: 'hipotecaria', label: 'Con un inmueble' }]"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.garantia === option.value"
                            class="rounded-md px-3 py-2 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                            :class="form.garantia === option.value ? 'bg-surface font-semibold text-primary shadow-xs' : 'text-muted hover:text-foreground'"
                            @click="form.garantia = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <!-- Cuotas -->
                <div>
                    <label for="tr-installments" class="flex items-baseline justify-between text-sm font-medium text-foreground">
                        <span>Número de cuotas</span>
                        <span class="font-serif text-xl tabular-nums text-primary">{{ form.cuotas }}</span>
                    </label>
                    <input id="tr-installments" v-model.number="form.cuotas" type="range" min="1" :max="sliderMax" class="mt-2 w-full h-1.5 cursor-pointer appearance-none rounded-lg bg-border accent-primary" @change="onInstallmentsChange" />
                    <p class="mt-1 text-[11px] text-muted">
                        <template v-if="plan && sliderMax < maxInstallments">Hasta {{ sliderMax }}: cada cuota debe ser de al menos {{ bs(plan.min_installment_bs) }}.</template>
                        <template v-else>Hasta {{ sliderMax }} cuotas mensuales.</template>
                    </p>
                </div>

                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2"
                    :disabled="loading"
                >
                    <Loader2 v-if="loading" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    {{ loading ? 'Calculando…' : 'Calcular' }}
                </button>
            </div>
        </form>

        <!-- ============================ RESULTADOS ============================ -->
        <section ref="resultsRef" class="lg:col-span-7 space-y-5 scroll-mt-4" aria-live="polite" aria-label="Resultado">
            <BaseAlert v-if="errorMessage" type="error" :title="errorMessage">
                <template v-if="errorDetail">El servicio de cálculo dijo: «{{ errorDetail }}»</template>
            </BaseAlert>

            <!-- Antes de calcular -->
            <div v-if="!plan" class="rounded-brand border border-border bg-paper p-6 sm:p-8">
                <Scale class="h-8 w-8 text-primary" aria-hidden="true" />
                <h2 class="mt-4 font-serif text-2xl leading-tight text-foreground">La Ley 1733 te da dos caminos para ponerte al día</h2>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-emerald-600/25 bg-surface p-4">
                        <dt class="font-semibold text-emerald-700 dark:text-emerald-400">Pagar al contado</dt>
                        <dd class="mt-1 text-sm leading-relaxed text-muted">Pagas lo que debías más la mitad de lo que subió con la UFV. Sin intereses ni multas.</dd>
                    </div>
                    <div class="rounded-lg border border-primary/25 bg-surface p-4">
                        <dt class="font-semibold text-primary">Pagar en cuotas</dt>
                        <dd class="mt-1 text-sm leading-relaxed text-muted">Hasta {{ maxInstallments }} cuotas mensuales. También sin intereses viejos ni multas, pero cada cuota se actualiza un poco.</dd>
                    </div>
                </dl>
                <p class="mt-5 text-sm text-muted">Completa los datos de tu deuda y presiona <strong class="text-foreground">Calcular</strong> para ver cuánto pagarías en cada caso.</p>
            </div>

            <BaseAlert v-if="isStale && !loading" type="warning" title="Cambiaste los datos">
                Lo que ves abajo es del cálculo anterior. Presiona <strong class="text-foreground">Calcular</strong> para actualizarlo.
            </BaseAlert>

            <div v-if="plan" class="space-y-5 motion-safe:transition-opacity" :class="isStale && 'opacity-50'">
                <!-- Todo perdonado -->
                <div v-if="allForgiven" class="rounded-brand border border-emerald-600/30 bg-emerald-500/10 p-6 sm:p-8">
                    <h2 class="font-serif text-2xl leading-tight text-foreground">No pagas nada: la ley perdona estas deudas completas</h2>
                    <p class="mt-2 text-sm leading-relaxed text-muted">
                        Sin la ley deberías {{ bs(plan.total_without_relief) }}. La ley perdona al 100% las deudas de ciertos periodos,
                        y las tuyas están entre ellas.
                    </p>
                </div>

                <!-- Cómo se achica la deuda -->
                <div v-else class="rounded-brand border border-border bg-paper p-5 sm:p-7">
                    <p class="text-sm text-muted">Hoy debes, sin alivio,</p>
                    <p class="font-serif text-4xl sm:text-5xl leading-none tracking-tight text-foreground tabular-nums">{{ bs(plan.total_without_relief) }}</p>
                    <p class="mt-3 inline-flex flex-wrap items-baseline gap-x-1.5 rounded-full bg-gold/25 px-3 py-1 text-sm text-foreground">
                        Pagando al contado ahorras <strong class="tabular-nums">{{ bs(plan.savings_cash) }}</strong>
                    </p>

                    <div class="mt-6 space-y-4">
                        <!-- Sin alivio -->
                        <div>
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="text-foreground">Sin alivio</span>
                                <span class="tabular-nums text-foreground">{{ bs(plan.total_without_relief) }}</span>
                            </div>
                            <div class="mt-1.5 flex h-3.5 overflow-hidden rounded-full bg-border/50" role="img" :aria-label="`Sin alivio: ${bs(plan.total_without_relief)}`">
                                <span class="h-full bg-secondary dark:bg-slate-300 motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(sums.tax) }"></span>
                                <span class="h-full bg-gold motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(sums.adjustment) }"></span>
                                <span class="h-full bg-amber-600 motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(sums.interest) }"></span>
                            </div>
                        </div>
                        <!-- Al contado -->
                        <div>
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-emerald-700 dark:text-emerald-400">Al contado</span>
                                <span class="tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">{{ bs(plan.total_cash) }}</span>
                            </div>
                            <div class="mt-1.5 flex h-3.5 overflow-hidden rounded-full bg-border/50" role="img" :aria-label="`Al contado: ${bs(plan.total_cash)}`">
                                <span class="h-full bg-emerald-600 motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(plan.total_cash) }"></span>
                                <span class="tr-forgiven h-full" :style="{ width: width(plan.total_without_relief - plan.total_cash) }"></span>
                            </div>
                        </div>
                        <!-- En cuotas -->
                        <div>
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-primary">En {{ plan.installments }} cuotas, sumando todo</span>
                                <span class="tabular-nums font-semibold text-primary">{{ bs(plan.plan_final_total) }}</span>
                            </div>
                            <div class="mt-1.5 flex h-3.5 overflow-hidden rounded-full bg-border/50" role="img" :aria-label="`En cuotas: ${bs(plan.plan_final_total)}`">
                                <span class="h-full bg-primary motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(plan.plan_base) }"></span>
                                <span class="h-full bg-primary/45 motion-safe:transition-[width] motion-safe:duration-700" :style="{ width: width(plan.plan_final_total - plan.plan_base) }"></span>
                                <span class="tr-forgiven h-full" :style="{ width: width(plan.total_without_relief - plan.plan_final_total) }"></span>
                            </div>
                        </div>
                    </div>

                    <ul class="mt-5 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted">
                        <li class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-secondary dark:bg-slate-300"></span>Tributo omitido</li>
                        <li class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gold"></span>Subida por UFV</li>
                        <li class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-amber-600"></span>Intereses</li>
                        <li class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-primary/45"></span>Lo que suman las cuotas al actualizarse</li>
                        <li class="inline-flex items-center gap-1.5"><span class="tr-forgiven h-2.5 w-2.5 rounded-sm ring-1 ring-muted/40"></span>Lo que perdona la ley</li>
                    </ul>
                </div>

                <!-- Avisos -->
                <BaseAlert v-if="plan.forgiven_count > 0 && !allForgiven" type="success" title="Buena noticia">
                    {{ plan.forgiven_count === 1 ? 'Una de tus deudas se perdona' : `${plan.forgiven_count} de tus deudas se perdonan` }}
                    completa: te ahorras {{ bs(plan.total_forgiven) }}.
                </BaseAlert>
                <BaseAlert v-if="afterLawDebts.length" type="warning" title="Revisa esta deuda">
                    Venció después de que se publicó la ley ({{ shortDate(plan.law_published_on) }}): es posible que el alivio no le corresponda.
                </BaseAlert>
                <BaseAlert v-for="(notice, n) in plan.notices" :key="n" type="info">{{ notice }}</BaseAlert>

                <!-- Las dos opciones -->
                <div v-if="!allForgiven" class="grid gap-4 sm:grid-cols-2">
                    <article class="relative rounded-brand border-2 border-emerald-600/40 bg-surface p-5">
                        <span class="absolute -top-3 start-4 rounded-full bg-emerald-600 px-2.5 py-0.5 text-xs font-semibold text-white">Lo más barato</span>
                        <h3 class="font-semibold text-foreground">Al contado</h3>
                        <p class="mt-1 font-serif text-3xl text-emerald-700 dark:text-emerald-400 tabular-nums">{{ bs(plan.total_cash) }}</p>
                        <p class="text-xs text-muted">en un solo pago</p>
                        <dl class="mt-4 space-y-1.5 text-sm tabular-nums">
                            <div class="flex justify-between gap-3"><dt class="text-muted">Tributo omitido</dt><dd class="whitespace-nowrap text-foreground">{{ bs(sums.eligibleTax) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-muted">Mitad de la subida por UFV</dt><dd class="whitespace-nowrap text-foreground">{{ bs(plan.total_cash - sums.eligibleTax) }}</dd></div>
                            <div class="flex justify-between gap-3 text-muted"><dt>Intereses y multas</dt><dd class="whitespace-nowrap">se perdonan</dd></div>
                        </dl>
                    </article>

                    <article class="rounded-brand border border-primary/30 bg-surface p-5">
                        <h3 class="font-semibold text-foreground">En cuotas</h3>
                        <p class="mt-1 font-serif text-3xl text-primary tabular-nums">{{ bs(plan.plan_base) }}</p>
                        <p class="text-xs text-muted">al empezar, en {{ plan.installments }} cuotas de unos {{ bs(plan.average_installment) }}</p>
                        <dl class="mt-4 space-y-1.5 text-sm tabular-nums">
                            <div class="flex justify-between gap-3"><dt class="text-muted">Pago inicial ({{ decimals(plan.down_payment_pct, 0) }}%)</dt><dd class="whitespace-nowrap text-foreground">{{ bs(plan.down_payment) }}</dd></div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-muted">Garantía</dt>
                                <dd class="whitespace-nowrap text-foreground">{{ plan.guarantee > 0 ? bs(plan.guarantee) : 'con inmueble' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3"><dt class="text-muted">Saldo en cuotas</dt><dd class="whitespace-nowrap text-foreground">{{ bs(plan.balance) }}</dd></div>
                        </dl>
                        <p class="mt-4 border-t border-border pt-3 text-sm text-foreground">
                            Al final pagarás <strong class="tabular-nums">{{ bs(plan.plan_final_total) }}</strong>:
                            <span class="tabular-nums">{{ bs(plan.extra_vs_cash) }}</span> más que al contado (+{{ decimals(plan.extra_vs_cash_pct, 1) }}%).
                        </p>
                    </article>
                </div>

                <!-- Cronograma -->
                <div v-if="plan.schedule.length" class="rounded-brand border border-border bg-surface">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/50"
                        :aria-expanded="showSchedule"
                        aria-controls="tr-schedule"
                        @click="showSchedule = !showSchedule"
                    >
                        <span>
                            <span class="block font-semibold text-foreground">Ver las {{ plan.schedule.length }} cuotas</span>
                            <span class="block text-xs text-muted">Cada una vence el último día hábil del mes y sube un poco con la UFV.</span>
                        </span>
                        <ChevronDown class="h-5 w-5 shrink-0 text-muted transition-transform" :class="showSchedule && 'rotate-180'" aria-hidden="true" />
                    </button>
                    <div v-show="showSchedule" id="tr-schedule" class="border-t border-border">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm tabular-nums">
                                <thead class="bg-background text-xs text-muted">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 text-left font-medium">N.º</th>
                                        <th scope="col" class="px-3 py-2 text-left font-medium">Vence</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Cuota (Bs)</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Capital</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Subida UFV</th>
                                        <th scope="col" class="px-3 py-2 text-right font-medium">Interés</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="row in plan.schedule" :key="row.number">
                                        <td class="px-3 py-2 text-muted">{{ row.number }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-foreground">
                                            {{ shortDate(row.due_date) }}
                                            <span v-if="row.projected" class="ms-1 rounded bg-gold/25 px-1.5 py-0.5 text-[10px] text-foreground" title="Usa una UFV estimada">estimada</span>
                                            <span v-else-if="row.overdue" class="ms-1 rounded bg-rose-500/15 px-1.5 py-0.5 text-[10px] text-rose-700 dark:text-rose-300">ya venció</span>
                                        </td>
                                        <!-- Sin "Bs" en cada celda (va en el encabezado) para que las cifras no se partan en dos líneas. -->
                                        <td class="px-3 py-2 text-right whitespace-nowrap font-semibold text-foreground">{{ decimals(row.total) }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap text-muted">{{ decimals(row.capital) }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap text-muted">{{ decimals(row.value_adjustment) }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap text-muted">{{ decimals(row.interest) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-background font-semibold text-foreground">
                                    <tr>
                                        <td colspan="2" class="px-3 py-2">Total de las cuotas</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ decimals(plan.schedule_total) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="px-5 py-3 text-xs leading-relaxed text-muted">
                            Las cuotas "estimadas" usan una UFV proyectada con la inflación de los últimos 12 meses
                            ({{ decimals(plan.inflation_estimate_pct, 2) }}%). El monto exacto lo fija el SIN el día que pagas.
                        </p>
                    </div>
                </div>

                <!-- Por deuda -->
                <div class="rounded-brand border border-border bg-surface">
                    <h3 class="border-b border-border px-5 py-3 text-sm font-semibold text-foreground">{{ plan.debts.length > 1 ? 'Tus deudas, una por una' : 'Tu deuda' }}</h3>
                    <ul class="divide-y divide-border">
                        <li v-for="(debt, i) in plan.debts" :key="i" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">{{ debt.tax }} de {{ periodLabel(debt.period) }}</p>
                                <p class="text-xs text-muted">
                                    <span v-if="debt.treatment === 'condonado'" class="font-medium text-emerald-700 dark:text-emerald-400">Se perdona completa</span>
                                    <template v-else>Al contado {{ bs(debt.cash) }}, en cuotas {{ bs(debt.plan_base) }}</template>
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                                @click="openBreakdown(i)"
                            >
                                ¿De dónde sale?
                            </button>
                        </li>
                    </ul>
                </div>

                <p class="text-xs leading-relaxed text-muted">
                    Cálculo referencial hecho con la calculadora de
                    <a href="https://boliviaimpuestos.com/maletin/fap-alivio.php" target="_blank" rel="noopener noreferrer" class="text-primary underline-offset-2 hover:underline">boliviaimpuestos.com</a>.
                    El monto oficial te lo da el SIN al momento de pagar.
                </p>
            </div>
        </section>

        <DebtBreakdown
            v-model="breakdownOpen"
            :request="sentRequest?.deudas[breakdownIndex] ?? null"
            :plan-debt="plan?.debts[breakdownIndex] ?? null"
            :request-date="sentRequest?.fecha_solicitud ?? ''"
            :breakdown-url="breakdownUrl"
        />
    </div>
</template>

<style scoped>
/* Rayado = lo que la ley perdona. */
.tr-forgiven {
    background-image: repeating-linear-gradient(
        135deg,
        rgb(var(--color-muted) / 0.38) 0 4px,
        transparent 4px 8px
    );
}
</style>
