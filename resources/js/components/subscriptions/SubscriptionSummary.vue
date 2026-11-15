<script setup>
/**
 * Cabecera del panel: una frase que dice qué hay que hacer hoy y tres cifras que llevan a su lista.
 */
import { inject, computed } from 'vue';
import { ArrowRight, CheckCircle2 } from 'lucide-vue-next';
import { PANEL_CONTEXT, btn, plural } from './shared';

const { summary, goTo } = inject(PANEL_CONTEXT);

const pending = computed(() => summary.value.pending_payments);

const figures = computed(() => [
    { key: 'members', value: summary.value.active_members, text: summary.value.active_members === 1 ? 'persona con acceso' : 'personas con acceso', action: () => goTo('members', 'active') },
    { key: 'expiring', value: summary.value.expiring_soon, text: 'vencen en los próximos 15 días', action: () => goTo('members', 'expiring'), warn: summary.value.expiring_soon > 0 },
    { key: 'codes', value: summary.value.unused_codes, text: summary.value.unused_codes === 1 ? 'código sin usar' : 'códigos sin usar', action: () => goTo('codes') },
]);
</script>

<template>
    <div class="rounded-brand border border-border bg-paper dark:bg-surface p-5 sm:p-7 flex flex-col lg:flex-row lg:items-end gap-6 lg:gap-10">
        <div class="flex-1 min-w-0">
            <p class="text-xs font-semibold text-foreground/75 dark:text-muted">Hoy</p>

            <template v-if="pending > 0">
                <h2 class="mt-1 font-serif text-3xl sm:text-4xl leading-tight text-foreground text-balance">
                    {{ plural(pending, 'pago espera', 'pagos esperan') }} tu revisión
                </h2>
                <p class="mt-2 text-sm text-foreground/75 dark:text-muted max-w-prose">
                    Mira cada comprobante y decide. Al aprobar, la persona puede leer todo de inmediato.
                </p>
                <button type="button" :class="[btn.primary, 'mt-4']" @click="goTo('payments')">
                    Revisar pagos
                    <ArrowRight class="h-4 w-4" aria-hidden="true" />
                </button>
            </template>

            <template v-else>
                <h2 class="mt-1 font-serif text-3xl sm:text-4xl leading-tight text-foreground flex items-center gap-3">
                    <CheckCircle2 class="h-7 w-7 text-emerald-600 dark:text-emerald-400 shrink-0" aria-hidden="true" />
                    Todo al día
                </h2>
                <p class="mt-2 text-sm text-foreground/75 dark:text-muted max-w-prose">
                    No hay pagos por revisar. Cuando alguien envíe su comprobante, aparecerá aquí.
                </p>
            </template>
        </div>

        <ul class="grid grid-cols-3 gap-2 sm:gap-3 lg:w-[30rem] shrink-0">
            <li v-for="figure in figures" :key="figure.key">
                <button
                    type="button"
                    class="w-full h-full flex flex-col justify-start text-left rounded-brand border border-border bg-surface px-3 py-3 sm:px-4 hover:border-primary/40 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    @click="figure.action"
                >
                    <span
                        class="block font-serif text-2xl sm:text-3xl tabular-nums leading-none"
                        :class="figure.warn ? 'text-amber-700 dark:text-amber-400' : 'text-foreground'"
                    >
                        {{ figure.value }}
                    </span>
                    <span class="mt-1.5 block text-xs text-muted leading-snug">{{ figure.text }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
