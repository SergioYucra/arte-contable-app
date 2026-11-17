<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { ChevronDown, CreditCard } from 'lucide-vue-next';
import BaseModal from './ui/BaseModal.vue';
import BaseToast from './ui/BaseToast.vue';
import MembershipFaq from './membership/MembershipFaq.vue';
import MembershipHero from './membership/MembershipHero.vue';
import MembershipHistory from './membership/MembershipHistory.vue';
import MembershipIncludes from './membership/MembershipIncludes.vue';
import MembershipNotices from './membership/MembershipNotices.vue';
import MembershipPaymentModal from './membership/MembershipPaymentModal.vue';
import MembershipPlanCard from './membership/MembershipPlanCard.vue';
import MembershipRedeemModal from './membership/MembershipRedeemModal.vue';
import { btn, readError } from './subscriptions/shared';
import { sectionLead, sectionTitle } from './membership/ui';

/**
 * Página de membresía del estudiante. Este componente guarda el estado y habla con el servidor;
 * cada parte de la pantalla vive en resources/js/components/membership/.
 */
const props = defineProps({
    initialPlans: { type: Array, default: () => [] },
    initialMembership: { type: Object, required: true },
    initialPendingRequest: { type: Object, default: null },
    initialRecentRequests: { type: Array, default: () => [] },
    initialRecentCodes: { type: Array, default: () => [] },
    storePaymentUrl: { type: String, required: true },
    redeemCodeUrl: { type: String, required: true },
    statusUrl: { type: String, required: true },
    libraryUrl: { type: String, default: '/biblioteca' },
});

// ---------------------------------------------------------------------------
// Estado
// ---------------------------------------------------------------------------
const plans = ref([...props.initialPlans]);
const membership = ref({ ...props.initialMembership });
const pendingRequest = ref(props.initialPendingRequest ? { ...props.initialPendingRequest } : null);
const recentRequests = ref([...props.initialRecentRequests]);
const recentCodes = ref([...props.initialRecentCodes]);

// Cuenta los cambios que hace la persona (enviar, retirar, canjear). Una consulta del estado que salió
// antes de uno de esos cambios trae datos viejos: al llegar se descarta en vez de pisar lo nuevo.
let changes = 0;

/** Aplica lo que devuelve el servidor (estado al día, retirar envío, canje). */
function applyState(data) {
    if (!data || typeof data !== 'object') return;
    if (Array.isArray(data.plans)) plans.value = data.plans;
    if (data.membership) membership.value = data.membership;
    if ('pending_request' in data) pendingRequest.value = data.pending_request;
    if (Array.isArray(data.recent_requests)) recentRequests.value = data.recent_requests;
    if (Array.isArray(data.recent_codes)) recentCodes.value = data.recent_codes;
}

// ---------------------------------------------------------------------------
// Avisos
// ---------------------------------------------------------------------------
const toast = ref(null);
let toastTimer = null;

function notify(type, title, message, ms = type === 'error' ? 7000 : 5000) {
    toast.value = { type, title, message };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), ms);
}

// La barra superior (fuera de este componente, la dibuja el servidor) también dice si la persona
// tiene acceso. Cuando el acceso cambia se recarga la página para que todo coincida, y el aviso
// se guarda para mostrarlo al volver.
const NOTICE_KEY = 'membership:notice';

function reloadWithNotice(type, title, message) {
    try {
        window.sessionStorage.setItem(NOTICE_KEY, JSON.stringify({ type, title, message }));
    } catch {
        // Sin almacenamiento no se recarga: el aviso importa más que la barra superior.
        notify(type, title, message, 12000);
        return;
    }
    window.location.reload();
}

function showSavedNotice() {
    try {
        const raw = window.sessionStorage.getItem(NOTICE_KEY);
        if (!raw) return;
        window.sessionStorage.removeItem(NOTICE_KEY);
        const saved = JSON.parse(raw);
        notify(saved.type, saved.title, saved.message, 12000);
    } catch {
        // Un aviso guardado ilegible se descarta.
    }
}

// El último pago no aprobado se muestra hasta que la persona lo cierra o envía otro.
const DISMISSED_KEY = 'membership:rejected-dismissed';
const dismissedRejectedId = ref(readDismissed());

