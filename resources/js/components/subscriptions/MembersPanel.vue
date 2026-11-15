<script setup>
/**
 * Personas con acceso: quién tiene membresía, cuándo vence, y entrada a su historial.
 */
import { ref, watch, inject, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { Search, Users, X, Clock, History } from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';
import MemberHistoryModal from './MemberHistoryModal.vue';
import { PANEL_CONTEXT, btn, readError, plural } from './shared';

const filter = defineModel('filter', { type: String, default: 'active' });

const { urls, summary, notify, updateSummary } = inject(PANEL_CONTEXT);

const FILTERS = [
    { id: 'active', label: 'Con acceso' },
    { id: 'expiring', label: 'Vencen pronto' },
    { id: 'expired', label: 'Vencidos' },
    { id: 'revoked', label: 'Acceso retirado' },
    { id: 'all', label: 'Todos' },
];

const EMPTY = {
    active: 'Todavía nadie tiene acceso activo. Aparecerán aquí cuando apruebes un pago o se canjee un código.',
    expiring: 'Ningún acceso vence en los próximos 15 días.',
    expired: 'No hay membresías vencidas registradas.',
    revoked: 'No se ha retirado el acceso a ninguna cuenta.',
    all: 'Todavía no hay registros de membresía.',
};

const search = ref('');
const items = ref([]);
const total = ref(0);
const nextPage = ref(null);
const loading = ref(false);
const loadError = ref('');
const selected = ref(null);
const historyOpen = ref(false);

let debounce = null;
let requestId = 0;
let activeController = null;

async function load({ append = false } = {}) {
    if (!append && activeController) {
        activeController.abort();
    }
    const controller = new AbortController();
    activeController = controller;

    const current = ++requestId;
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(urls.members, {
            params: { status: filter.value, q: search.value.trim() || undefined, page: append ? nextPage.value : 1 },
            signal: controller.signal,
        });
        if (current !== requestId) return;
        items.value = append ? [...items.value, ...data.items] : data.items;
        total.value = data.total;
        nextPage.value = data.next_page;
    } catch (error) {
        if (error?.name === 'AbortError' || error?.code === 'ERR_CANCELED') return;
        if (current === requestId) loadError.value = readError(error).message;
    } finally {
        if (current === requestId) loading.value = false;
    }
}

watch(filter, () => load());
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => load(), 300);
});

onMounted(() => load());
onBeforeUnmount(() => {
    clearTimeout(debounce);
    activeController?.abort();
});

function openHistory(member) {
    selected.value = member;
    historyOpen.value = true;
}

function onRevoked({ member, message }) {
    const index = items.value.findIndex((m) => m.id === member.id);
    if (index !== -1) {
        if (['active', 'expiring'].includes(filter.value)) {
            items.value.splice(index, 1);
            total.value -= 1;
        } else {
            items.value[index] = member;
        }
    }
    selected.value = member;
    updateSummary({ active_members: Math.max(0, (summary.value.active_members ?? 0) - 1) });
    notify('success', 'Acceso retirado', message);
}

const statusStyle = {
    active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    expired: 'bg-background text-muted border-border',
    revoked: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
};
</script>

