<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    BookOpen,
    CheckCircle2,
    ChevronRight,
    FileText,
    Layers,
    ListTree,
    Lock,
    Play,
    Search,
    Sparkles,
    Unlock,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    book: {
        type: Object,
        default: null,
    },
    edition: {
        type: Object,
        default: null,
    },
    hasActiveMembership: {
        type: Boolean,
        default: false,
    },
    membershipUrl: {
        type: String,
        default: '/membresia',
    },
});

const emit = defineEmits(['update:modelValue', 'open-reader']);

const tocSearch = ref('');

function closeDrawer() {
    emit('update:modelValue', false);
}

function handleKeydown(event) {
    if (props.modelValue && event.key === 'Escape') {
        closeDrawer();
    }
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            tocSearch.value = '';
            document.body.classList.add('overflow-hidden');
        } else {
            document.body.classList.remove('overflow-hidden');
        }
    }
);

onMounted(() => document.addEventListener('keydown', handleKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.body.classList.remove('overflow-hidden');
});

/**
 * Normaliza el árbol `toc_json` o `toc` (soporta tanto estructura anidada `children`/`subtopics`
 * como estructura plana con `level: 1 | 2 | 3`).
 */
const normalizedTocItems = computed(() => {
    const rawToc = props.edition?.toc || props.edition?.toc_json || [];
    if (!Array.isArray(rawToc)) {
        return [];
    }

    const flattened = [];
    const traverse = (nodes, depth = 1) => {
        nodes.forEach((node, idx) => {
            if (!node || typeof node !== 'object') return;
            const title = String(node.title || node.label || `Sección ${idx + 1}`).trim();
            const page = Math.max(1, Number(node.page || node.start_page || 1));
            const level = Number(node.level || depth);

            flattened.push({
                id: `${page}-${title}-${idx}-${depth}`,
                title,
                page,
                level: Math.min(3, Math.max(1, level)),
            });

            const children = node.children || node.subtopics || node.items || [];
            if (Array.isArray(children) && children.length > 0) {
                traverse(children, depth + 1);
            }
        });
    };

    traverse(rawToc, 1);
    return flattened;
});

const filteredTocItems = computed(() => {
    const query = tocSearch.value.trim().toLowerCase();
    if (!query) {
        return normalizedTocItems.value;
    }
    return normalizedTocItems.value.filter(
        (item) =>
            item.title.toLowerCase().includes(query) ||
            String(item.page).includes(query)
    );
});

const samplePages = computed(() => Number(props.edition?.sample_pages_count || 0));
const totalPages = computed(() => Number(props.edition?.total_pages || 0));

function isPageUnlocked(page) {
    if (props.hasActiveMembership) return true;
    return Number(page) <= samplePages.value;
}

