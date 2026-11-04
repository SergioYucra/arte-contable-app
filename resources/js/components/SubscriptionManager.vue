<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
    CreditCard,
    Plus,
    Search,
    QrCode,
    CheckCircle2,
    XCircle,
    Clock,
    Eye,
    KeyRound,
    Settings,
    Upload,
    Trash2,
    RotateCcw,
    X,
    Sparkles,
    AlertTriangle,
    ExternalLink,
    Filter,
    Copy,
    Check,
} from 'lucide-vue-next';
import BaseModal from './ui/BaseModal.vue';
import BaseAlert from './ui/BaseAlert.vue';
import BaseToast from './ui/BaseToast.vue';
import { optimizeCoverImage, formatBytesHuman } from './books/pdfProcessor';

const props = defineProps({
    initialPlans: {
        type: Array,
        required: true,
    },
    initialRequests: {
        type: Array,
        required: true,
    },
    initialAccessCodes: {
        type: Array,
        required: true,
    },
    storePlanUrl: {
        type: String,
        required: true,
    },
    storeCodeUrl: {
        type: String,
        required: true,
    },
});

function clone(val) {
    return JSON.parse(JSON.stringify(val));
}

// --- Pestaña Principal ---
// 'requests' = Solicitudes de Pago | 'plans' = Planes de Suscripción | 'codes' = Códigos Prepago
const currentTab = ref('requests');

// --- Notificaciones Toast unificadas ---
const toastState = ref({
    open: false,
    variant: 'info',
    title: '',
    message: '',
});

function notify(variant, title, message = '') {
    toastState.value = {
        open: true,
        variant,
        title,
        message,
    };
}

function extractErrors(error, fallbackMessage = 'Ocurrió un error inesperado al procesar la solicitud.') {
    const response = error?.response;
    const data = response?.data;
    const fieldErrors = {};

    if (data?.errors && typeof data.errors === 'object') {
        for (const [key, messages] of Object.entries(data.errors)) {
            fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
        }
    }

    const message = data?.message || (typeof data === 'string' ? data : null) || fallbackMessage;
    return { fieldErrors, message };
}

// =========================================================================
// SECCIÓN 1: SOLICITUDES DE PAGO P2P
// =========================================================================
const requests = ref(clone(props.initialRequests));
const requestSearch = ref('');
const requestFilter = ref('pending'); // 'all' | 'pending' | 'approved' | 'rejected'

const pendingCount = computed(() => requests.value.filter((r) => r.status === 'pending').length);

const filteredRequests = computed(() => {
    let list = requests.value;

    if (requestFilter.value !== 'all') {
        list = list.filter((r) => r.status === requestFilter.value);
    }

    if (requestSearch.value.trim()) {
        const query = requestSearch.value.toLowerCase().trim();
        list = list.filter(
            (r) =>
                r.user?.name?.toLowerCase().includes(query) ||
                r.user?.email?.toLowerCase().includes(query) ||
                r.reference_text?.toLowerCase().includes(query) ||
                r.plan?.name?.toLowerCase().includes(query)
        );
    }

    return list;
});

// Modal de Comprobante / Aprobación / Rechazo
const selectedRequest = ref(null);
const isProofModalOpen = ref(false);
const isRejectModalOpen = ref(false);
const rejectReason = ref('');
const rejectError = ref('');
const isProcessingAction = ref(false);

function openProofModal(req) {
    selectedRequest.value = req;
    isProofModalOpen.value = true;
}

function openRejectModal(req) {
    selectedRequest.value = req;
    rejectReason.value = '';
    rejectError.value = '';
    isRejectModalOpen.value = true;
}

async function approveRequest(req) {
    if (isProcessingAction.value) return;
    isProcessingAction.value = true;

    try {
        const { data } = await axios.post(req.approve_url);
        const idx = requests.value.findIndex((r) => r.id === req.id);
        if (idx !== -1) {
            requests.value[idx] = data.payment_request;
        }
        if (selectedRequest.value?.id === req.id) {
            selectedRequest.value = data.payment_request;
            isProofModalOpen.value = false;
        }
        notify('success', 'Pago aprobado', data.message);
    } catch (err) {
        const { message } = extractErrors(err);
        notify('error', 'No se pudo aprobar', message);
    } finally {
        isProcessingAction.value = false;
    }
}

async function submitRejectRequest() {
    if (!selectedRequest.value || isProcessingAction.value) return;
    if (!rejectReason.value.trim() || rejectReason.value.trim().length < 3) {
        rejectError.value = 'Escribe el motivo del rechazo con al menos 3 caracteres.';
        return;
    }

    isProcessingAction.value = true;
    rejectError.value = '';

    try {
        const { data } = await axios.post(selectedRequest.value.reject_url, {
            admin_notes: rejectReason.value.trim(),
        });
        const idx = requests.value.findIndex((r) => r.id === selectedRequest.value.id);
        if (idx !== -1) {
            requests.value[idx] = data.payment_request;
        }
        isRejectModalOpen.value = false;
        isProofModalOpen.value = false;
        notify('info', 'Solicitud rechazada', data.message);
    } catch (err) {
        const { message } = extractErrors(err);
        rejectError.value = message;
    } finally {
        isProcessingAction.value = false;
    }
}

// =========================================================================
// SECCIÓN 2: PLANES DE SUSCRIPCIÓN
// =========================================================================
const plans = ref(clone(props.initialPlans));
const isPlanModalOpen = ref(false);
const editingPlanId = ref(null);

