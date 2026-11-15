<script setup>
/**
 * Crear o editar un plan. El QR se sube tal cual: antes pasaba por una recompresión con pérdida
 * pensada para portadas, que podía volverlo ilegible para la app del banco.
 */
import { ref, watch, computed, inject, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { QrCode, Upload } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import { PANEL_CONTEXT, btn, field, label, hint, errorText, readError, durationLabel } from './shared';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    plan: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);
const { urls } = inject(PANEL_CONTEXT);

const DURATIONS = [
    { days: 30, label: '1 mes' },
    { days: 90, label: '3 meses' },
    { days: 180, label: '6 meses' },
    { days: 365, label: '1 año' },
];
const QR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const QR_MAX_BYTES = 2 * 1024 * 1024;

const form = ref({});
const errors = ref({});
const saving = ref(false);

const isEdit = computed(() => Boolean(props.plan));
const durationText = computed(() => durationLabel(form.value.duration_days));

function revoke(url) {
    if (typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url);
}

watch(
    () => [props.modelValue, props.plan?.id],
    ([open]) => {
        if (!open) return;
        revoke(form.value.qrPreview);
        form.value = {
            name: props.plan?.name ?? '',
            price: props.plan?.price ?? '',
            duration_days: props.plan?.duration_days ?? 365,
            description: props.plan?.description ?? '',
            is_active: props.plan ? Boolean(props.plan.is_active) : true,
            sort_order: props.plan?.sort_order ?? 0,
            remove_qr: false,
            qrFile: null,
            qrPreview: props.plan?.qr_image_url ?? null,
        };
        errors.value = {};
    },
    { immediate: true }
);

onBeforeUnmount(() => revoke(form.value.qrPreview));

function onQrSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (!QR_TYPES.includes(file.type)) {
        errors.value = { ...errors.value, qr_image: 'Elige una imagen JPG, PNG o WebP.' };
        return;
    }
    if (file.size > QR_MAX_BYTES) {
        errors.value = { ...errors.value, qr_image: 'La imagen pesa más de 2 MB. Usa una captura del QR, no una foto del celular.' };
        return;
    }

    const { qr_image, ...rest } = errors.value;
    errors.value = rest;
    revoke(form.value.qrPreview);
    form.value.qrFile = file;
    form.value.qrPreview = URL.createObjectURL(file);
    form.value.remove_qr = false;
}

function removeQr() {
    revoke(form.value.qrPreview);
    form.value.qrFile = null;
    form.value.qrPreview = null;
    form.value.remove_qr = true;
}

function validate() {
    const next = {};
    if ((form.value.name || '').trim().length < 3) next.name = 'Escribe un nombre de al menos 3 letras.';
    if (form.value.price === '' || Number(form.value.price) < 0) next.price = 'Escribe el precio en bolivianos.';
    const days = Number(form.value.duration_days);
    if (!Number.isInteger(days) || days < 1 || days > 1825) next.duration_days = 'Elige entre 1 día y 5 años (1825 días).';
    errors.value = next;
    return Object.keys(next).length === 0;
}

