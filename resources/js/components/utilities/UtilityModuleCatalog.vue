<script setup>
import { computed, ref } from 'vue';
import {
    ArrowLeft,
    ArrowRight,
    Banknote,
    Calculator,
    Coins,
    FileSpreadsheet,
    FileText,
    FolderOpen,
    Landmark,
    Layers,
    Percent,
    Receipt,
    Scale,
    Search,
    TrendingDown,
    Users,
    Wrench,
    X,
} from 'lucide-vue-next';

const ICON_MAP = {
    calculator: Calculator,
    wrench: Wrench,
    percent: Percent,
    landmark: Landmark,
    coins: Coins,
    'trending-down': TrendingDown,
    users: Users,
    'file-text': FileText,
    receipt: Receipt,
    banknote: Banknote,
    scale: Scale,
    'file-spreadsheet': FileSpreadsheet,
    layers: Layers,
};

const props = defineProps({
    moduleTitle: {
        type: String,
        required: true,
    },
    moduleDescription: {
        type: String,
        required: true,
    },
    searchPlaceholder: {
        type: String,
        default: 'Buscar por nombre...',
    },
    itemActionLabel: {
        type: String,
        default: 'Abrir',
    },
    initialItems: {
        type: Array,
        default: () => [],
    },
    componentMap: {
        type: Object,
        default: () => ({}),
    },
    moduleIcon: {
        type: [Object, Function],
        default: () => Layers,
    },
});

const items = computed(() => props.initialItems || []);
const searchQuery = ref('');
const activeItem = ref(null);

const filteredItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (!query) {
        return items.value;
    }

    return items.value.filter((item) => {
        return (
            item.title.toLowerCase().includes(query) ||
            (item.description || '').toLowerCase().includes(query) ||
            (item.badge || '').toLowerCase().includes(query)
        );
    });
});

const activeComponent = computed(() => {
    if (!activeItem.value) return null;
    const key = activeItem.value.component || activeItem.value.slug;
    return props.componentMap?.[key] || null;
});

function resolveIcon(iconKey) {
    if (!iconKey) return props.moduleIcon;
    if (typeof iconKey === 'string') {
        return ICON_MAP[iconKey] || props.moduleIcon;
    }
    return iconKey;
}

function openItem(item) {
    if (item.status === 'soon') return;
    activeItem.value = item;
}

function closeItem() {
    activeItem.value = null;
}

function resetSearch() {
    searchQuery.value = '';
}
</script>

