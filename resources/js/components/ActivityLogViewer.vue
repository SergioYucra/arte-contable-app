<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import axios from 'axios';
import {
    Search,
    X,
    RefreshCw,
    Eye,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    User,
    Calendar,
    Filter,
} from 'lucide-vue-next';
import BaseModal from './ui/BaseModal.vue';
import BaseAlert from './ui/BaseAlert.vue';

const props = defineProps({
    initialData: {
        type: Object,
        required: true,
    },
    logOptions: {
        type: Array,
        required: true,
    },
    eventOptions: {
        type: Array,
        required: true,
    },
    listUrl: {
        type: String,
        required: true,
    },
});

const items = ref(props.initialData.items ?? []);
const meta = ref(
    props.initialData.meta ?? {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: 0,
        to: 0,
    },
);

const searchQuery = ref('');
const selectedLogName = ref('all');
const selectedEvent = ref('all');
const dateFrom = ref('');
const dateTo = ref('');

const isLoading = ref(false);
const loadError = ref(null);
const selectedActivity = ref(null);
const isDetailOpen = ref(false);

let debounceTimer = null;
let activeController = null;

const hasActiveFilters = computed(() => {
    return (
        searchQuery.value.trim() !== '' ||
        selectedLogName.value !== 'all' ||
        selectedEvent.value !== 'all' ||
        dateFrom.value !== '' ||
        dateTo.value !== ''
    );
});

async function fetchLogs(page = 1) {
    if (activeController) {
        activeController.abort();
    }
    activeController = new AbortController();

    isLoading.value = true;
    loadError.value = null;

    try {
        const params = { page };
        if (searchQuery.value.trim() !== '') {
            params.q = searchQuery.value.trim();
        }
        if (selectedLogName.value !== 'all') {
            params.log_name = selectedLogName.value;
        }
        if (selectedEvent.value !== 'all') {
            params.event = selectedEvent.value;
        }
        if (dateFrom.value !== '') {
            params.date_from = dateFrom.value;
        }
        if (dateTo.value !== '') {
            params.date_to = dateTo.value;
        }

        const { data } = await axios.get(props.listUrl, {
            params,
            signal: activeController.signal,
            headers: { Accept: 'application/json' },
        });

        items.value = data.items ?? [];
        meta.value = data.meta ?? meta.value;
    } catch (error) {
        if (axios.isCancel(error) || error?.name === 'CanceledError') {
            return;
        }
        loadError.value =
            error?.response?.data?.message ||
            'No se pudo cargar la bitácora de actividad. Intenta nuevamente.';
    } finally {
        isLoading.value = false;
    }
}

watch(searchQuery, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchLogs(1);
    }, 300);
});

watch([selectedLogName, selectedEvent, dateFrom, dateTo], () => {
    fetchLogs(1);
});

onUnmounted(() => {
    clearTimeout(debounceTimer);
    if (activeController) {
        activeController.abort();
    }
});

function clearFilters() {
    clearTimeout(debounceTimer);
    searchQuery.value = '';
    selectedLogName.value = 'all';
    selectedEvent.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    fetchLogs(1);
}

function openDetail(activity) {
    selectedActivity.value = activity;
    isDetailOpen.value = true;
}

function logBadgeClass(logName) {
    switch (logName) {
        case 'security':
            return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/20';
        case 'users':
            return 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/20';
        case 'subscriptions':
        case 'subscription_plans':
        case 'payment_requests':
        case 'access_codes':
            return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/20';
        case 'books':
        case 'book_editions':
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20';
        default:
            return 'bg-primary/10 text-primary border-primary/20';
    }
}

function eventBadgeClass(event) {
    switch (event) {
        case 'created':
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/20';
        case 'updated':
            return 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/20';
        case 'deleted':
            return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/20';
        case 'restored':
            return 'bg-teal-500/10 text-teal-700 dark:text-teal-300 border-teal-500/20';
        default:
            return 'bg-background text-muted border-border';
    }
}

