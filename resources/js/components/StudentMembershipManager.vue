<script setup>
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import {
    Award,
    BookOpen,
    Calendar,
    Check,
    CheckCircle2,
    ChevronDown,
    Clock,
    CreditCard,
    ExternalLink,
    FileText,
    KeyRound,
    Laptop,
    Lock,
    QrCode,
    ShieldCheck,
    Sparkles,
    Star,
    UploadCloud,
    X,
    Zap,
} from 'lucide-vue-next';
import BaseAlert from './ui/BaseAlert.vue';
import BaseModal from './ui/BaseModal.vue';
import BaseToast from './ui/BaseToast.vue';

const props = defineProps({
    initialPlans: {
        type: Array,
        default: () => [],
    },
    initialMembership: {
        type: Object,
        default: () => ({
            is_active: false,
            status_label: 'Sin membresía activa',
            plan_name: 'Acceso Gratuito',
            days_remaining: 0,
            expires_at: null,
            starts_at: null,
        }),
    },
    initialPendingRequest: {
        type: Object,
        default: null,
    },
    initialRecentRequests: {
        type: Array,
        default: () => [],
    },
    initialRecentCodes: {
        type: Array,
        default: () => [],
    },
    storePaymentUrl: {
        type: String,
        required: true,
    },
    redeemCodeUrl: {
        type: String,
        required: true,
    },
    userName: {
        type: String,
        default: 'Estudiante',
    },
    userEmail: {
        type: String,
        default: '',
    },
    libraryUrl: {
        type: String,
        default: '/biblioteca',
    },
});

// =========================================================================
// ESTADO REACTIVO
// =========================================================================
const plans = ref([...props.initialPlans]);
const membership = ref({ ...props.initialMembership });
const pendingRequest = ref(props.initialPendingRequest ? { ...props.initialPendingRequest } : null);
const recentRequests = ref([...props.initialRecentRequests]);
const recentCodes = ref([...props.initialRecentCodes]);

// Control para extender vigencia en usuarios con membresía activa
const showExtendPlans = ref(false);

// Modal de Canje de Código Prepago
const isRedeemModalOpen = ref(false);
const redeemCodeInput = ref('');
const redeemErrors = ref({});
const isRedeeming = ref(false);
const redeemSuccessInfo = ref(null);

// Modal de Checkout / Pago por QR
const isPaymentModalOpen = ref(false);
const selectedPlanForPayment = ref(null);
const paymentForm = ref({
    student_notes: '',
    proof_file: null,
    previewUrl: null,
    fileName: '',
    isPdf: false,
});
const paymentErrors = ref({});
const isSubmittingPayment = ref(false);

// Sección de Historial desplegable
const showHistory = ref(false);

// Barra de progreso de membresía activa. QA: antes dividía siempre por 365, así que un plan
// semestral recién pagado aparecía casi a la mitad. Ahora usa la duración real de la vigencia.
const membershipProgressPercent = computed(() => {
    if (!membership.value.is_active || !membership.value.days_remaining) return 0;
    const days = Number(membership.value.days_remaining);
    const total = Math.max(days, Number(membership.value.total_days) || 365);
    return Math.min(100, Math.max(8, Math.round((days / total) * 100)));
});

// Último pago rechazado (sin otro en revisión): la persona tiene que ver el motivo para corregirlo.
const lastRejectedRequest = computed(() => {
    if (pendingRequest.value) return null;
    const latest = recentRequests.value[0];
    return latest && latest.status === 'rejected' ? latest : null;
});

// Comprobante: mismas reglas que el servidor, para avisar antes de subir.
const PROOF_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const PROOF_MAX_BYTES = 5 * 1024 * 1024;

// Acordeón de FAQs
const faqs = ref([
    {
        q: '¿Cómo funciona la activación mediante pago por QR Simple?',
        a: 'Seleccionas el plan deseado, abres tu aplicación bancaria móvil (BCP, Banco Unión, BNB, Mercantil, etc.), escaneas el código QR por el monto exacto y adjuntas la captura del comprobante. El equipo revisa tu pago y, al aprobarlo, tu acceso se activa de inmediato. Si lo rechaza, verás el motivo en esta página.',
        open: false,
    },
    {
        q: '¿Qué sucede si ya tengo una membresía activa y compro otro plan o canjeo un código?',
        a: 'El sistema suma acumulativamente los nuevos días a tu fecha de vencimiento actual. Por ejemplo, si te quedaban 20 días y canjeas un código de 180 días, tu nueva vigencia será de 200 días sin perder ningún día previo.',
        open: false,
    },
    {
        q: '¿Los planes tienen cobro recurrente automático?',
        a: 'No. En «El Arte de la Contabilidad» todos los planes son de pago único mediante transferencia o QR bancario. Nunca te cobraremos automáticamente ni requerimos registrar tarjetas de crédito.',
        open: false,
    },
    {
        q: '¿Puedo leer en mi celular, tablet y computadora?',
        a: 'Sí. La plataforma está optimizada para cualquier navegador web moderno (Chrome, Safari, Edge, Firefox) con diseño adaptativo y visor protegido. Tu avance de lectura se guarda en tu cuenta.',
        open: false,
    },
    {
        q: 'Compré un libro impreso, ¿dónde encuentro mi código de acceso?',
        a: 'Si tu libro impreso trae un código de acceso, o te lo entregó el autor, haz clic en «Canjear código PIN» e ingrésalo. Mayúsculas y minúsculas valen lo mismo. Cada código sirve una sola vez.',
        open: false,
    },
]);

function toggleFaq(index) {
    faqs.value[index].open = !faqs.value[index].open;
}

// Sistema Toast. QA: BaseToast espera un objeto { type, title, message } o null; antes recibía
// un booleano y props sueltas, por eso el aviso salía vacío.
const toast = ref(null);
let toastTimer = null;

function notify(type, title, message) {
    toast.value = { type, title, message };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = null), 6000);
}

function extractErrors(err) {
    const res = err?.response?.data;
    const message = res?.message || 'Ocurrió un error inesperado al procesar la solicitud.';
    const fieldErrors = {};
    if (res?.errors && typeof res.errors === 'object') {
        for (const [key, val] of Object.entries(res.errors)) {
            fieldErrors[key] = Array.isArray(val) ? val[0] : String(val);
        }
    }
    return { message, fieldErrors };
}

// =========================================================================
// PLANES Y RECOMENDACIÓN
// =========================================================================
const sortedPlans = computed(() => {
    return [...plans.value].sort((a, b) => a.sort_order - b.sort_order || a.price - b.price);
});