async function save() {
    if (saving.value || !validate()) return;
    saving.value = true;

    const data = new FormData();
    data.append('name', form.value.name.trim());
    data.append('price', String(form.value.price));
    data.append('duration_days', String(form.value.duration_days));
    data.append('description', (form.value.description || '').trim());
    data.append('is_active', form.value.is_active ? '1' : '0');
    data.append('sort_order', String(form.value.sort_order || 0));
    data.append('remove_qr', form.value.remove_qr ? '1' : '0');
    if (form.value.qrFile) data.append('qr_image', form.value.qrFile);

    try {
        const { data: response } = await axios.post(isEdit.value ? props.plan.update_url : urls.storePlan, data);
        emit('saved', { plan: response.plan, message: response.message, created: !isEdit.value });
    } catch (e) {
        const { fields, message } = readError(e);
        errors.value = Object.keys(fields).length ? fields : { general: message };
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        :title="isEdit ? 'Editar plan' : 'Nuevo plan'"
        max-width="lg"
        :closeable="!saving"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <form id="plan-form" class="space-y-5" novalidate @submit.prevent="save">
            <p v-if="errors.general" role="alert" :class="[errorText, 'text-sm']">{{ errors.general }}</p>

            <div class="space-y-1.5">
                <label for="plan-name" :class="label">Nombre</label>
                <input id="plan-name" v-model="form.name" type="text" maxlength="100" autocomplete="off" :class="field" placeholder="Ej.: Plan anual…" :aria-invalid="Boolean(errors.name)" aria-describedby="plan-name-error" />
                <p v-if="errors.name" id="plan-name-error" :class="errorText">{{ errors.name }}</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <label for="plan-price" :class="label">Precio (Bs)</label>
                    <input id="plan-price" v-model="form.price" type="number" inputmode="decimal" min="0" step="0.01" :class="field" placeholder="Ej.: 200…" :aria-invalid="Boolean(errors.price)" aria-describedby="plan-price-error" />
                    <p v-if="errors.price" id="plan-price-error" :class="errorText">{{ errors.price }}</p>
                </div>

                <div class="space-y-1.5">
                    <label for="plan-days" :class="label">Duración</label>
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Duraciones frecuentes">
                        <button
                            v-for="option in DURATIONS"
                            :key="option.days"
                            type="button"
                            class="px-3 py-1.5 rounded-full border text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                            :class="Number(form.duration_days) === option.days ? 'bg-primary text-primary-foreground border-primary' : 'border-border text-muted hover:text-foreground'"
                            :aria-pressed="Number(form.duration_days) === option.days"
                            @click="form.duration_days = option.days"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <input id="plan-days" v-model.number="form.duration_days" type="number" inputmode="numeric" min="1" max="1825" :class="[field, '!w-28']" :aria-invalid="Boolean(errors.duration_days)" aria-describedby="plan-days-hint plan-days-error" />
                        <span id="plan-days-hint" :class="hint">días · {{ durationText }}</span>
                    </div>
                    <p v-if="errors.duration_days" id="plan-days-error" :class="errorText">{{ errors.duration_days }}</p>
                </div>
            </div>

            <div class="space-y-1.5">
                <label for="plan-description" :class="label">Descripción <span class="font-normal text-muted">(opcional)</span></label>
                <input id="plan-description" v-model="form.description" type="text" maxlength="255" autocomplete="off" :class="field" placeholder="Ej.: Ideal para un semestre de estudio…" />
            </div>

            <fieldset class="space-y-2 rounded-brand border border-border bg-background p-4">
                <legend class="px-1 text-sm font-medium text-foreground">QR de pago</legend>
                <p :class="hint">Sube la imagen del QR que te dio tu banco, con el monto de este plan.</p>
                <div class="flex items-center gap-4 pt-1">
                    <div class="h-24 w-24 rounded-lg bg-white border border-border flex items-center justify-center overflow-hidden shrink-0">
                        <img v-if="form.qrPreview" :src="form.qrPreview" alt="Vista previa del QR" width="96" height="96" class="h-full w-full object-contain" />
                        <QrCode v-else class="h-8 w-8 text-muted" aria-hidden="true" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label :class="[btn.secondary, 'cursor-pointer focus-within:ring-2 focus-within:ring-primary/50']">
                            <Upload class="h-4 w-4" aria-hidden="true" />
                            {{ form.qrPreview ? 'Cambiar QR' : 'Subir QR' }}
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onQrSelected" />
                        </label>
                        <button v-if="form.qrPreview" type="button" :class="btn.dangerQuiet" @click="removeQr">Quitar QR</button>
                    </div>
                </div>
                <p v-if="errors.qr_image" role="alert" :class="errorText">{{ errors.qr_image }}</p>
            </fieldset>

            <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/40" />
                <span class="text-sm text-foreground">
                    Visible para estudiantes
                    <span class="block text-xs text-muted">Si lo desmarcas, nadie nuevo puede elegirlo; quien ya lo tiene no se ve afectado.</span>
                </span>
            </label>
        </form>

        <template #footer="{ close }">
            <button type="button" :class="btn.secondary" :disabled="saving" @click="close">Cancelar</button>
            <button type="submit" form="plan-form" :class="btn.primary" :disabled="saving">
                {{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear plan' }}
            </button>
        </template>
    </BaseModal>
</template>
