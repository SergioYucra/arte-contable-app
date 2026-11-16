<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { CheckCircle2, Download, FileText, ImagePlus, QrCode } from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseModal from '../ui/BaseModal.vue';
import { btn, errorText, field, hint, label, readError } from '../subscriptions/shared';

/**
 * Pagar un plan: ver el monto, pagar con el QR del banco y subir el comprobante.
 * Los pasos van numerados porque son una secuencia real.
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    plan: { type: Object, default: null },
    storeUrl: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue', 'sent', 'plans-updated', 'pending-found', 'unavailable']);

// Mismas reglas que el servidor, para avisar antes de subir.
const PROOF_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const PROOF_MAX_BYTES = 5 * 1024 * 1024;
const NOTE_MAX = 255;

const file = ref(null);
const previewUrl = ref(null);
const note = ref('');
const errors = ref({});
const priceNotice = ref('');
const busy = ref(false);
const progress = ref(0);
const dragging = ref(false);
const savingQr = ref(false);
const qrSaved = ref(false);

const isPdf = computed(() => file.value?.type === 'application/pdf');
const submitLabel = computed(() => {
    if (!busy.value) return 'Enviar comprobante';
    return progress.value > 0 && progress.value < 100 ? `Enviando… ${progress.value} %` : 'Enviando…';
});

function clearPreview() {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
}

function reset() {
    clearPreview();
    file.value = null;
    note.value = '';
    errors.value = {};
    priceNotice.value = '';
    progress.value = 0;
    dragging.value = false;
    qrSaved.value = false;
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) reset();
    },
);

onBeforeUnmount(clearPreview);

function close() {
    if (!busy.value) emit('update:modelValue', false);
}

function acceptFile(candidate) {
    if (!candidate) return;

    // QA: un archivo inválido se mostraba con un ✓ verde y solo fallaba al enviar.
    if (!PROOF_TYPES.includes(candidate.type)) {
        errors.value = {
            ...errors.value,
            proof_file: 'Ese archivo no sirve. Elige una imagen (JPG, PNG o WebP) o un PDF: una captura de pantalla del comprobante funciona bien.',
        };
        return;
    }
    if (candidate.size > PROOF_MAX_BYTES) {
        errors.value = {
            ...errors.value,
            proof_file: 'El archivo pesa más de 5 MB. Envía una captura de pantalla del comprobante en lugar de una foto.',
        };
        return;
    }

    const { proof_file: _removed, ...rest } = errors.value;
    errors.value = rest;

    clearPreview();
    file.value = candidate;
    if (candidate.type.startsWith('image/')) previewUrl.value = URL.createObjectURL(candidate);
}

function onFileChange(event) {
    acceptFile(event.target.files?.[0]);
    // Se vacía el campo para poder elegir otra vez el mismo archivo después de quitarlo.
    event.target.value = '';
}

function onDrop(event) {
    dragging.value = false;
    if (busy.value) return;
    acceptFile(event.dataTransfer?.files?.[0]);
}

function removeFile() {
    clearPreview();
    file.value = null;
}

/**
 * Guarda el QR en el dispositivo. Quien paga desde el celular no puede escanear su propia
 * pantalla: la app del banco deja elegir el QR desde la galería. Se guarda como PNG porque
 * no todas las apps bancarias leen WebP.
 */
async function saveQr() {
    const url = props.plan?.qr_image_url;
    if (!url || savingQr.value) return;

    savingQr.value = true;
    try {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('qr');

        const png = await toPng(await response.blob());
        const link = document.createElement('a');
        const objectUrl = URL.createObjectURL(png);
        link.href = objectUrl;
        link.download = `QR ${props.plan.name}`.replace(/[^\p{L}\p{N} _-]/gu, '').trim() + '.png';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        qrSaved.value = true;
    } catch {
        // Si el navegador no deja descargarlo, se abre la imagen para guardarla a mano.
        window.open(url, '_blank', 'noopener');
    } finally {
        savingQr.value = false;
    }
}

async function toPng(blob) {
    if (blob.type === 'image/png') return blob;

    const bitmap = await createImageBitmap(blob);
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0);

    return new Promise((resolve, reject) => {
        canvas.toBlob((result) => (result ? resolve(result) : reject(new Error('png'))), 'image/png');
    });
}