const recommendedPlanId = computed(() => {
    if (!sortedPlans.value.length) return null;
    const annual = sortedPlans.value.find((p) => p.duration_days >= 300);
    if (annual) return annual.id;
    return sortedPlans.value.reduce((prev, curr) => (curr.duration_days > prev.duration_days ? curr : prev), sortedPlans.value[0]).id;
});

function getMonthlyEquivalent(plan) {
    if (!plan || !plan.duration_days || !plan.price) return '';
    const months = plan.duration_days / 30;
    const monthly = (plan.price / months).toFixed(1);
    return `Bs. ${monthly} / mes`;
}

// =========================================================================
// CHECKOUT Y CANJE
// =========================================================================
function openPaymentModal(plan) {
    // QA: con un pago en revisión se podía llenar todo el formulario y recién al final salía el rechazo.
    if (pendingRequest.value) {
        notify('info', 'Ya tienes un pago en revisión', 'Cuando se confirme podrás enviar otro. Mientras tanto puedes usar un código de acceso.');
        return;
    }
    selectedPlanForPayment.value = plan;
    revokePaymentPreview();
    paymentForm.value = {
        student_notes: '',
        proof_file: null,
        previewUrl: null,
        fileName: '',
        isPdf: false,
    };
    paymentErrors.value = {};
    isPaymentModalOpen.value = true;
}

function revokePaymentPreview() {
    if (paymentForm.value.previewUrl && paymentForm.value.previewUrl.startsWith('blob:')) {
        URL.revokeObjectURL(paymentForm.value.previewUrl);
    }
}

function handleProofFileChange(event) {
    // No se limpia el campo: es "required" en el formulario y vaciarlo bloquea el envío en el navegador.
    const file = event.target.files?.[0];
    if (!file) return;

    // QA: un archivo inválido se mostraba con un ✓ verde y solo fallaba al enviar.
    if (!PROOF_TYPES.includes(file.type)) {
        paymentErrors.value = { proof_file: 'Elige una imagen (JPG, PNG o WebP) o un PDF.' };
        return;
    }
    if (file.size > PROOF_MAX_BYTES) {
        paymentErrors.value = { proof_file: 'El archivo pesa más de 5 MB. Envía una captura de pantalla del comprobante en lugar de una foto.' };
        return;
    }
    paymentErrors.value = {};

    revokePaymentPreview();
    const isPdf = file.type === 'application/pdf';
    paymentForm.value.proof_file = file;
    paymentForm.value.fileName = file.name;
    paymentForm.value.isPdf = isPdf;

    if (!isPdf && file.type.startsWith('image/')) {
        paymentForm.value.previewUrl = URL.createObjectURL(file);
    } else {
        paymentForm.value.previewUrl = null;
    }
}

async function submitPayment() {
    if (!selectedPlanForPayment.value) return;
    paymentErrors.value = {};

    if (!paymentForm.value.proof_file) {
        paymentErrors.value.proof_file = 'Debes adjuntar el comprobante o captura de tu transferencia.';
        return;
    }

    isSubmittingPayment.value = true;

    try {
        const formData = new FormData();
        formData.append('subscription_plan_id', selectedPlanForPayment.value.id);
        formData.append('proof_file', paymentForm.value.proof_file);
        if (paymentForm.value.student_notes) {
            formData.append('student_notes', paymentForm.value.student_notes.trim());
        }

        const { data } = await axios.post(props.storePaymentUrl, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        pendingRequest.value = data.payment_request;
        recentRequests.value.unshift(data.payment_request);
        isPaymentModalOpen.value = false;
        notify('success', '¡Comprobante enviado con éxito!', data.message);
    } catch (err) {
        const { fieldErrors, message } = extractErrors(err);
        paymentErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            paymentErrors.value.general = message;
        }
    } finally {
        isSubmittingPayment.value = false;
    }
}

function openRedeemModal() {
    redeemCodeInput.value = '';
    redeemErrors.value = {};
    redeemSuccessInfo.value = null;
    isRedeemModalOpen.value = true;
}

async function submitRedeemCode() {
    redeemErrors.value = {};
    redeemSuccessInfo.value = null;

    const code = redeemCodeInput.value.trim().toUpperCase();
    if (!code) {
        redeemErrors.value.code = 'Ingresa el código prepago.';
        return;
    }

    isRedeeming.value = true;

    try {
        const { data } = await axios.post(props.redeemCodeUrl, { code });
        membership.value = data.membership;
        redeemSuccessInfo.value = data.message;
        redeemCodeInput.value = '';

        if (data.redeemed_code) recentCodes.value.unshift(data.redeemed_code);

        notify('success', 'Código canjeado', data.message);
    } catch (err) {
        const { fieldErrors, message } = extractErrors(err);
        redeemErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            redeemErrors.value.general = message;
        }
    } finally {
        isRedeeming.value = false;
    }
}

