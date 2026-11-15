<script setup>
/**
 * Bandeja de pagos: todos los pendientes (del más antiguo al más nuevo) y, debajo, los ya revisados.
 * Aprobar y rechazar solo se hace desde la revisión, después de ver el comprobante.
 */
import { ref, inject } from 'vue';
import axios from 'axios';
import { Inbox, FileText, Image as ImageIcon, ChevronDown } from 'lucide-vue-next';
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
    approved: 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/30',
    rejected: 'bg-rose-500/10 text-rose-800 dark:text-rose-300 border-rose-500/30',
};
</script>

<template>
    <div class="space-y-8">
        <div>
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <h3 class="font-serif text-xl text-foreground">Por revisar</h3>
                <p v-if="pending.length > 1" class="text-xs text-muted">Del más antiguo al más nuevo</p>
            </div>

            <ul v-if="pending.length" class="space-y-3">
                <li
                    v-for="payment in pending"
                    :key="payment.id"
                    class="rounded-brand border border-border bg-surface p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-4"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-semibold text-foreground truncate">{{ payment.user.name }}</p>
                        <p class="text-sm text-muted truncate">{{ payment.user.email }}</p>
                        <p class="mt-2 text-sm text-foreground">
                            <span class="font-semibold">{{ payment.plan?.name || 'Sin plan' }}</span>
                            <span class="text-muted"> · {{ payment.amount_formatted }}</span>
                            <span v-if="payment.plan" class="text-muted"> · {{ payment.plan.duration_label }}</span>
                        </p>
                        <p v-if="payment.student_note" class="mt-1 text-sm text-muted line-clamp-2">
                            «{{ payment.student_note }}»
                        </p>
                    </div>

                    <div class="flex items-center justify-between sm:justify-end gap-4 shrink-0">
                        <div class="text-right">
                            <p class="text-xs text-muted inline-flex items-center gap-1">
                                <component :is="payment.proof_kind === 'pdf' ? FileText : ImageIcon" class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ payment.proof_kind === 'pdf' ? 'Comprobante PDF' : 'Comprobante en imagen' }}
                            </p>
                            <p class="text-xs text-muted" :title="payment.created_at">Enviado {{ payment.created_human }}</p>
                        </div>
                        <button type="button" :class="btn.primary" @click="openReview(payment)">
                            Revisar
                            <span class="sr-only"> el pago de {{ payment.user.name }}</span>
                        </button>
                    </div>
                </li>
            </ul>

            <div v-else class="rounded-brand border border-dashed border-border bg-surface/60 px-6 py-10 text-center">
                <Inbox class="mx-auto h-8 w-8 text-muted" aria-hidden="true" />
                <p class="mt-3 text-base font-semibold text-foreground">No hay pagos por revisar</p>
                <p class="mt-1 text-sm text-muted">Cuando alguien envíe su comprobante, aparecerá aquí.</p>
            </div>
        </div>

        <div v-if="reviewedTotal > 0">
            <button
                type="button"
                class="w-full flex items-center justify-between gap-3 py-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 rounded-brand"
                :aria-expanded="showReviewed"
                aria-controls="reviewed-payments"
                @click="showReviewed = !showReviewed"
            >
                <span class="font-serif text-xl text-foreground">Ya revisados <span class="text-muted text-base tabular-nums">({{ reviewedTotal }})</span></span>
                <ChevronDown class="h-5 w-5 text-muted transition-transform duration-200" :class="{ 'rotate-180': showReviewed }" aria-hidden="true" />
            </button>

            <div v-show="showReviewed" id="reviewed-payments" class="mt-3">
                <ul class="divide-y divide-border rounded-brand border border-border bg-surface">
                    <li v-for="payment in reviewed" :key="payment.id" class="p-4 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-foreground truncate">
                                {{ payment.user.name }}
                                <span class="font-normal text-muted">· {{ payment.plan?.name || 'Sin plan' }} · {{ payment.amount_formatted }}</span>
                            </p>
                            <p v-if="payment.status === 'rejected' && payment.admin_notes" class="text-sm text-muted mt-0.5">
                                Motivo: {{ payment.admin_notes }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-xs text-muted">{{ payment.reviewed_at }}<template v-if="payment.reviewer_name"> · {{ payment.reviewer_name }}</template></span>
                            <span class="px-2.5 py-1 rounded-full border text-xs font-semibold" :class="statusStyle[payment.status]">
                                {{ payment.status_label }}
                            </span>
                            <button type="button" :class="btn.small" @click="openReview(payment)">
                                Ver
                                <span class="sr-only"> el pago de {{ payment.user.name }}</span>
                            </button>
                        </div>
                    </li>
                </ul>

                <div v-if="nextPage" class="mt-3 text-center">
                    <button type="button" :class="btn.secondary" :disabled="loadingMore" @click="loadMore">
                        {{ loadingMore ? 'Cargando…' : 'Ver más' }}
                    </button>
                </div>
            </div>
        </div>

        <PaymentReviewModal v-model="reviewOpen" :payment="selected" @reviewed="onReviewed" />
    </div>
</template>
