<script setup>
/**
 * Panel de membresías y pagos (raíz de la isla).
 *
 * Reparte el trabajo en cuatro pestañas: pagos por revisar, personas con acceso, planes y códigos.
 * El estado compartido (cifras de cabecera, planes y avisos) se ofrece con provide/inject.
 */
import { ref, provide, computed, watch } from 'vue';
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

// BaseToast espera un objeto { type, title, message } o null. Antes se le pasaba un booleano
// y props sueltas, por eso los avisos salían vacíos y siempre verdes.
const toast = ref(null);
let toastTimer = null;

function notify(type, title, message = '') {
    toast.value = { type, title, message };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), type === 'error' ? 8000 : 5000);
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
    { id: 'payments', label: 'Pagos', count: summary.value.pending_payments, attention: summary.value.pending_payments > 0 },
    { id: 'members', label: 'Personas con acceso', count: summary.value.active_members },
    { id: 'plans', label: 'Planes', count: plans.value.filter((p) => !p.is_archived).length },
    { id: 'codes', label: 'Códigos', count: summary.value.unused_codes },
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
    <div class="space-y-6">
        <BaseToast v-model="toast" />

        <SubscriptionSummary />

        <div
            role="tablist"
            aria-label="Secciones de suscripciones"
            class="flex gap-1 overflow-x-auto no-scrollbar border-b border-border"
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
                class="relative shrink-0 inline-flex items-center gap-2 px-4 py-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 rounded-t-brand"
                :class="currentTab === tab.id ? 'text-foreground' : 'text-muted hover:text-foreground'"
                @click="currentTab = tab.id"
                @keydown="onTabKeydown($event, index)"
            >
                {{ tab.label }}
                <span
                    class="min-w-[1.5rem] px-1.5 py-0.5 rounded-full text-xs font-bold tabular-nums text-center"
                    :class="tab.attention ? 'bg-gold text-gold-foreground' : 'bg-background text-muted border border-border'"
                >
                    {{ tab.count }}
                </span>
                <span
                    v-if="currentTab === tab.id"
                    class="absolute inset-x-3 -bottom-px h-0.5 rounded-full bg-primary"
                    aria-hidden="true"
                ></span>
            </button>
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
            v-show="currentTab === 'plans'"
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
