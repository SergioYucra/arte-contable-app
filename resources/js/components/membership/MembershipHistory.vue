<script setup>
import { computed, ref } from 'vue';
import { CheckCircle2, Clock, Eye, KeyRound, ReceiptText, XCircle } from 'lucide-vue-next';
import { btn } from '../subscriptions/shared';
import { sectionLead, sectionTitle } from './ui';

/**
 * Lo que la persona pagó y los códigos que usó, abierto y a la vista.
 * En computadora se lee en columnas; en el celular cada pago ocupa dos líneas.
 */
const props = defineProps({
    requests: { type: Array, default: () => [] },
    codes: { type: Array, default: () => [] },
});

// Se muestran los más recientes (5 en computadora, 3 en el celular); el resto, a pedido.
const VISIBLE = 5;
const VISIBLE_PHONE = 3;
const allRequests = ref(false);
const allCodes = ref(false);

const shownRequests = computed(() => (allRequests.value ? props.requests : props.requests.slice(0, VISIBLE)));
const shownCodes = computed(() => (allCodes.value ? props.codes : props.codes.slice(0, VISIBLE)));

// En el celular, las filas que pasan de 3 se ocultan hasta que la persona pide verlas todas.
const phoneHidden = (index, all) => (!all && index >= VISIBLE_PHONE ? 'max-sm:hidden' : '');
// El botón "Ver todos" aparece en el celular desde 4 filas y en computadora desde 6.
const moreClass = (total) => (total > VISIBLE ? '' : 'sm:hidden');

// Lado a lado solo en pantallas muy anchas: en una computadora normal la tabla de pagos
// necesita todo el ancho para que el nombre del plan no se corte.
const sideBySide = computed(() => props.requests.length > 0 && props.codes.length > 0);

const statusStyle = {
    pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    approved: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
};
const statusIcon = { pending: Clock, approved: CheckCircle2, rejected: XCircle };

// Mismas columnas para la fila de títulos y para cada fila.
const paymentColumns = 'sm:grid-cols-[minmax(0,1fr)_5.5rem_9rem_7.25rem_5.5rem]';
const codeColumns = 'sm:grid-cols-[9.5rem_minmax(0,1fr)_9rem]';

const card = 'self-start rounded-brand border border-border bg-surface overflow-hidden';
const cardHeader = 'flex items-center justify-between gap-3 px-4 py-3 border-b border-border';
const cardTitle = 'flex items-center gap-2 text-sm font-semibold text-foreground';
const count = 'px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums';
const columnTitles = 'hidden sm:grid gap-x-3 px-4 py-2 bg-background/60 border-b border-border text-[11px] font-medium text-muted';
const more = 'border-t border-border px-4 py-2';
</script>

