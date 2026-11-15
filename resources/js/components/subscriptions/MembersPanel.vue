<script setup>
/**
 * Personas con acceso: quién tiene membresía, cuándo vence, y entrada a su historial.
 */
import { ref, watch, inject, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { Search, Users } from 'lucide-vue-next';
import MemberHistoryModal from './MemberHistoryModal.vue';
import { PANEL_CONTEXT, btn, field, readError, plural } from './shared';

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
    active: 'Todavía nadie tiene acceso. Aparecerán aquí cuando apruebes un pago o alguien use un código.',
    expiring: 'Nadie vence en los próximos 15 días.',
    expired: 'No hay membresías vencidas.',
    revoked: 'No le has quitado el acceso a nadie.',
    all: 'Todavía nadie ha tenido membresía.',
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

async function load({ append = false } = {}) {
    const current = ++requestId;
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(urls.members, {
            params: { status: filter.value, q: search.value.trim() || undefined, page: append ? nextPage.value : 1 },
        });
        if (current !== requestId) return;
        items.value = append ? [...items.value, ...data.items] : data.items;
        total.value = data.total;
        nextPage.value = data.next_page;
    } catch (error) {
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
onBeforeUnmount(() => clearTimeout(debounce));

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
    active: 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/30',
    expired: 'bg-background text-muted border-border',
    revoked: 'bg-rose-500/10 text-rose-800 dark:text-rose-300 border-rose-500/30',
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center gap-3 justify-between">
            <div class="flex gap-1.5 overflow-x-auto no-scrollbar pb-1" role="group" aria-label="Filtrar personas">
                <button
                    v-for="option in FILTERS"
                    :key="option.id"
                    type="button"
                    class="shrink-0 px-3.5 py-2 rounded-full border text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                    :class="filter === option.id ? 'bg-primary text-primary-foreground border-primary' : 'bg-surface border-border text-muted hover:text-foreground'"
                    :aria-pressed="filter === option.id"
                    @click="filter = option.id"
                >
                    {{ option.label }}
                </button>
            </div>

            <div class="relative w-full lg:w-72">
                <label for="members-search" class="sr-only">Buscar por nombre o correo</label>
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" aria-hidden="true" />
                <input
                    id="members-search"
                    v-model="search"
                    type="search"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Buscar por nombre o correo…"
                    :class="[field, 'pl-9']"
                />
            </div>
        </div>

        <p class="text-sm text-muted" aria-live="polite">
            <template v-if="loading && !items.length">Cargando…</template>
            <template v-else-if="!loadError">{{ plural(total, 'persona', 'personas') }}</template>
        </p>

        <div v-if="loadError" class="rounded-brand border border-rose-500/30 bg-rose-500/5 p-4 text-sm text-foreground flex items-center justify-between gap-3">
            <span>{{ loadError }}</span>
            <button type="button" :class="btn.small" @click="load()">Reintentar</button>
        </div>

        <ul v-else-if="items.length" class="divide-y divide-border rounded-brand border border-border bg-surface">
            <li v-for="member in items" :key="member.id" class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-base font-semibold text-foreground truncate">{{ member.name }}</p>
                    <p class="text-sm text-muted truncate">{{ member.email }}</p>
                </div>

                <div class="flex items-center justify-between sm:justify-end gap-3 sm:gap-5 shrink-0">
                    <div class="text-sm sm:text-right">
                        <span class="inline-block px-2.5 py-1 rounded-full border text-xs font-semibold" :class="statusStyle[member.status]">
                            {{ member.status_label }}
                        </span>
                        <p v-if="member.until" class="mt-1 text-xs" :class="member.expiring_soon ? 'text-amber-800 dark:text-amber-300 font-semibold' : 'text-muted'">
                            <template v-if="member.status === 'active'">
                                Hasta el {{ member.until }}
                                <template v-if="member.expiring_soon"> · {{ plural(member.days_left, 'día', 'días') }}</template>
                            </template>
                            <template v-else-if="member.status === 'expired'">Venció el {{ member.until }}</template>
                        </p>
                    </div>
                    <button type="button" :class="btn.small" @click="openHistory(member)">
                        Historial
                        <span class="sr-only"> de {{ member.name }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <div v-else-if="!loading" class="rounded-brand border border-dashed border-border bg-surface/60 px-6 py-10 text-center">
            <Users class="mx-auto h-8 w-8 text-muted" aria-hidden="true" />
            <p class="mt-3 text-sm text-foreground max-w-md mx-auto">
                {{ search.trim() ? `Nadie coincide con «${search.trim()}».` : EMPTY[filter] }}
            </p>
        </div>

        <div v-if="nextPage && !loadError" class="text-center">
            <button type="button" :class="btn.secondary" :disabled="loading" @click="load({ append: true })">
                {{ loading ? 'Cargando…' : 'Ver más' }}
            </button>
        </div>

        <MemberHistoryModal v-model="historyOpen" :member="selected" @revoked="onRevoked" />
    </div>
</template>
