<script setup>
/**
 * Cabecera operativa del panel: estado de comprobantes y accesos directos en una sola línea.
 */
import { inject, computed } from 'vue';
import { ArrowRight, CheckCircle2, Clock, Users, AlertTriangle, KeyRound } from 'lucide-vue-next';
import { PANEL_CONTEXT, btn, plural } from './shared';

const { summary, goTo } = inject(PANEL_CONTEXT);

const pending = computed(() => summary.value.pending_payments);

const figures = computed(() => [
    {
        key: 'members',
        icon: Users,
        value: summary.value.active_members,
        label: 'con acceso activo',
        hint: 'Ver personas con membresía activa',
        iconClass: 'text-primary',
        valueClass: 'text-foreground',
        action: () => goTo('members', 'active'),
    },
    {
        key: 'expiring',
        icon: AlertTriangle,
        value: summary.value.expiring_soon,
        label: 'vencen en 15 días',
        hint: 'Ver accesos que vencen en los próximos 15 días',
        iconClass: summary.value.expiring_soon > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted',
        valueClass: summary.value.expiring_soon > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground',
        action: () => goTo('members', 'expiring'),
    },
    {
        key: 'codes',
        icon: KeyRound,
        value: summary.value.unused_codes,
        label: summary.value.unused_codes === 1 ? 'código disponible' : 'códigos disponibles',
        hint: 'Ver códigos disponibles para canjear',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
        valueClass: 'text-foreground',
        action: () => goTo('codes'),
    },
]);
</script>

<template>
    <div class="px-3 py-2.5 sm:px-4 sm:py-3 flex flex-wrap items-center justify-between gap-2.5 sm:gap-3">
        <!-- Estado principal en una línea -->
        <div class="flex flex-wrap items-center gap-2 sm:gap-3 min-w-0">
            <span
                class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-foreground"
            >
                <Clock
                    v-if="pending > 0"
                    class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0"
                    aria-hidden="true"
                />
                <CheckCircle2
                    v-else
                    class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{ pending > 0 ? plural(pending, 'pago pendiente de revisión', 'pagos pendientes de revisión') : 'Bandeja de pagos al día' }}
                </span>
            </span>

            <button
                v-if="pending > 0"
                type="button"
                :class="[btn.primary, '!py-1.5 !px-3 !text-xs']"
                @click="goTo('payments')"
            >
                <span>Revisar</span>
                <ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
        </div>

        <!-- Indicadores rápidos en una sola línea, sin recortes -->
        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
            <button
                v-for="figure in figures"
                :key="figure.key"
                type="button"
                :title="figure.hint"
                class="inline-flex items-center gap-1.5 rounded-brand border border-border bg-background px-2.5 py-1.5 text-xs hover:border-primary/40 transition-colors whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                @click="figure.action"
            >
                <component :is="figure.icon" class="h-3.5 w-3.5 shrink-0" :class="figure.iconClass" aria-hidden="true" />
                <span class="font-bold tabular-nums" :class="figure.valueClass">{{ figure.value }}</span>
                <span class="text-muted">{{ figure.label }}</span>
            </button>
        </div>
    </div>
</template>