<template>
    <div class="space-y-5">
        <!-- Vista activa de un ítem seleccionado -->
        <template v-if="activeItem">
            <div class="rounded-brand border border-border bg-surface p-4 sm:p-5 shadow-xs">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:border-primary/40 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            @click="closeItem"
                        >
                            <ArrowLeft class="h-3.5 w-3.5" />
                            <span>Volver</span>
                        </button>
                        <div class="min-w-0">
                            <h1 class="truncate text-lg sm:text-xl font-bold tracking-tight text-foreground">
                                {{ activeItem.title }}
                            </h1>
                        </div>
                    </div>

                    <span
                        v-if="activeItem.badge"
                        class="rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-[11px] font-bold text-primary"
                    >
                        {{ activeItem.badge }}
                    </span>
                </div>
            </div>

            <div class="rounded-brand border border-border bg-surface p-5 sm:p-6 shadow-xs">
                <component
                    :is="activeComponent"
                    v-if="activeComponent"
                    :item="activeItem"
                />
                <div v-else class="py-10 text-center space-y-2.5">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                        <component :is="resolveIcon(activeItem.icon)" class="h-6 w-6" />
                    </div>
                    <h2 class="text-base sm:text-lg font-bold tracking-tight text-foreground">
                        {{ activeItem.title }}
                    </h2>
                    <p class="text-xs sm:text-sm text-muted max-w-md mx-auto leading-relaxed">
                        {{ activeItem.description || 'Espacio listo para implementar la lógica de este ítem.' }}
                    </p>
                </div>
            </div>
        </template>

        <!-- Vista principal de catálogo -->
        <template v-else>
            <!-- Encabezado compacto + buscador -->
            <div class="bg-surface border border-border rounded-brand p-4 sm:p-5 shadow-xs">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="h-10 w-10 shrink-0 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <component :is="moduleIcon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-foreground">
                                    {{ moduleTitle }}
                                </h1>
                                <span class="rounded-full bg-background border border-border px-2 py-0.5 text-[11px] font-semibold text-muted">
                                    {{ items.length }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs sm:text-sm text-muted leading-relaxed">
                                {{ moduleDescription }}
                            </p>
                        </div>
                    </div>

                    <!-- Buscador por nombre -->
                    <div class="relative w-full md:w-72 shrink-0">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                        <input
                            v-model="searchQuery"
                            type="search"
                            inputmode="search"
                            :placeholder="searchPlaceholder"
                            class="w-full pl-9 pr-8 py-2 bg-background border border-border rounded-brand text-xs sm:text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                        />
                        <button
                            v-if="searchQuery"
                            type="button"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-foreground p-1"
                            aria-label="Limpiar búsqueda"
                            @click="resetSearch"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cuadrícula de ítems -->
            <div
                v-if="filteredItems.length > 0"
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5"
            >
                <button
                    v-for="item in filteredItems"
                    :key="`item-${item.id || item.slug}`"
                    type="button"
                    :disabled="item.status === 'soon'"
                    class="group text-left flex flex-col justify-between rounded-brand border border-border bg-surface p-4 shadow-xs transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    :class="
                        item.status === 'soon'
                            ? 'opacity-75 cursor-not-allowed'
                            : 'hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md'
                    "
                    @click="openItem(item)"
                >
                    <div class="space-y-2.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="h-10 w-10 shrink-0 rounded-xl border border-border bg-background flex items-center justify-center text-primary group-hover:border-primary/30 transition-colors">
                                    <component :is="resolveIcon(item.icon)" class="h-5 w-5" />
                                </div>
                                <h2 class="text-sm sm:text-base font-bold tracking-tight text-foreground group-hover:text-primary transition-colors">
                                    {{ item.title }}
                                </h2>
                            </div>

                            <span
                                v-if="item.status === 'soon'"
                                class="shrink-0 rounded-full border border-border bg-background px-2 py-0.5 text-[10px] font-semibold text-muted"
                            >
                                Próximamente
                            </span>
                            <span
                                v-else-if="item.badge"
                                class="shrink-0 rounded-full border border-primary/20 bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary"
                            >
                                {{ item.badge }}
                            </span>
                        </div>

                        <p class="text-xs text-muted leading-relaxed line-clamp-2">
                            {{ item.description }}
                        </p>
                    </div>

                    <div class="mt-4 pt-3 border-t border-border/70 flex items-center justify-end text-xs font-bold text-primary">
                        <span class="inline-flex items-center gap-1">
                            {{ item.status === 'soon' ? 'En preparación' : itemActionLabel }}
                            <ArrowRight v-if="item.status !== 'soon'" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                        </span>
                    </div>
                </button>
            </div>

            <!-- Estado sin resultados -->
            <div v-else class="rounded-brand border border-border bg-surface p-8 text-center space-y-3">
                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-background text-muted">
                    <FolderOpen class="h-5 w-5" />
                </div>
                <div class="space-y-1">
                    <h2 class="text-base font-bold tracking-tight text-foreground">
                        Sin coincidencias para "{{ searchQuery }}"
                    </h2>
                    <p class="text-xs sm:text-sm text-muted">
                        Prueba con otro nombre o limpia la búsqueda para ver todas las opciones.
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:bg-primary-hover transition-colors"
                    @click="resetSearch"
                >
                    Limpiar búsqueda
                </button>
            </div>
        </template>
    </div>
</template>
