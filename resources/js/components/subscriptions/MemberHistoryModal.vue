<script setup>
/**
 * Historial de una persona (cada acceso otorgado y cada retiro) y la opción de quitarle el acceso.
 */
import { ref, watch, nextTick } from 'vue';
import axios from 'axios';
import { CreditCard, KeyRound, Ban } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
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
    try {
        const { data } = await axios.post(props.member.revoke_url, { reason: reason.value.trim() });
        emit('revoked', { member: data.member, message: data.message });
        revoking.value = false;
        reason.value = '';
        await loadHistory();
    } catch (e) {
        error.value = readError(e).message;
    } finally {
        busy.value = false;
    }
}

const icons = { payment: CreditCard, code: KeyRound };
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        :title="member ? member.name : 'Historial'"
        max-width="lg"
        :closeable="!busy"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div v-if="member" class="space-y-5">
            <div class="rounded-brand bg-paper dark:bg-background border border-border p-4">
                <p class="text-sm text-foreground/75 dark:text-muted break-all">{{ member.email }}</p>
                <p class="mt-1 font-serif text-xl text-foreground">
                    <template v-if="member.status === 'active'">Puede leer todo hasta el {{ member.until }}</template>
                    <template v-else-if="member.status === 'revoked'">Se le retiró el acceso</template>
                    <template v-else>Su acceso venció el {{ member.until }}</template>
                </p>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-foreground mb-3">Historial</h4>

                <p v-if="loading" class="text-sm text-muted" aria-live="polite">Cargando historial…</p>
                <div v-else-if="loadError" class="text-sm text-foreground flex items-center justify-between gap-3">
                    <span>{{ loadError }}</span>
                    <button type="button" :class="btn.small" @click="loadHistory">Reintentar</button>
                </div>
                <p v-else-if="!events.length" class="text-sm text-muted">Sin movimientos registrados.</p>

                <ol v-else class="relative space-y-4 border-l border-border ml-3">
                    <li v-for="(event, index) in events" :key="index" class="pl-6 relative">
                        <span
                            class="absolute -left-3 top-0.5 h-6 w-6 rounded-full border flex items-center justify-center bg-surface"
                            :class="event.type === 'revoke' ? 'border-rose-500/40 text-rose-700 dark:text-rose-400' : 'border-primary/30 text-primary'"
                            aria-hidden="true"
                        >
                            <component :is="event.type === 'revoke' ? Ban : icons[event.source]" class="h-3.5 w-3.5" />
                        </span>
                        <p class="text-sm font-semibold text-foreground">{{ event.title }}</p>
                        <p v-if="event.detail" class="text-sm text-muted break-words">{{ event.detail }}</p>
                        <p v-if="event.new_until" class="text-sm text-foreground mt-0.5">
                            Acceso hasta el {{ event.new_until }}
                            <span v-if="event.previous_until" class="text-muted">(antes: {{ event.previous_until }})</span>
                        </p>
                        <p class="text-xs text-muted mt-0.5">{{ event.at }}<template v-if="event.by"> · {{ event.by }}</template></p>
                    </li>
                </ol>
            </div>

            <div v-if="member.status === 'active'" class="pt-5 border-t border-border">
                <template v-if="!revoking">
                    <p class="text-sm text-muted">
                        Si un pago resultó falso o fue devuelto, puedes quitarle el acceso. Conserva sus apuntes y su avance.
                    </p>
                </template>
                <div v-else class="space-y-2">
                    <label for="revoke-reason" :class="label">¿Por qué le quitas el acceso?</label>
                    <textarea
                        id="revoke-reason"
                        ref="reasonField"
                        v-model="reason"
                        rows="3"
                        maxlength="500"
                        :class="field"
                        placeholder="Ej.: El banco devolvió el pago…"
                        :aria-invalid="Boolean(error)"
                        aria-describedby="revoke-help revoke-error"
                    ></textarea>
                    <p id="revoke-help" class="text-xs text-muted">
                        Dejará de ver las páginas de pago desde ahora. Queda registrado quién lo hizo y por qué.
                    </p>
                </div>
                <p v-if="error" id="revoke-error" role="alert" :class="[errorText, 'mt-2 text-sm']">{{ error }}</p>
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
                    {{ busy ? 'Quitando…' : 'Quitar acceso' }}
                </button>
            </template>
            <button v-else type="button" :class="btn.secondary" @click="close">Cerrar</button>
        </template>
    </BaseModal>
</template>
