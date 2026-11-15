<script setup>
/**
 * Crear códigos de acceso, con un plan o eligiendo los días. Al terminar, muestra los códigos
 * para copiarlos o descargarlos en una lista.
 */
import { ref, watch, computed, inject } from 'vue';
import axios from 'axios';
import { Copy, Check, Download } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import { PANEL_CONTEXT, btn, field, label, hint, errorText, readError, durationLabel, downloadText, plural } from './shared';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'generated']);
const { plans, urls, notify } = inject(PANEL_CONTEXT);

const DAY_SHORTCUTS = [7, 15, 30, 90, 180, 365];

const activePlans = computed(() => plans.value.filter((p) => !p.is_archived));

const form = ref({});
const errors = ref({});
const saving = ref(false);
const created = ref([]);
const copied = ref(null);

// Mismas reglas que el servidor: 6 a 32 letras sin tilde, números o guiones; sin guion al inicio o al final.
const CUSTOM_CODE_PATTERN = /^[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])?$/;

function reset() {
    form.value = {
        kind: 'random', // 'random' | 'custom'
        customCode: '',
        mode: activePlans.value.length ? 'plan' : 'days',
        planId: activePlans.value[0]?.id ?? '',
        days: 30,
        quantity: 1,
        reference: '',
    };
    errors.value = {};
    created.value = [];
    copied.value = null;
}

watch(() => props.modelValue, (open) => open && reset(), { immediate: true });

watch(
    () => [form.value.customCode, form.value.kind],
    () => {
        if (errors.value.custom_code) {
            const { custom_code, ...rest } = errors.value;
            errors.value = rest;
        }
    }
);

const selectedPlan = computed(() => activePlans.value.find((p) => String(p.id) === String(form.value.planId)) ?? null);
const isCustom = computed(() => form.value.kind === 'custom');
const normalizedCustomCode = computed(() => (form.value.customCode || '').trim().toUpperCase());
const howMany = computed(() => (isCustom.value ? 1 : Number(form.value.quantity) || 1));
const daysGiven = computed(() => (form.value.mode === 'plan' ? selectedPlan.value?.duration_days : Number(form.value.days)) || 0);

function validate() {
    const next = {};
    if (form.value.mode === 'plan' && !selectedPlan.value) next.subscription_plan_id = 'Selecciona un plan.';
    if (form.value.mode === 'days') {
        const d = Number(form.value.days);
        if (!Number.isInteger(d) || d < 1 || d > 1825) next.duration_days = 'Indica entre 1 día y 5 años (1825 días).';
    }
    if (isCustom.value) {
        const code = normalizedCustomCode.value;
        if (code.length < 6 || code.length > 32) next.custom_code = 'El código debe tener entre 6 y 32 caracteres.';
        else if (!CUSTOM_CODE_PATTERN.test(code)) next.custom_code = 'Usa solo letras (sin tildes ni ñ), números y guiones, sin espacios.';
    } else {
        const q = Number(form.value.quantity);
        if (!Number.isInteger(q) || q < 1 || q > 100) next.quantity = 'Indica entre 1 y 100 códigos por lote.';
    }
    errors.value = next;
    return Object.keys(next).length === 0;
}

async function generate() {
    if (saving.value || !validate()) return;
    saving.value = true;
    try {
        const { data } = await axios.post(urls.storeCode, {
            subscription_plan_id: form.value.mode === 'plan' ? form.value.planId : null,
            duration_days: form.value.mode === 'days' ? Number(form.value.days) : null,
            quantity: howMany.value,
            custom_code: isCustom.value ? normalizedCustomCode.value : null,
            reference: form.value.reference.trim() || null,
        });
        created.value = data.access_codes;
        emit('generated', { codes: data.access_codes, summary: data.summary });
        notify('success', 'Códigos generados', data.message);
    } catch (e) {
        const { fields, message } = readError(e);
        errors.value = Object.keys(fields).length ? fields : { general: message };
    } finally {
        saving.value = false;
    }
}

async function copyText(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => copied.value === key && (copied.value = null), 2000);
    } catch {
        notify('error', 'No se pudo copiar', 'Selecciona los códigos y cópialos manualmente.');
    }
}

const listText = computed(() => created.value.map((c) => c.code).join('\n'));

