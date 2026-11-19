<script setup>
import { field, hint as hintClass, label as labelClass, errorText } from '../subscriptions/shared';

/**
 * Campo de monto en bolivianos. Acepta "15000", "15.000" o "15.000,50"; quien lo usa lo
 * convierte con parseAmount. Si hay error, el mensaje reemplaza a la ayuda.
 */
defineProps({
    modelValue: { type: String, required: true },
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    placeholder: { type: String, default: '0' },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label :for="id" :class="labelClass">{{ label }}</label>
        <div class="relative mt-1.5">
            <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-sm text-muted" aria-hidden="true">Bs</span>
            <input
                :id="id"
                :value="modelValue"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                maxlength="14"
                :placeholder="placeholder"
                :class="[field, error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30']"
                class="ps-9 tabular-nums"
                :aria-invalid="!!error"
                :aria-describedby="hint || error ? `${id}-help` : undefined"
                @input="emit('update:modelValue', $event.target.value)"
            />
        </div>
        <p v-if="hint || error" :id="`${id}-help`" class="mt-1" :class="error ? errorText : hintClass">{{ error || hint }}</p>
    </div>
</template>
