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

// Dos QR: el del precio normal y el del precio especial (el QR del banco lleva el monto adentro).
const QR_SLOTS = {
    qr: { file: 'qrFile', preview: 'qrPreview', remove: 'remove_qr', error: 'qr_image' },
    special: { file: 'specialQrFile', preview: 'specialQrPreview', remove: 'remove_special_qr', error: 'special_qr_image' },
};

function revoke(url) {
    if (typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url);
}

function revokeAll() {
    revoke(form.value.qrPreview);
    revoke(form.value.specialQrPreview);
}

watch(
    () => [props.modelValue, props.plan?.id],
    ([open]) => {
        if (!open) return;
        revokeAll();
        const special = props.plan?.special;
        // Un precio especial ya terminado no se precarga: se empieza de cero si se quiere otro.
        const keepSpecial = special && special.status !== 'ended';
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
            special_enabled: Boolean(keepSpecial),
            special_price: keepSpecial ? special.price : '',
            special_label: keepSpecial ? special.label : '',
            special_starts_at: keepSpecial ? special.starts_at_input ?? '' : '',
            special_ends_at: keepSpecial ? special.ends_at_input ?? '' : '',
            remove_special_qr: false,
            specialQrFile: null,
            specialQrPreview: keepSpecial ? special.qr_image_url : null,
        };
        errors.value = {};
    },
    { immediate: true }
);

onBeforeUnmount(revokeAll);

function onQrSelected(event, slotName = 'qr') {
    const slot = QR_SLOTS[slotName];
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (!QR_TYPES.includes(file.type)) {
        errors.value = { ...errors.value, [slot.error]: 'Elige una imagen JPG, PNG o WebP.' };
        return;
    }
    if (file.size > QR_MAX_BYTES) {
        errors.value = { ...errors.value, [slot.error]: 'La imagen pesa más de 2 MB. Usa una captura del QR, no una foto del celular.' };
        return;
    }

    const { [slot.error]: _removed, ...rest } = errors.value;
    errors.value = rest;
    revoke(form.value[slot.preview]);
    form.value[slot.file] = file;
    form.value[slot.preview] = URL.createObjectURL(file);
    form.value[slot.remove] = false;
}

function removeQr(slotName = 'qr') {
    const slot = QR_SLOTS[slotName];
    revoke(form.value[slot.preview]);
    form.value[slot.file] = null;
    form.value[slot.preview] = null;
    form.value[slot.remove] = true;
}

