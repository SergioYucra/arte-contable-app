<script setup>
/**
 * Revisión de un pago: el comprobante a un lado y la decisión al otro, con la consecuencia escrita.
 */
import { ref, watch, computed, nextTick } from 'vue';
import axios from 'axios';
import { FileText, ExternalLink, CalendarCheck } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import { btn, field, label, errorText, readError } from './shared';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    payment: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'reviewed']);

const SUGGESTED_REASONS = [
    'La imagen no se ve bien. Envía una foto más clara del comprobante.',
    'El monto no coincide con el precio del plan.',
    'No encuentro este pago en la cuenta. Revisa que se haya completado.',
];

const mode = ref('review'); // 'review' | 'reject'
const reason = ref('');
const error = ref('');
const busy = ref(false);
const imageFailed = ref(false);
const reasonField = ref(null);

const isPending = computed(() => props.payment?.status === 'pending');
const firstName = computed(() => (props.payment?.user?.name || '').split(' ')[0]);

watch(
    () => [props.modelValue, props.payment?.id],
    () => {
        mode.value = 'review';
        reason.value = '';
        error.value = '';
        imageFailed.value = false;
    }
);

async function startReject() {
    mode.value = 'reject';
    error.value = '';
    await nextTick();
    reasonField.value?.focus();
}

async function approve() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(props.payment.approve_url);
        emit('reviewed', { payment: data.payment_request, summary: data.summary, message: data.message, approved: true });
    } catch (e) {
        error.value = readError(e).message;
    } finally {
        busy.value = false;
    }
}