function download() {
    const first = created.value[0];
    const header = [
        'Códigos de acceso — El Arte de la Contabilidad',
        `Cada código da ${first?.duration_label ?? ''} de acceso y sirve una sola vez.`,
        first?.reference ? `Para: ${first.reference}` : null,
        `Creados: ${first?.created_at ?? ''}`,
        '',
    ].filter((line) => line !== null);
    const stamp = new Date().toISOString().slice(0, 10);
    downloadText(`codigos-${stamp}.txt`, `${header.join('\n')}\n${listText.value}\n`);
}
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        :title="created.length ? 'Códigos generados' : 'Generar códigos de acceso'"
        max-width="lg"
        :closeable="!saving"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <!-- Resultado -->
        <div v-if="created.length" class="space-y-4">
            <BaseAlert type="success" :title="created.length === 1 ? 'Código listo para entregar' : `${plural(created.length, 'código listo', 'códigos listos')} para entregar`">
                Cada código otorga <strong>{{ created[0].duration_label }}</strong> de acceso y es válido para un único canje.
            </BaseAlert>

            <ul class="max-h-60 overflow-y-auto divide-y divide-border rounded-brand border border-border bg-background">
                <li v-for="code in created" :key="code.id" class="flex items-center justify-between gap-3 px-3 py-2">
                    <code translate="no" class="font-mono text-sm font-semibold tracking-wide text-foreground select-all">{{ code.code }}</code>
                    <button
                        type="button"
                        class="p-1.5 rounded-brand text-muted hover:text-primary hover:bg-surface transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        :aria-label="`Copiar el código ${code.code}`"
                        @click="copyText(code.code, code.id)"
                    >
                        <Check v-if="copied === code.id" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Copy v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
                </li>
            </ul>

            <div class="flex flex-wrap gap-2">
                <button type="button" :class="btn.secondary" @click="copyText(listText, 'all')">
                    <component :is="copied === 'all' ? Check : Copy" class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                    <template v-if="created.length === 1">{{ copied === 'all' ? 'Copiado' : 'Copiar código' }}</template>
                    <template v-else>{{ copied === 'all' ? 'Copiados' : 'Copiar todos' }}</template>
                </button>
                <button type="button" :class="btn.secondary" @click="download">
                    <Download class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                    <span>Descargar lista (.txt)</span>
                </button>
            </div>
        </div>

        <!-- Formulario -->
        <form v-else id="code-form" class="space-y-4" novalidate @submit.prevent="generate">
            <BaseAlert v-if="errors.general" type="error">
                {{ errors.general }}
            </BaseAlert>

            <fieldset class="space-y-1.5">
                <legend :class="label">Modo de generación</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        class="flex items-center gap-2 rounded-brand border p-2.5 cursor-pointer text-xs font-medium transition-colors"
                        :class="form.kind === 'random' ? 'border-primary bg-primary/10 text-foreground font-semibold' : 'border-border bg-background text-muted hover:text-foreground'"
                    >
                        <input v-model="form.kind" type="radio" value="random" class="text-primary focus:ring-primary/30" />
                        <span>Generar al azar</span>
                    </label>
                    <label
                        class="flex items-center gap-2 rounded-brand border p-2.5 cursor-pointer text-xs font-medium transition-colors"
                        :class="form.kind === 'custom' ? 'border-primary bg-primary/10 text-foreground font-semibold' : 'border-border bg-background text-muted hover:text-foreground'"
                    >
                        <input v-model="form.kind" type="radio" value="custom" class="text-primary focus:ring-primary/30" />
                        <span>Código personalizado</span>
                    </label>
                </div>
            </fieldset>

            <div v-if="isCustom" class="space-y-1.5">
                <label for="code-custom" :class="label">Código personalizado *</label>
                <input
                    id="code-custom"
                    v-model="form.customCode"
                    type="text"
                    maxlength="32"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    translate="no"
                    :class="[field, 'font-mono uppercase tracking-wide']"
                    placeholder="Ej. 2026ARTE-UMSA"
                    :aria-invalid="Boolean(errors.custom_code)"
                    aria-describedby="code-custom-help code-custom-error"
                />
                <p id="code-custom-help" :class="hint">
                    De 6 a 32 letras, números o guiones. Válido para <strong class="font-semibold text-foreground">una sola persona</strong>.
                </p>
                <p v-if="errors.custom_code" id="code-custom-error" :class="errorText">{{ errors.custom_code }}</p>
            </div>

            <fieldset class="space-y-1.5">
                <legend :class="label">Duración del acceso</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        class="flex items-center gap-2 rounded-brand border p-2.5 cursor-pointer text-xs font-medium transition-colors"
                        :class="[
                            form.mode === 'plan' ? 'border-primary bg-primary/10 text-foreground font-semibold' : 'border-border bg-background text-muted hover:text-foreground',
                            !activePlans.length && 'opacity-50 cursor-not-allowed',
                        ]"
                    >
                        <input v-model="form.mode" type="radio" value="plan" class="text-primary focus:ring-primary/30" :disabled="!activePlans.length" />
                        <span>Según un plan activo</span>
                    </label>
                    <label
                        class="flex items-center gap-2 rounded-brand border p-2.5 cursor-pointer text-xs font-medium transition-colors"
                        :class="form.mode === 'days' ? 'border-primary bg-primary/10 text-foreground font-semibold' : 'border-border bg-background text-muted hover:text-foreground'"
                    >
                        <input v-model="form.mode" type="radio" value="days" class="text-primary focus:ring-primary/30" />
                        <span>Días personalizados</span>
                    </label>
                </div>
            </fieldset>

            <div v-if="form.mode === 'plan'" class="space-y-1.5">
                <label for="code-plan" :class="label">Plan asociado *</label>
                <select id="code-plan" v-model="form.planId" :class="field" :aria-invalid="Boolean(errors.subscription_plan_id)">
                    <option v-for="plan in activePlans" :key="plan.id" :value="plan.id">
                        {{ plan.name }} ({{ plan.duration_label }})
                    </option>
                </select>
                <p v-if="errors.subscription_plan_id" :class="errorText">{{ errors.subscription_plan_id }}</p>
            </div>

            <div v-else class="space-y-2">
                <label for="code-days" :class="label">Cantidad de días *</label>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Días frecuentes">
                    <button
                        v-for="d in DAY_SHORTCUTS"
                        :key="d"
                        type="button"
                        class="px-2.5 py-1 rounded-brand border text-xs font-medium transition-colors"
                        :class="
                            Number(form.days) === d
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-background border-border text-muted hover:text-foreground'
                        "
                        :aria-pressed="Number(form.days) === d"
                        @click="form.days = d"
                    >
                        {{ durationLabel(d) }}
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <input id="code-days" v-model.number="form.days" type="number" inputmode="numeric" min="1" max="1825" :class="[field, '!w-28']" :aria-invalid="Boolean(errors.duration_days)" />
                    <span :class="hint">días · {{ durationLabel(form.days) }}</span>
                </div>
                <p v-if="errors.duration_days" :class="errorText">{{ errors.duration_days }}</p>
            </div>

            <div class="grid gap-3.5" :class="isCustom ? '' : 'sm:grid-cols-[7.5rem_1fr]'">
                <div v-if="!isCustom" class="space-y-1">
                    <label for="code-quantity" :class="label">Cantidad *</label>
                    <input id="code-quantity" v-model.number="form.quantity" type="number" inputmode="numeric" min="1" max="100" :class="field" :aria-invalid="Boolean(errors.quantity)" />
                    <p v-if="errors.quantity" :class="errorText">{{ errors.quantity }}</p>
                </div>
                <div class="space-y-1">
                    <label for="code-reference" :class="label">Referencia interna <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="code-reference" v-model="form.reference" type="text" maxlength="255" autocomplete="off" :class="field" placeholder="Ej. Lectores edición impresa, Beca UMSA..." />
                    <p :class="hint">Nota privada para identificar el lote o destinatario.</p>
                </div>
            </div>
        </form>

        <template #footer="{ close }">
            <template v-if="created.length">
                <button type="button" :class="[btn.quiet, 'mr-auto']" @click="reset">Generar más</button>
                <button type="button" :class="btn.primary" @click="close">Finalizar</button>
            </template>
            <template v-else>
                <button type="button" :class="btn.secondary" :disabled="saving" @click="close">Cancelar</button>
                <button type="submit" form="code-form" :class="btn.primary" :disabled="saving">
                    {{ saving ? 'Generando…' : `Generar ${plural(howMany, 'código', 'códigos')}` }}
                    <span v-if="!saving && daysGiven" class="font-normal opacity-85">({{ durationLabel(daysGiven) }})</span>
                </button>
            </template>
        </template>
    </BaseModal>
</template>
