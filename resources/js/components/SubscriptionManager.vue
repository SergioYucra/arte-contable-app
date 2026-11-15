<script setup>
/**
 * Panel de membresías y pagos (raíz de la isla).
 *
 * Reparte el trabajo en cuatro pestañas: pagos por revisar, personas con acceso, planes y códigos.
 * El estado compartido (cifras de cabecera, planes y avisos) se ofrece con provide/inject.
 */
import { ref, provide, computed, watch } from 'vue';
import { Inbox, Users, CreditCard, KeyRound } from 'lucide-vue-next';
import BaseToast from './ui/BaseToast.vue';
import SubscriptionSummary from './subscriptions/SubscriptionSummary.vue';
import PaymentInbox from './subscriptions/PaymentInbox.vue';
import MembersPanel from './subscriptions/MembersPanel.vue';
import PlansPanel from './subscriptions/PlansPanel.vue';
import CodesPanel from './subscriptions/CodesPanel.vue';
import { PANEL_CONTEXT } from './subscriptions/shared';

const props = defineProps({
    initialPlans: { type: Array, required: true },
    initialPendingRequests: { type: Array, required: true },
    initialReviewedRequests: { type: Object, required: true },
    initialAccessCodes: { type: Object, required: true },
    initialSummary: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const summary = ref({ ...props.initialSummary });
const plans = ref(JSON.parse(JSON.stringify(props.initialPlans)));
const TAB_IDS = ['payments', 'members', 'plans', 'codes'];

// La pestaña elegida queda en la dirección (#personas, #planes…): al recargar o compartir el enlace se vuelve a ella.
const TAB_HASH = { payments: 'pagos', members: 'personas', plans: 'planes', codes: 'codigos' };
const hashTab = Object.keys(TAB_HASH).find((id) => `#${TAB_HASH[id]}` === window.location.hash);
const currentTab = ref(TAB_IDS.includes(hashTab) ? hashTab : 'payments');
const membersFilter = ref('active');

watch(currentTab, (tab) => {
    history.replaceState(null, '', tab === 'payments' ? window.location.pathname + window.location.search : `#${TAB_HASH[tab]}`);
});

// BaseToast espera un objeto { type, title, message } o null.
const toast = ref(null);
let toastTimer = null;

function notify(type, title, message = '') {
    toast.value = { type, title, message };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), type === 'error' ? 6000 : 3500);
}

function updateSummary(next) {
    if (next && typeof next === 'object') summary.value = { ...summary.value, ...next };
}

function goTo(tab, filter = null) {
    currentTab.value = tab;
    if (tab === 'members' && filter) membersFilter.value = filter;
    requestAnimationFrame(() => document.getElementById(`panel-${tab}`)?.focus({ preventScroll: true }));
}

provide(PANEL_CONTEXT, { summary, plans, urls: props.urls, notify, updateSummary, goTo });

const tabs = computed(() => [
    {
        id: 'payments',
        label: 'Pagos por revisar',
        short: 'Pagos',
        icon: Inbox,
        count: summary.value.pending_payments,
        attention: summary.value.pending_payments > 0,
    },
    {
        id: 'members',
        label: 'Personas con acceso',
        short: 'Personas',
        icon: Users,
        count: summary.value.active_members,
    },
    {
        id: 'plans',
        label: 'Planes y QR',
        short: 'Planes',
        icon: CreditCard,
        count: plans.value.filter((p) => !p.is_archived).length,
    },
    {
        id: 'codes',
        label: 'Códigos de acceso',
        short: 'Códigos',
        icon: KeyRound,
        count: summary.value.unused_codes,
    },
]);

function onTabKeydown(event, index) {
    const keys = { ArrowRight: 1, ArrowLeft: -1 };
    if (!(event.key in keys)) return;
    event.preventDefault();
    const next = (index + keys[event.key] + tabs.value.length) % tabs.value.length;
    currentTab.value = tabs.value[next].id;
    document.getElementById(`tab-${tabs.value[next].id}`)?.focus();
}
</script>

<template>
    <div class="space-y-4 sm:space-y-5">
        <BaseToast v-model="toast" />

        <!-- Cabecera unificada con resumen operativo y pestañas (estándar /admin/libros) -->
        <div class="bg-surface border border-border rounded-brand overflow-hidden">
            <SubscriptionSummary />

            <div class="px-1.5 sm:px-4 border-t border-border bg-background/50 overflow-x-auto no-scrollbar">
                <div
                    role="tablist"
                    aria-label="Secciones de suscripciones"
                    class="flex items-stretch gap-0 sm:gap-1 min-w-max sm:min-w-0"
                >
                    <button
                        v-for="(tab, index) in tabs"
                        :id="`tab-${tab.id}`"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        :aria-selected="currentTab === tab.id"
                        :aria-controls="`panel-${tab.id}`"
                        :tabindex="currentTab === tab.id ? 0 : -1"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 sm:gap-2 px-2.5 sm:px-3.5 py-3 text-xs sm:text-sm font-medium border-b-2 whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        :class="
                            currentTab === tab.id
                                ? 'border-primary text-primary font-semibold'
                                : 'border-transparent text-muted hover:text-foreground'
                        "
                        @click="currentTab = tab.id"
                        @keydown="onTabKeydown($event, index)"
                    >
                        <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="sm:hidden">{{ tab.short }}</span>
                        <span class="hidden sm:inline">{{ tab.label }}</span>
                        <span
                            class="px-1.5 py-0.5 rounded-full text-[10px] sm:text-[11px] font-semibold tabular-nums border"
                            :class="
                                tab.attention
                                    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                                    : currentTab === tab.id
                                      ? 'bg-primary/10 text-primary border-primary/20'
                                      : 'bg-surface text-muted border-border'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <section
            v-show="currentTab === 'payments'"
            id="panel-payments"
            role="tabpanel"
            aria-labelledby="tab-payments"
            tabindex="-1"
            class="focus:outline-none"
        >
            <PaymentInbox
                :initial-pending="initialPendingRequests"
                :initial-reviewed="initialReviewedRequests"
            />
        </section>

        <section
            v-if="currentTab === 'members'"
            id="panel-members"
            role="tabpanel"
            aria-labelledby="tab-members"
            tabindex="-1"
            class="focus:outline-none"
        >
            <MembersPanel v-model:filter="membersFilter" />
        </section>

        <section
            v-if="currentTab === 'plans'"
            id="panel-plans"
            role="tabpanel"
            aria-labelledby="tab-plans"
            tabindex="-1"
            class="focus:outline-none"
        >
            <PlansPanel />
        </section>

        <section
            v-show="currentTab === 'codes'"
            id="panel-codes"
            role="tabpanel"
            aria-labelledby="tab-codes"
            tabindex="-1"
            class="focus:outline-none"
        >
            <CodesPanel :initial-codes="initialAccessCodes" />
        </section>
    </div>
</template>
