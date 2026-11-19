<script setup>
import { computed } from 'vue';
import { CalendarCheck, QrCode } from 'lucide-vue-next';
import { btn, hint } from '../subscriptions/shared';

/**
 * Un plan a la venta. La misma tarjeta sirve para comprar por primera vez y para sumar días:
 * a quien ya tiene acceso le dice hasta qué fecha llegaría.
 */
const props = defineProps({
    plan: { type: Object, required: true },
    // Motivo por el que hoy no se puede pagar este plan (pago en revisión, falta el QR). Null = se puede.
    disabledReason: { type: String, default: null },
});

defineEmits(['pay']);

const reasonId = computed(() => `plan-${props.plan.id}-motivo`);
</script>

<template>
    <li
        class="relative flex flex-col gap-4 rounded-brand bg-surface p-4 sm:p-5 transition-colors"
        :class="plan.best_value ? 'border-2 border-primary/50' : 'border border-border hover:border-primary/40'"
    >
        <span
            v-if="plan.best_value"
            class="absolute -top-3 start-4 rounded-full bg-primary px-2.5 py-0.5 text-[11px] font-semibold text-primary-foreground"
        >
            Mejor precio por mes
        </span>

        <div>
            <h3 class="font-serif text-[19px] font-medium text-foreground leading-snug">{{ plan.name }}</h3>
            <p class="mt-0.5 text-sm text-muted">{{ plan.duration_label }} de lectura completa</p>
        </div>

        <div>
            <p class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
                <span class="text-3xl font-bold tracking-tight text-foreground tabular-nums">{{ plan.price_formatted }}</span>
                <s v-if="plan.special" class="text-sm text-muted tabular-nums">
                    <span class="sr-only">Antes </span>{{ plan.special.regular_price_formatted }}
                </s>
            </p>
            <p class="mt-1 text-xs text-muted">
                <template v-if="plan.per_month_label">Sale a unos {{ plan.per_month_label }}. </template>Un solo pago.
            </p>
        </div>

        <p
            v-if="plan.special"
            class="rounded-brand border border-amber-500/20 bg-amber-500/5 p-2.5 text-xs leading-relaxed text-muted"
        >
            <strong class="font-semibold text-foreground">{{ plan.special.label }}.</strong>
            Este precio vale hasta {{ plan.special.ends_label }}.
        </p>

        <p v-if="plan.description" class="text-sm leading-relaxed text-muted line-clamp-2 sm:line-clamp-none">{{ plan.description }}</p>

        <p
            v-if="plan.extends_until"
            class="flex items-start gap-2 rounded-brand border border-primary/20 bg-primary/10 p-2.5 text-xs leading-relaxed text-foreground"
        >
            <CalendarCheck class="mt-0.5 h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
            <span>
                Con este plan podrías leer todo hasta el
                <strong class="font-semibold">{{ plan.extends_until }}</strong>.<span class="hidden sm:inline"> No pierdes los días que te quedan.</span>
            </span>
        </p>

        <div class="mt-auto space-y-2 border-t border-border pt-4">
            <button
                type="button"
                :class="[btn.primary, 'w-full py-3 sm:py-2.5']"
                :disabled="!!disabledReason"
                :aria-describedby="disabledReason ? reasonId : undefined"
                @click="$emit('pay', plan)"
            >
                <QrCode class="h-4 w-4" aria-hidden="true" />
                <span>Pagar con QR</span>
                <span class="sr-only"> el {{ plan.name }}</span>
            </button>
            <p v-if="disabledReason" :id="reasonId" :class="hint">{{ disabledReason }}</p>
        </div>
    </li>
</template>