const planForm = ref({
    name: '',
    price: '',
    duration_days: 365,
    description: '',
    is_active: true,
    sort_order: 0,
    remove_qr: false,
    qrFile: null,
    qrPreviewUrl: null,
});
const planErrors = ref({});
const isSavingPlan = ref(false);

function revokeBlob(url) {
    if (url && typeof url === 'string' && url.startsWith('blob:')) {
        URL.revokeObjectURL(url);
    }
}

function openCreatePlanModal() {
    editingPlanId.value = null;
    revokeBlob(planForm.value.qrPreviewUrl);
    planForm.value = {
        name: '',
        price: '',
        duration_days: 365,
        description: '',
        is_active: true,
        sort_order: plans.value.length + 1,
        remove_qr: false,
        qrFile: null,
        qrPreviewUrl: null,
    };
    planErrors.value = {};
    isPlanModalOpen.value = true;
}

function openEditPlanModal(plan) {
    editingPlanId.value = plan.id;
    revokeBlob(planForm.value.qrPreviewUrl);
    planForm.value = {
        name: plan.name,
        price: plan.price,
        duration_days: plan.duration_days,
        description: plan.description || '',
        is_active: Boolean(plan.is_active),
        sort_order: plan.sort_order ?? 0,
        remove_qr: false,
        qrFile: null,
        qrPreviewUrl: plan.qr_image_url || null,
    };
    planErrors.value = {};
    isPlanModalOpen.value = true;
}

async function handleQrSelect(event) {
    const rawFile = event.target.files?.[0];
    event.target.value = '';
    if (!rawFile) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(rawFile.type)) {
        planErrors.value.qr_image = 'Selecciona una imagen válida (JPG, PNG o WebP).';
        return;
    }

    try {
        const optimized = await optimizeCoverImage(rawFile);
        if (optimized.size > 2 * 1024 * 1024) {
            planErrors.value.qr_image = 'La imagen del QR supera el límite de 2 MB.';
            return;
        }

        delete planErrors.value.qr_image;
        revokeBlob(planForm.value.qrPreviewUrl);
        planForm.value.qrFile = optimized;
        planForm.value.remove_qr = false;
        planForm.value.qrPreviewUrl = URL.createObjectURL(optimized);
    } catch {
        planErrors.value.qr_image = 'No se pudo procesar la imagen del QR.';
    }
}

function removeQrImage() {
    revokeBlob(planForm.value.qrPreviewUrl);
    planForm.value.qrFile = null;
    planForm.value.qrPreviewUrl = null;
    planForm.value.remove_qr = true;
}

async function submitPlanForm() {
    planErrors.value = {};

    if (!planForm.value.name.trim() || planForm.value.name.trim().length < 3) {
        planErrors.value.name = 'El nombre del plan debe tener al menos 3 caracteres.';
        return;
    }
    if (planForm.value.price === '' || Number(planForm.value.price) < 0) {
        planErrors.value.price = 'Ingresa un precio válido en Bolivianos.';
        return;
    }
    if (!planForm.value.duration_days || Number(planForm.value.duration_days) < 1) {
        planErrors.value.duration_days = 'Los días de duración deben ser al menos 1 día.';
        return;
    }

    isSavingPlan.value = true;

    try {
        const formData = new FormData();
        formData.append('name', planForm.value.name.trim());
        formData.append('price', String(planForm.value.price));
        formData.append('duration_days', String(planForm.value.duration_days));
        formData.append('description', planForm.value.description.trim());
        formData.append('is_active', planForm.value.is_active ? '1' : '0');
        formData.append('sort_order', String(planForm.value.sort_order || 0));
        formData.append('remove_qr', planForm.value.remove_qr ? '1' : '0');

        if (planForm.value.qrFile) {
            formData.append('qr_image', planForm.value.qrFile);
        }

        let res;
        if (editingPlanId.value) {
            const plan = plans.value.find((p) => p.id === editingPlanId.value);
            res = await axios.post(plan.update_url, formData);
            const idx = plans.value.findIndex((p) => p.id === editingPlanId.value);
            if (idx !== -1) {
                plans.value[idx] = res.data.plan;
            }
            notify('success', 'Plan actualizado', res.data.message);
        } else {
            res = await axios.post(props.storePlanUrl, formData);
            plans.value.unshift(res.data.plan);
            notify('success', 'Plan creado', res.data.message);
        }

        isPlanModalOpen.value = false;
    } catch (err) {
        const { fieldErrors, message } = extractErrors(err);
        planErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            planErrors.value.general = message;
        }
    } finally {
        isSavingPlan.value = false;
    }
}

// Modal de Confirmación Universal
const confirmDialog = ref({
    open: false,
    title: '',
    description: '',
    confirmLabel: 'Confirmar',
    variant: 'danger',
    loading: false,
    onConfirm: null,
});

function openConfirmDialog({ title, description, confirmLabel, variant = 'danger', onConfirm }) {
    confirmDialog.value = {
        open: true,
        title,
        description,
        confirmLabel,
        variant,
        loading: false,
        onConfirm,
    };
}

async function executeConfirmDialog() {
    if (!confirmDialog.value.onConfirm) return;
    confirmDialog.value.loading = true;
    try {
        await confirmDialog.value.onConfirm();
        confirmDialog.value.open = false;
    } finally {
        confirmDialog.value.loading = false;
    }
}

