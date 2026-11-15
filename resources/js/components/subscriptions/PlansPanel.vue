<script setup>
/**
 * Planes: lo que ve el estudiante al pagar (nombre, precio, duración y el QR para transferir).
 */
import { ref, computed, inject } from 'vue';
import axios from 'axios';
import { Plus, QrCode, AlertTriangle, Archive, RotateCcw, CreditCard, Settings2, ChevronDown } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import PlanFormModal from './PlanFormModal.vue';
import { PANEL_CONTEXT, btn, readError, plural } from './shared';

const { plans, notify } = inject(PANEL_CONTEXT);

const formOpen = ref(false);
const editing = ref(null);
const showArchived = ref(false);

const visiblePlans = computed(() => plans.value.filter((p) => !p.is_archived));
const archivedPlans = computed(() => plans.value.filter((p) => p.is_archived));

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(plan) {
    editing.value = plan;
    formOpen.value = true;
}

function replacePlan(plan) {
    const index = plans.value.findIndex((p) => p.id === plan.id);
    if (index === -1) plans.value.unshift(plan);
    else plans.value[index] = plan;
}

function onSaved({ plan, message, created }) {
    replacePlan(plan);
    formOpen.value = false;
    notify('success', created ? 'Plan creado' : 'Plan guardado', message);
}

// Archivar
const archiveTarget = ref(null);
const archiving = ref(false);

async function archive() {
    if (!archiveTarget.value || archiving.value) return;
    archiving.value = true;
    try {
        const { data } = await axios.delete(archiveTarget.value.destroy_url);
        replacePlan(data.plan);
        notify('success', 'Plan archivado', data.message);
        archiveTarget.value = null;
    } catch (e) {
        notify('error', 'No se pudo archivar', readError(e).message);
    } finally {
        archiving.value = false;
    }
}

async function restore(plan) {
    try {
        const { data } = await axios.post(plan.restore_url);
        replacePlan(data.plan);
        notify('success', 'Plan restaurado', data.message);
    } catch (e) {
        notify('error', 'No se pudo restaurar', readError(e).message);
    }
}
</script>