<template>
    <section aria-labelledby="membership-history-title" class="space-y-4">
        <div>
            <h2 id="membership-history-title" :class="sectionTitle">Tu historial</h2>
            <p :class="sectionLead">Lo que pagaste y los códigos que usaste, del más reciente al más antiguo.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:gap-5" :class="{ '2xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]': sideBySide }">
            <!-- Pagos -->
            <div v-if="requests.length" :class="card">
                <div :class="cardHeader">
                    <h3 :class="cardTitle">
                        <ReceiptText class="h-4 w-4 text-primary" aria-hidden="true" />
                        Pagos que enviaste
                    </h3>
                    <span :class="count">{{ requests.length }}</span>
                </div>

                <div :class="[columnTitles, paymentColumns]" aria-hidden="true">
                    <span>Plan</span>
                    <span class="text-right">Monto</span>
                    <span>Enviado</span>
                    <span>Estado</span>
                    <span class="text-right">Comprobante</span>
                </div>

                <ul class="divide-y divide-border">
                    <li
                        v-for="(request, index) in shownRequests"
                        :key="request.id"
                        class="px-4 py-3 sm:grid sm:items-center sm:gap-x-3"
                        :class="[paymentColumns, phoneHidden(index, allRequests)]"
                    >
                        <!-- Plan (y, en el celular, el monto a la derecha) -->
                        <div class="min-w-0 flex items-baseline justify-between gap-3 sm:block">
                            <p class="truncate text-sm font-semibold text-foreground">{{ request.plan_name }}</p>
                            <p class="sm:hidden shrink-0 text-sm font-semibold text-foreground tabular-nums">{{ request.amount_formatted }}</p>
                            <p
                                v-if="request.special_price_label"
                                class="hidden sm:block truncate text-[11px] font-medium text-amber-600 dark:text-amber-400"
                            >
                                {{ request.special_price_label }}
                            </p>
                        </div>
                        <p class="hidden sm:block text-right text-sm font-semibold text-foreground tabular-nums">{{ request.amount_formatted }}</p>

                        <!-- En el celular: fecha, estado y "Ver" en una sola línea. En computadora, tres columnas. -->
                        <div class="mt-1.5 sm:mt-0 flex items-center gap-2 sm:contents">
                            <p class="min-w-0 flex-1 truncate text-xs text-muted">
                                <span class="sr-only">Enviado el </span>{{ request.created_at }}
                            </p>
                            <span
                                class="inline-flex w-fit shrink-0 items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                                :class="statusStyle[request.status]"
                            >
                                <component :is="statusIcon[request.status]" class="h-3 w-3" aria-hidden="true" />
                                {{ request.status_label }}
                            </span>
                            <a :href="request.proof_url" target="_blank" rel="noopener" :class="[btn.small, 'shrink-0 sm:justify-self-end']">
                                <Eye class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                                <span>Ver</span>
                                <span class="sr-only"> el comprobante del {{ request.created_at }} (se abre en otra pestaña)</span>
                            </a>
                        </div>

                        <p v-if="request.status === 'rejected' && request.admin_notes" class="mt-2 sm:mt-1.5 sm:col-span-5 text-xs text-muted">
                            Motivo: <span class="text-foreground">{{ request.admin_notes }}</span>
                        </p>
                    </li>
                </ul>

                <div v-if="requests.length > VISIBLE_PHONE" :class="[more, moreClass(requests.length)]">
                    <button type="button" :class="btn.quiet" :aria-expanded="allRequests" @click="allRequests = !allRequests">
                        {{ allRequests ? 'Ver menos' : `Ver los ${requests.length} pagos` }}
                    </button>
                </div>
            </div>

            <!-- Códigos -->
            <div v-if="codes.length" :class="card">
                <div :class="cardHeader">
                    <h3 :class="cardTitle">
                        <KeyRound class="h-4 w-4 text-primary" aria-hidden="true" />
                        Códigos que usaste
                    </h3>
                    <span :class="count">{{ codes.length }}</span>
                </div>

                <div :class="[columnTitles, codeColumns]" aria-hidden="true">
                    <span>Código</span>
                    <span>Qué te dio</span>
                    <span>Canjeado</span>
                </div>

                <ul class="divide-y divide-border">
                    <li
                        v-for="(item, index) in shownCodes"
                        :key="item.id"
                        class="px-4 py-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 sm:grid sm:items-center"
                        :class="[codeColumns, phoneHidden(index, allCodes)]"
                    >
                        <code
                            translate="no"
                            class="w-fit font-mono text-xs font-semibold tracking-wide text-foreground bg-background border border-border px-2 py-1 rounded-brand"
                        >{{ item.code }}</code>
                        <p class="min-w-0 text-sm text-foreground sm:truncate">
                            <span class="font-semibold">Sumó {{ item.duration_label }}</span>
                            <span v-if="item.plan" class="text-muted"> · {{ item.plan }}</span>
                        </p>
                        <p class="basis-full sm:basis-auto text-xs text-muted">
                            <span class="sm:sr-only">Canjeado el </span>{{ item.redeemed_at }}
                        </p>
                    </li>
                </ul>

                <div v-if="codes.length > VISIBLE_PHONE" :class="[more, moreClass(codes.length)]">
                    <button type="button" :class="btn.quiet" :aria-expanded="allCodes" @click="allCodes = !allCodes">
                        {{ allCodes ? 'Ver menos' : `Ver los ${codes.length} códigos` }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