function promptArchivePlan(plan) {
    openConfirmDialog({
        title: 'Archivar plan de suscripción',
        description: `«${plan.name}» ya no estará visible para los alumnos. Los alumnos suscritos previamente continuarán con su acceso vigente intacto.`,
        confirmLabel: 'Archivar plan',
        variant: 'danger',
        onConfirm: async () => {
            try {
                const { data } = await axios.delete(plan.destroy_url);
                const idx = plans.value.findIndex((p) => p.id === plan.id);
                if (idx !== -1) plans.value[idx] = data.plan;
                notify('success', 'Plan archivado', data.message);
            } catch (err) {
                const { message } = extractErrors(err);
                notify('error', 'No se pudo archivar', message);
            }
        },
    });
}

async function restorePlan(plan) {
    try {
        const { data } = await axios.post(plan.restore_url);
        const idx = plans.value.findIndex((p) => p.id === plan.id);
        if (idx !== -1) plans.value[idx] = data.plan;
        notify('success', 'Plan restaurado', data.message);
    } catch (err) {
        const { message } = extractErrors(err);
        notify('error', 'No se pudo restaurar', message);
    }
}

// =========================================================================
// SECCIÓN 3: CÓDIGOS DE ACCESO PREPAGO (PINES)
// =========================================================================
const accessCodes = ref(clone(props.initialAccessCodes));
const codeSearch = ref('');
const isCreateCodeModalOpen = ref(false);

const codeForm = ref({
    mode: 'plan', // 'plan' | 'custom'
    subscription_plan_id: '',
    duration_days: 30,
    quantity: 1,
    reference: '',
});
const codeErrors = ref({});
const isSavingCode = ref(false);
const recentlyGeneratedCodes = ref([]);
const copiedBatch = ref(false);

const filteredCodes = computed(() => {
    if (!codeSearch.value.trim()) return accessCodes.value;
    const q = codeSearch.value.toLowerCase().trim();
    return accessCodes.value.filter(
        (c) =>
            c.code.toLowerCase().includes(q) ||
            c.reference?.toLowerCase().includes(q) ||
            c.redeemer_name?.toLowerCase().includes(q) ||
            c.plan_name?.toLowerCase().includes(q)
    );
});

function openCreateCodeModal() {
    codeForm.value = {
        mode: plans.value.length > 0 ? 'plan' : 'custom',
        subscription_plan_id: plans.value[0]?.id || '',
        duration_days: 30,
        quantity: 1,
        reference: '',
    };
    codeErrors.value = {};
    recentlyGeneratedCodes.value = [];
    copiedBatch.value = false;
    isCreateCodeModalOpen.value = true;
}

const selectedPlanForCode = computed(() => {
    if (codeForm.value.mode !== 'plan') return null;
    return plans.value.find((p) => String(p.id) === String(codeForm.value.subscription_plan_id)) || plans.value[0] || null;
});

async function submitCreateCode() {
    codeErrors.value = {};

    if (codeForm.value.mode === 'plan' && !codeForm.value.subscription_plan_id) {
        codeErrors.value.subscription_plan_id = 'Selecciona un plan de suscripción.';
        return;
    }

    if (codeForm.value.mode === 'custom') {
        const days = Number(codeForm.value.duration_days);
        if (!days || days < 1 || days > 1825) {
            codeErrors.value.duration_days = 'Ingresa una duración válida entre 1 y 1825 días.';
            return;
        }
    }

    if (!codeForm.value.quantity || Number(codeForm.value.quantity) < 1 || Number(codeForm.value.quantity) > 100) {
        codeErrors.value.quantity = 'Indica una cantidad entre 1 y 100 códigos.';
        return;
    }

    isSavingCode.value = true;

    try {
        const payload = {
            subscription_plan_id: codeForm.value.mode === 'plan' ? codeForm.value.subscription_plan_id : null,
            duration_days: codeForm.value.mode === 'custom' ? Number(codeForm.value.duration_days) : null,
            quantity: Number(codeForm.value.quantity),
            reference: codeForm.value.reference.trim() || null,
        };

        const { data } = await axios.post(props.storeCodeUrl, payload);
        const newBatch = Array.isArray(data.access_codes) ? data.access_codes : [data.access_code];
        accessCodes.value.unshift(...newBatch);
        recentlyGeneratedCodes.value = newBatch;
        notify('success', 'Códigos generados', data.message);
    } catch (err) {
        const { fieldErrors, message } = extractErrors(err);
        codeErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            codeErrors.value.general = message;
        }
    } finally {
        isSavingCode.value = false;
    }
}

async function copyGeneratedBatch() {
    if (!recentlyGeneratedCodes.value.length) return;
    const text = recentlyGeneratedCodes.value.map((c) => c.code).join('\n');
    try {
        await navigator.clipboard.writeText(text);
        copiedBatch.value = true;
        setTimeout(() => {
            copiedBatch.value = false;
        }, 2500);
        notify('info', 'Copiado al portapapeles', `${recentlyGeneratedCodes.value.length} códigos copiados.`);
    } catch {
        notify('error', 'Error al copiar', 'No se pudo acceder al portapapeles del navegador.');
    }
}

