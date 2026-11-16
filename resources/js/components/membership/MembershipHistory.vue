<script setup>
import { ref } from 'vue';
import { CheckCircle2, ChevronDown, Clock, Eye, XCircle } from 'lucide-vue-next';
import { btn } from '../subscriptions/shared';

/**
 * Pagos enviados y códigos canjeados por la persona. Listas, no tablas: en el celular
 * una tabla obliga a deslizar de lado.
 */
defineProps({
    requests: { type: Array, default: () => [] },
    codes: { type: Array, default: () => [] },
});

const showRequests = ref(false);
const showCodes = ref(false);

const statusStyle = {
    pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    approved: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
};
const statusIcon = { pending: Clock, approved: CheckCircle2, rejected: XCircle };

const toggle =
    'w-full bg-surface border border-border rounded-brand px-3.5 py-3 sm:px-4 flex items-center justify-between gap-3 text-left hover:border-primary/40 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30';
const count = 'px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums';
</script>

<template>
    <section aria-labelledby="membership-history-title" class="space-y-3">
        <h2 id="membership-history-title" class="text-base font-semibold text-foreground">Tu historial</h2>

        <div v-if="requests.length" class="space-y-3">
            <button
                type="button"
                :class="toggle"
                :aria-expanded="showRequests"
                aria-controls="membership-history-requests"
                @click="showRequests = !showRequests"
            >
                <span class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-foreground">Pagos que enviaste</span>
                    <span :class="count">{{ requests.length }}</span>
                </span>
                <ChevronDown
                    class="h-4 w-4 text-muted transition-transform duration-200 motion-reduce:transition-none"
                    :class="{ 'rotate-180': showRequests }"
                    aria-hidden="true"
                />
            </button>

            <ul
                v-if="showRequests"
                id="membership-history-requests"
                class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden"
            >
                <li
                    v-for="request in requests"
                    :key="request.id"
                    class="p-3 sm:p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 sm:gap-4"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                            <span class="font-semibold text-foreground">{{ request.plan_name }}</span>
                            <span class="font-semibold text-foreground tabular-nums">{{ request.amount_formatted }}</span>
                            <span
                                v-if="request.special_price_label"
                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                            >
                                {{ request.special_price_label }}
                            </span>
                        </p>
                        <p class="text-xs text-muted">Enviado el {{ request.created_at }}</p>
                        <p v-if="request.status === 'rejected' && request.admin_notes" class="text-xs text-muted">
                            Motivo: <span class="text-foreground">{{ request.admin_notes }}</span>
                        </p>
                    </div>

                    <div class="flex items-center justify-between sm:justify-end gap-2.5 shrink-0">
                        <span
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                            :class="statusStyle[request.status]"
                        >
                            <component :is="statusIcon[request.status]" class="h-3 w-3" aria-hidden="true" />
                            {{ request.status_label }}
                        </span>
                        <a :href="request.proof_url" target="_blank" rel="noopener" :class="btn.small">
                            <Eye class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                            <span>Ver</span>
                            <span class="sr-only"> el comprobante del {{ request.created_at }} (se abre en otra pestaña)</span>
                        </a>
                    </div>
                </li>
            </ul>
        </div>

        <div v-if="codes.length" class="space-y-3">
            <button
                type="button"
                :class="toggle"
                :aria-expanded="showCodes"
                aria-controls="membership-history-codes"
                @click="showCodes = !showCodes"
            >
                <span class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-foreground">Códigos que usaste</span>
                    <span :class="count">{{ codes.length }}</span>
                </span>
                <ChevronDown
                    class="h-4 w-4 text-muted transition-transform duration-200 motion-reduce:transition-none"
                    :class="{ 'rotate-180': showCodes }"
                    aria-hidden="true"
                />
            </button>

            <ul
                v-if="showCodes"
                id="membership-history-codes"
                class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden"
            >
                <li
                    v-for="item in codes"
                    :key="item.id"
                    class="p-3 sm:p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-4"
                >
                    <div class="min-w-0 space-y-1">
                        <code
                            translate="no"
                            class="inline-block font-mono text-xs sm:text-sm font-semibold tracking-wide text-foreground bg-background border border-border px-2.5 py-1 rounded-brand"
                        >{{ item.code }}</code>
                        <p class="text-xs text-muted">{{ item.plan_name }}</p>
                    </div>
                    <p class="text-xs text-muted shrink-0">
                        Sumó <span class="font-semibold text-foreground">{{ item.duration_label }}</span> el {{ item.redeemed_at }}
                    </p>
                </li>
            </ul>
        </div>
    </section>
</template>
