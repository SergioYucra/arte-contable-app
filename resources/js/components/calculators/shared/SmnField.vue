<script setup>
import { computed, nextTick, ref } from 'vue';
import { bs, parseAmount } from './formatters';
import { field, hint, label as labelClass, errorText } from '../../subscriptions/shared';

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
const amountInput = ref(null);

// Lo último que la persona eligió en la lista: un año ("2019") u "otro".
// Hace falta porque dos años pueden tener el mismo monto (2019 y 2020: Bs 2.122).
const pick = ref(null);

const amount = computed(() => parseAmount(props.modelValue));
const matches = computed(() => props.salarios.filter((s) => s.monto === amount.value));

const selected = computed(() => {
    if (pick.value === 'otro') return 'otro';
    if (matches.value.some((s) => String(s.gestion) === pick.value)) return pick.value;
    return matches.value.length ? String(matches.value[0].gestion) : 'otro';
});

async function onSelect(event) {
    pick.value = event.target.value;
    const row = props.salarios.find((s) => String(s.gestion) === pick.value);
    if (row) {
        emit('update:modelValue', String(row.monto));
    } else {
        await nextTick();
        amountInput.value?.focus();
        amountInput.value?.select();
    }
}

function onInput(event) {
    // Si escribe un monto de la tabla, la lista vuelve a mostrar su año.
    if (pick.value === 'otro' && props.salarios.some((s) => s.monto === parseAmount(event.target.value))) pick.value = null;
    emit('update:modelValue', event.target.value);
}

const reference = computed(() => {
    const row = props.salarios.find((s) => String(s.gestion) === selected.value);
    if (!row || row.monto !== amount.value) return 'Monto escrito a mano: revisa el decreto del año que calculas.';
    return `Salario mínimo de ${row.gestion} (${row.norma}).`;
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
                ref="amountInput"
                :value="modelValue"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                maxlength="12"
                :class="[field, error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30']"
                class="min-w-0 flex-1 tabular-nums"
                :aria-invalid="!!error"
                :aria-describedby="`${id}-help`"
                @input="onInput"
            />
        </div>
        <p :id="`${id}-help`" class="mt-1" :class="error ? errorText : hint">{{ error || reference }}</p>
    </div>
</template>