async function reject() {
    if (busy.value) return;
    if (reason.value.trim().length < 3) {
        error.value = 'Escribe el motivo para que la persona sepa qué corregir.';
        reasonField.value?.focus();
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(props.payment.reject_url, { admin_notes: reason.value.trim() });
        emit('reviewed', { payment: data.payment_request, summary: data.summary, message: data.message, approved: false });
    } catch (e) {
        error.value = readError(e).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        :title="payment ? `Pago de ${payment.user.name}` : 'Pago'"
        max-width="2xl"
        :closeable="!busy"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div v-if="payment" class="grid gap-5 md:grid-cols-[1fr_17rem]">
            <!-- Comprobante -->
            <div class="rounded-brand border border-border bg-background min-h-[16rem] flex items-center justify-center overflow-hidden">
                <template v-if="payment.proof_kind === 'pdf' || imageFailed">
                    <div class="p-6 text-center">
                        <FileText class="mx-auto h-10 w-10 text-primary" aria-hidden="true" />
                        <p class="mt-3 text-sm font-semibold text-foreground">
                            {{ imageFailed ? 'No se pudo mostrar la imagen aquí' : 'El comprobante es un PDF' }}
                        </p>
                        <p class="mt-1 text-sm text-muted">Se abre en una pestaña nueva.</p>
                        <a
                            :href="payment.proof_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :class="[btn.secondary, 'mt-4']"
                        >
                            Abrir comprobante
                            <ExternalLink class="h-4 w-4" aria-hidden="true" />
                        </a>
                    </div>
                </template>
                <a
                    v-else
                    :href="payment.proof_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block w-full max-h-[60vh] overflow-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    title="Abrir en tamaño completo"
                >
                    <img
                        :src="payment.proof_url"
                        :alt="`Comprobante de pago de ${payment.user.name}`"
                        class="mx-auto max-w-full h-auto"
                        @error="imageFailed = true"
                    />
                </a>
            </div>

            <!-- Datos y decisión -->
            <div class="space-y-4 text-sm">
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-muted">Persona</dt>
                        <dd class="font-semibold text-foreground break-words">{{ payment.user.name }}</dd>
                        <dd class="text-muted break-all">{{ payment.user.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Plan</dt>
                        <dd class="font-semibold text-foreground">
                            {{ payment.plan?.name || 'Sin plan' }}
                            <span v-if="payment.plan" class="font-normal text-muted">· {{ payment.plan.duration_label }}</span>
                        </dd>
                        <dd v-if="payment.plan?.is_archived" class="text-xs text-muted">Este plan ya está archivado; se respetan sus días.</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Monto</dt>
                        <dd class="font-serif text-2xl text-foreground tabular-nums">{{ payment.amount_formatted }}</dd>
                        <dd v-if="payment.special_price_label" class="text-xs text-amber-800 dark:text-amber-300">
                            Pagó con precio especial: {{ payment.special_price_label }}
                        </dd>
                    </div>
                    <div v-if="payment.student_note">
                        <dt class="text-xs text-muted">Nota de la persona</dt>
                        <dd class="text-foreground break-words">«{{ payment.student_note }}»</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted">Enviado</dt>
                        <dd class="text-foreground">{{ payment.created_at }}</dd>
                    </div>
                </dl>

                <div v-if="isPending && payment.would_expire_at" class="rounded-brand bg-primary/10 border border-primary/20 p-3 flex gap-2.5">
                    <CalendarCheck class="h-5 w-5 text-primary shrink-0 mt-0.5" aria-hidden="true" />
                    <p class="text-foreground leading-relaxed">
                        Al aprobar, {{ firstName }} podrá leer todo hasta el <strong>{{ payment.would_expire_at }}</strong>.
                        <span v-if="payment.current_access_until" class="block text-muted mt-1">
                            Hoy tiene acceso hasta el {{ payment.current_access_until }}; se suman los días del plan.
                        </span>
                    </p>
                </div>

                <div v-if="!isPending" class="rounded-brand border border-border p-3">
                    <p class="font-semibold text-foreground">{{ payment.status_label }} el {{ payment.reviewed_at }}</p>
                    <p v-if="payment.reviewer_name" class="text-muted">Por {{ payment.reviewer_name }}</p>
                    <p v-if="payment.admin_notes" class="text-muted mt-1">Motivo: {{ payment.admin_notes }}</p>
                </div>
            </div>
        </div>

        <!-- Motivo del rechazo -->
        <div v-if="isPending && mode === 'reject'" class="mt-5 pt-5 border-t border-border space-y-3">
            <label for="reject-reason" :class="label">¿Por qué lo rechazas?</label>
            <p class="text-xs text-muted">La persona verá este mensaje en su cuenta.</p>
            <div class="flex flex-wrap gap-2" role="group" aria-label="Motivos frecuentes">
                <button
                    v-for="suggestion in SUGGESTED_REASONS"
                    :key="suggestion"
                    type="button"
                    class="px-3 py-1.5 rounded-full border text-xs text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    :class="reason === suggestion ? 'border-primary bg-primary/10 text-foreground' : 'border-border text-muted hover:text-foreground hover:border-primary/40'"
                    :aria-pressed="reason === suggestion"
                    @click="reason = suggestion"
                >
                    {{ suggestion.split('.')[0] }}
                </button>
            </div>
            <textarea
                id="reject-reason"
                ref="reasonField"
                v-model="reason"
                rows="3"
                maxlength="500"
                :class="field"
                placeholder="Ej.: La imagen no se ve bien. Envía una foto más clara…"
                :aria-invalid="Boolean(error)"
                aria-describedby="review-error"
            ></textarea>
        </div>

        <p v-if="error" id="review-error" role="alert" :class="[errorText, 'mt-4 text-sm']">{{ error }}</p>

        <template #footer="{ close }">
            <template v-if="isPending && mode === 'review'">
                <button type="button" :class="[btn.dangerQuiet, 'mr-auto']" :disabled="busy" @click="startReject">
                    Rechazar…
                </button>
                <button type="button" :class="btn.primary" :disabled="busy" @click="approve">
                    {{ busy ? 'Aprobando…' : 'Aprobar pago' }}
                </button>
            </template>
            <template v-else-if="isPending && mode === 'reject'">
                <button type="button" :class="[btn.secondary, 'mr-auto']" :disabled="busy" @click="mode = 'review'; error = ''">
                    Volver
                </button>
                <button type="button" :class="btn.danger" :disabled="busy" @click="reject">
                    {{ busy ? 'Rechazando…' : 'Rechazar pago' }}
                </button>
            </template>
            <button v-else type="button" :class="btn.secondary" @click="close">Cerrar</button>
        </template>
    </BaseModal>
</template>