function handleSelectChapter(item) {
    emit('open-reader', {
        book: props.book,
        edition: props.edition,
        page: item.page,
    });
    closeDrawer();
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue && book"
                class="fixed inset-0 z-50 overflow-hidden bg-slate-950/70 backdrop-blur-xs flex justify-end"
                role="dialog"
                aria-modal="true"
                :aria-label="`Índice de contenidos de ${book.title}`"
                @click.self="closeDrawer"
            >
                <Transition
                    enter-active-class="transform transition duration-250 ease-out"
                    enter-from-class="translate-x-full"
                    enter-to-class="translate-x-0"
                    leave-active-class="transform transition duration-200 ease-in"
                    leave-from-class="translate-x-0"
                    leave-to-class="translate-x-full"
                    appear
                >
                    <aside
                        class="w-full max-w-lg h-full bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-800 shadow-2xl flex flex-col"
                    >
                        <!-- Cabecera del Slide-over -->
                        <div class="p-5 sm:p-6 border-b border-slate-200 dark:border-slate-800 flex items-start justify-between gap-4 bg-slate-50/70 dark:bg-slate-950/50">
                            <div class="flex items-start gap-3.5 min-w-0">
                                <div
                                    v-if="book.cover_url"
                                    class="h-16 w-12 shrink-0 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-700 shadow-sm bg-slate-800"
                                >
                                    <img
                                        :src="book.cover_url"
                                        :alt="`Portada de ${book.title}`"
                                        class="h-full w-full object-cover"
                                    />
                                </div>
                                <div
                                    v-else
                                    class="h-16 w-12 shrink-0 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400"
                                >
                                    <BookOpen class="h-5 w-5" />
                                </div>

                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-sky-500/10 border border-sky-500/20 px-2 py-0.5 text-[11px] font-semibold text-sky-600 dark:text-sky-400">
                                            <ListTree class="h-3 w-3" />
                                            Índice Temático
                                        </span>
                                        <span
                                            v-if="edition"
                                            class="inline-flex items-center gap-1 rounded-md bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:text-slate-300"
                                        >
                                            <Layers class="h-3 w-3" />
                                            {{ edition.edition_name || edition.name || '1ra Edición' }}
                                        </span>
                                    </div>
                                    <h3 class="mt-1.5 text-base font-bold text-slate-900 dark:text-slate-100 truncate">
                                        {{ book.title }}
                                    </h3>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate">
                                        {{ book.author_name || 'Editorial El Arte de la Contabilidad' }}
                                        <template v-if="totalPages > 0">
                                            · {{ totalPages }} págs.
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="rounded-xl p-2 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                                aria-label="Cerrar índice de contenidos"
                                @click="closeDrawer"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>

                        <!-- Buscador interno y resumen de acceso -->
                        <div class="px-5 py-3.5 border-b border-slate-200 dark:border-slate-800 space-y-3 bg-white dark:bg-slate-900">
                            <div class="relative">
                                <Search class="h-4 w-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                                <input
                                    v-model="tocSearch"
                                    type="search"
                                    placeholder="Buscar capítulo, tema o número de página..."
                                    class="w-full rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/70 pl-9 pr-4 py-2 text-xs sm:text-sm text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition"
                                />
                            </div>

                            <div class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                                <span>
                                    {{ filteredTocItems.length }}
                                    {{ filteredTocItems.length === 1 ? 'entrada disponible' : 'entradas en el índice' }}
                                </span>
                                <span
                                    v-if="hasActiveMembership"
                                    class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400"
                                >
                                    <CheckCircle2 class="h-3.5 w-3.5" />
                                    Acceso Completo Desbloqueado
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 font-semibold text-amber-600 dark:text-amber-400"
                                >
                                    <Unlock class="h-3.5 w-3.5" />
                                    Muestra libre hasta pág. {{ samplePages }}
                                </span>
                            </div>
                        </div>

                        <!-- Cuerpo: Árbol de Capítulos y Subtemas -->
                        <div class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-1.5">
                            <template v-if="filteredTocItems.length > 0">
                                <button
                                    v-for="item in filteredTocItems"
                                    :key="item.id"
                                    type="button"
                                    class="group w-full text-left rounded-xl border transition-all duration-150 flex items-center justify-between gap-3 px-3.5 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                                    :class="[
                                        item.level === 1
                                            ? 'bg-slate-50/80 dark:bg-slate-800/40 border-slate-200/80 dark:border-slate-800 font-semibold text-slate-900 dark:text-slate-100 hover:border-sky-500/40 hover:bg-sky-500/5'
                                            : item.level === 2
                                              ? 'ml-4 w-[calc(100%-1rem)] bg-transparent border-transparent hover:border-slate-200 dark:hover:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/30 text-slate-700 dark:text-slate-300 font-medium'
                                              : 'ml-8 w-[calc(100%-2rem)] bg-transparent border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/20 text-slate-600 dark:text-slate-400 text-xs',
                                    ]"
                                    @click="handleSelectChapter(item)"
                                >
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span
                                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-xs transition-colors"
                                            :class="
                                                isPageUnlocked(item.page)
                                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 group-hover:bg-sky-500/15 group-hover:text-sky-500'
                                                    : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                            "
                                        >
                                            <Play v-if="isPageUnlocked(item.page)" class="h-3 w-3" />
                                            <Lock v-else class="h-3 w-3" />
                                        </span>
                                        <span class="text-xs sm:text-sm truncate group-hover:text-sky-600 dark:group-hover:text-sky-400 transition-colors">
                                            {{ item.title }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <span
                                            class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-mono font-medium"
                                            :class="
                                                isPageUnlocked(item.page)
                                                    ? 'bg-slate-200/70 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                                    : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'
                                            "
                                        >
                                            Pág. {{ item.page }}
                                        </span>
                                        <ChevronRight class="h-4 w-4 text-slate-400 group-hover:translate-x-0.5 group-hover:text-sky-500 transition-transform" />
                                    </div>
                                </button>
                            </template>

                            <!-- Estado vacío del índice -->
                            <div
                                v-else
                                class="h-64 flex flex-col items-center justify-center text-center px-6"
                            >
                                <div class="h-12 w-12 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-400 mb-3">
                                    <FileText class="h-6 w-6" />
                                </div>
                                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                                    {{ tocSearch ? 'Sin coincidencias en el índice' : 'Índice temático en preparación' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 max-w-xs">
                                    {{
                                        tocSearch
                                            ? 'Prueba con otro término de búsqueda o número de página.'
                                            : 'El comité editorial aún no ha publicado el desglose de capítulos para esta edición.'
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- Footer con CTA Paywall-Aware -->
                        <div class="p-4 sm:p-5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/60 flex flex-col sm:flex-row items-center gap-2.5">
                            <button
                                type="button"
                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-semibold text-xs sm:text-sm px-4 py-2.5 shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                                @click="
                                    emit('open-reader', {
                                        book,
                                        edition,
                                        page: edition?.last_page > 0 ? edition.last_page : 1,
                                    });
                                    closeDrawer();
                                "
                            >
                                <BookOpen class="h-4 w-4" />
                                <span>
                                    {{
                                        hasActiveMembership
                                            ? edition?.last_page > 0
                                                ? `Continuar lectura (Pág. ${edition.last_page})`
                                                : 'Leer libro completo'
                                            : `Leer muestra gratis (${samplePages} págs.)`
                                    }}
                                </span>
                            </button>

                            <a
                                v-if="!hasActiveMembership"
                                :href="membershipUrl"
                                class="w-full sm:w-auto shrink-0 inline-flex items-center justify-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-300 font-semibold text-xs sm:text-sm px-4 py-2.5 transition-colors"
                            >
                                <Sparkles class="h-4 w-4" />
                                <span>Desbloquear todo</span>
                            </a>
                        </div>
                    </aside>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

