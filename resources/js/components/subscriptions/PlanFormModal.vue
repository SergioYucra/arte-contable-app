<script setup>
/**
 * Crear o editar un plan. El QR se sube tal cual: antes pasaba por una recompresión con pérdida
 * pensada para portadas, que podía volverlo ilegible para la app del banco.
 */
import { ref, watch, computed, inject, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { QrCode, ImagePlus } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
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
        errors.value = { ...errors.value, [slot.error]: 'Selecciona una imagen en formato JPG, PNG o WebP.' };
        return;
    }
    if (file.size > QR_MAX_BYTES) {
        errors.value = { ...errors.value, [slot.error]: 'La imagen supera el límite de 2 MB.' };
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

const specialSummary = computed(() => {
    const f = form.value;
    if (!f.special_enabled || !f.special_price || !f.special_ends_at || !f.price) return '';
    const fmt = (value) => new Intl.DateTimeFormat('es-BO', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
    const from = f.special_starts_at ? `Desde el ${fmt(f.special_starts_at)}` : 'Desde que guardes';
    return `${from} hasta el ${fmt(f.special_ends_at)}, el plan costará Bs ${f.special_price} en lugar de Bs ${f.price}.`;
});

function validate() {
    const next = {};
    if ((form.value.name || '').trim().length < 3) next.name = 'El nombre debe tener al menos 3 caracteres.';
    if (form.value.price === '' || Number(form.value.price) < 0) next.price = 'Ingresa el precio en bolivianos.';
    const days = Number(form.value.duration_days);
    if (!Number.isInteger(days) || days < 1 || days > 1825) next.duration_days = 'Indica entre 1 día y 5 años (1825 días).';

    if (form.value.special_enabled) {
        const special = Number(form.value.special_price);
        if (!special || special < 1) next.special_price = 'Ingresa el precio especial.';
        else if (special >= Number(form.value.price)) next.special_price = 'El precio especial debe ser menor al precio regular.';
        if (!form.value.special_ends_at) next.special_ends_at = 'Indica la fecha de finalización.';
        else if (new Date(form.value.special_ends_at) <= new Date()) next.special_ends_at = 'Selecciona una fecha futura.';
        if (form.value.special_starts_at && form.value.special_ends_at && form.value.special_starts_at >= form.value.special_ends_at) {
            next.special_starts_at = 'La fecha de inicio debe ser anterior al cierre.';
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
        :title="isEdit ? 'Editar plan de suscripción' : 'Registrar nuevo plan'"
        max-width="xl"
        :closeable="!saving"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <form id="plan-form" class="space-y-4" novalidate @submit.prevent="save">
            <BaseAlert v-if="errors.general" type="error">
                {{ errors.general }}
            </BaseAlert>

            <div class="flex flex-col sm:flex-row gap-4 items-start">
                <!-- Selector compacto de QR estilo portada de /admin/libros -->
                <div class="w-full sm:w-28 shrink-0 flex sm:block items-center gap-3.5 sm:space-y-2 p-2.5 sm:p-0 rounded-brand bg-background sm:bg-transparent border sm:border-0 border-border">
                    <div class="w-16 h-16 sm:w-28 sm:h-28 shrink-0 rounded-brand overflow-hidden border border-border bg-white flex items-center justify-center p-1.5">
                        <img
                            v-if="form.qrPreview"
                            :src="form.qrPreview"
                            alt="Vista previa del QR"
                            width="96"
                            height="96"
                            class="w-full h-full object-contain"
                        />
                        <QrCode v-else class="h-6 w-6 text-slate-400" aria-hidden="true" />
                    </div>

                    <div class="flex-1 sm:w-full space-y-1.5">
                        <p class="text-xs font-medium text-foreground sm:hidden">Código QR de cobro</p>
                        <div class="flex sm:flex-col items-center gap-2 sm:gap-1.5">
                            <label
                                class="sm:w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 sm:px-2.5 sm:py-1.5 rounded-brand bg-surface sm:bg-background border border-border text-xs sm:text-[11px] font-semibold text-foreground cursor-pointer hover:border-primary/40 transition-colors"
                            >
                                <ImagePlus class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                                <span>{{ form.qrPreview ? 'Cambiar QR' : 'Subir QR' }}</span>
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="hidden"
                                    @change="onQrSelected($event, 'qr')"
                                />
                            </label>

                            <button
                                v-if="form.qrPreview"
                                type="button"
                                class="px-2 py-1 sm:w-full text-xs sm:text-[11px] text-rose-600 dark:text-rose-400 hover:underline"
                                @click="removeQr('qr')"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Campos principales -->
                <div class="flex-1 w-full space-y-3.5">
                    <div class="space-y-1">
                        <label for="plan-name" :class="label">Nombre del plan *</label>
                        <input
                            id="plan-name"
                            v-model="form.name"
                            type="text"
                            maxlength="100"
                            autocomplete="off"
                            :class="field"
                            placeholder="Ej. Plan Anual Completo"
                            :aria-invalid="Boolean(errors.name)"
                        />
                        <p v-if="errors.name" :class="errorText">{{ errors.name }}</p>
                    </div>

                    <div class="grid gap-3.5 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="plan-price" :class="label">Precio (Bs) *</label>
                            <input
                                id="plan-price"
                                v-model="form.price"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                :class="field"
                                placeholder="Ej. 200"
                                :aria-invalid="Boolean(errors.price)"
                            />
                            <p v-if="errors.price" :class="errorText">{{ errors.price }}</p>
                        </div>

                        <div class="space-y-1">
                            <label for="plan-days" :class="label">Vigencia (días) *</label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="plan-days"
                                    v-model.number="form.duration_days"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    max="1825"
                                    :class="[field, '!w-24']"
                                    :aria-invalid="Boolean(errors.duration_days)"
                                />
                                <span :class="hint">{{ durationText }}</span>
                            </div>
                            <p v-if="errors.duration_days" :class="errorText">{{ errors.duration_days }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Duraciones frecuentes">
                        <button
                            v-for="option in DURATIONS"
                            :key="option.days"
                            type="button"
                            class="px-2.5 py-1 rounded-brand border text-xs font-medium transition-colors"
                            :class="
                                Number(form.duration_days) === option.days
                                    ? 'bg-primary text-primary-foreground border-primary'
                                    : 'bg-background border-border text-muted hover:text-foreground'
                            "
                            :aria-pressed="Number(form.duration_days) === option.days"
                            @click="form.duration_days = option.days"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="errors.qr_image" role="alert" :class="errorText">{{ errors.qr_image }}</p>

            <div class="space-y-1">
                <label for="plan-description" :class="label">Descripción breve <span class="font-normal text-muted">(opcional)</span></label>
                <input
                    id="plan-description"
                    v-model="form.description"
                    type="text"
                    maxlength="255"
                    autocomplete="off"
                    :class="field"
                    placeholder="Ej. Acceso completo a las ediciones vigentes y apuntes..."
                />
            </div>

            <!-- Precio especial por tiempo limitado -->
            <div
                class="rounded-brand border p-3.5 space-y-3.5"
                :class="form.special_enabled ? 'border-amber-500/30 bg-amber-500/5' : 'border-border bg-background/50'"
            >
                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input
                        v-model="form.special_enabled"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30"
                    />
                    <div>
                        <span class="block text-xs font-semibold text-foreground">
                            Activar precio promocional por tiempo limitado
                        </span>
                        <span class="block text-[11px] text-muted mt-0.5">
                            Al cumplirse la fecha límite, el plan vuelve automáticamente a su precio y QR habituales.
                        </span>
                    </div>
                </label>

                <template v-if="form.special_enabled">
                    <div class="grid gap-3 sm:grid-cols-2 pt-1">
                        <div class="space-y-1">
                            <label for="special-price" :class="label">Precio promocional (Bs) *</label>
                            <input
                                id="special-price"
                                v-model="form.special_price"
                                type="number"
                                inputmode="decimal"
                                min="1"
                                step="0.01"
                                :class="field"
                                placeholder="Ej. 150"
                                :aria-invalid="Boolean(errors.special_price)"
                            />
                            <p v-if="errors.special_price" :class="errorText">{{ errors.special_price }}</p>
                        </div>
                        <div class="space-y-1">
                            <label for="special-label" :class="label">Etiqueta <span class="font-normal text-muted">(opcional)</span></label>
                            <input
                                id="special-label"
                                v-model="form.special_label"
                                type="text"
                                maxlength="60"
                                autocomplete="off"
                                :class="field"
                                placeholder="Ej. Inicio de semestre"
                            />
                        </div>
                        <div class="space-y-1">
                            <label for="special-starts" :class="label">Desde <span class="font-normal text-muted">(opcional)</span></label>
                            <input
                                id="special-starts"
                                v-model="form.special_starts_at"
                                type="datetime-local"
                                :class="field"
                                :aria-invalid="Boolean(errors.special_starts_at)"
                            />
                            <p v-if="errors.special_starts_at" :class="errorText">{{ errors.special_starts_at }}</p>
                            <p v-else :class="hint">Vacío = inicia al guardar.</p>
                        </div>
                        <div class="space-y-1">
                            <label for="special-ends" :class="label">Hasta *</label>
                            <input
                                id="special-ends"
                                v-model="form.special_ends_at"
                                type="datetime-local"
                                :class="field"
                                :aria-invalid="Boolean(errors.special_ends_at)"
                            />
                            <p v-if="errors.special_ends_at" :class="errorText">{{ errors.special_ends_at }}</p>
                            <p v-else :class="hint">Hora local de Bolivia.</p>
                        </div>
                    </div>

                    <div class="p-3 rounded-brand bg-surface border border-border flex items-center gap-3.5">
                        <div class="h-16 w-16 rounded-brand bg-white border border-border flex items-center justify-center overflow-hidden shrink-0 p-1">
                            <img
                                v-if="form.specialQrPreview"
                                :src="form.specialQrPreview"
                                alt="Vista previa del QR promocional"
                                width="64"
                                height="64"
                                class="h-full w-full object-contain"
                            />
                            <QrCode v-else class="h-5 w-5 text-slate-400" aria-hidden="true" />
                        </div>
                        <div class="flex-1 min-w-0 space-y-1.5">
                            <p class="text-xs font-semibold text-foreground">QR del precio promocional</p>
                            <p :class="hint">Sube el QR bancario generado con el monto promocional.</p>
                            <div class="flex flex-wrap items-center gap-2 pt-0.5">
                                <label
                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-brand bg-background border border-border hover:border-primary/40 text-xs font-semibold text-foreground cursor-pointer transition-colors"
                                >
                                    <ImagePlus class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                                    <span>{{ form.specialQrPreview ? 'Cambiar QR' : 'Subir QR' }}</span>
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden"
                                        @change="onQrSelected($event, 'special')"
                                    />
                                </label>
                                <button
                                    v-if="form.specialQrPreview"
                                    type="button"
                                    class="px-2 py-1 text-xs font-medium text-rose-600 dark:text-rose-400 hover:underline"
                                    @click="removeQr('special')"
                                >
                                    Quitar
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="errors.special_qr_image" role="alert" :class="errorText">{{ errors.special_qr_image }}</p>

                    <p v-if="specialSummary" class="text-xs text-foreground rounded-brand bg-surface border border-border p-2.5">
                        {{ specialSummary }}
                    </p>
                </template>
            </div>

            <div class="rounded-brand bg-background border border-border p-3.5">
                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30"
                    />
                    <div>
                        <span class="block text-xs font-semibold text-foreground">
                            Publicar plan para estudiantes
                        </span>
                        <span class="block text-[11px] text-muted mt-0.5">
                            Si lo desmarcas, quedará oculto para nuevas compras sin afectar a quienes ya tienen acceso.
                        </span>
                    </div>
                </label>
            </div>
        </form>

        <template #footer="{ close }">
            <button type="button" :class="btn.secondary" :disabled="saving" @click="close">Cancelar</button>
            <button type="submit" form="plan-form" :class="btn.primary" :disabled="saving">
                {{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear plan' }}
            </button>
        </template>
    </BaseModal>
</template>
