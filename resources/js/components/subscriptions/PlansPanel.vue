<script setup>
/**
 * Planes: lo que ve el estudiante al pagar (nombre, precio, duración y el QR para transferir).
 */
import { ref, computed, inject } from 'vue';
import axios from 'axios';
import { Plus, QrCode, AlertTriangle } from 'lucide-vue-next';
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
    <div class="space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
            <div>
                <h3 class="font-serif text-xl text-foreground">Planes</h3>
                <p class="text-sm text-muted max-w-prose">
                    Cada plan da acceso a todos los libros y materiales durante el tiempo que elijas. El QR es el que el estudiante escanea para pagar.
                </p>
            </div>
            <button type="button" :class="btn.primary" @click="openCreate">
                <Plus class="h-4 w-4" aria-hidden="true" />
                Nuevo plan
            </button>
        </div>

        <ul v-if="visiblePlans.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="plan in visiblePlans" :key="plan.id" class="rounded-brand border border-border bg-surface p-5 flex flex-col gap-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h4 class="text-base font-semibold text-foreground break-words">{{ plan.name }}</h4>
                        <p class="text-sm text-muted">{{ plan.duration_label }} de acceso</p>
                    </div>
                    <p class="font-serif text-2xl text-foreground tabular-nums shrink-0">{{ plan.price_formatted }}</p>
                </div>

                <p v-if="plan.description" class="text-sm text-muted">{{ plan.description }}</p>

                <div
                    v-if="plan.special && plan.special.status !== 'ended'"
                    class="rounded-brand border border-gold/60 bg-gold/10 p-3 text-sm space-y-1"
                >
                    <p class="font-semibold text-foreground">
                        {{ plan.special.status === 'active' ? 'Precio especial vigente' : 'Precio especial programado' }}:
                        <span class="font-serif text-base tabular-nums">{{ plan.special.price_formatted }}</span>
                        <span v-if="plan.special.label" class="font-normal text-foreground/75"> · {{ plan.special.label }}</span>
                    </p>
                    <p class="text-foreground/75">
                        <template v-if="plan.special.status === 'scheduled'">Empieza el {{ plan.special.starts_label }} · </template>
                        Termina el {{ plan.special.ends_label }}
                    </p>
                    <p v-if="!plan.special.qr_image_url" class="text-amber-800 dark:text-amber-300 inline-flex items-center gap-1.5">
                        <AlertTriangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Falta el QR del precio especial
                    </p>
                </div>
                <p v-else-if="plan.special?.status === 'ended'" class="text-xs text-muted">
                    El precio especial terminó el {{ plan.special.ends_label }}.
                </p>

                <div class="flex items-center gap-3 rounded-brand bg-background border border-border p-3">
                    <div class="h-14 w-14 rounded-lg bg-white border border-border flex items-center justify-center overflow-hidden shrink-0">
                        <img v-if="plan.qr_image_url" :src="plan.qr_image_url" :alt="`QR de pago de ${plan.name}`" width="56" height="56" class="h-full w-full object-contain" />
                        <QrCode v-else class="h-6 w-6 text-muted" aria-hidden="true" />
                    </div>
                    <p v-if="plan.qr_image_url" class="text-sm text-foreground">QR listo para pagar</p>
                    <p v-else class="text-sm text-amber-800 dark:text-amber-300 inline-flex items-center gap-1.5">
                        <AlertTriangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Falta el QR: nadie puede pagar este plan
                    </p>
                </div>

                <p class="text-xs text-muted">
                    <span v-if="!plan.is_active" class="font-semibold text-foreground">Oculto para estudiantes · </span>
                    {{ plural(plan.active_subscriptions_count, 'persona con acceso', 'personas con acceso') }}
                    <template v-if="plan.pending_requests_count"> · {{ plural(plan.pending_requests_count, 'pago por revisar', 'pagos por revisar') }}</template>
                </p>

                <div class="mt-auto flex items-center justify-between gap-2 pt-3 border-t border-border">
                    <button type="button" :class="btn.dangerQuiet" @click="archiveTarget = plan">
                        Archivar
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>
                    <button type="button" :class="btn.secondary" @click="openEdit(plan)">
                        Editar
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <div v-else class="rounded-brand border border-dashed border-border bg-surface/60 px-6 py-10 text-center">
            <p class="text-base font-semibold text-foreground">Todavía no hay planes</p>
            <p class="mt-1 text-sm text-muted">Crea uno para que los estudiantes puedan pagar su membresía.</p>
            <button type="button" :class="[btn.primary, 'mt-4']" @click="openCreate">Crear el primer plan</button>
        </div>

        <div v-if="archivedPlans.length">
            <button
                type="button"
                :class="btn.quiet"
                :aria-expanded="showArchived"
                aria-controls="archived-plans"
                @click="showArchived = !showArchived"
            >
                {{ showArchived ? 'Ocultar' : 'Ver' }} {{ plural(archivedPlans.length, 'plan archivado', 'planes archivados') }}
            </button>
            <ul v-show="showArchived" id="archived-plans" class="mt-2 divide-y divide-border rounded-brand border border-dashed border-border">
                <li v-for="plan in archivedPlans" :key="plan.id" class="p-4 flex items-center justify-between gap-3">
                    <p class="text-sm text-muted min-w-0 truncate">
                        <span class="font-semibold text-foreground">{{ plan.name }}</span> · {{ plan.price_formatted }} · {{ plan.duration_label }}
                    </p>
                    <button type="button" :class="btn.small" @click="restore(plan)">
                        Restaurar
                        <span class="sr-only"> {{ plan.name }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <PlanFormModal v-model="formOpen" :plan="editing" @saved="onSaved" />

        <BaseModal
            :model-value="Boolean(archiveTarget)"
            :title="archiveTarget ? `¿Archivar «${archiveTarget.name}»?` : ''"
            max-width="md"
            :closeable="!archiving"
            @update:model-value="!$event && (archiveTarget = null)"
        >
            <p class="text-sm text-foreground leading-relaxed">
                Dejará de aparecer para quienes quieran pagar. Quien ya lo tiene conserva su acceso, y los pagos pendientes de este plan se pueden seguir aprobando.
            </p>
            <p class="mt-2 text-sm text-muted">Puedes restaurarlo cuando quieras.</p>
            <template #footer="{ close }">
                <button type="button" :class="btn.secondary" :disabled="archiving" @click="close">Cancelar</button>
                <button type="button" :class="btn.danger" :disabled="archiving" @click="archive">
                    {{ archiving ? 'Archivando…' : 'Archivar plan' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