function toggleExtendPlans() {
    showExtendPlans.value = !showExtendPlans.value;
    if (showExtendPlans.value) {
        nextTick(() => {
            const el = document.getElementById('extend-plans-section');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }
}
</script>

<template>
    <div class="space-y-6 w-full pb-10">
        <!-- Toast de Notificaciones -->
        <BaseToast v-model="toast" />

        <!-- ================================================================= -->
        <!-- ALERTA DE PAGO EN REVISIÓN (Si tiene una solicitud pendiente)     -->
        <!-- ================================================================= -->
        <div
            v-if="pendingRequest"
            class="p-3 sm:p-3.5 rounded-xl border border-amber-500/30 bg-amber-500/10 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 sm:gap-3 text-xs"
        >
            <div class="flex items-center gap-2.5 min-w-0">
                <Clock class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 animate-pulse" />
                <span class="text-amber-900 dark:text-amber-200 leading-snug">
                    <strong class="font-bold">Pago en revisión:</strong> Comprobante para <strong>{{ pendingRequest.plan_name }}</strong> ({{ pendingRequest.amount_formatted }}) enviado el {{ pendingRequest.created_at }}. Se activa cuando el equipo lo revise.
                </span>
            </div>
            <a
                :href="pendingRequest.proof_url"
                target="_blank"
                rel="noopener"
                class="self-start sm:self-auto inline-flex items-center gap-1 text-[11px] font-semibold text-foreground hover:text-primary transition-colors shrink-0 underline decoration-dotted"
            >
                <span>Ver comprobante</span>
                <ExternalLink class="h-3 w-3" />
            </a>
        </div>

        <!-- QA: el motivo del rechazo nunca se mostraba al estudiante, aunque el panel dice que lo verá. -->
        <div
            v-if="lastRejectedRequest"
            class="p-3 sm:p-3.5 rounded-xl border border-rose-500/30 bg-rose-500/5 text-xs text-foreground"
            role="status"
        >
            <strong class="font-bold">Tu último pago no fue aprobado</strong>
            ({{ lastRejectedRequest.plan_name }}, {{ lastRejectedRequest.amount_formatted }}).
            <span v-if="lastRejectedRequest.admin_notes">Motivo: «{{ lastRejectedRequest.admin_notes }}».</span>
            Puedes enviar un comprobante nuevo eligiendo el plan otra vez.
        </div>

        <!-- QA: quien venció o perdió el acceso veía "Acceso Gratuito" sin explicación (lámina 14 de Max). -->
        <div
            v-if="!membership.is_active && membership.previous"
            class="p-3.5 sm:p-4 rounded-xl border border-amber-500/30 bg-amber-500/10 text-sm text-foreground"
            role="status"
        >
            <template v-if="membership.previous.state === 'expired'">
                <strong class="font-bold">Tu suscripción venció el {{ membership.previous.until }}.</strong>
                Conservas tus apuntes y tu avance. Elige un plan o usa un código para seguir leyendo todo.
            </template>
            <template v-else>
                <strong class="font-bold">Tu acceso fue retirado.</strong>
                Conservas tus apuntes y tu avance. Si crees que es un error, comunícate con la administración.
            </template>
        </div>

        <!-- ================================================================= -->
        <!-- MODO A: EL USUARIO TIENE UNA MEMBRESÍA ACTIVA (HUB DEL SUSCRIPTOR) -->
        <!-- ================================================================= -->
        <div v-if="membership.is_active" class="space-y-6">
            <!-- PANEL PRINCIPAL DE MEMBRESÍA ACTIVA (VIP PASS) -->
            <div class="relative overflow-hidden rounded-2xl sm:rounded-3xl border border-emerald-500/30 bg-gradient-to-br from-emerald-500/10 via-surface to-background p-5 sm:p-8 shadow-sm">
                <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-emerald-500/10 blur-3xl" aria-hidden="true"></div>

                <div class="relative flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6">
                    <div class="space-y-3 max-w-xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25 tracking-wide uppercase">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                Membresía Activa
                            </span>
                            <span class="text-xs font-semibold text-muted">
                                {{ membership.plan_name }}
                            </span>
                        </div>

                        <h2 class="text-2xl sm:text-3xl font-black tracking-tight text-foreground">
                            ¡Acceso completo habilitado, {{ props.userName }}!
                        </h2>

                        <p class="text-xs sm:text-sm text-muted leading-relaxed">
                            Cuentas con la membresía <strong class="text-foreground">{{ membership.plan_name }}</strong>. Puedes leer todos los libros publicados sin páginas bloqueadas.
                        </p>

                        <!-- Barra de progreso de vigencia -->
                        <div class="pt-2 space-y-1.5 max-w-md">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-muted font-medium">Vigencia de tu suscripción</span>
                                <span class="font-bold text-foreground">
                                    Hasta el {{ membership.expires_at }}
                                </span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-background border border-border overflow-hidden">
                                <div
                                    class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-all duration-500"
                                    :style="{ width: `${membershipProgressPercent}%` }"
                                ></div>
                            </div>
                            <p class="text-[11px] text-muted flex items-center gap-1 pt-0.5">
                                <Calendar class="h-3.5 w-3.5" />
                                <span>Inició el {{ membership.starts_at }} · Vence el {{ membership.expires_at }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Bloque de Tiempo y Acciones del Alumno Activo -->
                    <div class="w-full lg:w-80 shrink-0 flex flex-col justify-between gap-3.5 sm:gap-4 p-4 sm:p-5 rounded-2xl bg-surface/90 border border-border shadow-xs">
                        <div class="flex items-center justify-between sm:flex-row lg:flex-col lg:items-end w-full">
                            <p class="text-[11px] sm:text-xs font-semibold uppercase tracking-wider text-muted">Tiempo restante</p>
                            <div class="text-2xl sm:text-3xl lg:text-4xl font-black text-primary tracking-tight">
                                {{ membership.days_remaining }} <span class="text-sm sm:text-base font-bold text-muted">días</span>
                            </div>
                        </div>

                        <!-- Botones de Acción Ergonómicos y Responsivos -->
                        <div class="flex flex-col gap-2 w-full">
                            <a
                                :href="props.libraryUrl"
                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 min-h-[42px] sm:min-h-[44px] rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-bold shadow-xs transition-all active:scale-[0.98]"
                            >
                                <BookOpen class="h-4 w-4 shrink-0" />
                                <span>Ir a mis libros</span>
                            </a>

                            <div class="grid grid-cols-2 gap-2 w-full">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-1.5 px-2.5 py-2.5 min-h-[42px] sm:min-h-[44px] rounded-brand bg-background border border-border hover:border-primary/40 text-foreground text-xs font-semibold transition-all active:scale-[0.98]"
                                    @click="openRedeemModal"
                                >
                                    <KeyRound class="h-3.5 w-3.5 text-primary shrink-0" />
                                    <span class="truncate">Canjear PIN</span>
                                </button>

                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-1.5 px-2.5 py-2.5 min-h-[42px] sm:min-h-[44px] rounded-brand text-xs font-bold transition-all border active:scale-[0.98]"
                                    :class="
                                        showExtendPlans
                                            ? 'bg-primary text-primary-foreground border-primary shadow-xs ring-2 ring-primary/20'
                                            : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 border-emerald-500/25'
                                    "
                                    :aria-expanded="showExtendPlans"
                                    aria-controls="extend-plans-section"
                                    @click="toggleExtendPlans"
                                >
                                    <Sparkles class="h-3.5 w-3.5 shrink-0" />
                                    <span class="truncate">{{ showExtendPlans ? 'Ocultar' : 'Extender plan' }}</span>
                                    <ChevronDown
                                        class="h-3.5 w-3.5 shrink-0 transition-transform duration-200"
                                        :class="{ 'rotate-180': showExtendPlans }"
                                    />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN CONDICIONAL INMEDIATA: EXTENDER MEMBRESÍA CON OTRO PLAN -->
            <transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-2 scale-[0.99]"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 -translate-y-2 scale-[0.99]"
            >
                <div
                    v-if="showExtendPlans"
                    id="extend-plans-section"
                    class="space-y-4 p-4 sm:p-6 rounded-2xl sm:rounded-3xl border border-primary/30 bg-primary/5 shadow-sm scroll-mt-6"
                >
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-primary/15 pb-3">
                        <div class="space-y-1">
                            <h3 class="text-sm sm:text-base font-bold text-foreground flex items-center gap-2">
                                <Sparkles class="h-4 w-4 text-primary shrink-0" />
                                Sumar más días a tu suscripción activa
                            </h3>
                            <p class="text-xs text-muted leading-relaxed">
                                Los días del plan elegido se <strong class="text-foreground">sumarán acumulativamente a tu fecha de vencimiento actual ({{ membership.expires_at }})</strong> sin perder días previos.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="self-start sm:self-auto shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface border border-border hover:border-primary/40 text-xs font-semibold text-muted hover:text-foreground transition-colors active:scale-[0.98]"
                            @click="showExtendPlans = false"
                        >
                            <X class="h-3.5 w-3.5" />
                            <span>Ocultar planes</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5 w-full items-stretch pt-1">
                        <div
                            v-for="plan in sortedPlans"
                            :key="plan.id"
                            class="rounded-2xl sm:rounded-3xl p-4 sm:p-6 bg-surface border border-border hover:border-primary/50 flex flex-col justify-between shadow-xs transition-all"
                        >
                            <div class="space-y-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-primary">
                                            Extensión acumulativa
                                        </span>
                                        <h4 class="text-base sm:text-lg font-black text-foreground">{{ plan.name }}</h4>
                                    </div>
                                    <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/25 shrink-0">
                                        +{{ plan.duration_days }} días
                                    </span>
                                </div>

                                <div class="p-3 rounded-xl bg-background border border-border space-y-1">
                                    <p v-if="plan.special" class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                        {{ plan.special.label }} · hasta el {{ plan.special.ends_label }}
                                    </p>
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <span class="text-2xl sm:text-3xl font-black text-foreground">{{ plan.price_formatted }}</span>
                                        <s v-if="plan.special" class="text-sm text-muted">{{ plan.special.regular_price_formatted }}</s>
                                        <span class="text-xs text-muted">/ +{{ plan.duration_days }} días acumulados</span>
                                    </div>
                                </div>

                                <p class="text-xs text-muted leading-relaxed">
                                    {{ plan.description || 'Suma días de lectura irrestricta a tu suscripción actual.' }}
                                </p>
                            </div>

                            <div class="pt-4">
                                <button
                                    type="button"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 min-h-[44px] rounded-xl bg-primary hover:bg-primary-hover text-primary-foreground font-bold text-xs shadow-xs transition-all active:scale-[0.98]"
                                    @click="openPaymentModal(plan)"
                                >
                                    <QrCode class="h-4 w-4 shrink-0" />
                                    <span>Pagar con QR y sumar {{ plan.duration_days }} días</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- BENEFICIOS DESBLOQUEADOS (CONFIRMACIÓN DE VALOR) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4 w-full">
                <div class="p-3.5 sm:p-4 rounded-xl sm:rounded-2xl bg-surface border border-border space-y-1.5 shadow-2xs">
                    <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 class="h-4 w-4" />
                    </div>
                    <h4 class="text-xs font-bold text-foreground">Lectura 100% Desbloqueada</h4>
                    <p class="text-[11px] text-muted leading-relaxed">
                        Todas las páginas de todos los libros publicados, sin bloqueos.
                    </p>
                </div>

                <div class="p-3.5 sm:p-4 rounded-xl sm:rounded-2xl bg-surface border border-border space-y-1.5 shadow-2xs">
                    <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                        <BookOpen class="h-4 w-4" />
                    </div>
                    <h4 class="text-xs font-bold text-foreground">Nuevas ediciones incluidas</h4>
                    <p class="text-[11px] text-muted leading-relaxed">
                        Las actualizaciones que se publiquen mientras tu acceso esté vigente.
                    </p>
                </div>

                <div class="p-3.5 sm:p-4 rounded-xl sm:rounded-2xl bg-surface border border-border space-y-1.5 shadow-2xs">
                    <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                        <Laptop class="h-4 w-4" />
                    </div>
                    <h4 class="text-xs font-bold text-foreground">Multiplataforma 24/7</h4>
                    <p class="text-[11px] text-muted leading-relaxed">
                        Continúa tu lectura en tu computador, tablet o celular en cualquier momento.
                    </p>
                </div>

                <div class="p-3.5 sm:p-4 rounded-xl sm:rounded-2xl bg-surface border border-border space-y-1.5 shadow-2xs">
                    <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                        <ShieldCheck class="h-4 w-4" />
                    </div>
                    <h4 class="text-xs font-bold text-foreground">Tu avance se guarda</h4>
                    <p class="text-[11px] text-muted leading-relaxed">
                        Al volver, sigues desde la última página que leíste.
                    </p>
                </div>
            </div>

            <!-- HISTORIAL DE COMPROBANTES Y CÓDIGOS DE LA CUENTA -->
            <div class="space-y-4 pt-2 border-t border-border">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-sm font-bold text-foreground">Mis comprobantes y transacciones</h4>
                        <p class="text-xs text-muted">Registro de pagos enviados y códigos prepago aplicados a tu cuenta.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Solicitudes de Pago -->
                    <div class="rounded-xl sm:rounded-2xl border border-border bg-surface overflow-hidden p-3.5 sm:p-4 space-y-3 shadow-2xs">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-muted flex items-center gap-2">
                            <CreditCard class="h-3.5 w-3.5 text-primary" />
                            Comprobantes de pago enviados
                        </h5>
                        <div class="overflow-x-auto -mx-3.5 sm:mx-0 px-3.5 sm:px-0">
                            <table class="w-full text-left text-xs min-w-[340px]">
                                <thead class="bg-background/80 border-b border-border text-muted uppercase text-[10px] tracking-wider">
                                    <tr>
                                        <th class="px-2.5 py-2">Plan</th>
                                        <th class="px-2.5 py-2">Monto</th>
                                        <th class="px-2.5 py-2">Estado</th>
                                        <th class="px-2.5 py-2 text-right">Recibo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="r in recentRequests" :key="r.id">
                                        <td class="px-2.5 py-2 font-semibold text-foreground">{{ r.plan_name }}</td>
                                        <td class="px-2.5 py-2 font-bold text-primary">{{ r.amount_formatted }}</td>
                                        <td class="px-2.5 py-2">
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                                                :class="
                                                    r.status === 'approved'
                                                        ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
                                                        : r.status === 'rejected'
                                                          ? 'bg-rose-500/10 text-rose-600 border-rose-500/20'
                                                          : 'bg-amber-500/10 text-amber-600 border-amber-500/20'
                                                "
                                            >
                                                {{ r.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-2.5 py-2 text-right">
                                            <a :href="r.proof_url" target="_blank" rel="noopener" class="text-primary hover:underline font-semibold inline-flex items-center gap-1">
                                                <span>Ver</span>
                                                <ExternalLink class="h-3 w-3" />
                                            </a>
                                        </td>
                                    </tr>
                                    <tr v-if="recentRequests.length === 0">
                                        <td colspan="4" class="px-2.5 py-4 text-center text-muted">
                                            No tienes solicitudes de pago registradas.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Códigos Canjeados -->
                    <div class="rounded-xl sm:rounded-2xl border border-border bg-surface overflow-hidden p-3.5 sm:p-4 space-y-3 shadow-2xs">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-muted flex items-center gap-2">
                            <KeyRound class="h-3.5 w-3.5 text-primary" />
                            Códigos PIN canjeados
                        </h5>
                        <div class="overflow-x-auto -mx-3.5 sm:mx-0 px-3.5 sm:px-0">
                            <table class="w-full text-left text-xs min-w-[300px]">
                                <thead class="bg-background/80 border-b border-border text-muted uppercase text-[10px] tracking-wider">
                                    <tr>
                                        <th class="px-2.5 py-2">Código PIN</th>
                                        <th class="px-2.5 py-2">Detalle</th>
                                        <th class="px-2.5 py-2">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="c in recentCodes" :key="c.id">
                                        <td class="px-2.5 py-2 font-mono font-bold text-foreground">{{ c.code }}</td>
                                        <td class="px-2.5 py-2 text-muted">{{ c.plan_name }}</td>
                                        <td class="px-2.5 py-2 text-muted">{{ c.redeemed_at }}</td>
                                    </tr>
                                    <tr v-if="recentCodes.length === 0">
                                        <td colspan="3" class="px-2.5 py-4 text-center text-muted">
                                            No has canjeado códigos PIN todavía.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- MODO B: EL USUARIO NO TIENE MEMBRESÍA ACTIVA (VISTA DE ADQUISICIÓN)-->
        <!-- ================================================================= -->
        <div v-else class="space-y-6">
            <!-- 1. BARRA DE ESTADO / BIENVENIDA -->
            <div class="p-3.5 sm:p-4 rounded-xl sm:rounded-2xl bg-surface border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="h-3 w-3 rounded-full bg-muted/60 shrink-0"></span>
                    <div class="min-w-0 text-xs sm:text-sm">
                        <p class="font-bold text-foreground">
                            Plan actual: Acceso Gratuito (Muestra libre)
                        </p>
                        <p class="text-[11px] sm:text-xs text-muted leading-snug">
                            Tienes acceso a las páginas de muestra oficiales. Selecciona un plan comercial para desbloquear el catálogo completo.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="w-full sm:w-auto shrink-0 inline-flex items-center justify-center gap-2 px-3.5 py-2.5 sm:py-2 rounded-brand bg-primary/10 hover:bg-primary/20 text-primary border border-primary/20 text-xs font-bold transition-colors shadow-2xs"
                    @click="openRedeemModal"
                >
                    <KeyRound class="h-3.5 w-3.5" />
                    <span>¿Tienes un código PIN? Canjear</span>
                </button>
            </div>

            <!-- 2. GRID COMPARATIVO DE PLANES A ANCHO COMPLETO -->
            <section class="space-y-4" aria-labelledby="pricing-heading">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-1 sm:gap-2 border-b border-border pb-3">
                    <div>
                        <h2 id="pricing-heading" class="text-lg sm:text-2xl font-black text-foreground tracking-tight">
                            Planes de suscripción y lectura
                        </h2>
                        <p class="text-xs text-muted leading-relaxed">
                            Compara las opciones de acceso. Pago único por transferencia o QR Simple sin cobros recurrentes sorpresa.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5 lg:gap-6 w-full items-stretch pt-2">
                    <!-- TARJETA 1: PLAN GRATUITO (FREE) -->
                    <div class="rounded-2xl sm:rounded-3xl p-4 sm:p-6 lg:p-7 bg-surface border border-border flex flex-col justify-between shadow-xs transition-all hover:border-primary/40">
                        <div class="space-y-4 sm:space-y-5">
                            <div class="space-y-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-muted/15 text-muted border border-border">
                                    Muestra oficial
                                </span>
                                <h3 class="text-lg sm:text-xl font-black text-foreground">
                                    Plan Gratuito
                                </h3>
                                <p class="text-xs text-muted leading-relaxed">
                                    Ideal para explorar el catálogo y hojear las primeras páginas de muestra de cada libro.
                                </p>
                            </div>

                            <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="text-2xl sm:text-3xl font-black text-foreground">0 BOB</span>
                                    <span class="text-xs text-muted font-medium">/ siempre libre</span>
                                </div>
                                <span class="text-[11px] text-muted block">Sin registro bancario</span>
                            </div>

                            <div class="space-y-2 pt-1">
                                <p class="text-xs font-bold uppercase tracking-wider text-foreground">Características:</p>
                                <ul class="space-y-2 text-xs">
                                    <li class="flex items-start gap-2 sm:gap-2.5 text-foreground leading-snug">
                                        <Check class="h-4 w-4 text-emerald-500 shrink-0 mt-0.5" />
                                        <span>Páginas de muestra oficiales por edición</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 text-foreground leading-snug">
                                        <Check class="h-4 w-4 text-emerald-500 shrink-0 mt-0.5" />
                                        <span>Consulta de índices y temarios generales</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 text-foreground leading-snug">
                                        <Check class="h-4 w-4 text-emerald-500 shrink-0 mt-0.5" />
                                        <span>Visor de lectura adaptativo en cualquier pantalla</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 text-muted opacity-60 leading-snug">
                                        <X class="h-4 w-4 text-rose-500/80 shrink-0 mt-0.5" />
                                        <span>Lectura completa de tomos y capítulos</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="pt-5 sm:pt-6">
                            <button
                                type="button"
                                disabled
                                class="w-full py-2.5 sm:py-3 rounded-xl border border-border bg-background text-muted font-semibold text-xs text-center cursor-default select-none"
                            >
                                ✓ Tu plan actual
                            </button>
                        </div>
                    </div>

                    <!-- TARJETAS DE PLANES COMERCIALES (Semestral, Anual, etc.) -->
                    <div
                        v-for="plan in sortedPlans"
                        :key="plan.id"
                        class="relative rounded-2xl sm:rounded-3xl p-4 sm:p-6 lg:p-7 flex flex-col justify-between transition-all duration-300"
                        :class="
                            plan.id === recommendedPlanId
                                ? 'bg-surface border-2 border-primary shadow-xl shadow-primary/10 ring-2 ring-primary/20 lg:-translate-y-1 mt-2 sm:mt-0'
                                : 'bg-surface border border-border hover:border-primary/50 shadow-xs'
                        "
                    >
                        <div
                            v-if="plan.id === recommendedPlanId"
                            class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-primary text-primary-foreground text-[10px] font-black uppercase tracking-wider shadow-sm flex items-center gap-1 whitespace-nowrap z-10"
                        >
                            <Star class="h-3 w-3 fill-current" />
                            Más popular y recomendado
                        </div>

                        <div class="space-y-4 sm:space-y-5">
                            <div class="space-y-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-primary/10 text-primary border border-primary/20">
                                    Acceso Total
                                </span>
                                <h3 class="text-lg sm:text-xl font-black text-foreground">
                                    {{ plan.name }}
                                </h3>
                                <p class="text-xs text-muted leading-relaxed">
                                    {{ plan.description || 'Lectura completa de todos los libros, ejercicios y apuntes.' }}
                                </p>
                            </div>

                            <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1">
                                <p v-if="plan.special" class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    {{ plan.special.label }} · hasta el {{ plan.special.ends_label }}
                                </p>
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="text-2xl sm:text-3xl font-black text-foreground tracking-tight">
                                        {{ plan.price_formatted }}
                                    </span>
                                    <s v-if="plan.special" class="text-sm text-muted">{{ plan.special.regular_price_formatted }}</s>
                                    <span class="text-xs text-muted font-medium">
                                        / {{ plan.duration_days }} días
                                    </span>
                                </div>
                                <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[10px] sm:text-[11px] font-bold">
                                    <span>Equivale a {{ getMonthlyEquivalent(plan) }}</span>
                                </div>
                            </div>

                            <div class="space-y-2 pt-1">
                                <p class="text-xs font-bold uppercase tracking-wider text-foreground">Todo lo incluido:</p>
                                <ul class="space-y-2 text-xs text-foreground">
                                    <li class="flex items-start gap-2 sm:gap-2.5 leading-snug">
                                        <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                        <span><strong>100% de las obras oficiales</strong> sin páginas bloqueadas</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 leading-snug">
                                        <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                        <span><strong>{{ plan.duration_days }} días continuos</strong> de vigencia garantizada</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 leading-snug">
                                        <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                        <span>Visor de lectura sin restricciones en PC, tablet y celular</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 leading-snug">
                                        <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                        <span>Tu avance de lectura se guarda en tu cuenta</span>
                                    </li>
                                    <li class="flex items-start gap-2 sm:gap-2.5 leading-snug">
                                        <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                        <span>Pago único por QR Simple: sin cargos recurrentes</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="pt-5 sm:pt-6 space-y-1.5">
                            <button
                                type="button"
                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 min-h-[44px] rounded-xl font-bold text-xs shadow-xs transition-all duration-200 active:scale-[0.98]"
                                :class="
                                    plan.id === recommendedPlanId
                                        ? 'bg-primary hover:bg-primary-hover text-primary-foreground shadow-md'
                                        : 'bg-background hover:bg-primary/10 hover:text-primary hover:border-primary border border-border text-foreground'
                                "
                                @click="openPaymentModal(plan)"
                            >
                                <QrCode class="h-4 w-4" />
                                <span>Pagar con QR Simple &rarr;</span>
                            </button>
                            <p class="text-[10px] text-muted text-center flex items-center justify-center gap-1">
                                <Lock class="h-3 w-3 text-muted/80" />
                                Transferencia bancaria directa y segura
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 3. VENTAJAS INSTITUCIONALES -->
            <section class="rounded-2xl sm:rounded-3xl border border-border bg-surface p-4 sm:p-8 space-y-4 sm:space-y-6 w-full" aria-labelledby="features-heading">
                <div class="text-center space-y-1">
                    <span class="text-[10px] sm:text-xs font-bold uppercase tracking-wider text-primary">Ventajas de la plataforma</span>
                    <h3 id="features-heading" class="text-base sm:text-xl font-extrabold text-foreground tracking-tight">
                        ¿Por qué estudiar con la biblioteca oficial de Arte Contable?
                    </h3>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-4 w-full">
                    <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1.5 sm:space-y-2">
                        <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <Award class="h-4 w-4" />
                        </div>
                        <h4 class="text-xs font-bold text-foreground">Autoridad Oficial</h4>
                        <p class="text-[10px] sm:text-[11px] text-muted leading-relaxed">
                            Libros escritos por el Lic. Max Apaza Luna, pensados para aprender contabilidad desde cero.
                        </p>
                    </div>

                    <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1.5 sm:space-y-2">
                        <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <BookOpen class="h-4 w-4" />
                        </div>
                        <h4 class="text-xs font-bold text-foreground">Práctica Real</h4>
                        <p class="text-[10px] sm:text-[11px] text-muted leading-relaxed">
                            Ejercicios explicados con documentación de respaldo y normativa contable boliviana vigente.
                        </p>
                    </div>

                    <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1.5 sm:space-y-2">
                        <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <Laptop class="h-4 w-4" />
                        </div>
                        <h4 class="text-xs font-bold text-foreground">Multiplataforma</h4>
                        <p class="text-[10px] sm:text-[11px] text-muted leading-relaxed">
                            Lectura fluida y protegida en computadoras, tabletas y teléfonos celulares sin apps pesadas.
                        </p>
                    </div>

                    <div class="p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-background border border-border space-y-1.5 sm:space-y-2">
                        <div class="h-8 w-8 sm:h-9 sm:w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <ShieldCheck class="h-4 w-4" />
                        </div>
                        <h4 class="text-xs font-bold text-foreground">Seguridad Total</h4>
                        <p class="text-[10px] sm:text-[11px] text-muted leading-relaxed">
                            Tu cuenta es privada y tu avance de lectura queda guardado en ella.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 4. PREGUNTAS FRECUENTES (FAQ ACORDEÓN) -->
            <section class="space-y-4 w-full" aria-labelledby="faq-heading">
                <div class="text-center space-y-1">
                    <span class="text-[10px] sm:text-xs font-bold uppercase tracking-wider text-primary">Respuestas directas</span>
                    <h3 id="faq-heading" class="text-base sm:text-xl font-extrabold text-foreground tracking-tight">
                        Preguntas frecuentes
                    </h3>
                </div>

                <div class="space-y-2 sm:space-y-2.5 max-w-4xl mx-auto w-full">
                    <div
                        v-for="(faq, idx) in faqs"
                        :key="idx"
                        class="rounded-xl sm:rounded-2xl border border-border bg-surface transition-colors overflow-hidden"
                    >
                        <button
                            type="button"
                            class="w-full p-3.5 sm:p-4.5 flex items-center justify-between gap-3 text-left font-semibold text-xs sm:text-sm text-foreground hover:text-primary transition-colors focus:outline-none"
                            @click="toggleFaq(idx)"
                        >
                            <span>{{ faq.q }}</span>
                            <ChevronDown
                                class="h-4 w-4 text-muted shrink-0 transition-transform duration-200"
                                :class="{ 'rotate-180 text-primary': faq.open }"
                            />
                        </button>
                        <div
                            v-if="faq.open"
                            class="px-3.5 sm:px-4.5 pb-3.5 sm:pb-4 text-xs text-muted leading-relaxed border-t border-border/60 pt-2.5 sm:pt-3"
                        >
                            {{ faq.a }}
                        </div>
                    </div>
                </div>
            </section>

            <!-- 5. HISTORIAL DE PAGOS Y CÓDIGOS (COLAPSABLE) -->
            <section class="pt-4 border-t border-border w-full">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h4 class="text-xs sm:text-sm font-bold text-foreground">Historial de transacciones</h4>
                        <p class="text-[11px] sm:text-xs text-muted">Consulta tus comprobantes enviados y códigos PIN canjeados.</p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand bg-surface border border-border hover:border-primary/40 text-xs font-semibold text-foreground transition-colors"
                        @click="showHistory = !showHistory"
                    >
                        <span>{{ showHistory ? 'Ocultar' : 'Ver historial' }}</span>
                        <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': showHistory }" />
                    </button>
                </div>

                <div v-if="showHistory" class="mt-4 space-y-4">
                    <div class="rounded-xl sm:rounded-2xl border border-border bg-surface overflow-hidden p-3.5 sm:p-4 space-y-3">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-muted flex items-center gap-2">
                            <CreditCard class="h-3.5 w-3.5 text-primary" />
                            Comprobantes de pago enviados
                        </h5>
                        <div class="overflow-x-auto -mx-3.5 sm:mx-0 px-3.5 sm:px-0">
                            <table class="w-full text-left text-xs min-w-[460px]">
                                <thead class="bg-background/80 border-b border-border text-muted uppercase text-[10px] tracking-wider">
                                    <tr>
                                        <th class="px-3 py-2">Plan</th>
                                        <th class="px-3 py-2">Monto</th>
                                        <th class="px-3 py-2">Fecha</th>
                                        <th class="px-3 py-2">Estado</th>
                                        <th class="px-3 py-2 text-right">Comprobante</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="r in recentRequests" :key="r.id">
                                        <td class="px-3 py-2.5 font-semibold text-foreground">{{ r.plan_name }}</td>
                                        <td class="px-3 py-2.5 font-bold text-primary">{{ r.amount_formatted }}</td>
                                        <td class="px-3 py-2.5 text-muted">{{ r.created_at }}</td>
                                        <td class="px-3 py-2.5">
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                                                :class="
                                                    r.status === 'approved'
                                                        ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
                                                        : r.status === 'rejected'
                                                          ? 'bg-rose-500/10 text-rose-600 border-rose-500/20'
                                                          : 'bg-amber-500/10 text-amber-600 border-amber-500/20'
                                                "
                                            >
                                                {{ r.status_label }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <a :href="r.proof_url" target="_blank" rel="noopener" class="text-primary hover:underline font-semibold inline-flex items-center gap-1">
                                                <span>Ver</span>
                                                <ExternalLink class="h-3 w-3" />
                                            </a>
                                        </td>
                                    </tr>
                                    <tr v-if="recentRequests.length === 0">
                                        <td colspan="5" class="px-3 py-6 text-center text-muted">
                                            No has enviado solicitudes de pago todavía.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-xl sm:rounded-2xl border border-border bg-surface overflow-hidden p-3.5 sm:p-4 space-y-3">
                        <h5 class="text-xs font-bold uppercase tracking-wider text-muted flex items-center gap-2">
                            <KeyRound class="h-3.5 w-3.5 text-primary" />
                            Códigos PIN canjeados
                        </h5>
                        <div class="overflow-x-auto -mx-3.5 sm:mx-0 px-3.5 sm:px-0">
                            <table class="w-full text-left text-xs min-w-[360px]">
                                <thead class="bg-background/80 border-b border-border text-muted uppercase text-[10px] tracking-wider">
                                    <tr>
                                        <th class="px-3 py-2">Código PIN</th>
                                        <th class="px-3 py-2">Detalle</th>
                                        <th class="px-3 py-2">Fecha de canje</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="c in recentCodes" :key="c.id">
                                        <td class="px-3 py-2.5 font-mono font-bold text-foreground">{{ c.code }}</td>
                                        <td class="px-3 py-2.5 text-muted">{{ c.plan_name }}</td>
                                        <td class="px-3 py-2.5 text-muted">{{ c.redeemed_at }}</td>
                                    </tr>
                                    <tr v-if="recentCodes.length === 0">
                                        <td colspan="3" class="px-3 py-6 text-center text-muted">
                                            No has canjeado códigos PIN todavía.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ================================================================= -->
        <!-- MODAL 1: CHECKOUT CON CÓDIGO QR BANCARIO                          -->
        <!-- ================================================================= -->
        <!-- QA: mientras se envía no se puede cerrar; antes "Cancelar" cerraba la ventana pero el pago igual se enviaba. -->
        <BaseModal
            v-model="isPaymentModalOpen"
            title="Pago por transferencia QR"
            max-width="lg"
            :closeable="!isSubmittingPayment"
        >
            <div v-if="selectedPlanForPayment" class="space-y-5">
                <div class="p-4 rounded-2xl bg-background border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <span class="text-[10px] font-bold text-primary uppercase tracking-wider">Plan seleccionado</span>
                        <h4 class="text-base font-bold text-foreground">{{ selectedPlanForPayment.name }}</h4>
                        <p class="text-xs text-muted">{{ selectedPlanForPayment.duration_days }} días de membresía completa</p>
                    </div>
                    <div class="text-left sm:text-right">
                        <div class="text-2xl font-black text-primary">{{ selectedPlanForPayment.price_formatted }}</div>
                        <p v-if="selectedPlanForPayment.special" class="text-xs text-muted">
                            Antes <s>{{ selectedPlanForPayment.special.regular_price_formatted }}</s> · {{ selectedPlanForPayment.special.label }} hasta el {{ selectedPlanForPayment.special.ends_label }}
                        </p>
                        <span class="text-[10px] text-muted font-medium">Pago único en moneda nacional</span>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-background border border-border space-y-3 text-center">
                    <p class="text-xs font-bold text-foreground">
                        Escanea el código QR desde la aplicación de tu banco:
                    </p>

                    <div class="mx-auto max-w-[210px] aspect-square rounded-2xl bg-white p-3 border border-border shadow-xs flex items-center justify-center">
                        <img
                            v-if="selectedPlanForPayment.qr_image_url"
                            :src="selectedPlanForPayment.qr_image_url"
                            :alt="`QR ${selectedPlanForPayment.name}`"
                            class="h-full w-full object-contain rounded-lg"
                        />
                        <div v-else class="text-center p-3 text-muted">
                            <QrCode class="h-10 w-10 mx-auto text-muted/40 mb-1" />
                            <p class="text-[11px]">QR en actualización. Puedes realizar la transferencia por {{ selectedPlanForPayment.price_formatted }}.</p>
                        </div>
                    </div>

                    <p class="text-xs text-muted">
                        Monto exacto a transferir: <strong class="text-foreground font-bold">{{ selectedPlanForPayment.price_formatted }}</strong>
                    </p>
                </div>

                <form class="space-y-4" @submit.prevent="submitPayment">
                    <BaseAlert v-if="paymentErrors.general" type="error" title="Revisa los datos">
                        {{ paymentErrors.general }}
                    </BaseAlert>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-foreground">
                            Adjunta la captura o comprobante del pago <span class="text-rose-600">*</span>
                        </label>

                        <div class="border-2 border-dashed border-border rounded-2xl p-4 text-center hover:border-primary/40 transition-colors bg-background">
                            <input
                                id="proof_upload"
                                type="file"
                                accept="image/webp,image/jpeg,image/png,application/pdf"
                                required
                                class="hidden"
                                @change="handleProofFileChange"
                            />
                            <label for="proof_upload" class="cursor-pointer space-y-2 block">
                                <div class="mx-auto h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                                    <UploadCloud class="h-5 w-5" />
                                </div>
                                <div class="text-xs font-semibold text-foreground">
                                    <span class="text-primary hover:underline">Selecciona una imagen o PDF</span> o arrástralo aquí
                                </div>
                                <p class="text-[11px] text-muted">
                                    Formatos: WebP, JPG, PNG o PDF (máx. 5 MB)
                                </p>
                            </label>

                            <div v-if="paymentForm.fileName" class="mt-3 p-2.5 rounded-xl bg-surface border border-border flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2 truncate">
                                    <component :is="paymentForm.isPdf ? FileText : CheckCircle2" class="h-4 w-4 text-primary shrink-0" />
                                    <span class="font-medium text-foreground truncate">{{ paymentForm.fileName }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="p-1 rounded-brand text-muted hover:text-rose-600"
                                    @click="paymentForm.proof_file = null; paymentForm.fileName = ''; revokePaymentPreview();"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <img
                                v-if="paymentForm.previewUrl"
                                :src="paymentForm.previewUrl"
                                alt="Vista previa comprobante"
                                class="mt-3 max-h-36 mx-auto rounded-xl border border-border object-contain"
                            />
                        </div>
                        <p v-if="paymentErrors.proof_file" class="text-xs text-rose-600">{{ paymentErrors.proof_file }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">
                            Nota o número de referencia bancaria (opcional)
                        </label>
                        <input
                            v-model="paymentForm.student_notes"
                            type="text"
                            maxlength="500"
                            placeholder="Ej: Transferencia Banco Unión ref. 54321"
                            class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                        <button
                            type="button"
                            :disabled="isSubmittingPayment"
                            class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground disabled:opacity-50"
                            @click="isPaymentModalOpen = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="isSubmittingPayment || !paymentForm.proof_file"
                            class="px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-bold shadow-xs transition-colors disabled:opacity-50"
                        >
                            {{ isSubmittingPayment ? 'Enviando comprobante…' : 'Confirmar y enviar comprobante' }}
                        </button>
                    </div>
                    <p v-if="!paymentForm.proof_file && !isSubmittingPayment" class="text-[11px] text-muted text-right">
                        Adjunta el comprobante para poder enviarlo.
                    </p>
                </form>
            </div>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL 2: CANJEAR CÓDIGO PIN                                       -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isRedeemModalOpen"
            title="Canjear código prepago (PIN)"
            max-width="md"
            :closeable="!isRedeeming"
        >
            <div class="space-y-5">
                <div class="text-center space-y-1.5">
                    <div class="mx-auto h-12 w-12 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                        <KeyRound class="h-6 w-6" />
                    </div>
                    <h4 class="text-base font-bold text-foreground">Activa tu membresía con código</h4>
                    <p class="text-xs text-muted max-w-sm mx-auto leading-relaxed">
                        Ingresa el código PIN incluido en tu libro impreso o entregado por la administración.
                    </p>
                </div>

                <BaseAlert v-if="redeemSuccessInfo" type="success" title="¡Activación exitosa!">
                    {{ redeemSuccessInfo }}
                </BaseAlert>

                <BaseAlert v-if="redeemErrors.general" type="error" title="No se pudo canjear">
                    {{ redeemErrors.general }}
                </BaseAlert>

                <form class="space-y-4" @submit.prevent="submitRedeemCode">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-foreground text-center">
                            Código PIN de acceso
                        </label>
                        <input
                            v-model="redeemCodeInput"
                            type="text"
                            required
                            maxlength="50"
                            placeholder="EJ: AC-9J4K-X8PQ"
                            class="w-full text-center font-mono text-base font-bold tracking-widest uppercase px-4 py-3 bg-background border border-border rounded-xl text-foreground placeholder:text-muted/40 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="redeemErrors.code" class="text-xs text-rose-600 text-center">{{ redeemErrors.code }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                        <button
                            type="button"
                            class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                            @click="isRedeemModalOpen = false"
                        >
                            Cerrar
                        </button>
                        <button
                            type="submit"
                            :disabled="isRedeeming || !redeemCodeInput.trim()"
                            class="px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-bold shadow-xs transition-colors disabled:opacity-50"
                        >
                            {{ isRedeeming ? 'Validando...' : 'Canjear código ahora' }}
                        </button>
                    </div>
                </form>
            </div>
        </BaseModal>
    </div>
</template>