async function submit() {
    if (!props.plan || busy.value) return;

    if (!file.value) {
        errors.value = { ...errors.value, proof_file: 'Sube la captura o el comprobante de tu pago para poder enviarlo.' };
        return;
    }

    errors.value = {};
    busy.value = true;
    progress.value = 0;

    try {
        const body = new FormData();
        body.append('subscription_plan_id', props.plan.id);
        body.append('proof_file', file.value);
        // El precio que la persona tuvo a la vista: si cambió, el servidor avisa en vez de registrar otro monto.
        body.append('expected_amount', props.plan.price);
        if (note.value.trim()) body.append('student_notes', note.value.trim());

        const { data } = await axios.post(props.storeUrl, body, {
            onUploadProgress: (event) => {
                if (event.total) progress.value = Math.round((event.loaded / event.total) * 100);
            },
        });

        emit('sent', data);
    } catch (error) {
        const data = error?.response?.data;

        if (data?.code === 'price_changed') {
            priceNotice.value = data.message;
            emit('plans-updated', data.plans);
        } else if (data?.code === 'pending_exists') {
            emit('pending-found', data);
        } else if (data?.code === 'plan_unavailable') {
            emit('unavailable', data);
        } else {
            const { message, fields } = readError(error, 'No se pudo enviar tu comprobante. Revisa tu conexión e inténtalo de nuevo.');
            errors.value = Object.keys(fields).length ? fields : { general: message };
        }
    } finally {
        busy.value = false;
    }
}

const stepNumber = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums';
</script>