/** "Del 20 de noviembre al 30 de noviembre, a las 23:59, costará Bs 150 en vez de Bs 200." */
const specialSummary = computed(() => {
    const f = form.value;
    if (!f.special_enabled || !f.special_price || !f.special_ends_at || !f.price) return '';
    const fmt = (value) => new Intl.DateTimeFormat('es-BO', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
    const from = f.special_starts_at ? `Desde el ${fmt(f.special_starts_at)}` : 'Desde que guardes';
    return `${from} hasta el ${fmt(f.special_ends_at)}, el plan costará Bs ${f.special_price} en vez de Bs ${f.price}.`;
});

function validate() {
    const next = {};
    if ((form.value.name || '').trim().length < 3) next.name = 'Escribe un nombre de al menos 3 letras.';
    if (form.value.price === '' || Number(form.value.price) < 0) next.price = 'Escribe el precio en bolivianos.';
    const days = Number(form.value.duration_days);
    if (!Number.isInteger(days) || days < 1 || days > 1825) next.duration_days = 'Elige entre 1 día y 5 años (1825 días).';

    if (form.value.special_enabled) {
        const special = Number(form.value.special_price);
        if (!special || special < 1) next.special_price = 'Escribe el precio especial.';
        else if (special >= Number(form.value.price)) next.special_price = 'El precio especial tiene que ser menor que el precio normal.';
        if (!form.value.special_ends_at) next.special_ends_at = 'Elige hasta cuándo dura el precio especial.';
        else if (new Date(form.value.special_ends_at) <= new Date()) next.special_ends_at = 'Esa fecha ya pasó. Elige una fecha futura.';
        if (form.value.special_starts_at && form.value.special_ends_at && form.value.special_starts_at >= form.value.special_ends_at) {
            next.special_starts_at = 'El inicio tiene que ser antes del fin.';
        }
    }

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

    data.append('special_enabled', form.value.special_enabled ? '1' : '0');
    data.append('remove_special_qr', form.value.remove_special_qr ? '1' : '0');
    if (form.value.special_enabled) {
        data.append('special_price', String(form.value.special_price));
        data.append('special_label', (form.value.special_label || '').trim());
        data.append('special_ends_at', form.value.special_ends_at);
        if (form.value.special_starts_at) data.append('special_starts_at', form.value.special_starts_at);
        if (form.value.specialQrFile) data.append('special_qr_image', form.value.specialQrFile);
    }

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
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onQrSelected($event, 'qr')" />
                        </label>
                        <button v-if="form.qrPreview" type="button" :class="btn.dangerQuiet" @click="removeQr('qr')">Quitar QR</button>
                    </div>
                </div>
                <p v-if="errors.qr_image" role="alert" :class="errorText">{{ errors.qr_image }}</p>
            </fieldset>

            <!-- Precio especial por tiempo limitado (opcional) -->
            <div class="rounded-brand border p-4 space-y-4" :class="form.special_enabled ? 'border-gold/60 bg-gold/5' : 'border-border'">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="form.special_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/40" />
                    <span class="text-sm text-foreground">
                        Precio especial por tiempo limitado
                        <span class="block text-xs text-muted">Opcional. Por ejemplo, Black Friday o el lanzamiento de un libro. Al terminar vuelve solo el precio normal.</span>
                    </span>
                </label>

                <template v-if="form.special_enabled">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <label for="special-price" :class="label">Precio especial (Bs)</label>
                            <input id="special-price" v-model="form.special_price" type="number" inputmode="decimal" min="1" step="0.01" :class="field" placeholder="Ej.: 150…" :aria-invalid="Boolean(errors.special_price)" />
                            <p v-if="errors.special_price" :class="errorText">{{ errors.special_price }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <label for="special-label" :class="label">Nombre <span class="font-normal text-muted">(opcional)</span></label>
                            <input id="special-label" v-model="form.special_label" type="text" maxlength="60" autocomplete="off" :class="field" placeholder="Ej.: Black Friday…" />
                        </div>
                        <div class="space-y-1.5">
                            <label for="special-starts" :class="label">Desde <span class="font-normal text-muted">(opcional)</span></label>
                            <input id="special-starts" v-model="form.special_starts_at" type="datetime-local" :class="field" :aria-invalid="Boolean(errors.special_starts_at)" />
                            <p v-if="errors.special_starts_at" :class="errorText">{{ errors.special_starts_at }}</p>
                            <p v-else :class="hint">Vacío = empieza al guardar.</p>
                        </div>
                        <div class="space-y-1.5">
                            <label for="special-ends" :class="label">Hasta</label>
                            <input id="special-ends" v-model="form.special_ends_at" type="datetime-local" :class="field" :aria-invalid="Boolean(errors.special_ends_at)" />
                            <p v-if="errors.special_ends_at" :class="errorText">{{ errors.special_ends_at }}</p>
                            <p v-else :class="hint">Hora de Bolivia.</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <p class="text-sm font-medium text-foreground">QR del precio especial</p>
                        <p :class="hint">El QR del banco lleva el monto adentro, así que este precio necesita su propio QR. Sin él, el estudiante no verá ningún QR mientras dure el precio especial.</p>
                        <div class="flex items-center gap-4">
                            <div class="h-20 w-20 rounded-lg bg-white border border-border flex items-center justify-center overflow-hidden shrink-0">
                                <img v-if="form.specialQrPreview" :src="form.specialQrPreview" alt="Vista previa del QR del precio especial" width="80" height="80" class="h-full w-full object-contain" />
                                <QrCode v-else class="h-7 w-7 text-muted" aria-hidden="true" />
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <label :class="[btn.secondary, 'cursor-pointer focus-within:ring-2 focus-within:ring-primary/50']">
                                    <Upload class="h-4 w-4" aria-hidden="true" />
                                    {{ form.specialQrPreview ? 'Cambiar QR' : 'Subir QR' }}
                                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onQrSelected($event, 'special')" />
                                </label>
                                <button v-if="form.specialQrPreview" type="button" :class="btn.dangerQuiet" @click="removeQr('special')">Quitar QR</button>
                            </div>
                        </div>
                        <p v-if="errors.special_qr_image" role="alert" :class="errorText">{{ errors.special_qr_image }}</p>
                    </div>

                    <p v-if="specialSummary" class="text-sm text-foreground rounded-brand bg-surface border border-border p-3">{{ specialSummary }}</p>
                </template>
            </div>

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