const diffRows = computed(() => {
    const propsObj = selectedActivity.value?.properties;
    if (!propsObj || typeof propsObj !== 'object') {
        return [];
    }

    const attrs =
        propsObj.attributes && typeof propsObj.attributes === 'object'
            ? propsObj.attributes
            : null;
    const old =
        propsObj.old && typeof propsObj.old === 'object' ? propsObj.old : null;

    if (!attrs && !old) {
        return [];
    }

    const keys = Array.from(
        new Set([
            ...(old ? Object.keys(old) : []),
            ...(attrs ? Object.keys(attrs) : []),
        ]),
    );

    return keys.map((key) => ({
        field: key,
        oldValue: old && key in old ? formatValue(old[key]) : '—',
        newValue: attrs && key in attrs ? formatValue(attrs[key]) : '—',
    }));
});

const extraProperties = computed(() => {
    const propsObj = selectedActivity.value?.properties;
    if (!propsObj || typeof propsObj !== 'object') {
        return [];
    }

    return Object.entries(propsObj)
        .filter(([key]) => key !== 'attributes' && key !== 'old')
        .map(([key, value]) => ({
            key,
            value: formatValue(value),
        }));
});

function formatValue(val) {
    if (val === null || val === undefined) {
        return '—';
    }
    if (typeof val === 'boolean') {
        return val ? 'Sí' : 'No';
    }
    if (typeof val === 'object') {
        return JSON.stringify(val);
    }
    return String(val);
}
</script>