<template>
    <div class="space-y-4">
        <!-- Barra de filtros y búsqueda alineada a /admin/libros -->
        <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 sm:gap-4">
            <div class="-mx-3 px-3 sm:mx-0 sm:px-0 flex items-center gap-1.5 overflow-x-auto no-scrollbar snap-x" role="group" aria-label="Filtrar personas">
                <button
                    v-for="option in FILTERS"
                    :key="option.id"
                    type="button"
                    class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 rounded-brand text-xs font-medium whitespace-nowrap transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                    :class="
                        filter === option.id
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'bg-background text-muted hover:text-foreground border border-border'
                    "
                    :aria-pressed="filter === option.id"
                    @click="filter = option.id"
                >
                    <span>{{ option.label }}</span>
                    <span
                        v-if="filter === option.id && !loading"
                        class="px-1.5 py-0.5 rounded-full text-[11px] font-semibold bg-primary-foreground/20 text-primary-foreground tabular-nums"
                    >
                        {{ total }}
                    </span>
                </button>
            </div>

            <div class="relative w-full lg:w-72">
                <label for="members-search" class="sr-only">Buscar por nombre o correo</label>
                <Search class="h-4 w-4 text-muted absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
                <input
                    id="members-search"
                    v-model="search"
                    type="search"
                    inputmode="search"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Buscar nombre o correo..."
                    class="w-full pl-9 pr-9 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                />
                <button
                    v-if="search"
                    type="button"
                    class="absolute right-1 top-1/2 -translate-y-1/2 p-2 text-muted hover:text-foreground"
                    aria-label="Limpiar búsqueda"
                    @click="search = ''"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <!-- Contador de resultados -->
        <div class="flex items-center justify-between text-xs text-muted px-1" aria-live="polite">
            <span class="flex items-center gap-1.5">
                <Users class="h-3.5 w-3.5" aria-hidden="true" />
                <template v-if="loading && !items.length">Cargando listado...</template>
                <template v-else-if="!loadError">
                    Mostrando <strong class="text-foreground tabular-nums">{{ items.length }}</strong> de {{ plural(total, 'persona', 'personas') }}
                </template>
            </span>
        </div>

        <BaseAlert v-if="loadError" type="error" title="No se pudo cargar el listado">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>{{ loadError }}</span>
                <button type="button" :class="btn.small" @click="load()">Reintentar</button>
            </div>
        </BaseAlert>

        <ul v-else-if="items.length" class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
            <li
                v-for="member in items"
                :key="member.id"
                class="p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-background/40 transition-colors"
            >
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <div
                        class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center font-semibold text-xs border"
                        :class="
                            member.status === 'active'
                                ? 'bg-primary/10 text-primary border-primary/20'
                                : 'bg-background text-muted border-border'
                        "
                    >
                        {{ (member.name || '?').charAt(0).toUpperCase() }}
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-foreground truncate">{{ member.name }}</p>
                        <p class="text-xs text-muted truncate">{{ member.email }}</p>
                    </div>
                </div>

                <div class="pt-2 sm:pt-0 border-t sm:border-t-0 border-border flex items-center justify-between sm:justify-end gap-3 sm:gap-4 shrink-0">
                    <div class="text-left sm:text-right space-y-0.5">
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                            :class="statusStyle[member.status]"
                        >
                            {{ member.status_label }}
                        </span>
                        <p
                            v-if="member.until"
                            class="text-[11px] flex items-center sm:justify-end gap-1"
                            :class="member.expiring_soon ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-muted'"
                        >
                            <Clock v-if="member.expiring_soon" class="h-3 w-3 shrink-0" aria-hidden="true" />
                            <template v-if="member.status === 'active'">
                                Hasta el {{ member.until }}
                                <template v-if="member.expiring_soon"> · {{ plural(member.days_left, 'día', 'días') }}</template>
                            </template>
                            <template v-else-if="member.status === 'expired'">Venció el {{ member.until }}</template>
                        </p>
                    </div>

                    <button type="button" :class="btn.small" @click="openHistory(member)">
                        <History class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                        <span>Historial</span>
                        <span class="sr-only"> de {{ member.name }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <div
            v-else-if="!loading"
            class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-2.5"
        >
            <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                <Users class="h-6 w-6" aria-hidden="true" />
            </div>
            <h4 class="text-base font-semibold text-foreground">
                {{ search.trim() ? 'Sin coincidencias de búsqueda' : 'No hay registros en esta vista' }}
            </h4>
            <p class="text-sm text-muted max-w-md mx-auto">
                {{ search.trim() ? `Ninguna cuenta coincide con «${search.trim()}».` : EMPTY[filter] }}
            </p>
            <div v-if="search.trim() || filter !== 'active'" class="pt-1">
                <button
                    type="button"
                    :class="btn.secondary"
                    @click="
                        search = '';
                        filter = 'active';
                    "
                >
                    Mostrar con acceso activo
                </button>
            </div>
        </div>

        <div v-if="nextPage && !loadError" class="text-center">
            <button type="button" :class="btn.secondary" :disabled="loading" @click="load({ append: true })">
                {{ loading ? 'Cargando…' : 'Cargar más personas' }}
            </button>
        </div>

        <MemberHistoryModal v-model="historyOpen" :member="selected" @revoked="onRevoked" />
    </div>
</template>
