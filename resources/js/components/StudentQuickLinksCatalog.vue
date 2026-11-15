<script setup>
import { ref, computed } from 'vue';
import {
    Search,
    ExternalLink,
    Globe,
    X,
    Compass,
} from 'lucide-vue-next';

const props = defineProps({
    initialCategories: {
        type: Array,
        required: true,
    },
});

const categories = ref(props.initialCategories || []);
const selectedCategorySlug = ref('ALL');
const searchQuery = ref('');

const allLinks = computed(() => {
    return categories.value.flatMap((cat) =>
        (cat.links || []).map((link) => ({
            ...link,
            category_slug: cat.slug,
            category_name: link.category_name || cat.name,
        }))
    );
});

const totalLinksCount = computed(() => allLinks.value.length);

const filteredLinks = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return allLinks.value.filter((link) => {
        if (selectedCategorySlug.value !== 'ALL' && link.category_slug !== selectedCategorySlug.value) {
            return false;
        }

        if (!query) {
            return true;
        }

        return (
            link.title.toLowerCase().includes(query) ||
            (link.description || '').toLowerCase().includes(query) ||
            (link.domain || '').toLowerCase().includes(query) ||
            (link.category_name || '').toLowerCase().includes(query)
        );
    });
});

function resetFilters() {
    selectedCategorySlug.value = 'ALL';
    searchQuery.value = '';
}
</script>

<template>
    <div class="space-y-5">
        <!-- Barra de filtrado rápido por categoría y buscador -->
        <div class="bg-surface border border-border rounded-brand p-3.5 sm:p-4 shadow-xs">
            <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                <!-- Píldoras de categoría -->
                <div class="-mx-3.5 px-3.5 sm:mx-0 sm:px-0 flex md:flex-wrap items-center gap-1.5 overflow-x-auto md:overflow-visible no-scrollbar snap-x">
                    <button
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition-all"
                        :class="
                            selectedCategorySlug === 'ALL'
                                ? 'bg-primary text-primary-foreground shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="selectedCategorySlug = 'ALL'"
                    >
                        <span>Todos</span>
                        <span
                            class="px-1.5 py-0.2 rounded-full text-[10px]"
                            :class="
                                selectedCategorySlug === 'ALL'
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-surface text-muted'
                            "
                        >
                            {{ totalLinksCount }}
                        </span>
                    </button>

                    <button
                        v-for="cat in categories"
                        :key="cat.id"
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition-all"
                        :class="
                            selectedCategorySlug === cat.slug
                                ? 'bg-primary text-primary-foreground shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="selectedCategorySlug = cat.slug"
                    >
                        <span>{{ cat.name }}</span>
                        <span
                            class="px-1.5 py-0.2 rounded-full text-[10px]"
                            :class="
                                selectedCategorySlug === cat.slug
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-surface text-muted'
                            "
                        >
                            {{ cat.links?.length || 0 }}
                        </span>
                    </button>
                </div>

                <!-- Buscador instantáneo -->
                <div class="relative w-full md:w-72 shrink-0">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                    <input
                        v-model="searchQuery"
                        type="search"
                        inputmode="search"
                        placeholder="Buscar institución o trámite..."
                        class="w-full pl-9 pr-8 py-2 bg-background border border-border rounded-brand text-xs sm:text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-foreground p-1"
                        aria-label="Limpiar búsqueda"
                        @click="searchQuery = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Cuadrícula única continua sin separaciones por categoría -->
        <div
            v-if="filteredLinks.length > 0"
            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5"
        >
            <a
                v-for="link in filteredLinks"
                :key="`card-${link.id}`"
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="group flex flex-col justify-between rounded-brand border border-border bg-surface p-4 shadow-xs transition-all duration-150 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
                <div class="space-y-2.5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <!-- Contenedor estandarizado de logotipo 44x44 -->
                            <div class="h-11 w-11 shrink-0 rounded-xl border border-border bg-background flex items-center justify-center overflow-hidden p-1.5 group-hover:border-primary/30 transition-colors">
                                <img
                                    v-if="link.logo_url"
                                    :src="link.logo_url"
                                    :alt="`Logotipo de ${link.title}`"
                                    class="h-full w-full object-contain"
                                    loading="lazy"
                                />
                                <Globe v-else class="h-5 w-5 text-primary/75" />
                            </div>

                            <div class="min-w-0 space-y-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary/10 text-primary border border-primary/20">
                                    {{ link.category_name }}
                                </span>
                                <h3 class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors leading-snug">
                                    {{ link.title }}
                                </h3>
                            </div>
                        </div>

                        <span
                            class="shrink-0 grid h-7 w-7 place-items-center rounded-lg bg-background border border-border text-muted group-hover:border-primary/30 group-hover:bg-primary/10 group-hover:text-primary transition-colors"
                            aria-hidden="true"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                        </span>
                    </div>

                    <p v-if="link.description" class="text-xs text-muted leading-relaxed">
                        {{ link.description }}
                    </p>
                </div>

                <div class="mt-4 pt-2.5 border-t border-border/70 flex items-center justify-between gap-2 text-[11px]">
                    <span class="inline-flex items-center gap-1.5 text-muted truncate">
                        <Globe class="h-3.5 w-3.5 text-primary/80 shrink-0" />
                        <span class="truncate font-mono">{{ link.domain }}</span>
                    </span>

                    <span class="shrink-0 font-semibold text-primary group-hover:underline">
                        Abrir portal &rarr;
                    </span>
                </div>
            </a>
        </div>

        <!-- Estado vacío -->
        <div
            v-else
            class="bg-surface border border-border rounded-brand p-10 text-center space-y-3"
        >
            <div class="h-11 w-11 mx-auto rounded-full bg-primary/10 text-primary flex items-center justify-center">
                <Compass class="h-5 w-5" />
            </div>
            <h3 class="text-sm font-semibold text-foreground">
                No encontramos portales con ese criterio
            </h3>
            <p class="text-xs text-muted max-w-sm mx-auto leading-relaxed">
                {{
                    totalLinksCount === 0
                        ? 'Aún no hay enlaces publicados en el directorio.'
                        : 'Prueba seleccionando otra categoría o limpiando el texto de búsqueda.'
                }}
            </p>
            <button
                v-if="totalLinksCount > 0 && (searchQuery || selectedCategorySlug !== 'ALL')"
                type="button"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                @click="resetFilters"
            >
                Mostrar todos los portales
            </button>
        </div>
    </div>
</template>