<template>
    <BaseModal
        :model-value="modelValue"
        title="Pagar con QR"
        max-width="lg"
        :closeable="!busy"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <form v-if="plan" id="membership-payment-form" class="space-y-5" novalidate @submit.prevent="submit">
            <BaseAlert v-if="priceNotice" type="warning" title="El precio cambió">{{ priceNotice }}</BaseAlert>
            <BaseAlert v-if="errors.general" type="error" title="No se pudo enviar">{{ errors.general }}</BaseAlert>

            <div class="flex items-center justify-between gap-4 rounded-brand border border-border bg-background p-3 sm:p-3.5">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">{{ plan.name }}</p>
                    <p class="text-xs text-muted">{{ plan.duration_label }} de lectura completa</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-xl font-bold text-foreground tabular-nums">{{ plan.price_formatted }}</p>
                    <p v-if="plan.special" class="text-[11px] text-muted">
                        Antes <s class="tabular-nums">{{ plan.special.regular_price_formatted }}</s>
                    </p>
                </div>
            </div>

            <ol class="space-y-5">
                <li class="flex gap-3">
                    <span :class="stepNumber" aria-hidden="true">1</span>
                    <div class="min-w-0 flex-1 space-y-3">
                        <p class="text-sm font-semibold text-foreground">
                            Paga <span class="tabular-nums">{{ plan.price_formatted }}</span> desde la app de tu banco
                        </p>

                        <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
                            <div class="mx-auto sm:mx-0 flex h-44 w-44 shrink-0 items-center justify-center rounded-brand border border-border bg-white p-2">
                                <img
                                    v-if="plan.qr_image_url"
                                    :src="plan.qr_image_url"
                                    :alt="`QR de pago de ${plan.name} por ${plan.price_formatted}`"
                                    width="160"
                                    height="160"
                                    decoding="async"
                                    class="h-full w-full object-contain"
                                />
                                <div v-else class="space-y-1 p-2 text-center">
                                    <QrCode class="mx-auto h-7 w-7 text-slate-400" aria-hidden="true" />
                                    <p class="text-[11px] font-medium leading-snug text-slate-600">Este plan todavía no tiene su QR de pago.</p>
                                </div>
                            </div>

                            <div class="space-y-2.5">
                                <p class="text-xs leading-relaxed text-muted">
                                    Escanea el QR con la app de tu banco. El monto ya viene incluido.
                                </p>
                                <p class="text-xs leading-relaxed text-muted">
                                    <strong class="font-semibold text-foreground">¿Estás en el celular?</strong>
                                    Guarda el QR o haz una captura de pantalla y, en la app del banco, elige pagar con un QR de tu galería.
                                </p>
                                <button
                                    v-if="plan.qr_image_url"
                                    type="button"
                                    :class="btn.small"
                                    :disabled="savingQr"
                                    @click="saveQr"
                                >
                                    <component :is="qrSaved ? CheckCircle2 : Download" class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                                    <span>{{ savingQr ? 'Guardando…' : qrSaved ? 'QR guardado' : 'Guardar QR' }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span :class="stepNumber" aria-hidden="true">2</span>
                    <div class="min-w-0 flex-1 space-y-3">
                        <p id="proof-label" class="text-sm font-semibold text-foreground">Sube la captura de tu pago</p>

                        <div
                            v-if="!file"
                            class="rounded-brand border border-dashed px-4 py-5 text-center transition-colors"
                            :class="dragging ? 'border-primary bg-primary/10' : 'border-border bg-background'"
                            @dragover.prevent="dragging = true"
                            @dragleave.prevent="dragging = false"
                            @drop.prevent="onDrop"
                        >
                            <!-- El campo queda oculto a la vista pero no al teclado: el botón de abajo muestra su foco. -->
                            <input
                                id="proof-file"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                class="peer sr-only"
                                aria-labelledby="proof-label"
                                :aria-invalid="errors.proof_file ? 'true' : undefined"
                                :aria-describedby="errors.proof_file ? 'proof-error' : 'proof-hint'"
                                :disabled="busy"
                                @change="onFileChange"
                            />
                            <label
                                for="proof-file"
                                :class="[btn.secondary, 'cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40']"
                            >
                                <ImagePlus class="h-4 w-4 text-primary" aria-hidden="true" />
                                <span>Elegir imagen o PDF</span>
                            </label>
                            <p id="proof-hint" :class="[hint, 'mt-2']">
                                JPG, PNG, WebP o PDF de hasta 5 MB. En computadora también puedes arrastrarlo aquí.
                            </p>
                        </div>

                        <div v-else class="flex items-center gap-3 rounded-brand border border-border bg-background p-2.5">
                            <img
                                v-if="previewUrl"
                                :src="previewUrl"
                                alt="Vista previa de tu comprobante"
                                class="h-16 w-16 shrink-0 rounded-brand border border-border bg-white object-cover"
                            />
                            <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-brand border border-border bg-surface text-primary">
                                <FileText class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-foreground">{{ file.name }}</p>
                                <p :class="hint">{{ isPdf ? 'Documento PDF' : 'Imagen' }} listo para enviar</p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-brand px-2 py-2 text-xs font-medium text-rose-600 dark:text-rose-400 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 disabled:opacity-50"
                                :disabled="busy"
                                @click="removeFile"
                            >
                                Quitar<span class="sr-only"> el archivo {{ file.name }}</span>
                            </button>
                        </div>

                        <p v-if="errors.proof_file" id="proof-error" role="alert" :class="errorText">{{ errors.proof_file }}</p>

                        <div class="space-y-1.5">
                            <label for="proof-note" :class="label">Nota para quien revisa (opcional)</label>
                            <input
                                id="proof-note"
                                v-model="note"
                                type="text"
                                :maxlength="NOTE_MAX"
                                autocomplete="off"
                                placeholder="Por ejemplo: pagué desde la cuenta de mi mamá"
                                :class="field"
                                :aria-invalid="errors.student_notes ? 'true' : undefined"
                                :disabled="busy"
                            />
                            <p v-if="errors.student_notes" role="alert" :class="errorText">{{ errors.student_notes }}</p>
                        </div>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span :class="stepNumber" aria-hidden="true">3</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-foreground">Espera la revisión</p>
                        <p class="mt-1 text-xs leading-relaxed text-muted">
                            La administración revisa cada comprobante a mano. Cuando lo apruebe se abren todos los libros y esta página te avisa.
                            Si algo no coincide, aquí mismo verás el motivo para corregirlo.
                        </p>
                    </div>
                </li>
            </ol>
        </form>

        <template #footer>
            <button type="button" :class="btn.secondary" :disabled="busy" @click="close">Cancelar</button>
            <button type="submit" form="membership-payment-form" :class="btn.primary" :disabled="busy">
                {{ submitLabel }}
            </button>
        </template>
    </BaseModal>
</template>
