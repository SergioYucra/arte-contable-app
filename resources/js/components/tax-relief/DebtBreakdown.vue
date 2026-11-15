<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { Loader2 } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import { bs, decimals, periodLabel, shortDate } from './format';

/**
 * "¿De dónde sale este monto?": desglose de UNA deuda, paso a paso.
 * Pide el detalle al backend solo cuando se abre (y al marcar la sanción).
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // Deuda tal como se envió al calcular (impuesto, periodo, fecha_vencimiento, tributo_omitido).
    request: { type: Object, default: null },
    // La misma deuda en el resultado del plan (contado, base en cuotas, tratamiento).
    planDebt: { type: Object, default: null },
    requestDate: { type: String, default: '' },
    breakdownUrl: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const open = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const withPenalty = ref(false);
const loading = ref(false);
const error = ref('');
const detail = ref(null);
// Con qué opción de sanción se pidió el detalle que se está mostrando.
const shownWithPenalty = ref(false);

const rateColors = { 4: 'bg-primary', 6: 'bg-gold', 10: 'bg-rose-500' };

// Cada pedido lleva un número: si llegan respuestas fuera de orden, solo vale la última.
let lastRequestId = 0;

async function load() {
    if (!props.request) return;
    const requestId = ++lastRequestId;
    const askedWithPenalty = withPenalty.value;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(props.breakdownUrl, {
            fecha_solicitud: props.requestDate,
            impuesto: props.request.impuesto,
            periodo: props.request.periodo,
            fecha_vencimiento: props.request.fecha_vencimiento,
            tributo_omitido: props.request.tributo_omitido,
            con_sancion: askedWithPenalty,
        });
        if (requestId !== lastRequestId) return;
        detail.value = data.data;
        shownWithPenalty.value = askedWithPenalty;
    } catch (e) {
        if (requestId !== lastRequestId) return;
        detail.value = null;
        error.value = e.response?.data?.message || 'No se pudo cargar el desglose. Intenta de nuevo.';
    } finally {
        if (requestId === lastRequestId) loading.value = false;
    }
}

watch(() => props.modelValue, (isOpen) => {
    if (!isOpen) return;
    // Al abrir se empieza sin sanción. Si la casilla estaba marcada, el watcher de abajo hace la carga.
    const wasChecked = withPenalty.value;
    withPenalty.value = false;
    if (!wasChecked) load();
});
watch(withPenalty, () => props.modelValue && load());

const maxTrancheInterest = computed(() => Math.max(1, ...(detail.value?.tranches ?? []).map((t) => t.interest)));
const penaltyReady = computed(() => withPenalty.value && shownWithPenalty.value && !loading.value);
const totalWithPenalty = computed(() => (detail.value ? detail.value.total + (penaltyReady.value ? detail.value.penalty : 0) : 0));
const title = computed(() => (props.request ? `${props.request.impuesto} de ${periodLabel(props.request.periodo)}` : 'Desglose'));
</script>

<template>
    <BaseModal v-model="open" :title="`¿De dónde sale? ${title}`" max-width="2xl">
        <div class="space-y-6 text-sm" aria-live="polite">
            <div v-if="loading && !detail" class="flex items-center gap-2 py-10 justify-center text-muted">
                <Loader2 class="h-5 w-5 animate-spin" aria-hidden="true" />
                Calculando el desglose…
            </div>

            <BaseAlert v-else-if="error" type="error">{{ error }}</BaseAlert>

            <template v-else-if="detail">
                <p class="text-muted leading-relaxed">
                    Venció el <strong class="text-foreground">{{ shortDate(detail.due_date) }}</strong> y la calculas al
                    <strong class="text-foreground">{{ shortDate(detail.payment_date) }}</strong>:
                    {{ detail.days_late.toLocaleString('es-BO') }} días de atraso.
                </p>

                <!-- 1. Actualización por UFV -->
                <section>
                    <h3 class="font-semibold text-foreground">1. El tributo se actualiza con la UFV</h3>
                    <p class="mt-1 text-muted leading-relaxed">
                        La UFV sube con la inflación. Tu deuda se convierte a UFV del día que venció
                        ({{ decimals(detail.ufv_due, 5) }}) y se vuelve a pasar a bolivianos con la UFV de hoy
                        ({{ decimals(detail.ufv_payment, 5) }}).
                    </p>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 tabular-nums">
                        <dt class="text-muted">Tributo omitido</dt>
                        <dd class="text-right text-foreground">{{ bs(detail.omitted_tax) }}</dd>
                        <dt class="text-muted">Tributo actualizado</dt>
                        <dd class="text-right text-foreground">{{ bs(detail.updated_tax) }}</dd>
                        <dt class="font-medium text-foreground">Mantenimiento de valor (la diferencia)</dt>
                        <dd class="text-right font-semibold text-foreground">{{ bs(detail.value_adjustment) }}</dd>
                    </dl>
                </section>

                <!-- 2. Interés por tramos -->
                <section v-if="detail.tranches.length">
                    <h3 class="font-semibold text-foreground">2. Se suma interés, más caro cuanto más años pasan</h3>
                    <p class="mt-1 text-muted leading-relaxed">
                        4% al año los primeros 4 años, 6% del quinto al séptimo y 10% desde el octavo (Art. 47 del Código Tributario).
                    </p>
                    <ul class="mt-3 space-y-2.5">
                        <li v-for="t in detail.tranches" :key="t.from" class="grid grid-cols-[3.25rem_1fr_auto] items-center gap-3 tabular-nums">
                            <span class="text-xs font-semibold text-foreground">{{ decimals(t.rate_pct, 0) }}% anual</span>
                            <span class="relative h-2.5 rounded-full bg-border/70 overflow-hidden" :title="`${shortDate(t.from)} al ${shortDate(t.to)}`">
                                <span
                                    class="absolute inset-y-0 start-0 rounded-full motion-safe:transition-[width] motion-safe:duration-500"
                                    :class="rateColors[t.rate_pct] || 'bg-primary'"
                                    :style="{ width: `${Math.max(3, (t.interest / maxTrancheInterest) * 100)}%` }"
                                ></span>
                            </span>
                            <span class="text-right text-foreground">
                                {{ bs(t.interest) }}
                                <span class="block text-[11px] text-muted">{{ t.days.toLocaleString('es-BO') }} días</span>
                            </span>
                        </li>
                    </ul>
                    <p class="mt-2 flex justify-between font-medium text-foreground tabular-nums">
                        <span>Interés total</span><span>{{ bs(detail.interest) }}</span>
                    </p>
                </section>

                <!-- 3. Total sin alivio -->
                <section class="rounded-brand border border-border bg-background p-4">
                    <h3 class="font-semibold text-foreground">3. Lo que debes hoy sin alivio</h3>
                    <label class="mt-3 flex items-start gap-2.5 cursor-pointer">
                        <input v-model="withPenalty" type="checkbox" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                        <span class="text-muted leading-snug">
                            Es una deuda que el SIN ya me determinó (con sanción por omisión del 60%)
                        </span>
                    </label>
                    <p class="mt-3 text-muted tabular-nums">
                        {{ bs(detail.omitted_tax) }} de tributo + {{ bs(detail.value_adjustment) }} de mantenimiento de valor
                        + {{ bs(detail.interest) }} de interés<template v-if="penaltyReady"> + <span class="text-rose-600 dark:text-rose-400">{{ bs(detail.penalty) }} de sanción</span></template>
                    </p>
                    <p v-if="loading" class="mt-1 inline-flex items-center gap-2 text-muted">
                        <Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" /> Calculando…
                    </p>
                    <p v-else class="mt-1 font-serif text-2xl text-foreground tabular-nums">{{ bs(totalWithPenalty) }}</p>
                </section>

                <!-- Con la ley -->
                <section v-if="planDebt">
                    <h3 class="font-semibold text-foreground">Con la Ley 1733</h3>
                    <p v-if="planDebt.treatment === 'condonado'" class="mt-1 text-emerald-700 dark:text-emerald-400 leading-relaxed">
                        Esta deuda se perdona completa: no pagas nada por ella.
                    </p>
                    <dl v-else class="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 tabular-nums">
                        <dt class="text-muted">Al contado: tributo + la mitad del mantenimiento de valor</dt>
                        <dd class="text-right font-semibold text-emerald-700 dark:text-emerald-400">{{ bs(planDebt.cash) }}</dd>
                        <dt class="text-muted">En cuotas: tributo actualizado solo hasta el día de la ley</dt>
                        <dd class="text-right font-semibold text-primary">{{ bs(planDebt.plan_base) }}</dd>
                    </dl>
                    <p class="mt-2 text-xs text-muted">Los intereses y la sanción se perdonan en los dos casos.</p>
                </section>
            </template>
        </div>
    </BaseModal>
</template>