function readDismissed() {
    try {
        return Number(window.localStorage.getItem(DISMISSED_KEY)) || null;
    } catch {
        return null;
    }
}

const rejectedRequest = computed(() => {
    if (pendingRequest.value) return null;
    const latest = recentRequests.value[0];
    if (!latest || latest.status !== 'rejected' || latest.id === dismissedRejectedId.value) return null;
    return latest;
});

function dismissRejected() {
    const id = recentRequests.value[0]?.id ?? null;
    dismissedRejectedId.value = id;
    try {
        if (id) window.localStorage.setItem(DISMISSED_KEY, String(id));
    } catch {
        // Sin almacenamiento disponible el aviso vuelve al recargar: no es grave.
    }
}

// ---------------------------------------------------------------------------
// Planes
// ---------------------------------------------------------------------------
// Quien ya tiene acceso no necesita ver los planes de entrada, salvo que esté por vencer.
const plansOpen = ref(!membership.value.is_active || membership.value.expiring_soon);
const plansHeading = ref(null);

const plansTitle = computed(() => (membership.value.is_active ? 'Suma más días a tu membresía' : 'Elige tu plan'));

const hasHistory = computed(() => recentRequests.value.length > 0 || recentCodes.value.length > 0);

// Con membresía vigente el historial va antes que los planes. Si está por vencer, primero los planes.
const historyFirst = computed(() => membership.value.is_active && !membership.value.expiring_soon);