<template>
    <div class="space-y-4 sm:space-y-5">
        <!-- Barra superior alineada a /admin/libros -->
        <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-foreground">Planes de suscripción</h3>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums">
                        {{ visiblePlans.length }}
                    </span>
                </div>
                <p class="text-xs text-muted mt-0.5">
                    Configura precios, vigencia y el código QR bancario que escanean los estudiantes al pagar.
                </p>
            </div>

            <button type="button" :class="[btn.primary, 'shrink-0 w-full sm:w-auto']" @click="openCreate">
                <Plus class="h-4 w-4" aria-hidden="true" />
                <span>Nuevo plan</span>
            </button>
        </div>

        <!-- Cuadrícula adaptable de planes estilo /admin/libros -->
        <ul v-if="visiblePlans.length" class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-3 sm:gap-5">
            <li
                v-for="plan in visiblePlans"
                :key="plan.id"
                class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col justify-between gap-3 sm:gap-4 hover:border-primary/40 transition-colors"
            >
                <div class="space-y-3">
                    <div class="flex gap-3 sm:gap-4">
                        <!-- Miniatura del código QR -->
                        <div class="w-20 h-20 sm:w-24 sm:h-24 shrink-0 rounded-brand overflow-hidden border border-border bg-white flex items-center justify-center p-1.5">
                            <img
                                v-if="plan.qr_image_url"
                                :src="plan.qr_image_url"
                                :alt="`QR de pago de ${plan.name}`"
                                width="88"
                                height="88"
                                loading="lazy"
                                decoding="async"
                                class="w-full h-full object-contain"
                            />
                            <div v-else class="text-center space-y-1 p-1 select-none">
                                <QrCode class="h-6 w-6 mx-auto text-slate-400" aria-hidden="true" />
                                <span class="block text-[10px] font-semibold text-slate-500 leading-tight">Sin QR</span>
                            </div>
                        </div>

                        <!-- Información principal del plan -->
                        <div class="flex-1 min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center justify-between gap-1.5">
                                <span
                                    v-if="plan.is_active"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                >
                                    Visible
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                                >
                                    Oculto
                                </span>

                                <span class="text-sm sm:text-base font-bold text-foreground tabular-nums">
                                    {{ plan.price_formatted }}
                                </span>
                            </div>

                            <div>
                                <h4 class="text-sm sm:text-base font-semibold text-foreground leading-snug line-clamp-2">
                                    {{ plan.name }}
                                </h4>
                                <p v-if="plan.description" class="text-xs text-muted line-clamp-1 mt-0.5">
                                    {{ plan.description }}
                                </p>
                                <p class="text-xs font-medium text-muted mt-1">
                                    {{ plan.duration_label }} de acceso
                                </p>
                            </div>

                            <div class="pt-0.5 text-xs text-muted flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span
                                    v-if="plan.qr_image_url"
                                    class="font-medium text-emerald-600 dark:text-emerald-400"
                                >
                                    QR activo
                                </span>
                                <span
                                    v-else
                                    class="font-medium text-amber-600 dark:text-amber-400 inline-flex items-center gap-1"
                                >
                                    <AlertTriangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    Falta QR
                                </span>
                                <span>·</span>
                                <span>{{ plural(plan.active_subscriptions_count, 'con acceso', 'con acceso') }}</span>
                                <template v-if="plan.pending_requests_count">
                                    <span>·</span>
                                    <span class="font-medium text-amber-600 dark:text-amber-400">
                                        {{ plural(plan.pending_requests_count, 'por revisar', 'por revisar') }}
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Aviso de precio especial -->
                    <div
                        v-if="plan.special && plan.special.status !== 'ended'"
                        class="rounded-brand border border-amber-500/20 bg-amber-500/5 p-2.5 text-xs space-y-1"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-1">
                            <span class="font-semibold text-foreground">
                                {{ plan.special.status === 'active' ? 'Precio especial vigente' : 'Precio especial programado' }}
                                <span v-if="plan.special.label" class="font-normal text-muted">· {{ plan.special.label }}</span>
                            </span>
                            <span class="font-bold text-amber-600 dark:text-amber-400 tabular-nums">
                                {{ plan.special.price_formatted }}
                            </span>
                        </div>
                        <p class="text-[11px] text-muted">
                            <template v-if="plan.special.status === 'scheduled'">Inicia {{ plan.special.starts_label }} · </template>
                            Finaliza {{ plan.special.ends_label }}
                        </p>
                        <p v-if="!plan.special.qr_image_url" class="text-[11px] font-medium text-amber-600 dark:text-amber-400 inline-flex items-center gap-1">
                            <AlertTriangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            Falta subir el QR con el monto especial
                        </p>
                    </div>
                </div>

                <!-- Pie de acciones de tarjeta (idéntico a /admin/libros) -->
                <div class="pt-3 border-t border-border flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                        @click="openEdit(plan)"
                    >
                        <Settings2 class="h-3.5 w-3.5" aria-hidden="true" />
                        <span>Editar plan</span>
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>

                    <button
                        type="button"
                        :class="btn.dangerQuiet"
                        title="Archivar plan"
                        @click="archiveTarget = plan"
                    >
                        <Archive class="h-4 w-4 sm:h-3.5 sm:w-3.5" aria-hidden="true" />
                        <span class="hidden sm:inline">Archivar</span>
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <!-- Estado vacío alineado a /admin/libros -->
        <div
            v-else
            class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-3"
        >
            <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                <CreditCard class="h-6 w-6" aria-hidden="true" />
            </div>
            <h4 class="text-base font-semibold text-foreground">Aún no hay planes activos</h4>
            <p class="text-sm text-muted max-w-md mx-auto">
                Registra el primer plan de suscripción con su código QR para que los estudiantes puedan solicitar su acceso.
            </p>
            <div class="pt-2">
                <button type="button" :class="btn.primary" @click="openCreate">
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    <span>Crear primer plan</span>
                </button>
            </div>
        </div>

        <!-- Planes archivados -->
        <div v-if="archivedPlans.length" class="space-y-3">
            <button
                type="button"
                class="w-full bg-surface border border-border rounded-brand px-3.5 py-2.5 sm:px-4 sm:py-3 flex items-center justify-between gap-3 text-left hover:border-primary/40 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                :aria-expanded="showArchived"
                aria-controls="archived-plans"
                @click="showArchived = !showArchived"
            >
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold text-foreground">Planes archivados</span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums">
                        {{ archivedPlans.length }}
                    </span>
                </div>
                <ChevronDown
                    class="h-4 w-4 text-muted transition-transform duration-200"
                    :class="{ 'rotate-180': showArchived }"
                    aria-hidden="true"
                />
            </button>

            <ul v-if="showArchived" id="archived-plans" class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
                <li
                    v-for="plan in archivedPlans"
                    :key="plan.id"
                    class="p-3 sm:p-3.5 flex items-center justify-between gap-3 hover:bg-background/40 transition-colors"
                >
                    <div class="min-w-0">
                        <p class="text-xs sm:text-sm text-muted truncate">
                            <span class="font-semibold text-foreground">{{ plan.name }}</span>
                            <span> · {{ plan.price_formatted }} · {{ plan.duration_label }}</span>
                        </p>
                    </div>
                    <button type="button" :class="btn.small" @click="restore(plan)">
                        <RotateCcw class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                        <span>Restaurar</span>
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <PlanFormModal v-model="formOpen" :plan="editing" @saved="onSaved" />

        <BaseModal
            :model-value="Boolean(archiveTarget)"
            :title="archiveTarget ? `Archivar «${archiveTarget.name}»` : 'Archivar plan'"
            max-width="md"
            :closeable="!archiving"
            @update:model-value="!$event && (archiveTarget = null)"
        >
            <div class="space-y-2 text-xs sm:text-sm text-foreground leading-relaxed">
                <p>
                    Este plan dejará de mostrarse a los nuevos estudiantes. Quienes ya tienen acceso activo lo conservarán intacto, y los comprobantes pendientes aún podrán aprobarse.
                </p>
                <p class="text-xs text-muted">Puedes restaurar el plan en cualquier momento desde la sección de archivados.</p>
            </div>
            <template #footer="{ close }">
                <button type="button" :class="btn.secondary" :disabled="archiving" @click="close">Cancelar</button>
                <button type="button" :class="btn.danger" :disabled="archiving" @click="archive">
                    {{ archiving ? 'Archivando…' : 'Archivar plan' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
