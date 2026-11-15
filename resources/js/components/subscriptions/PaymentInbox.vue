<script setup>
/**
 * Bandeja de pagos: todos los pendientes (del más antiguo al más nuevo) y, debajo, los ya revisados.
 * Aprobar y rechazar solo se hace desde la revisión, después de ver el comprobante.
 */
import { ref, inject } from 'vue';
import axios from 'axios';
import { Inbox, FileText, Image as ImageIcon, ChevronDown, Eye, Clock, CheckCircle2, XCircle } from 'lucide-vue-next';
import PaymentReviewModal from './PaymentReviewModal.vue';
import { PANEL_CONTEXT, btn, readError } from './shared';

const props = defineProps({
    initialPending: { type: Array, required: true },
    initialReviewed: { type: Object, required: true },
});

const { urls, notify, updateSummary } = inject(PANEL_CONTEXT);

const pending = ref([...props.initialPending]);
const reviewed = ref([...props.initialReviewed.items]);
const reviewedTotal = ref(props.initialReviewed.total);
const nextPage = ref(props.initialReviewed.next_page);
const loadingMore = ref(false);
const showReviewed = ref(false);

const selected = ref(null);
const reviewOpen = ref(false);

function openReview(payment) {
    selected.value = payment;
    reviewOpen.value = true;
}

function onReviewed({ payment, summary, message, approved }) {
    pending.value = pending.value.filter((p) => p.id !== payment.id);
    reviewed.value.unshift(payment);
    reviewedTotal.value += 1;
    updateSummary(summary);
    reviewOpen.value = false;
    notify(approved ? 'success' : 'info', approved ? 'Pago aprobado' : 'Pago rechazado', message);
}

async function loadMore() {
    if (!nextPage.value || loadingMore.value) return;
    loadingMore.value = true;
    try {
        const { data } = await axios.get(urls.reviewedRequests, { params: { page: nextPage.value } });
        const known = new Set(reviewed.value.map((r) => r.id));
        reviewed.value.push(...data.items.filter((r) => !known.has(r.id)));
        nextPage.value = data.next_page;
        reviewedTotal.value = data.total;
    } catch (error) {
        notify('error', 'No se pudieron cargar más pagos', readError(error).message);
    } finally {
        loadingMore.value = false;
    }
}

const statusStyle = {
    approved: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
};
</script>

