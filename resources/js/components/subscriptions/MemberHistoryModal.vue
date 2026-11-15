<script setup>
/**
 * Historial de una persona (cada acceso otorgado y cada retiro) y la opción de quitarle el acceso.
 */
import { ref, watch, nextTick } from 'vue';
import axios from 'axios';
import { CreditCard, KeyRound, Ban } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import { btn, field, label, errorText, readError } from './shared';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    member: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'revoked']);

const events = ref([]);
const loading = ref(false);
const loadError = ref('');
const revoking = ref(false);
const reason = ref('');
const error = ref('');
const busy = ref(false);
const reasonField = ref(null);

async function loadHistory() {
    if (!props.member) return;
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(props.member.history_url);
        events.value = data.events;
    } catch (e) {
        loadError.value = readError(e).message;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.modelValue, props.member?.id],
    ([open]) => {
        revoking.value = false;
        reason.value = '';
        error.value = '';
        if (open) loadHistory();
    }
);

async function startRevoke() {
    revoking.value = true;
    await nextTick();
    reasonField.value?.focus();
}

async function revoke() {
    if (busy.value) return;
    if (reason.value.trim().length < 3) {
        error.value = 'Escribe por qué se quita el acceso. Queda guardado en el historial.';
        reasonField.value?.focus();
        return;
    }
    busy.value = true;
    error.value = '';
    let done = false;
    try {
        const { data } = await axios.post(props.member.revoke_url, { reason: reason.value.trim() });
        emit('revoked', { member: data.member, message: data.message });
        revoking.value = false;
        reason.value = '';
        done = true;
    } catch (e) {
        error.value = readError(e).message;
    } finally {
        // Se libera antes de recargar el historial: si no, la ventana no se puede cerrar mientras carga.
        busy.value = false;
    }
    if (done) await loadHistory();
}

const icons = { payment: CreditCard, code: KeyRound };

const statusBadge = {
    active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    expired: 'bg-background text-muted border-border',
    revoked: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
};
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        :title="member ? `Historial · ${member.name}` : 'Historial de acceso'"
        max-width="lg"
        :closeable="!busy"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div v-if="member" class="space-y-4">
            <div class="rounded-brand bg-background border border-border p-3.5 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-muted break-all">{{ member.email }}</p>
                    <p class="mt-0.5 text-sm font-semibold text-foreground">
                        <template v-if="member.status === 'active'">Acceso habilitado hasta el {{ member.until }}</template>
                        <template v-else-if="member.status === 'revoked'">Acceso retirado manualmente</template>
                        <template v-else>Acceso vencido el {{ member.until }}</template>
                    </p>
                </div>
                <span
                    class="shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold border"
                    :class="statusBadge[member.status]"
                >
                    {{ member.status_label }}
                </span>
            </div>

            <div class="space-y-3">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-muted">Movimientos registrados</h4>

                <p v-if="loading" class="text-xs text-muted py-4 text-center" aria-live="polite">Cargando historial…</p>
                <BaseAlert v-else-if="loadError" type="error">
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ loadError }}</span>
                        <button type="button" :class="btn.small" @click="loadHistory">Reintentar</button>
                    </div>
                </BaseAlert>
                <p v-else-if="!events.length" class="text-xs text-muted py-4 text-center">Sin movimientos registrados.</p>

                <ol v-else class="relative space-y-3.5 border-l border-border ml-3">
                    <li v-for="(event, index) in events" :key="index" class="pl-5 relative">
                        <span
                            class="absolute -left-3 top-0.5 h-6 w-6 rounded-full border flex items-center justify-center bg-surface"
                            :class="
                                event.type === 'revoke'
                                    ? 'border-rose-500/30 text-rose-600 dark:text-rose-400'
                                    : 'border-primary/30 text-primary'
                            "
                            aria-hidden="true"
                        >
                            <component :is="event.type === 'revoke' ? Ban : icons[event.source]" class="h-3.5 w-3.5" />
                        </span>
                        <p class="text-xs font-semibold text-foreground">{{ event.title }}</p>
                        <p v-if="event.detail" class="text-xs text-muted break-words mt-0.5">{{ event.detail }}</p>
                        <p v-if="event.new_until" class="text-xs text-foreground mt-0.5">
                            Vigencia hasta el <strong>{{ event.new_until }}</strong>
                            <span v-if="event.previous_until" class="text-muted"> (antes: {{ event.previous_until }})</span>
                        </p>
                        <p class="text-[11px] text-muted mt-0.5">
                            {{ event.at }}<template v-if="event.by"> · {{ event.by }}</template>
                        </p>
                    </li>
                </ol>
            </div>

            <div v-if="member.status === 'active'" class="pt-4 border-t border-border">
                <template v-if="!revoking">
                    <p class="text-xs text-muted leading-relaxed">
                        Si un comprobante resultó inválido o el pago fue revertido, puedes retirar el acceso. Los apuntes y el avance de lectura del estudiante se conservan.
                    </p>
                </template>
                <div v-else class="space-y-2">
                    <label for="revoke-reason" :class="label">Motivo para retirar el acceso *</label>
                    <textarea
                        id="revoke-reason"
                        ref="reasonField"
                        v-model="reason"
                        rows="3"
                        maxlength="500"
                        :class="field"
                        placeholder="Ej.: El banco revirtió la transferencia..."
                        :aria-invalid="Boolean(error)"
                        aria-describedby="revoke-help revoke-error"
                    ></textarea>
                    <p id="revoke-help" class="text-[11px] text-muted">
                        El acceso a las páginas completas se cerrará de inmediato y quedará constancia en este historial.
                    </p>
                </div>
                <p v-if="error" id="revoke-error" role="alert" :class="[errorText, 'mt-2']">{{ error }}</p>
            </div>
        </div>

        <template #footer="{ close }">
            <template v-if="member?.status === 'active' && !revoking">
                <button type="button" :class="[btn.dangerQuiet, 'mr-auto']" @click="startRevoke">Quitar acceso…</button>
                <button type="button" :class="btn.secondary" @click="close">Cerrar</button>
            </template>
            <template v-else-if="revoking">
                <button type="button" :class="[btn.secondary, 'mr-auto']" :disabled="busy" @click="revoking = false; error = ''">Volver</button>
                <button type="button" :class="btn.danger" :disabled="busy" @click="revoke">
                    {{ busy ? 'Quitando…' : 'Confirmar retiro' }}
                </button>
            </template>
            <button v-else type="button" :class="btn.secondary" @click="close">Cerrar</button>
        </template>
    </BaseModal>
</template>