function promptRevokeCode(code) {
    openConfirmDialog({
        title: 'Revocar código de acceso',
        description: `El código «${code.code}» quedará anulado de inmediato y nadie podrá canjearlo.`,
        confirmLabel: 'Revocar código',
        variant: 'danger',
        onConfirm: async () => {
            try {
                const { data } = await axios.post(code.revoke_url);
                const idx = accessCodes.value.findIndex((c) => c.id === code.id);
                if (idx !== -1) accessCodes.value[idx] = data.access_code;
                notify('success', 'Código revocado', data.message);
            } catch (err) {
                const { message } = extractErrors(err);
                notify('error', 'No se pudo revocar', message);
            }
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <!-- Notificaciones Toast unificadas -->
        <BaseToast
            v-model="toastState.open"
            :variant="toastState.variant"
            :title="toastState.title"
            :message="toastState.message"
        />

        <!-- Pestañas de Navegación del Módulo de Suscripciones -->
        <div class="flex items-center gap-1.5 p-1 bg-surface border border-border rounded-brand overflow-x-auto no-scrollbar">
            <button
                type="button"
                class="flex-1 min-w-[130px] sm:min-w-0 inline-flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-brand text-xs font-semibold transition-all select-none"
                :class="
                    currentTab === 'requests'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'text-muted hover:text-foreground hover:bg-background/60'
                "
                @click="currentTab = 'requests'"
            >
                <CreditCard class="h-4 w-4" />
                <span>Solicitudes de pago</span>
                <span
                    v-if="pendingCount > 0"
                    class="px-1.5 py-0.2 rounded-full text-[10px] font-bold"
                    :class="
                        currentTab === 'requests'
                            ? 'bg-primary-foreground/20 text-primary-foreground'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'
                    "
                >
                    {{ pendingCount }}
                </span>
            </button>

            <button
                type="button"
                class="flex-1 min-w-[130px] sm:min-w-0 inline-flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-brand text-xs font-semibold transition-all select-none"
                :class="
                    currentTab === 'plans'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'text-muted hover:text-foreground hover:bg-background/60'
                "
                @click="currentTab = 'plans'"
            >
                <QrCode class="h-4 w-4" />
                <span>Planes y códigos QR</span>
            </button>

            <button
                type="button"
                class="flex-1 min-w-[130px] sm:min-w-0 inline-flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-brand text-xs font-semibold transition-all select-none"
                :class="
                    currentTab === 'codes'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'text-muted hover:text-foreground hover:bg-background/60'
                "
                @click="currentTab = 'codes'"
            >
                <KeyRound class="h-4 w-4" />
                <span>Códigos prepago</span>
            </button>
        </div>

        <!-- ================================================================= -->
        <!-- PESTAÑA 1: BANDEJA DE SOLICITUDES DE PAGO P2P                     -->
        <!-- ================================================================= -->
        <div v-if="currentTab === 'requests'" class="space-y-4">
            <!-- Barra de control: Filtros y buscador -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <!-- Filtros por estado -->
                <div class="flex items-center gap-1 overflow-x-auto no-scrollbar pb-1 sm:pb-0">
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-brand text-xs font-medium transition-colors"
                        :class="
                            requestFilter === 'pending'
                                ? 'bg-primary/10 text-primary border border-primary/20 font-semibold'
                                : 'bg-surface border border-border text-muted hover:text-foreground'
                        "
                        @click="requestFilter = 'pending'"
                    >
                        Pendientes ({{ pendingCount }})
                    </button>
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-brand text-xs font-medium transition-colors"
                        :class="
                            requestFilter === 'approved'
                                ? 'bg-primary/10 text-primary border border-primary/20 font-semibold'
                                : 'bg-surface border border-border text-muted hover:text-foreground'
                        "
                        @click="requestFilter = 'approved'"
                    >
                        Aprobados
                    </button>
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-brand text-xs font-medium transition-colors"
                        :class="
                            requestFilter === 'rejected'
                                ? 'bg-primary/10 text-primary border border-primary/20 font-semibold'
                                : 'bg-surface border border-border text-muted hover:text-foreground'
                        "
                        @click="requestFilter = 'rejected'"
                    >
                        Rechazados
                    </button>
                    <button
                        type="button"
                        class="px-3 py-1.5 rounded-brand text-xs font-medium transition-colors"
                        :class="
                            requestFilter === 'all'
                                ? 'bg-primary/10 text-primary border border-primary/20 font-semibold'
                                : 'bg-surface border border-border text-muted hover:text-foreground'
                        "
                        @click="requestFilter = 'all'"
                    >
                        Todos
                    </button>
                </div>

                <!-- Buscador -->
                <div class="relative w-full sm:w-64">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted pointer-events-none" />
                    <input
                        v-model="requestSearch"
                        type="search"
                        placeholder="Buscar por alumno o referencia..."
                        class="w-full pl-8 pr-3 py-2 text-xs bg-surface border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                </div>
            </div>

            <!-- Listado de Solicitudes -->
            <div v-if="filteredRequests.length > 0" class="space-y-3">
                <div
                    v-for="req in filteredRequests"
                    :key="req.id"
                    class="p-4 sm:p-5 rounded-brand bg-surface border border-border hover:border-primary/30 transition-all flex flex-col md:flex-row md:items-center justify-between gap-4"
                >
                    <div class="flex items-start gap-3.5 min-w-0">
                        <div
                            class="h-10 w-10 rounded-full flex items-center justify-center shrink-0 border"
                            :class="
                                req.status === 'approved'
                                    ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
                                    : req.status === 'rejected'
                                      ? 'bg-rose-500/10 text-rose-600 border-rose-500/20'
                                      : 'bg-amber-500/10 text-amber-600 border-amber-500/20'
                            "
                        >
                            <CheckCircle2 v-if="req.status === 'approved'" class="h-5 w-5" />
                            <XCircle v-else-if="req.status === 'rejected'" class="h-5 w-5" />
                            <Clock v-else class="h-5 w-5" />
                        </div>

                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="text-sm font-semibold text-foreground truncate">
                                    {{ req.user?.name }}
                                </h4>
                                <span class="text-xs text-muted">· {{ req.user?.email }}</span>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                                    :class="
                                        req.status === 'approved'
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                                            : req.status === 'rejected'
                                              ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20'
                                              : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                                    "
                                >
                                    {{ req.status_label }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                                <span>Plan: <strong class="text-foreground">{{ req.plan?.name || 'General' }}</strong></span>
                                <span>Monto reportado: <strong class="text-foreground font-semibold">{{ req.amount_formatted }}</strong></span>
                                <span>Referencia: <code class="text-xs font-mono bg-background px-1.5 py-0.5 rounded border border-border">{{ req.reference_text }}</code></span>
                                <span>Enviado: {{ req.created_human }} ({{ req.created_at }})</span>
                            </div>

                            <p v-if="req.admin_notes" class="text-xs text-rose-600 dark:text-rose-400 bg-rose-500/5 p-2 rounded-brand border border-rose-500/10">
                                <strong>Motivo de rechazo:</strong> {{ req.admin_notes }}
                            </p>
                        </div>
                    </div>

                    <!-- Botones de Acción -->
                    <div class="flex items-center gap-2 shrink-0 pt-2 md:pt-0 border-t md:border-t-0 border-border">
                        <button
                            type="button"
                            class="flex-1 md:flex-none inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-brand bg-background border border-border hover:border-primary/40 text-xs font-medium text-foreground transition-colors"
                            @click="openProofModal(req)"
                        >
                            <Eye class="h-3.5 w-3.5 text-primary" />
                            <span>Ver comprobante</span>
                        </button>

                        <template v-if="req.status === 'pending'">
                            <button
                                type="button"
                                :disabled="isProcessingAction"
                                class="flex-1 md:flex-none inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                                @click="approveRequest(req)"
                            >
                                <CheckCircle2 class="h-3.5 w-3.5" />
                                <span>Aprobar</span>
                            </button>

                            <button
                                type="button"
                                :disabled="isProcessingAction"
                                class="p-2 rounded-brand bg-surface border border-border hover:bg-rose-500/10 text-muted hover:text-rose-600 transition-colors"
                                title="Rechazar solicitud"
                                @click="openRejectModal(req)"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div v-else class="p-10 text-center rounded-brand border border-dashed border-border bg-surface/50">
                <CreditCard class="mx-auto h-9 w-9 text-muted/60 mb-2" />
                <p class="text-sm font-semibold text-foreground">No se encontraron solicitudes</p>
                <p class="text-xs text-muted mt-1">No hay comprobantes bajo los filtros seleccionados.</p>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- PESTAÑA 2: GESTIÓN DE PLANES Y QR INDEPENDIENTE                   -->
        <!-- ================================================================= -->
        <div v-else-if="currentTab === 'plans'" class="space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-foreground">Catálogo de Planes de Suscripción</h3>
                    <p class="text-xs text-muted mt-0.5">Define los planes disponibles y carga el código QR de cobro correspondiente a cada uno.</p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                    @click="openCreatePlanModal"
                >
                    <Plus class="h-3.5 w-3.5" />
                    <span>Nuevo plan</span>
                </button>
            </div>

            <!-- Cuadrícula de Planes -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="p-5 rounded-brand bg-surface border border-border flex flex-col justify-between space-y-4 relative"
                    :class="{ 'opacity-60 border-dashed': plan.is_archived }"
                >
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span
                                    class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border"
                                    :class="
                                        plan.is_active && !plan.is_archived
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                                            : 'bg-muted/20 text-muted border-border'
                                    "
                                >
                                    {{ plan.is_archived ? 'Archivado' : plan.is_active ? 'Activo' : 'Pausado' }}
                                </span>
                                <h4 class="text-base font-bold text-foreground mt-1.5">{{ plan.name }}</h4>
                            </div>

                            <div class="text-right">
                                <div class="text-lg font-extrabold text-primary">{{ plan.price_formatted }}</div>
                                <div class="text-[11px] text-muted">{{ plan.duration_days }} días de acceso</div>
                            </div>
                        </div>

                        <p class="text-xs text-muted line-clamp-2 leading-relaxed">
                            {{ plan.description || 'Acceso general al catálogo completo de libros y apuntes.' }}
                        </p>

                        <!-- Vista previa del QR asignado a este plan -->
                        <div class="p-3 rounded-brand bg-background border border-border flex items-center gap-3">
                            <div class="h-12 w-12 rounded-lg bg-surface border border-border flex items-center justify-center overflow-hidden shrink-0">
                                <img
                                    v-if="plan.qr_image_url"
                                    :src="plan.qr_image_url"
                                    :alt="`QR ${plan.name}`"
                                    class="h-full w-full object-contain"
                                />
                                <QrCode v-else class="h-6 w-6 text-muted/50" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-foreground truncate">
                                    {{ plan.qr_image_url ? 'Código QR configurado' : 'Sin código QR asignado' }}
                                </p>
                                <p class="text-[11px] text-muted">
                                    {{ plan.qr_image_url ? 'Listo para escaneo del alumno' : 'Sube la imagen del QR para este plan' }}
                                </p>
                            </div>
                        </div>

                        <div class="text-[11px] text-muted flex items-center justify-between pt-1 border-t border-border">
                            <span>Suscripciones activas: <strong class="text-foreground">{{ plan.active_subscriptions_count }}</strong></span>
                            <span>Pendientes: <strong class="text-foreground">{{ plan.pending_requests_count }}</strong></span>
                        </div>
                    </div>

                    <!-- Acciones del plan -->
                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                        <template v-if="!plan.is_archived">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand bg-background border border-border hover:border-primary/40 text-xs font-medium text-foreground transition-colors"
                                @click="openEditPlanModal(plan)"
                            >
                                <Settings class="h-3.5 w-3.5 text-primary" />
                                <span>Editar</span>
                            </button>

                            <button
                                type="button"
                                class="p-1.5 rounded-brand text-muted hover:text-rose-600 hover:bg-rose-500/10 transition-colors"
                                title="Archivar plan"
                                @click="promptArchivePlan(plan)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </template>

                        <button
                            v-else
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand bg-primary/10 border border-primary/20 text-xs font-medium text-primary hover:bg-primary/20 transition-colors"
                            @click="restorePlan(plan)"
                        >
                            <RotateCcw class="h-3.5 w-3.5" />
                            <span>Reactivar</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- PESTAÑA 3: CÓDIGOS DE ACCESO PREPAGO (PINES)                      -->
        <!-- ================================================================= -->
        <div v-else-if="currentTab === 'codes'" class="space-y-4">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <div class="relative w-full sm:w-72">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted pointer-events-none" />
                    <input
                        v-model="codeSearch"
                        type="search"
                        placeholder="Buscar por código, alumno o referencia..."
                        class="w-full pl-8 pr-3 py-2 text-xs bg-surface border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                </div>

                <button
                    type="button"
                    class="inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                    @click="openCreateCodeModal"
                >
                    <Plus class="h-3.5 w-3.5" />
                    <span>Generar código prepago</span>
                </button>
            </div>

            <!-- Tabla de Códigos -->
            <div class="bg-surface border border-border rounded-brand overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-background/80 border-b border-border text-muted uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Código PIN</th>
                                <th class="px-4 py-3">Plan / Duración</th>
                                <th class="px-4 py-3">Referencia</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Canjeado por</th>
                                <th class="px-4 py-3 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="code in filteredCodes" :key="code.id" class="hover:bg-background/50 transition-colors">
                                <td class="px-4 py-3 font-mono font-bold text-foreground">
                                    {{ code.code }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ code.plan_name || 'Personalizado' }} ({{ code.duration_days }} días)
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ code.reference || '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                                        :class="
                                            code.is_available
                                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                                                : code.status_label === 'Revocado'
                                                  ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20'
                                                  : 'bg-primary/10 text-primary border-primary/20'
                                        "
                                    >
                                        {{ code.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ code.redeemer_name || 'Sin canjear' }}
                                    <span v-if="code.redeemed_at" class="block text-[10px] opacity-75">({{ code.redeemed_at }})</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="code.is_available"
                                        type="button"
                                        class="p-1 rounded-brand text-muted hover:text-rose-600 hover:bg-rose-500/10 transition-colors"
                                        title="Revocar código"
                                        @click="promptRevokeCode(code)"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filteredCodes.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted">
                                    No se encontraron códigos bajo la búsqueda.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- MODAL: VISOR DE COMPROBANTE DE PAGO (STREAMING PRIVADO)           -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isProofModalOpen"
            :title="`Comprobante de pago · ${selectedRequest?.user?.name || ''}`"
            max-width="lg"
        >
            <div v-if="selectedRequest" class="space-y-4">
                <div class="grid grid-cols-2 gap-3 p-3 rounded-brand bg-background border border-border text-xs">
                    <div>
                        <span class="text-muted block">Monto reportado:</span>
                        <strong class="text-foreground text-sm font-bold">{{ selectedRequest.amount_formatted }}</strong>
                    </div>
                    <div>
                        <span class="text-muted block">Plan:</span>
                        <strong class="text-foreground">{{ selectedRequest.plan?.name || 'Suscripción' }}</strong>
                    </div>
                    <div>
                        <span class="text-muted block">Referencia bancaria:</span>
                        <code class="font-mono text-xs">{{ selectedRequest.reference_text }}</code>
                    </div>
                    <div>
                        <span class="text-muted block">Fecha de solicitud:</span>
                        <span>{{ selectedRequest.created_at }}</span>
                    </div>
                </div>

                <!-- Imagen del comprobante transmitida desde PrivateVault -->
                <div class="rounded-brand border border-border bg-background p-2 flex items-center justify-center max-h-[55vh] overflow-auto">
                    <img
                        :src="selectedRequest.proof_url"
                        :alt="`Comprobante #${selectedRequest.id}`"
                        class="max-w-full h-auto object-contain rounded"
                    />
                </div>
            </div>

            <template #footer="{ close }">
                <div class="flex items-center justify-between w-full">
                    <button
                        type="button"
                        class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                        @click="close"
                    >
                        Cerrar visor
                    </button>

                    <div v-if="selectedRequest?.status === 'pending'" class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="isProcessingAction"
                            class="px-3.5 py-2 rounded-brand bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 text-xs font-semibold transition-colors disabled:opacity-50"
                            @click="openRejectModal(selectedRequest)"
                        >
                            Rechazar
                        </button>

                        <button
                            type="button"
                            :disabled="isProcessingAction"
                            class="px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                            @click="approveRequest(selectedRequest)"
                        >
                            Aprobar pago
                        </button>
                    </div>
                </div>
            </template>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL: RECHAZAR SOLICITUD DE PAGO                                 -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isRejectModalOpen"
            title="Rechazar solicitud de pago"
            max-width="md"
        >
            <form class="space-y-4" @submit.prevent="submitRejectRequest">
                <p class="text-xs text-muted leading-relaxed">
                    Indica el motivo del rechazo para que el alumno pueda revisar su comprobante o volver a enviarlo de forma corregida.
                </p>

                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Motivo del rechazo</label>
                    <textarea
                        v-model="rejectReason"
                        rows="3"
                        required
                        placeholder="Ejemplo: El monto de la captura no coincide con el plan seleccionado o la imagen está borrosa."
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-rose-500/30 focus:border-rose-500"
                    ></textarea>
                    <p v-if="rejectError" class="text-xs text-rose-600">{{ rejectError }}</p>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                        @click="isRejectModalOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="isProcessingAction"
                        class="px-4 py-2 rounded-brand bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold transition-colors disabled:opacity-50"
                    >
                        {{ isProcessingAction ? 'Guardando...' : 'Confirmar rechazo' }}
                    </button>
                </div>
            </form>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL: CREAR / EDITAR PLAN CON SU QR                              -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isPlanModalOpen"
            :title="editingPlanId ? 'Editar plan de suscripción' : 'Nuevo plan de suscripción'"
            max-width="lg"
        >
            <form class="space-y-4" @submit.prevent="submitPlanForm">
                <BaseAlert v-if="planErrors.general" type="error" title="Revisa los campos">
                    {{ planErrors.general }}
                </BaseAlert>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="block text-xs font-medium text-foreground">Nombre del plan</label>
                        <input
                            v-model="planForm.name"
                            type="text"
                            required
                            placeholder="Ejemplo: Plan Semestral Universitario"
                            class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="planErrors.name" class="text-xs text-rose-600">{{ planErrors.name }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">Precio en Bolivianos (BOB)</label>
                        <input
                            v-model="planForm.price"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            placeholder="120.00"
                            class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="planErrors.price" class="text-xs text-rose-600">{{ planErrors.price }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">Días de duración</label>
                        <input
                            v-model.number="planForm.duration_days"
                            type="number"
                            min="1"
                            max="1825"
                            required
                            placeholder="180"
                            class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p class="text-[11px] text-muted">Ej: 180 para semestre, 365 para año completo.</p>
                        <p v-if="planErrors.duration_days" class="text-xs text-rose-600">{{ planErrors.duration_days }}</p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Descripción breve</label>
                    <input
                        v-model="planForm.description"
                        type="text"
                        maxlength="255"
                        placeholder="Acceso total a todas las obras y visor protegido"
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                </div>

                <!-- Subida del Código QR exclusivo para este Plan -->
                <div class="space-y-2 p-3.5 rounded-brand bg-background border border-border">
                    <label class="block text-xs font-semibold text-foreground">
                        Código QR Bancario de este Plan
                    </label>
                    <p class="text-[11px] text-muted leading-relaxed">
                        Sube la imagen del QR generado por tu banco (con el monto de este plan). El alumno lo escaneará directamente.
                    </p>

                    <div class="flex items-center gap-3 pt-1">
                        <div class="h-16 w-16 rounded-brand bg-surface border border-border flex items-center justify-center overflow-hidden shrink-0">
                            <img
                                v-if="planForm.qrPreviewUrl"
                                :src="planForm.qrPreviewUrl"
                                alt="Vista previa QR"
                                class="h-full w-full object-contain"
                            />
                            <QrCode v-else class="h-7 w-7 text-muted/40" />
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold cursor-pointer transition-colors">
                                <Upload class="h-3.5 w-3.5" />
                                <span>{{ planForm.qrPreviewUrl ? 'Cambiar QR' : 'Subir imagen QR' }}</span>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="hidden"
                                    @change="handleQrSelect"
                                />
                            </label>

                            <button
                                v-if="planForm.qrPreviewUrl"
                                type="button"
                                class="px-2.5 py-1.5 rounded-brand text-xs text-rose-600 hover:bg-rose-500/10 transition-colors"
                                @click="removeQrImage"
                            >
                                Quitar QR
                            </button>
                        </div>
                    </div>
                    <p v-if="planErrors.qr_image" class="text-xs text-rose-600">{{ planErrors.qr_image }}</p>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2 border-t border-border">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground select-none">
                        <input
                            v-model="planForm.is_active"
                            type="checkbox"
                            class="rounded border-border text-primary focus:ring-primary/30"
                        />
                        <span>Plan visible y disponible para suscripciones</span>
                    </label>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                            @click="isPlanModalOpen = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="isSavingPlan"
                            class="px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                        >
                            {{ isSavingPlan ? 'Guardando...' : editingPlanId ? 'Actualizar plan' : 'Guardar plan' }}
                        </button>
                    </div>
                </div>
            </form>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL: GENERAR CÓDIGO PREPAGO                                     -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isCreateCodeModalOpen"
            title="Generar códigos prepago"
            max-width="md"
        >
            <div v-if="recentlyGeneratedCodes.length > 0" class="space-y-4">
                <BaseAlert type="success" title="Lote generado con éxito">
                    Se generaron {{ recentlyGeneratedCodes.length }} códigos aleatorios listos para entregar.
                </BaseAlert>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-semibold text-foreground">Códigos generados</label>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-xs text-primary hover:text-primary-hover font-semibold"
                            @click="copyGeneratedBatch"
                        >
                            <component :is="copiedBatch ? Check : Copy" class="h-3.5 w-3.5" />
                            <span>{{ copiedBatch ? '¡Copiados!' : 'Copiar lista' }}</span>
                        </button>
                    </div>

                    <div class="p-3 bg-background border border-border rounded-brand font-mono text-xs max-h-48 overflow-y-auto space-y-1 select-all">
                        <div v-for="c in recentlyGeneratedCodes" :key="c.id" class="flex items-center justify-between">
                            <span class="font-bold text-foreground">{{ c.code }}</span>
                            <span class="text-muted text-[10px]">{{ c.plan_name }} ({{ c.duration_days }} días)</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2 border-t border-border">
                    <button
                        type="button"
                        class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                        @click="isCreateCodeModalOpen = false"
                    >
                        Cerrar
                    </button>
                    <button
                        type="button"
                        class="px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                        @click="recentlyGeneratedCodes = []"
                    >
                        Generar otro lote
                    </button>
                </div>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submitCreateCode">
                <BaseAlert v-if="codeErrors.general" type="error" title="Revisa los campos">
                    {{ codeErrors.general }}
                </BaseAlert>

                <!-- Selector de modalidad: Con Plan vs Días personalizados -->
                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Tipo de código</label>
                    <div class="grid grid-cols-2 gap-2 p-1 bg-background rounded-brand border border-border">
                        <button
                            type="button"
                            class="px-3 py-1.5 rounded-brand text-xs font-semibold transition-all select-none text-center"
                            :class="codeForm.mode === 'plan' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted hover:text-foreground'"
                            @click="codeForm.mode = 'plan'"
                        >
                            Vincular a Plan
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1.5 rounded-brand text-xs font-semibold transition-all select-none text-center"
                            :class="codeForm.mode === 'custom' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted hover:text-foreground'"
                            @click="codeForm.mode = 'custom'"
                        >
                            Días personalizados (Sin plan)
                        </button>
                    </div>
                </div>

                <!-- Caso A: Selección de Plan -->
                <div v-if="codeForm.mode === 'plan'" class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Plan de suscripción que otorgará</label>
                    <select
                        v-model="codeForm.subscription_plan_id"
                        required
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    >
                        <option v-for="p in plans" :key="p.id" :value="p.id">
                            {{ p.name }} — {{ p.duration_days }} días ({{ p.price_formatted }})
                        </option>
                    </select>
                    <p v-if="selectedPlanForCode" class="text-[11px] text-muted">
                        Cada código canjeado activará automáticamente <strong>{{ selectedPlanForCode.duration_days }} días</strong> de membresía.
                    </p>
                    <p v-if="codeErrors.subscription_plan_id" class="text-xs text-rose-600">{{ codeErrors.subscription_plan_id }}</p>
                </div>

                <!-- Caso B: Días Libres Personalizados -->
                <div v-else class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Días de duración del acceso</label>
                    <input
                        v-model.number="codeForm.duration_days"
                        type="number"
                        min="1"
                        max="1825"
                        required
                        placeholder="Ejemplo: 30"
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                    <div class="flex flex-wrap items-center gap-1.5 pt-1">
                        <span class="text-[10px] text-muted">Atajos:</span>
                        <button
                            v-for="d in [7, 15, 30, 60, 90, 180, 365]"
                            :key="d"
                            type="button"
                            class="px-2 py-0.5 rounded-full text-[10px] font-medium border transition-colors"
                            :class="codeForm.duration_days === d ? 'bg-primary/20 text-primary border-primary/30 font-bold' : 'bg-background border-border text-muted hover:text-foreground'"
                            @click="codeForm.duration_days = d"
                        >
                            {{ d }} d
                        </button>
                    </div>
                    <p class="text-[11px] text-muted">Ideal para accesos de cortesía, docentes invitados o compensación de soporte.</p>
                    <p v-if="codeErrors.duration_days" class="text-xs text-rose-600">{{ codeErrors.duration_days }}</p>
                </div>

                <!-- Cantidad a generar -->
                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Cantidad de códigos a generar (1 a 100)</label>
                    <input
                        v-model.number="codeForm.quantity"
                        type="number"
                        min="1"
                        max="100"
                        required
                        placeholder="Ejemplo: 10"
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                    <p class="text-[11px] text-muted">Todos se generarán con claves aleatorias seguras y únicas.</p>
                    <p v-if="codeErrors.quantity" class="text-xs text-rose-600">{{ codeErrors.quantity }}</p>
                </div>

                <!-- Referencia -->
                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Referencia interna (motivo del lote)</label>
                    <input
                        v-model="codeForm.reference"
                        type="text"
                        placeholder="Ejemplo: Lote 25 libros impresos feria UMSA / Becas taller"
                        class="w-full px-3 py-2 text-xs bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                    <button
                        type="button"
                        class="px-3.5 py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                        @click="isCreateCodeModalOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="isSavingCode"
                        class="px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                    >
                        {{ isSavingCode ? 'Generando lote...' : `Generar ${codeForm.quantity || 1} ${codeForm.quantity > 1 ? 'códigos' : 'código'}` }}
                    </button>
                </div>
            </form>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL DE CONFIRMACIÓN GENERAL                                     -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="confirmDialog.open"
            :title="confirmDialog.title"
            max-width="md"
            :closeable="!confirmDialog.loading"
        >
            <p class="text-sm text-muted leading-relaxed">
                {{ confirmDialog.description }}
            </p>

            <template #footer="{ close }">
                <button
                    type="button"
                    :disabled="confirmDialog.loading"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="confirmDialog.loading"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 transition-colors disabled:opacity-50"
                    @click="executeConfirmDialog"
                >
                    {{ confirmDialog.loading ? 'Procesando...' : confirmDialog.confirmLabel }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>