<template>
    <div class="space-y-4">
        <!-- Barra de filtros -->
        <section
            aria-label="Filtros de bitácora"
            class="bg-surface border border-border rounded-brand p-4 sm:p-5 sheet-shadow"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12 lg:items-end">
                <!-- Búsqueda -->
                <div class="lg:col-span-4">
                    <label
                        for="activity-search"
                        class="block text-xs font-medium text-muted mb-1.5"
                    >
                        Buscar
                    </label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted"
                            aria-hidden="true"
                        />
                        <input
                            id="activity-search"
                            v-model="searchQuery"
                            type="search"
                            placeholder="Descripción, usuario, correo o ID..."
                            class="w-full rounded-lg border border-border bg-background ps-9 pe-9 py-2 text-sm text-foreground placeholder:text-muted/75 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                        <button
                            v-if="searchQuery"
                            type="button"
                            @click="searchQuery = ''"
                            class="absolute end-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                            aria-label="Limpiar búsqueda"
                        >
                            <X class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <!-- Módulo -->
                <div class="lg:col-span-3">
                    <label
                        for="activity-log-name"
                        class="block text-xs font-medium text-muted mb-1.5"
                    >
                        Módulo
                    </label>
                    <select
                        id="activity-log-name"
                        v-model="selectedLogName"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                        <option value="all">Todos los módulos</option>
                        <option
                            v-for="opt in logOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>

                <!-- Evento -->
                <div class="lg:col-span-2">
                    <label
                        for="activity-event"
                        class="block text-xs font-medium text-muted mb-1.5"
                    >
                        Evento
                    </label>
                    <select
                        id="activity-event"
                        v-model="selectedEvent"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    >
                        <option value="all">Todos</option>
                        <option
                            v-for="opt in eventOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>

                <!-- Rango de fechas -->
                <div class="sm:col-span-2 lg:col-span-3 grid grid-cols-2 gap-2">
                    <div>
                        <label
                            for="activity-date-from"
                            class="block text-xs font-medium text-muted mb-1.5"
                        >
                            Desde
                        </label>
                        <input
                            id="activity-date-from"
                            v-model="dateFrom"
                            type="date"
                            class="w-full rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-foreground tabular-nums focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>
                    <div>
                        <label
                            for="activity-date-to"
                            class="block text-xs font-medium text-muted mb-1.5"
                        >
                            Hasta
                        </label>
                        <input
                            id="activity-date-to"
                            v-model="dateTo"
                            type="date"
                            class="w-full rounded-lg border border-border bg-background px-2.5 py-2 text-sm text-foreground tabular-nums focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        />
                    </div>
                </div>
            </div>

            <!-- Barra de estado de filtros -->
            <div class="mt-3 pt-3 border-t border-border/70 flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
                <div class="flex items-center gap-2 tabular-nums">
                    <Filter class="h-3.5 w-3.5 text-muted" aria-hidden="true" />
                    <span v-if="meta.total > 0">
                        Mostrando <strong class="font-semibold text-foreground">{{ meta.from }}–{{ meta.to }}</strong> de
                        <strong class="font-semibold text-foreground">{{ meta.total }}</strong> registros
                    </span>
                    <span v-else>0 registros encontrados</span>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        @click="clearFilters"
                        class="inline-flex items-center gap-1 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-surface transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                    >
                        <X class="h-3.5 w-3.5" aria-hidden="true" />
                        Limpiar filtros
                    </button>

                    <button
                        type="button"
                        @click="fetchLogs(meta.current_page)"
                        :disabled="isLoading"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-surface disabled:opacity-60 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                    >
                        <RefreshCw
                            class="h-3.5 w-3.5"
                            :class="{ 'animate-spin': isLoading }"
                            aria-hidden="true"
                        />
                        Actualizar
                    </button>
                </div>
            </div>
        </section>

        <!-- Error -->
        <BaseAlert
            v-if="loadError"
            type="error"
            title="Error al consultar la bitácora"
            :message="loadError"
        >
            <template #actions>
                <button
                    type="button"
                    @click="fetchLogs(meta.current_page)"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500/40"
                >
                    Reintentar
                </button>
            </template>
        </BaseAlert>

        <!-- Contenido principal -->
        <section
            aria-label="Registros de actividad"
            class="bg-surface border border-border rounded-brand overflow-hidden sheet-shadow"
        >
            <!-- Estado vacío -->
            <div
                v-if="!isLoading && items.length === 0"
                class="px-6 py-14 text-center"
            >
                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-background border border-border text-muted">
                    <ClipboardList class="h-5 w-5" aria-hidden="true" />
                </div>
                <h2 class="mt-3 text-sm font-semibold text-foreground">
                    Sin registros de actividad
                </h2>
                <p class="mt-1 text-xs text-muted max-w-sm mx-auto">
                    {{
                        hasActiveFilters
                            ? 'Ningún evento coincide con los filtros aplicados.'
                            : 'Aún no existen eventos registrados en la bitácora del sistema.'
                    }}
                </p>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="clearFilters"
                    class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-medium text-primary-foreground hover:bg-primary-hover transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                >
                    Limpiar filtros
                </button>
            </div>

            <!-- Tabla Desktop -->
            <div v-else class="hidden md:block overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr class="border-b border-border bg-background/60 text-[11px] font-semibold uppercase tracking-wider text-muted">
                            <th scope="col" class="px-4 py-3 whitespace-nowrap">Fecha y hora</th>
                            <th scope="col" class="px-4 py-3 whitespace-nowrap">Módulo</th>
                            <th scope="col" class="px-4 py-3">Descripción</th>
                            <th scope="col" class="px-4 py-3 whitespace-nowrap">Responsable</th>
                            <th scope="col" class="px-4 py-3 text-right whitespace-nowrap">Detalle</th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-border/80 transition-opacity duration-150"
                        :class="{ 'opacity-60': isLoading }"
                    >
                        <tr
                            v-for="item in items"
                            :key="item.id"
                            @click="openDetail(item)"
                            class="group cursor-pointer hover:bg-background/60 transition-colors"
                        >
                            <!-- Fecha -->
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <div class="text-xs font-medium text-foreground tabular-nums">
                                    {{ item.created_at_human }}
                                </div>
                                <div class="text-[11px] text-muted mt-0.5">
                                    {{ item.created_at_relative }}
                                </div>
                            </td>

                            <!-- Módulo + Evento -->
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <div class="flex flex-col items-start gap-1">
                                    <span
                                        class="inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-medium"
                                        :class="logBadgeClass(item.log_name)"
                                    >
                                        {{ item.log_label }}
                                    </span>
                                    <span
                                        class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="eventBadgeClass(item.event)"
                                    >
                                        {{ item.event_label }}
                                    </span>
                                </div>
                            </td>

                            <!-- Descripción + Sujeto -->
                            <td class="px-4 py-3.5 align-top max-w-md">
                                <p class="text-sm font-medium text-foreground leading-snug">
                                    {{ item.description }}
                                </p>
                                <p
                                    v-if="item.subject_type_label"
                                    class="mt-1 text-xs text-muted tabular-nums"
                                >
                                    {{ item.subject_type_label }}
                                    <span v-if="item.subject_id" class="font-mono text-[11px]">
                                        #{{ item.subject_id }}
                                    </span>
                                </p>
                            </td>

                            <!-- Responsable -->
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <div v-if="item.causer" class="min-w-0">
                                    <div class="text-xs font-medium text-foreground flex items-center gap-1.5">
                                        <span class="truncate max-w-[180px]">{{ item.causer.name }}</span>
                                        <span
                                            v-if="item.causer.deleted"
                                            class="rounded bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-medium text-rose-700 dark:text-rose-300"
                                        >
                                            Inactivo
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-muted truncate max-w-[200px]">
                                        {{ item.causer.email }}
                                    </div>
                                </div>
                                <span v-else class="text-xs italic text-muted">
                                    Sistema
                                </span>
                            </td>

                            <!-- Acción -->
                            <td class="px-4 py-3.5 align-top text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    @click.stop="openDetail(item)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground group-hover:border-primary/40 group-hover:text-primary transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                                >
                                    <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                                    Ver
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Lista Móvil -->
            <div
                v-if="items.length > 0"
                class="md:hidden divide-y divide-border"
                :class="{ 'opacity-60': isLoading }"
            >
                <article
                    v-for="item in items"
                    :key="`m-${item.id}`"
                    @click="openDetail(item)"
                    class="p-4 space-y-2.5 active:bg-background/70 transition-colors cursor-pointer"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-medium"
                                :class="logBadgeClass(item.log_name)"
                            >
                                {{ item.log_label }}
                            </span>
                            <span
                                class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium"
                                :class="eventBadgeClass(item.event)"
                            >
                                {{ item.event_label }}
                            </span>
                        </div>
                        <span class="text-[11px] text-muted tabular-nums shrink-0">
                            {{ item.created_at_human }}
                        </span>
                    </div>

                    <p class="text-sm font-medium text-foreground leading-snug">
                        {{ item.description }}
                    </p>

                    <div class="flex items-center justify-between gap-2 text-xs text-muted pt-1">
                        <span class="truncate">
                            <template v-if="item.causer">
                                {{ item.causer.name }}
                            </template>
                            <template v-else>
                                Sistema
                            </template>
                        </span>
                        <span class="inline-flex items-center gap-1 text-primary font-medium shrink-0">
                            <Eye class="h-3.5 w-3.5" aria-hidden="true" />
                            Detalle
                        </span>
                    </div>
                </article>
            </div>

            <!-- Paginación -->
            <div
                v-if="meta.last_page > 1"
                class="border-t border-border bg-background/50 px-4 py-3 flex items-center justify-between gap-3"
            >
                <p class="text-xs text-muted tabular-nums">
                    Página <strong class="font-semibold text-foreground">{{ meta.current_page }}</strong> de
                    <strong class="font-semibold text-foreground">{{ meta.last_page }}</strong>
                </p>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="meta.current_page <= 1 || isLoading"
                        @click="fetchLogs(meta.current_page - 1)"
                        class="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-foreground hover:bg-background disabled:opacity-45 disabled:pointer-events-none transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                    >
                        <ChevronLeft class="h-3.5 w-3.5" aria-hidden="true" />
                        Anterior
                    </button>
                    <button
                        type="button"
                        :disabled="meta.current_page >= meta.last_page || isLoading"
                        @click="fetchLogs(meta.current_page + 1)"
                        class="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-foreground hover:bg-background disabled:opacity-45 disabled:pointer-events-none transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                    >
                        Siguiente
                        <ChevronRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </section>

        <!-- Modal de detalle -->
        <BaseModal
            v-model="isDetailOpen"
            :title="selectedActivity ? `Registro #${selectedActivity.id}` : 'Detalle de actividad'"
            max-width="2xl"
        >
            <div v-if="selectedActivity" class="space-y-5">
                <!-- Resumen principal -->
                <div class="rounded-lg border border-border bg-background/60 p-4 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium"
                            :class="logBadgeClass(selectedActivity.log_name)"
                        >
                            {{ selectedActivity.log_label }}
                        </span>
                        <span
                            class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium"
                            :class="eventBadgeClass(selectedActivity.event)"
                        >
                            {{ selectedActivity.event_label }}
                        </span>
                    </div>
                    <p class="text-sm font-semibold text-foreground">
                        {{ selectedActivity.description }}
                    </p>
                </div>

                <!-- Metadatos -->
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="rounded-lg border border-border p-3">
                        <dt class="text-muted flex items-center gap-1.5">
                            <Calendar class="h-3.5 w-3.5" aria-hidden="true" />
                            Fecha y hora
                        </dt>
                        <dd class="mt-1 font-medium text-foreground tabular-nums">
                            {{ selectedActivity.created_at_human }}
                            <span class="block text-[11px] font-normal text-muted">
                                {{ selectedActivity.created_at_relative }}
                            </span>
                        </dd>
                    </div>

                    <div class="rounded-lg border border-border p-3">
                        <dt class="text-muted flex items-center gap-1.5">
                            <User class="h-3.5 w-3.5" aria-hidden="true" />
                            Responsable
                        </dt>
                        <dd class="mt-1 font-medium text-foreground">
                            <template v-if="selectedActivity.causer">
                                {{ selectedActivity.causer.name }}
                                <span class="block text-[11px] font-normal text-muted">
                                    {{ selectedActivity.causer.email }}
                                </span>
                            </template>
                            <span v-else class="italic text-muted font-normal">Sistema</span>
                        </dd>
                    </div>

                    <div v-if="selectedActivity.subject_type_label" class="sm:col-span-2 rounded-lg border border-border p-3">
                        <dt class="text-muted">Objeto afectado</dt>
                        <dd class="mt-1 font-medium text-foreground tabular-nums">
                            {{ selectedActivity.subject_type_label }}
                            <span v-if="selectedActivity.subject_id" class="font-mono text-xs text-muted">
                                #{{ selectedActivity.subject_id }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <!-- Cambios (old vs attributes) -->
                <div v-if="diffRows.length > 0" class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-muted">
                        Cambios registrados
                    </h3>
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full border-collapse text-left text-xs">
                            <thead>
                                <tr class="border-b border-border bg-background/60 text-muted">
                                    <th scope="col" class="px-3 py-2 font-medium">Campo</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Anterior</th>
                                    <th scope="col" class="px-3 py-2 font-medium">Nuevo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="row in diffRows" :key="row.field">
                                    <td class="px-3 py-2 font-mono text-[11px] font-medium text-foreground whitespace-nowrap">
                                        {{ row.field }}
                                    </td>
                                    <td class="px-3 py-2 text-muted break-all">
                                        {{ row.oldValue }}
                                    </td>
                                    <td class="px-3 py-2 text-foreground font-medium break-all">
                                        {{ row.newValue }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Otras propiedades -->
                <div v-if="extraProperties.length > 0" class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-muted">
                        Datos adicionales
                    </h3>
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full border-collapse text-left text-xs">
                            <tbody class="divide-y divide-border/70">
                                <tr v-for="prop in extraProperties" :key="prop.key">
                                    <th scope="row" class="px-3 py-2 font-mono text-[11px] font-medium text-muted w-1/3 whitespace-nowrap">
                                        {{ prop.key }}
                                    </th>
                                    <td class="px-3 py-2 text-foreground break-all">
                                        {{ prop.value }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sin propiedades -->
                <p
                    v-if="!selectedActivity.has_properties"
                    class="text-xs italic text-muted text-center py-2"
                >
                    Este registro no contiene propiedades adicionales.
                </p>

                <!-- JSON técnico colapsable -->
                <details v-if="selectedActivity.has_properties" class="rounded-lg border border-border bg-background/40">
                    <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-muted hover:text-foreground select-none">
                        Ver JSON completo
                    </summary>
                    <pre class="overflow-x-auto border-t border-border p-3 font-mono text-[11px] text-foreground leading-relaxed">{{ JSON.stringify(selectedActivity.properties, null, 2) }}</pre>
                </details>
            </div>

            <template #footer>
                <button
                    type="button"
                    @click="isDetailOpen = false"
                    class="rounded-lg border border-border bg-surface px-4 py-2 text-xs font-medium text-foreground hover:bg-background transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                >
                    Cerrar
                </button>
            </template>
        </BaseModal>
    </div>
</template>
