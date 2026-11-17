<script setup>
import { computed } from 'vue';
import { bs, parseAmount } from './format';
import { field, hint, label as labelClass, errorText } from '../subscriptions/shared';

/**
 * Salario mínimo nacional: se elige por año (con su decreto) o se escribe a mano.
 * v-model guarda el texto que escribió la persona; quien lo usa lo convierte con parseAmount.
 */
const props = defineProps({
    modelValue: { type: String, required: true },
    salarios: { type: Array, required: true },
    id: { type: String, required: true },
    label: { type: String, default: 'Salario mínimo nacional' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const amount = computed(() => parseAmount(props.modelValue));
const matches = computed(() => props.salarios.filter((s) => s.monto === amount.value));

const selected = computed(() => (matches.value.length ? String(matches.value[0].gestion) : 'otro'));

function onSelect(event) {
    const year = event.target.value;
    const row = props.salarios.find((s) => String(s.gestion) === year);
    if (row) emit('update:modelValue', String(row.monto));
    else event.target.value = selected.value;
}

const reference = computed(() => {
    if (!matches.value.length) return 'Monto escrito a mano: revisa el decreto del año que calculas.';
    const years = matches.value.map((s) => s.gestion).join(' y ');
    return `Salario mínimo de ${years} (${matches.value[0].norma}).`;
});
</script>

<template>
    <div>
        <label :for="`${id}-amount`" :class="labelClass">{{ label }}</label>
        <div class="mt-1.5 flex gap-2">
            <select
                :id="`${id}-year`"
                :value="selected"
                :class="field"
                class="min-w-0 flex-[1.3]"
                :aria-label="`${label}: elegir por año`"
                @change="onSelect"
            >
                <option v-for="s in salarios" :key="s.gestion" :value="String(s.gestion)">
                    {{ s.gestion }}: {{ bs(s.monto, 0) }}
                </option>
                <option value="otro">Otro monto</option>
            </select>
            <input
                :id="`${id}-amount`"
                :value="modelValue"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                maxlength="12"
                :class="[field, error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30']"
                class="min-w-0 flex-1 tabular-nums"
                :aria-invalid="!!error"
                :aria-describedby="`${id}-help`"
                @input="emit('update:modelValue', $event.target.value)"
            />
        </div>
        <p :id="`${id}-help`" class="mt-1" :class="error ? errorText : hint">{{ error || reference }}</p>
    </div>
</template>
