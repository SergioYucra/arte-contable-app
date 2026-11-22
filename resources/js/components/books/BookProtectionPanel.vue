<script setup>
/**
 * Panel "Protección de libros" del administrador (/admin/libros/proteccion).
 *
 * Primero lo que pide una decisión (lecturas pausadas), después la búsqueda del código de una
 * página filtrada, los avisos de cuenta compartida y quién está leyendo hoy.
 * Usa el kit plano del panel de membresías (shared.js, BaseModal, BaseAlert, listas).
 */
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import {
    BookOpen,
    ChevronDown,
    Clock,
    Fingerprint,
    Hourglass,
    MonitorSmartphone,
    RefreshCw,
    ScanSearch,
    ShieldCheck,
    UserX,
    Users,
} from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseToast from '../ui/BaseToast.vue';
import { btn, errorText, field, hint, label, plural, readError } from '../subscriptions/shared';

const props = defineProps({
    initialData: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const data = ref(props.initialData);
const refreshing = ref(false);
const notification = ref(null);
const releasingId = ref(null);
const showPastHolds = ref(false);

const settings = computed(() => data.value.settings || {});
const velocityText = computed(() =>
    (settings.value.velocity_rules || [])
        .map((rule) => `${rule.pages} páginas en ${rule.minutes} minutos`)
        .join(' o ')
);

// readError ya traduce 419 y 429; aquí se suma la sesión cerrada (401), que llega en inglés.
function panelError(error, fallback) {
    if (error?.response?.status === 401) {
        return 'Tu sesión terminó. Recarga la página para volver a entrar.';
    }
    return readError(error, fallback).message;
}

function notify(type, title, message) {
    notification.value = { type, title, message };
    setTimeout(() => {
        if (notification.value?.title === title) notification.value = null;
    }, 5000);
}

async function refresh() {
    refreshing.value = true;
    try {
        const { data: fresh } = await axios.get(props.urls.data);
        data.value = fresh;
    } catch (error) {
        notify('error', 'No se pudo actualizar', panelError(error));
    } finally {
        refreshing.value = false;
    }
}

async function release(hold) {
    releasingId.value = hold.id;
    try {
        const { data: response } = await axios.post(hold.release_url);
        data.value = response.data;
        // Si la misma persona está abierta en "¿Quién filtró esta página?", se actualiza su ficha.
        if (lookupResult.value?.student?.id === hold.student.id) {
            lookupResult.value.active_hold = null;
        }
        notify('success', 'Lectura reactivada', response.message);
    } catch (error) {
        if (error?.response?.data?.data) data.value = error.response.data.data;
        notify('error', 'No se pudo reactivar', panelError(error));
    } finally {
        releasingId.value = null;
    }
}

// ---------------------------------------------------------------- Quitar acceso
const revokeTarget = ref(null);
const revokeOpen = ref(false);
const revokeReason = ref('');
const revokeError = ref('');
const revokeBusy = ref(false);
const revokeField = ref(null);

async function askRevoke(student, revokeUrl, suggestedReason) {
    revokeTarget.value = { ...student, revoke_url: revokeUrl };
    revokeReason.value = suggestedReason || '';
    revokeError.value = '';
    revokeOpen.value = true;
    await nextTick();
    revokeField.value?.focus();
}

async function confirmRevoke() {
    if (revokeReason.value.trim().length < 3) {
        revokeError.value = 'Escribe por qué se quita el acceso (al menos 3 letras). Queda guardado en su historial.';
        revokeField.value?.focus();
        return;
    }

    revokeBusy.value = true;
    revokeError.value = '';
    try {
        const { data: response } = await axios.post(revokeTarget.value.revoke_url, { reason: revokeReason.value.trim() });
        revokeOpen.value = false;
        notify('success', 'Acceso retirado', response.message);
        await refresh();
        if (lookupResult.value?.student?.id === revokeTarget.value.id) {
            lookupResult.value.student.has_membership = false;
        }
    } catch (error) {
        revokeError.value = panelError(error);
    } finally {
        revokeBusy.value = false;
    }
}

// ---------------------------------------------------------------- ¿Quién filtró esta página?
const code = ref('');
const lookupBusy = ref(false);
const lookupError = ref('');
const lookupResult = ref(null);

async function lookup() {
    lookupError.value = '';
    lookupResult.value = null;

    if (code.value.replace(/[^a-z0-9]/gi, '').length !== 8) {
        lookupError.value = 'El código tiene 8 letras y números, por ejemplo K7QM-3XPA. Está arriba, al centro y al pie de cada página.';
        return;
    }

    lookupBusy.value = true;
    try {
        const { data: result } = await axios.get(props.urls.lookup, { params: { code: code.value } });
        lookupResult.value = result;
    } catch (error) {
        lookupError.value = panelError(error, 'No se pudo buscar el código. Inténtalo de nuevo.');
    } finally {
        lookupBusy.value = false;
    }
}

function initial(name) {
    return (name || '?').trim().charAt(0).toUpperCase();
}
</script>

<template>
    <div class="space-y-6">
        <!-- Cómo se cuidan los libros + números del día -->
        <section class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4" aria-labelledby="rules-title">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div class="flex items-start gap-3">
                    <div class="h-10 w-10 shrink-0 rounded-brand bg-primary/10 text-primary flex items-center justify-center">
                        <ShieldCheck class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h2 id="rules-title" class="text-sm sm:text-base font-semibold text-foreground">Cómo se cuidan los libros</h2>
                        <p :class="hint">Funciona solo. Aquí ves lo que necesita tu decisión y puedes rastrear una página filtrada.</p>
                    </div>
                </div>
                <button type="button" :class="[btn.small, 'self-start']" :disabled="refreshing" @click="refresh">
                    <RefreshCw class="h-3.5 w-3.5 text-primary" :class="{ 'animate-spin': refreshing }" aria-hidden="true" />
                    <span>{{ refreshing ? 'Actualizando…' : 'Actualizar' }}</span>
                </button>
            </div>

            <ul class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2 text-xs text-foreground">
                <li class="flex items-start gap-2 rounded-brand border border-border bg-background px-3 py-2.5">
                    <BookOpen class="h-4 w-4 shrink-0 text-primary mt-0.5" aria-hidden="true" />
                    <span>Cada persona lee hasta <strong>{{ settings.daily_page_limit }} páginas nuevas por día</strong>. Volver a una página ya vista no cuenta.</span>
                </li>
                <li class="flex items-start gap-2 rounded-brand border border-border bg-background px-3 py-2.5">
                    <MonitorSmartphone class="h-4 w-4 shrink-0 text-primary mt-0.5" aria-hidden="true" />
                    <span>Cada cuenta lee en <strong>un dispositivo a la vez</strong>.</span>
                </li>
                <li class="flex items-start gap-2 rounded-brand border border-border bg-background px-3 py-2.5">
                    <Fingerprint class="h-4 w-4 shrink-0 text-primary mt-0.5" aria-hidden="true" />
                    <span>Cada página lleva <strong>el nombre y un código</strong> de quien la lee.</span>
                </li>
                <li class="flex items-start gap-2 rounded-brand border border-border bg-background px-3 py-2.5">
                    <Hourglass class="h-4 w-4 shrink-0 text-primary mt-0.5" aria-hidden="true" />
                    <span>Si alguien pasa {{ velocityText }}, su lectura <strong>se pausa {{ settings.pause_hours }} horas</strong> y te llega un correo.</span>
                </li>
            </ul>

            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-brand border border-border bg-background px-3 py-2.5">
                    <dt class="text-[11px] text-muted">Páginas leídas hoy</dt>
                    <dd class="text-lg font-semibold text-foreground tabular-nums">{{ data.stats.pages_today }}</dd>
                </div>
                <div class="rounded-brand border border-border bg-background px-3 py-2.5">
                    <dt class="text-[11px] text-muted">Personas leyendo hoy</dt>
                    <dd class="text-lg font-semibold text-foreground tabular-nums">{{ data.stats.readers_today }}</dd>
                </div>
                <div
                    class="rounded-brand border px-3 py-2.5"
                    :class="data.stats.active_holds > 0 ? 'border-amber-500/30 bg-amber-500/10' : 'border-border bg-background'"
                >
                    <dt class="text-[11px] text-muted">Lecturas pausadas</dt>
                    <dd
                        class="text-lg font-semibold tabular-nums"
                        :class="data.stats.active_holds > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'"
                    >
                        {{ data.stats.active_holds }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- 1. Lecturas pausadas: lo que pide una decisión -->
        <section class="space-y-3" aria-labelledby="holds-title">
            <h2 id="holds-title" class="text-sm font-semibold text-foreground flex items-center gap-2">
                <Hourglass class="h-4 w-4 text-amber-500" aria-hidden="true" />
                Lecturas pausadas
            </h2>

            <ul v-if="data.active_holds.length" class="divide-y divide-border rounded-brand border border-amber-500/30 bg-surface overflow-hidden">
                <li v-for="hold in data.active_holds" :key="hold.id" class="p-3 sm:p-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="flex items-start gap-3 min-w-0 flex-1">
                        <div class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center font-semibold text-xs border bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20">
                            {{ initial(hold.student.name) }}
                        </div>
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-semibold text-foreground truncate">
                                {{ hold.student.name }} <span class="font-normal text-muted">· {{ hold.student.email }}</span>
                            </p>
                            <p class="text-xs text-foreground leading-relaxed">{{ hold.reason_text }}</p>
                            <p class="text-[11px] text-muted flex items-center gap-1">
                                <Clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                Pausada {{ hold.started_relative }} · se reanuda sola {{ hold.ends }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            v-if="hold.revoke_url"
                            type="button"
                            :class="btn.dangerQuiet"
                            @click="askRevoke(hold.student, hold.revoke_url, 'Copiado de libros: ' + hold.reason_text)"
                        >
                            <UserX class="h-3.5 w-3.5" aria-hidden="true" />
                            Quitar acceso…
                        </button>
                        <button type="button" :class="btn.primary" :disabled="releasingId === hold.id" @click="release(hold)">
                            {{ releasingId === hold.id ? 'Reactivando…' : 'Reactivar lectura' }}
                        </button>
                    </div>
                </li>
            </ul>

            <div v-else class="bg-surface border border-border rounded-brand px-5 py-6 text-center space-y-1">
                <p class="text-sm font-semibold text-foreground">Ninguna lectura está en pausa</p>
                <p class="text-xs text-muted max-w-md mx-auto">
                    Si alguien lee más rápido de lo que una persona puede, o pide páginas que el lector nunca pide, su lectura se pausa, aparece aquí y te llega un correo.
                </p>
            </div>

            <div v-if="data.past_holds.length">
                <button
                    type="button"
                    :class="btn.quiet"
                    :aria-expanded="showPastHolds"
                    aria-controls="past-holds"
                    @click="showPastHolds = !showPastHolds"
                >
                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-180': showPastHolds }" aria-hidden="true" />
                    {{ showPastHolds ? 'Ocultar' : 'Ver' }} pausas terminadas en los últimos 14 días ({{ data.past_holds.length }})
                </button>
                <ul v-if="showPastHolds" id="past-holds" class="mt-2 divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
                    <li v-for="hold in data.past_holds" :key="hold.id" class="p-3 text-xs space-y-0.5">
                        <p class="text-foreground"><strong>{{ hold.student.name }}</strong> · {{ hold.started }}</p>
                        <p class="text-muted">{{ hold.reason_text }}</p>
                        <p class="text-muted">
                            <template v-if="hold.released_by">Reactivada por {{ hold.released_by }} el {{ hold.released }}.</template>
                            <template v-else>Terminó sola.</template>
                        </p>
                    </li>
                </ul>
            </div>
        </section>

        <!-- 2. ¿Quién filtró esta página? -->
        <section class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4" aria-labelledby="lookup-title">
            <div>
                <h2 id="lookup-title" class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <ScanSearch class="h-4 w-4 text-primary" aria-hidden="true" />
                    ¿Quién filtró esta página?
                </h2>
                <p :class="[hint, 'mt-1']">
                    Si te llega una foto o captura de un libro, busca el código impreso arriba, al centro o al pie de la página
                    (por ejemplo <span class="font-mono font-semibold text-foreground">K7QM-3XPA</span>) y escríbelo aquí.
                </p>
            </div>

            <form class="flex flex-col sm:flex-row gap-2 sm:items-start" @submit.prevent="lookup">
                <div class="flex-1 space-y-1">
                    <label for="leak-code" :class="label">Código de la página</label>
                    <input
                        id="leak-code"
                        v-model="code"
                        type="text"
                        maxlength="20"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="K7QM-3XPA"
                        :class="[field, 'font-mono uppercase tracking-wider']"
                        :aria-invalid="Boolean(lookupError)"
                        aria-describedby="leak-code-error"
                    />
                    <p v-if="lookupError" id="leak-code-error" role="alert" :class="errorText">{{ lookupError }}</p>
                </div>
                <button type="submit" :class="[btn.primary, 'sm:mt-5']" :disabled="lookupBusy">
                    {{ lookupBusy ? 'Buscando…' : 'Buscar quién la leyó' }}
                </button>
            </form>

            <div v-if="lookupResult" class="rounded-brand border border-border bg-background p-4 space-y-4" aria-live="polite">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="h-10 w-10 shrink-0 rounded-full flex items-center justify-center font-semibold text-sm border bg-primary/10 text-primary border-primary/20">
                            {{ initial(lookupResult.student.name) }}
                        </div>
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-semibold text-foreground">
                                El código <span class="font-mono">{{ lookupResult.code }}</span> es de {{ lookupResult.student.name }}
                            </p>
                            <p class="text-xs text-muted truncate">{{ lookupResult.student.email }} · lee desde el {{ lookupResult.student.reader_since }}</p>
                            <div class="flex flex-wrap gap-1.5 pt-0.5">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                                    :class="lookupResult.student.has_membership ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20' : 'bg-background text-muted border-border'"
                                >
                                    {{ lookupResult.student.has_membership ? 'Con membresía' : 'Sin membresía' }}
                                </span>
                                <span v-if="lookupResult.active_hold" class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20">
                                    Lectura en pausa
                                </span>
                                <span v-if="lookupResult.student.is_deactivated" class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20">
                                    Cuenta desactivada
                                </span>
                            </div>
                        </div>
                    </div>
                    <button
                        v-if="lookupResult.revoke_url && lookupResult.student.has_membership"
                        type="button"
                        :class="[btn.dangerQuiet, 'self-start']"
                        @click="askRevoke(lookupResult.student, lookupResult.revoke_url, `Filtró páginas de un libro (código ${lookupResult.code}).`)"
                    >
                        <UserX class="h-3.5 w-3.5" aria-hidden="true" />
                        Quitar acceso…
                    </button>
                </div>

                <div>
                    <h3 class="text-xs font-semibold text-foreground mb-2">Lo que leyó en los últimos {{ lookupResult.lookup_days }} días</h3>
                    <ol v-if="lookupResult.days.length" class="space-y-2">
                        <li v-for="day in lookupResult.days" :key="day.date" class="rounded-brand border border-border bg-surface p-3 text-xs space-y-1">
                            <p class="text-foreground font-semibold">{{ day.date }} · {{ plural(day.pages_count, 'página', 'páginas') }}</p>
                            <p v-for="book in day.books" :key="book.title + book.edition" class="text-foreground">
                                {{ book.title }} <span class="text-muted">({{ book.edition }})</span>: páginas {{ book.pages }}
                            </p>
                            <p v-if="day.ips.length" class="text-muted">Desde {{ plural(day.ips.length, 'conexión', 'conexiones') }}: {{ day.ips.join(', ') }}</p>
                        </li>
                    </ol>
                    <p v-else class="text-xs text-muted">No leyó páginas en estos días (el registro guarda los últimos meses).</p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <!-- 3. Posibles cuentas compartidas -->
            <section class="space-y-3" aria-labelledby="sharing-title">
                <h2 id="sharing-title" class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <MonitorSmartphone class="h-4 w-4 text-primary" aria-hidden="true" />
                    Posibles cuentas compartidas
                </h2>
                <ul v-if="data.sharing_alerts.length" class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
                    <li v-for="alert in data.sharing_alerts" :key="alert.id" class="p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-semibold text-foreground truncate">{{ alert.student.name }}</p>
                            <p class="text-xs text-foreground">
                                {{ plural(alert.device_switches, 'cambio', 'cambios') }} de dispositivo y {{ plural(alert.distinct_ips, 'conexión distinta', 'conexiones distintas') }} en {{ alert.window_hours }} horas.
                            </p>
                            <p class="text-[11px] text-muted">{{ alert.when }}</p>
                        </div>
                        <button
                            v-if="alert.revoke_url"
                            type="button"
                            :class="[btn.dangerQuiet, 'shrink-0']"
                            @click="askRevoke(alert.student, alert.revoke_url, 'Cuenta compartida con otras personas.')"
                        >
                            <UserX class="h-3.5 w-3.5" aria-hidden="true" />
                            Quitar acceso…
                        </button>
                    </li>
                </ul>
                <BaseAlert v-else type="info">
                    Sin avisos en los últimos 14 días. Como cada cuenta lee en un solo dispositivo a la vez, quien comparte su cuenta se va turnando; si salta muchas veces entre dispositivos, aparece aquí.
                </BaseAlert>
            </section>

            <!-- 4. Lectores de hoy -->
            <section class="space-y-3" aria-labelledby="readers-title">
                <h2 id="readers-title" class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <Users class="h-4 w-4 text-primary" aria-hidden="true" />
                    Quién leyó más hoy
                </h2>
                <ul v-if="data.readers_today.length" class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
                    <li v-for="reader in data.readers_today" :key="reader.user_id" class="p-3 space-y-1.5">
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="font-semibold text-foreground truncate">{{ reader.name }}</span>
                            <span class="text-muted shrink-0 tabular-nums">
                                {{ reader.pages }} de {{ settings.daily_page_limit }} páginas · {{ reader.last_seen }}
                            </span>
                        </div>
                        <div
                            class="h-1.5 rounded-full bg-background border border-border overflow-hidden"
                            role="progressbar"
                            :aria-valuenow="reader.pages"
                            aria-valuemin="0"
                            :aria-valuemax="settings.daily_page_limit"
                            :aria-label="`${reader.name}: ${reader.pages} de ${settings.daily_page_limit} páginas hoy`"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="reader.percent_of_limit >= 80 ? 'bg-amber-500' : 'bg-primary'"
                                :style="{ width: `${reader.percent_of_limit}%` }"
                            />
                        </div>
                    </li>
                </ul>
                <BaseAlert v-else type="info">Nadie leyó todavía hoy.</BaseAlert>
            </section>
        </div>

        <!-- Quitar acceso (reutiliza la acción de Membresías: queda en el historial de la persona) -->
        <BaseModal v-model="revokeOpen" :title="revokeTarget ? `Quitar acceso a ${revokeTarget.name}` : 'Quitar acceso'" max-width="md">
            <div class="space-y-3">
                <p class="text-xs text-muted leading-relaxed">
                    Se cierra de inmediato el acceso a las páginas completas de todos los libros. Sus apuntes y su avance se conservan, y queda constancia en su historial de membresía.
                </p>
                <label for="protection-revoke-reason" :class="label">Motivo *</label>
                <textarea
                    id="protection-revoke-reason"
                    ref="revokeField"
                    v-model="revokeReason"
                    rows="3"
                    maxlength="500"
                    :class="field"
                    :aria-invalid="Boolean(revokeError)"
                    aria-describedby="protection-revoke-error"
                ></textarea>
                <p v-if="revokeError" id="protection-revoke-error" role="alert" :class="errorText">{{ revokeError }}</p>
            </div>
            <template #footer="{ close }">
                <button type="button" :class="[btn.secondary, 'mr-auto']" :disabled="revokeBusy" @click="close">Cancelar</button>
                <button type="button" :class="btn.danger" :disabled="revokeBusy" @click="confirmRevoke">
                    {{ revokeBusy ? 'Quitando…' : 'Quitar acceso' }}
                </button>
            </template>
        </BaseModal>

        <BaseToast v-model="notification" />
    </div>
</template>