function payDisabledReason(plan) {
    if (pendingRequest.value) return 'Ya tienes un pago en revisión. Cuando se resuelva podrás enviar otro.';
    if (!plan.qr_image_url) return 'Este plan todavía no tiene su QR de pago. Vuelve a intentarlo más tarde.';
    return null;
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

async function goToPlans() {
    plansOpen.value = true;
    await nextTick();
    const target = plansHeading.value;
    if (!target) return;
    target.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
    target.focus({ preventScroll: true });
}

// ---------------------------------------------------------------------------
// Pagar
// ---------------------------------------------------------------------------
const paymentOpen = ref(false);
const selectedPlanId = ref(null);
// Se busca por id: si el servidor avisa que el precio cambió, el modal muestra el plan al día.
const selectedPlan = computed(() => plans.value.find((plan) => plan.id === selectedPlanId.value) ?? null);

function openPayment(plan) {
    if (payDisabledReason(plan)) return;
    selectedPlanId.value = plan.id;
    paymentOpen.value = true;
}

function onPaymentSent(data) {
    changes++;
    pendingRequest.value = data.payment_request;
    recentRequests.value = [data.payment_request, ...recentRequests.value];
    paymentOpen.value = false;
    notify('success', 'Comprobante enviado', data.message);
}

function onPendingFound(data) {
    // Se envió desde otra pestaña u otro dispositivo: se muestra el que ya está en revisión.
    if (data.pending_request) pendingRequest.value = data.pending_request;
    paymentOpen.value = false;
    notify('info', 'Ya tienes un pago en revisión', data.message);
    refreshState({ announce: false });
}

function onPlanUnavailable(data) {
    paymentOpen.value = false;
    notify('error', 'Ese plan ya no está disponible', data.message);

    if (Array.isArray(data.plans)) {
        plans.value = data.plans;
    } else {
        // El plan se archivó: la respuesta no trae la lista, así que se pide.
        refreshState({ announce: false });
    }
}

// ---------------------------------------------------------------------------
// Canjear código
// ---------------------------------------------------------------------------
const redeemOpen = ref(false);

function onRedeemed(data) {
    changes++;
    applyState(data);
    if (data.redeemed_code) recentCodes.value = [data.redeemed_code, ...recentCodes.value];
    redeemOpen.value = false;
    reloadWithNotice('success', 'Código canjeado', data.message);
}

// ---------------------------------------------------------------------------
// Retirar un envío
// ---------------------------------------------------------------------------
const withdrawOpen = ref(false);
const withdrawing = ref(false);
const withdrawError = ref('');

function askWithdraw() {
    withdrawError.value = '';
    withdrawOpen.value = true;
}

async function confirmWithdraw() {
    const url = pendingRequest.value?.cancel_url;
    if (!url || withdrawing.value) return;

    withdrawing.value = true;
    withdrawError.value = '';
    changes++;

    try {
        const { data } = await axios.delete(url);
        changes++;
        applyState(data);
        withdrawOpen.value = false;
        plansOpen.value = true;
        notify('success', 'Envío retirado', data.message);
    } catch (error) {
        const data = error?.response?.data;

        if (data?.code === 'already_reviewed') {
            // Lo revisaron mientras la persona decidía: se muestra el resultado.
            applyState(data);
            withdrawOpen.value = false;
            notify('info', 'Tu pago ya fue revisado', data.message);
        } else if (error?.response?.status === 404) {
            withdrawOpen.value = false;
            await refreshState({ announce: false });
        } else {
            withdrawError.value = readError(error, 'No se pudo retirar el envío. Inténtalo de nuevo.').message;
        }
    } finally {
        withdrawing.value = false;
    }
}

// ---------------------------------------------------------------------------
// Estado al día: mientras hay un pago en revisión, la página pregunta sola
// ---------------------------------------------------------------------------
const POLL_MS = 45000;
let pollTimer = null;
let pollingAllowed = true;

async function refreshState({ announce = true } = {}) {
    const watchedId = pendingRequest.value?.id ?? null;
    const changesAtStart = changes;

    try {
        const { data } = await axios.get(props.statusUrl);
        // Mientras llegaba la respuesta la persona hizo un cambio: estos datos ya son viejos.
        if (changesAtStart !== changes) return;
        applyState(data);
        if (announce && watchedId && !data.pending_request) announceReview(watchedId, data);
    } catch (error) {
        // Sesión vencida o sin permiso: no tiene sentido seguir preguntando.
        if ([401, 403, 419].includes(error?.response?.status)) {
            pollingAllowed = false;
            syncPolling();
        }
    }
}

function announceReview(id, data) {
    const reviewed = (data.recent_requests ?? []).find((request) => request.id === id);
    if (!reviewed) return;

    if (reviewed.status === 'approved') {
        reloadWithNotice('success', 'Tu pago fue aprobado', `Ya puedes leer todo hasta el ${data.membership?.expires_at}.`);
    } else if (reviewed.status === 'rejected') {
        notify('error', 'Tu pago no fue aprobado', 'Arriba puedes ver el motivo y enviar otro comprobante.', 12000);
    }
}

function syncPolling() {
    const shouldPoll = pollingAllowed && !!pendingRequest.value && document.visibilityState === 'visible';

    if (shouldPoll && !pollTimer) {
        pollTimer = setInterval(refreshState, POLL_MS);
    } else if (!shouldPoll && pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function onVisibilityChange() {
    // Al volver a la pestaña se pregunta enseguida: es cuando la persona quiere saber.
    if (document.visibilityState === 'visible' && pendingRequest.value && pollingAllowed) refreshState();
    syncPolling();
}

watch(pendingRequest, syncPolling);

onMounted(() => {
    showSavedNotice();
    document.addEventListener('visibilitychange', onVisibilityChange);
    syncPolling();
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    clearInterval(pollTimer);
    clearTimeout(toastTimer);
});
</script>

<template>
    <div class="space-y-6 sm:space-y-8 w-full pb-12">
        <BaseToast v-model="toast" />

        <MembershipNotices
            :pending-request="pendingRequest"
            :rejected-request="rejectedRequest"
            :is-staff="!!membership.is_staff"
            @withdraw="askWithdraw"
            @send-another="goToPlans"
            @dismiss-rejected="dismissRejected"
        />

        <MembershipHero
            :membership="membership"
            :has-pending="!!pendingRequest"
            :library-url="libraryUrl"
            @plans="goToPlans"
            @redeem="redeemOpen = true"
        />

        <!-- Quien ya tiene membresía viene sobre todo a ver lo que pagó y canjeó: el historial va primero. -->
        <MembershipHistory v-if="historyFirst && hasHistory" :requests="recentRequests" :codes="recentCodes" />

        <!-- Planes -->
        <section aria-labelledby="membership-plans-title" class="space-y-4 scroll-mt-20">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <div>
                    <h2
                        id="membership-plans-title"
                        ref="plansHeading"
                        tabindex="-1"
                        :class="[sectionTitle, 'focus:outline-none']"
                    >
                        {{ plansTitle }}
                    </h2>
                    <p :class="sectionLead">
                        Pagas una sola vez con el QR de tu banco y subes tu comprobante. No se cobra solo cada mes.
                    </p>
                </div>

                <button
                    v-if="membership.is_active && plans.length"
                    type="button"
                    :class="[btn.secondary, 'self-start sm:self-auto shrink-0']"
                    :aria-expanded="plansOpen"
                    aria-controls="membership-plans-list"
                    @click="plansOpen = !plansOpen"
                >
                    <span>{{ plansOpen ? 'Ocultar planes' : 'Ver planes' }}</span>
                    <ChevronDown
                        class="h-4 w-4 text-muted transition-transform duration-200 motion-reduce:transition-none"
                        :class="{ 'rotate-180': plansOpen }"
                        aria-hidden="true"
                    />
                </button>
            </div>

            <template v-if="plans.length">
                <!-- Las columnas se ajustan a cuántos planes hay: dos planes ocupan todo el ancho, sin un hueco al lado. -->
                <ul
                    v-if="plansOpen"
                    id="membership-plans-list"
                    class="grid grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(17rem,1fr))] gap-4 sm:gap-5 pt-2"
                >
                    <MembershipPlanCard
                        v-for="plan in plans"
                        :key="plan.id"
                        :plan="plan"
                        :disabled-reason="payDisabledReason(plan)"
                        @pay="openPayment"
                    />
                </ul>
            </template>

            <div v-else class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-2.5">
                <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                    <CreditCard class="h-6 w-6" aria-hidden="true" />
                </div>
                <h3 class="font-serif text-[19px] font-medium text-foreground">Todavía no hay planes a la venta</h3>
                <p class="text-sm text-muted max-w-md mx-auto">
                    La administración los publicará aquí. Si tienes un código de acceso, puedes usarlo ahora.
                </p>
                <button type="button" :class="btn.primary" @click="redeemOpen = true">Tengo un código</button>
            </div>
        </section>

        <MembershipHistory v-if="!historyFirst && hasHistory" :requests="recentRequests" :codes="recentCodes" />

        <MembershipFaq />

        <!-- Al final y solo en pantallas grandes: es un recordatorio, no hace falta para usar la página. -->
        <MembershipIncludes v-if="membership.is_active && !membership.is_staff" />

        <MembershipPaymentModal
            v-model="paymentOpen"
            :plan="selectedPlan"
            :store-url="storePaymentUrl"
            @sent="onPaymentSent"
            @plans-updated="plans = $event"
            @pending-found="onPendingFound"
            @unavailable="onPlanUnavailable"
        />

        <MembershipRedeemModal v-model="redeemOpen" :redeem-url="redeemCodeUrl" @redeemed="onRedeemed" />

        <!-- Confirmar el retiro: borra el comprobante, así que se pregunta antes -->
        <BaseModal v-model="withdrawOpen" title="¿Retirar tu envío?" max-width="sm" :closeable="!withdrawing">
            <div class="space-y-3">
                <p class="text-sm leading-relaxed text-foreground">
                    Se borrará el comprobante que subiste y nadie lo revisará. Después podrás elegir un plan y enviar otro.
                </p>
                <p class="text-xs leading-relaxed text-muted">
                    Si ya pagaste, tu dinero no se mueve: solo vuelve a subir el comprobante correcto.
                </p>
                <p v-if="withdrawError" role="alert" class="text-xs text-rose-600 dark:text-rose-400">{{ withdrawError }}</p>
            </div>

            <template #footer>
                <button type="button" :class="btn.secondary" :disabled="withdrawing" @click="withdrawOpen = false">
                    No, dejarlo
                </button>
                <button type="button" :class="btn.danger" :disabled="withdrawing" @click="confirmWithdraw">
                    {{ withdrawing ? 'Retirando…' : 'Sí, retirar' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
