<script setup>
import { nextTick, ref, watch } from 'vue';
import axios from 'axios';
import BaseModal from '../ui/BaseModal.vue';
import { btn, errorText, field, hint, label, readError } from '../subscriptions/shared';

/**
 * Canjear un código de acceso (el del libro impreso o el que entrega la administración).
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    redeemUrl: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue', 'redeemed']);

const code = ref('');
const error = ref('');
const busy = ref(false);
const input = ref(null);

watch(
    () => props.modelValue,
    async (open) => {
        if (!open) return;
        code.value = '';
        error.value = '';
        // Dos ciclos: BaseModal enfoca primero su botón de cerrar; aquí conviene empezar en el campo.
        await nextTick();
        await nextTick();
        input.value?.focus();
    },
);

function close() {
    if (!busy.value) emit('update:modelValue', false);
}

async function submit() {
    if (busy.value) return;

    if (!code.value.trim()) {
        error.value = 'Escribe tu código de acceso.';
        input.value?.focus();
        return;
    }

    error.value = '';
    busy.value = true;

    try {
        const { data } = await axios.post(props.redeemUrl, { code: code.value });
        emit('redeemed', data);
    } catch (failure) {
        error.value = readError(failure, 'No se pudo canjear el código. Revisa tu conexión e inténtalo de nuevo.').message;
        await nextTick();
        input.value?.focus();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        title="Canjear código de acceso"
        max-width="md"
        :closeable="!busy"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <form id="membership-redeem-form" class="space-y-3" novalidate @submit.prevent="submit">
            <p class="text-xs sm:text-sm leading-relaxed text-muted">
                Es el código que viene en tu libro impreso o el que te dio la administración. Al canjearlo se abren todos los libros.
            </p>

            <div class="space-y-1.5">
                <label for="redeem-code" :class="label">Tu código</label>
                <input
                    id="redeem-code"
                    ref="input"
                    v-model="code"
                    type="text"
                    name="code"
                    maxlength="50"
                    autocomplete="off"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    translate="no"
                    placeholder="AC-XXXX-XXXX"
                    :class="[field, 'font-mono tracking-wide uppercase placeholder:normal-case placeholder:tracking-normal']"
                    :aria-invalid="error ? 'true' : undefined"
                    :aria-describedby="error ? 'redeem-error' : 'redeem-hint'"
                    :disabled="busy"
                />
                <p v-if="error" id="redeem-error" role="alert" :class="errorText">{{ error }}</p>
                <p v-else id="redeem-hint" :class="hint">
                    Puedes escribirlo con o sin guiones, en mayúsculas o minúsculas. Cada código sirve una sola vez.
                </p>
            </div>
        </form>

        <template #footer>
            <button type="button" :class="btn.secondary" :disabled="busy" @click="close">Cancelar</button>
            <button type="submit" form="membership-redeem-form" :class="btn.primary" :disabled="busy">
                {{ busy ? 'Canjeando…' : 'Canjear código' }}
            </button>
        </template>
    </BaseModal>
</template>