<template>
    <div class="space-y-4 sm:space-y-5">
        <div class="space-y-3">
            <div class="bg-surface border border-border rounded-brand px-3.5 py-2.5 sm:px-4 sm:py-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <h3 class="text-sm font-semibold text-foreground truncate">Comprobantes por revisar</h3>
                    <span
                        class="px-2 py-0.5 rounded-full text-[11px] font-semibold tabular-nums border"
                        :class="
                            pending.length > 0
                                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                                : 'bg-background text-muted border-border'
                        "
                    >
                        {{ pending.length }}
                    </span>
                </div>
                <p v-if="pending.length > 1" class="text-xs text-muted hidden sm:block">
                    Ordenados del más antiguo al más reciente
                </p>
            </div>

            <ul v-if="pending.length" class="space-y-3">
                <li
                    v-for="payment in pending"
                    :key="payment.id"
                    class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 hover:border-primary/40 transition-colors"
                >
                    <div class="flex items-start gap-3 min-w-0 flex-1">
                        <div class="h-10 w-10 rounded-brand bg-primary/10 text-primary border border-primary/20 flex items-center justify-center shrink-0 text-sm font-semibold">
                            {{ (payment.user?.name || '?').charAt(0).toUpperCase() }}
                        </div>

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-foreground truncate">{{ payment.user.name }}</p>
                                <span class="text-xs text-muted truncate">{{ payment.user.email }}</span>
                            </div>

                            <div class="text-xs text-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-semibold text-foreground">{{ payment.plan?.name || 'Sin plan' }}</span>
                                <span>·</span>
                                <span class="font-semibold text-primary tabular-nums">{{ payment.amount_formatted }}</span>
                                <span
                                    v-if="payment.special_price_label"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                                >
                                    {{ payment.special_price_label }}
                                </span>
                                <template v-if="payment.plan">
                                    <span>·</span>
                                    <span>{{ payment.plan.duration_label }}</span>
                                </template>
                            </div>

                            <p
                                v-if="payment.student_note"
                                class="text-xs text-muted bg-background border border-border rounded-brand px-2.5 py-1.5 line-clamp-2"
                            >
                                «{{ payment.student_note }}»
                            </p>
                        </div>
                    </div>

                    <div class="pt-2.5 sm:pt-0 border-t sm:border-t-0 border-border flex items-center justify-between sm:justify-end gap-3 shrink-0">
                        <div class="text-left sm:text-right space-y-0.5">
                            <p class="text-xs font-medium text-foreground inline-flex items-center gap-1">
                                <component
                                    :is="payment.proof_kind === 'pdf' ? FileText : ImageIcon"
                                    class="h-3.5 w-3.5 text-primary"
                                    aria-hidden="true"
                                />
                                {{ payment.proof_kind === 'pdf' ? 'Documento PDF' : 'Imagen adjunta' }}
                            </p>
                            <p class="text-[11px] text-muted flex items-center sm:justify-end gap-1" :title="payment.created_at">
                                <Clock class="h-3 w-3" aria-hidden="true" />
                                Enviado {{ payment.created_human }}
                            </p>
                        </div>

                        <button type="button" :class="btn.primary" @click="openReview(payment)">
                            <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                            <span>Revisar</span>
                            <span class="sr-only"> el pago de {{ payment.user.name }}</span>
                        </button>
                    </div>
                </li>
            </ul>

            <div
                v-else
                class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-2.5"
            >
                <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                    <Inbox class="h-6 w-6" aria-hidden="true" />
                </div>
                <h4 class="text-base font-semibold text-foreground">No hay pagos por revisar</h4>
                <p class="text-sm text-muted max-w-md mx-auto">
                    Cuando un estudiante envíe su comprobante de transferencia o QR, aparecerá en esta lista.
                </p>
            </div>
        </div>

        <div v-if="reviewedTotal > 0" class="space-y-3">
            <button
                type="button"
                class="w-full bg-surface border border-border rounded-brand px-3.5 py-2.5 sm:px-4 sm:py-3 flex items-center justify-between gap-3 text-left hover:border-primary/40 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                :aria-expanded="showReviewed"
                aria-controls="reviewed-payments"
                @click="showReviewed = !showReviewed"
            >
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-foreground">Historial de pagos revisados</span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums">
                        {{ reviewedTotal }}
                    </span>
                </div>
                <ChevronDown
                    class="h-4 w-4 text-muted transition-transform duration-200"
                    :class="{ 'rotate-180': showReviewed }"
                    aria-hidden="true"
                />
            </button>

            <div v-if="showReviewed" id="reviewed-payments" class="space-y-3">
                <ul class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
                    <li
                        v-for="payment in reviewed"
                        :key="payment.id"
                        class="p-3 sm:p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 sm:gap-4 hover:bg-background/40 transition-colors"
                    >
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                <span class="text-sm font-semibold text-foreground truncate">{{ payment.user.name }}</span>
                                <span class="text-muted">·</span>
                                <span class="font-medium text-foreground">{{ payment.plan?.name || 'Sin plan' }}</span>
                                <span class="text-muted">·</span>
                                <span class="font-semibold text-foreground tabular-nums">{{ payment.amount_formatted }}</span>
                            </div>
                            <p v-if="payment.status === 'rejected' && payment.admin_notes" class="text-xs text-muted">
                                Motivo: {{ payment.admin_notes }}
                            </p>
                        </div>

                        <div class="flex items-center justify-between sm:justify-end gap-2.5 shrink-0">
                            <span class="text-[11px] text-muted">
                                {{ payment.reviewed_at }}<template v-if="payment.reviewer_name"> · {{ payment.reviewer_name }}</template>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                                :class="statusStyle[payment.status]"
                            >
                                <CheckCircle2 v-if="payment.status === 'approved'" class="h-3 w-3" aria-hidden="true" />
                                <XCircle v-else class="h-3 w-3" aria-hidden="true" />
                                {{ payment.status_label }}
                            </span>
                            <button type="button" :class="btn.small" @click="openReview(payment)">
                                <Eye class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                                <span>Ver</span>
                                <span class="sr-only"> el pago de {{ payment.user.name }}</span>
                            </button>
                        </div>
                    </li>
                </ul>

                <div v-if="nextPage" class="text-center">
                    <button type="button" :class="btn.secondary" :disabled="loadingMore" @click="loadMore">
                        {{ loadingMore ? 'Cargando…' : 'Cargar más pagos' }}
                    </button>
                </div>
            </div>
        </div>

        <PaymentReviewModal v-model="reviewOpen" :payment="selected" @reviewed="onReviewed" />
    </div>
</template>
