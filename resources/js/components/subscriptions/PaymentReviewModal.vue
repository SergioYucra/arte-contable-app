<script setup>
/**
 * Revisión de un pago: el comprobante a un lado y la decisión al otro, con la consecuencia escrita.
 */
import { ref, watch, computed, nextTick } from 'vue';
import axios from 'axios';
import { FileText, ExternalLink, CalendarCheck } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import { btn, field, label, readError } from './shared';

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
        :title="payment ? `Comprobante de ${payment.user.name}` : 'Revisión de pago'"
        max-width="2xl"
        :closeable="!busy"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div v-if="payment" class="space-y-4">
            <BaseAlert v-if="error" type="error">
                {{ error }}
            </BaseAlert>

            <div class="grid gap-4 md:grid-cols-12 items-start">
                <!-- Visor del comprobante -->
                <div class="md:col-span-7 rounded-brand border border-border bg-background min-h-[15rem] flex items-center justify-center overflow-hidden">
                    <template v-if="payment.proof_kind === 'pdf' || imageFailed">
                        <div class="p-6 text-center space-y-2.5">
                            <div class="mx-auto h-10 w-10 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                                <FileText class="h-5 w-5" aria-hidden="true" />
                            </div>
                            <p class="text-sm font-semibold text-foreground">
                                {{ imageFailed ? 'No se pudo previsualizar la imagen' : 'Comprobante en formato PDF' }}
                            </p>
                            <p class="text-xs text-muted">Ábrelo en una pestaña nueva para verificar los datos.</p>
                            <div class="pt-1">
                                <a
                                    :href="payment.proof_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    :class="btn.secondary"
                                >
                                    <span>Abrir comprobante</span>
                                    <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                    </template>
                    <a
                        v-else
                        :href="payment.proof_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block w-full max-h-[56vh] overflow-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        title="Abrir comprobante en tamaño completo"
                    >
                        <img
                            :src="payment.proof_url"
                            :alt="`Comprobante de pago de ${payment.user.name}`"
                            loading="lazy"
                            decoding="async"
                            class="mx-auto max-w-full h-auto"
                            @error="imageFailed = true"
                        />
                    </a>
                </div>

                <!-- Datos del pago y resolución -->
                <div class="md:col-span-5 space-y-3 text-xs">
                    <div class="p-3 rounded-brand bg-background border border-border space-y-2.5">
                        <div>
                            <div class="text-[11px] text-muted">Estudiante</div>
                            <div class="text-sm font-semibold text-foreground break-words mt-0.5">{{ payment.user.name }}</div>
                            <div class="text-xs text-muted break-all">{{ payment.user.email }}</div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-border">
                            <div>
                                <div class="text-[11px] text-muted">Plan solicitado</div>
                                <div class="text-xs font-semibold text-foreground mt-0.5">
                                    {{ payment.plan?.name || 'Sin plan' }}
                                </div>
                                <div v-if="payment.plan" class="text-[11px] text-muted">
                                    {{ payment.plan.duration_label }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] text-muted">Monto declarado</div>
                                <div class="text-sm font-bold text-foreground tabular-nums mt-0.5">
                                    {{ payment.amount_formatted }}
                                </div>
                                <div v-if="payment.special_price_label" class="text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                    {{ payment.special_price_label }}
                                </div>
                            </div>
                        </div>

                        <p v-if="payment.plan?.is_archived" class="text-[11px] text-muted pt-1 border-t border-border">
                            Este plan fue archivado; se respetan sus días de vigencia.
                        </p>

                        <div v-if="payment.student_note" class="pt-2 border-t border-border">
                            <div class="text-[11px] text-muted">Nota adjunta</div>
                            <div class="text-xs text-foreground break-words mt-0.5">«{{ payment.student_note }}»</div>
                        </div>

                        <div class="pt-2 border-t border-border flex items-center justify-between text-[11px] text-muted">
                            <span>Enviado</span>
                            <span class="font-medium text-foreground">{{ payment.created_at }}</span>
                        </div>
                    </div>

                    <div
                        v-if="isPending && payment.would_expire_at"
                        class="rounded-brand bg-primary/10 border border-primary/20 p-3 flex items-start gap-2.5"
                    >
                        <CalendarCheck class="h-4 w-4 text-primary shrink-0 mt-0.5" aria-hidden="true" />
                        <div class="text-xs text-foreground leading-relaxed">
                            Al aprobar, {{ firstName }} tendrá acceso completo hasta el <strong>{{ payment.would_expire_at }}</strong>.
                            <span v-if="payment.current_access_until" class="block text-[11px] text-muted mt-0.5">
                                Actualmente vence el {{ payment.current_access_until }} (se acumulan los días).
                            </span>
                        </div>
                    </div>

                    <div v-if="!isPending" class="rounded-brand bg-background border border-border p-3 space-y-1">
                        <p class="text-xs font-semibold text-foreground">{{ payment.status_label }} el {{ payment.reviewed_at }}</p>
                        <p v-if="payment.reviewer_name" class="text-[11px] text-muted">Revisado por {{ payment.reviewer_name }}</p>
                        <p v-if="payment.admin_notes" class="text-xs text-muted pt-1 border-t border-border mt-1">
                            Motivo: {{ payment.admin_notes }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Motivo del rechazo -->
            <div v-if="isPending && mode === 'reject'" class="pt-4 border-t border-border space-y-2.5">
                <div>
                    <label for="reject-reason" :class="label">Motivo del rechazo *</label>
                    <p class="text-[11px] text-muted mt-0.5">El estudiante verá esta indicación en su panel de membresía.</p>
                </div>

                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Motivos frecuentes">
                    <button
                        v-for="suggestion in SUGGESTED_REASONS"
                        :key="suggestion"
                        type="button"
                        class="px-2.5 py-1.5 rounded-brand border text-xs font-medium text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        :class="
                            reason === suggestion
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'bg-background border-border text-muted hover:text-foreground hover:border-primary/40'
                        "
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
                    placeholder="Ej.: La imagen no se distingue con claridad. Por favor sube una captura nítida..."
                    :aria-invalid="Boolean(error)"
                ></textarea>
            </div>
        </div>

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
                    {{ busy ? 'Rechazando…' : 'Confirmar rechazo' }}
                </button>
            </template>
            <button v-else type="button" :class="btn.secondary" @click="close">Cerrar</button>
        </template>
    </BaseModal>
</template>
