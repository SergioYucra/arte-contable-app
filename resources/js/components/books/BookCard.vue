<script setup>
import { computed } from 'vue';
import {
    BookOpen,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    GraduationCap,
    Layers,
    ListTree,
    Lock,
    Play,
    Sparkles,
    StickyNote,
} from 'lucide-vue-next';

const props = defineProps({
    book: {
        type: Object,
        required: true,
    },
    selectedEditionId: {
        type: Number,
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

const emit = defineEmits(['select-edition', 'open-toc', 'open-reader']);

const editionsList = computed(() => {
    if (Array.isArray(props.book.editions) && props.book.editions.length > 0) {
        return props.book.editions;
    }
    if (props.book.edition && typeof props.book.edition === 'object') {
        return [props.book.edition];
    }
    return [];
});

const activeEdition = computed(() => {
    if (editionsList.value.length === 0) {
        return null;
    }
    if (props.selectedEditionId) {
        const found = editionsList.value.find((e) => Number(e.id) === Number(props.selectedEditionId));
        if (found) return found;
    }
    return (
        editionsList.value.find((e) => e.is_ready && e.is_current) ||
        editionsList.value.find((e) => e.is_ready) ||
        editionsList.value[0]
    );
});

const editionLabel = computed(() => {
    if (!activeEdition.value) return 'Sin edición';
    return activeEdition.value.edition_name || activeEdition.value.name || '1ra Edición';
});

const totalPages = computed(() => Number(activeEdition.value?.total_pages || 0));
const samplePagesCount = computed(() => Number(activeEdition.value?.sample_pages_count || 0));
const lastPage = computed(() => Number(activeEdition.value?.last_page || 0));
const progressPercent = computed(() => Number(activeEdition.value?.progress_percent || 0));
const notesCount = computed(() => Number(activeEdition.value?.notes_count || 0));

const tocItems = computed(() => {
    const toc = activeEdition.value?.toc || activeEdition.value?.toc_json;
    return Array.isArray(toc) ? toc : [];
});

const tocItemsCount = computed(() => tocItems.value.length);

const previewChapters = computed(() => tocItems.value.slice(0, 2));

const estimatedHours = computed(() => {
    if (totalPages.value <= 0) return null;
    const minutes = Math.max(15, Math.round(totalPages.value * 2.2));
    if (minutes < 60) return `${minutes} min`;
    const hrs = (minutes / 60).toFixed(1).replace(/\.0$/, '');
    return `${hrs} h de estudio`;
});

const isEditionReady = computed(() => {
    if (!activeEdition.value) return false;
    if (typeof activeEdition.value.is_ready === 'boolean') {
        return activeEdition.value.is_ready;
    }
    return totalPages.value > 0;
});

function handleEditionChange(event) {
    emit('select-edition', {
        bookId: props.book.id,
        editionId: Number(event.target.value),
    });
}

function handleOpenToc() {
    emit('open-toc', {
        book: props.book,
        edition: activeEdition.value,
    });
}

function handleOpenReader() {
    if (!activeEdition.value || !isEditionReady.value) return;
    emit('open-reader', {
        book: props.book,
        edition: activeEdition.value,
        page: lastPage.value > 0 ? lastPage.value : 1,
    });
}
</script>

<template>
    <article
        class="group relative flex flex-col rounded-2xl border border-slate-200/90 dark:border-slate-800/90 bg-white dark:bg-slate-900/80 hover:border-sky-500/40 dark:hover:border-sky-500/40 transition-all duration-300 shadow-xs hover:shadow-2xl hover:shadow-slate-950/10 dark:hover:shadow-slate-950/60 overflow-hidden"
    >
        <!-- 1. Escenario de Portada Estilo Plataforma Educativa (Perlego / O'Reilly) -->
        <div
            class="relative aspect-[3/4] w-full overflow-hidden bg-slate-100 dark:bg-slate-950 border-b border-slate-200/70 dark:border-slate-800/80 cursor-pointer"
            @click="handleOpenReader"
        >
            <!-- Imagen de portada con efecto lomo de libro -->
            <img
                v-if="book.cover_url"
                :src="book.cover_url"
                :alt="`Portada de ${book.title}`"
                loading="lazy"
                class="h-full w-full object-cover object-center transition-transform duration-500 ease-out group-hover:scale-[1.04]"
            />

            <!-- Cubierta editorial académica si no hay imagen -->
            <div
                v-else
                class="h-full w-full p-6 flex flex-col justify-between bg-slate-900 text-slate-100 relative overflow-hidden select-none"
            >
                <div class="pointer-events-none absolute inset-0 bg-brand-grid opacity-25" />
                <div class="pointer-events-none absolute left-0 inset-y-0 w-3 bg-white/5 border-r border-white/10" />

                <div class="relative z-10 flex items-center justify-between text-[10px] font-mono uppercase tracking-widest text-sky-400">
                    <span>Colección Contable</span>
                    <span>{{ editionLabel }}</span>
                </div>

                <div class="relative z-10 my-auto space-y-2.5 pl-3 border-l-2 border-sky-400">
                    <p class="text-base sm:text-lg font-bold leading-snug text-white line-clamp-3">
                        {{ book.title }}
                    </p>
                    <p v-if="book.subtitle" class="text-xs text-slate-300 line-clamp-2">
                        {{ book.subtitle }}
                    </p>
                </div>

                <div class="relative z-10 flex items-center justify-between text-[11px] text-slate-400 pt-3 border-t border-slate-800">
                    <span class="truncate font-medium text-slate-300">{{ book.author_name || 'Edición Oficial' }}</span>
                    <BookOpen class="h-4 w-4 text-sky-400 shrink-0" />
                </div>
            </div>

            <!-- Reflejo de lomo de libro en el borde izquierdo -->
            <div
                class="pointer-events-none absolute inset-y-0 left-0 w-2.5 bg-gradient-to-r from-black/35 via-white/10 to-transparent"
                aria-hidden="true"
            />

            <!-- Gradiente superior suave para contraste de insignias -->
            <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-slate-950/75 via-slate-950/25 to-transparent" />

            <!-- Badge Superior Dinámico según Membresía -->
            <div class="absolute top-3 left-3 right-3 flex items-center justify-between gap-2 z-10">
                <span
                    v-if="hasActiveMembership"
                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/95 backdrop-blur-md px-2.5 py-1 text-[11px] font-semibold text-slate-950 shadow-md"
                >
                    <CheckCircle2 class="h-3.5 w-3.5 stroke-[2.3]" />
                    <span>Acceso Completo</span>
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 rounded-full bg-amber-400/95 backdrop-blur-md px-2.5 py-1 text-[11px] font-semibold text-slate-950 shadow-md"
                >
                    <Sparkles class="h-3.5 w-3.5 stroke-[2.3]" />
                    <span>Muestra gratis • {{ samplePagesCount }} págs.</span>
                </span>

                <span
                    v-if="notesCount > 0"
                    class="inline-flex items-center gap-1 rounded-full bg-slate-900/90 backdrop-blur-md border border-slate-700 px-2 py-0.5 text-[10px] font-semibold text-slate-200"
                    :title="`${notesCount} apuntes de estudio`"
                >
                    <StickyNote class="h-3 w-3 text-sky-400" />
                    {{ notesCount }}
                </span>
            </div>

            <!-- Overlay de acción rápida al pasar el cursor -->
            <div
                v-if="isEditionReady"
                class="pointer-events-none absolute inset-0 bg-slate-950/40 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center"
            >
                <span class="inline-flex items-center gap-2 rounded-full bg-white/95 text-slate-950 font-semibold text-xs px-4 py-2 shadow-xl transform translate-y-2 group-hover:translate-y-0 transition-transform duration-200">
                    <Eye class="h-3.5 w-3.5 text-sky-600" />
                    <span>{{ lastPage > 0 ? `Continuar en pág. ${lastPage}` : 'Abrir lector de estudio' }}</span>
                </span>
            </div>

            <!-- Barra de progreso de lectura superpuesta al pie de la portada -->
            <div
                v-if="lastPage > 0 && totalPages > 0"
                class="absolute inset-x-0 bottom-0 bg-slate-950/90 backdrop-blur-md px-3.5 py-2.5 border-t border-slate-800/80 z-10"
            >
                <div class="flex items-center justify-between text-[11px] font-medium text-slate-200 mb-1">
                    <span>Avance: Pág. {{ lastPage }} de {{ totalPages }}</span>
                    <span class="text-sky-400 font-bold">{{ progressPercent }}%</span>
                </div>
                <div class="h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                    <div
                        class="h-full rounded-full bg-sky-400 transition-all duration-300"
                        :style="{ width: `${Math.max(4, progressPercent)}%` }"
                    />
                </div>
            </div>
        </div>

        <!-- 2. Cuerpo de la Ficha Académica -->
        <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between space-y-4">
            <div class="space-y-2.5">
                <!-- Autor / Categoría Editorial -->
                <div class="flex items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                    <span class="inline-flex items-center gap-1.5 font-medium truncate">
                        <GraduationCap class="h-3.5 w-3.5 text-sky-500 shrink-0" />
                        <span class="truncate">{{ book.author_name || 'Editorial Arte Contable' }}</span>
                    </span>
                    <span
                        v-if="estimatedHours"
                        class="inline-flex items-center gap-1 shrink-0 text-[10px] font-medium text-slate-400"
                    >
                        <Clock class="h-3 w-3" />
                        {{ estimatedHours }}
                    </span>
                </div>

                <!-- Título semibold con truncate -->
                <h3
                    class="text-base font-bold text-slate-900 dark:text-slate-100 truncate group-hover:text-sky-600 dark:group-hover:text-sky-400 transition-colors"
                    :title="book.title"
                >
                    {{ book.title }}
                </h3>

                <!-- Breve descripción del libro (clamp de 2 líneas) -->
                <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-2 leading-relaxed min-h-[2.25rem]">
                    {{
                        book.description ||
                        book.subtitle ||
                        'Obra técnica de consulta contable, financiera y tributaria con casos prácticos aplicados.'
                    }}
                </p>

                <!-- Selector de edición si hay múltiples ediciones -->
                <div v-if="editionsList.length > 1" class="pt-1">
                    <label :for="`edition-select-${book.id}`" class="sr-only">Seleccionar edición</label>
                    <select
                        :id="`edition-select-${book.id}`"
                        :value="activeEdition?.id"
                        class="w-full rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 px-2.5 py-1.5 text-xs font-medium text-slate-700 dark:text-slate-200 focus:border-sky-500 focus:ring-1 focus:ring-sky-500"
                        @change="handleEditionChange"
                    >
                        <option
                            v-for="ed in editionsList"
                            :key="ed.id"
                            :value="ed.id"
                        >
                            {{ ed.edition_name || ed.name }} ({{ ed.total_pages }} págs.)
                        </option>
                    </select>
                </div>

                <!-- Fila de badges de metadatos editoriales -->
                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                    <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700/70 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                        <Layers class="h-3 w-3 text-slate-400" />
                        {{ editionLabel }}
                    </span>

                    <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700/70 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                        <FileText class="h-3 w-3 text-slate-400" />
                        {{ totalPages }} págs.
                    </span>

                    <span
                        class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-medium border"
                        :class="
                            isEditionReady
                                ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-700 dark:text-emerald-400'
                                : 'bg-amber-500/10 border-amber-500/20 text-amber-700 dark:text-amber-400'
                        "
                    >
                        <span
                            class="h-1.5 w-1.5 rounded-full"
                            :class="isEditionReady ? 'bg-emerald-500' : 'bg-amber-500'"
                        />
                        {{ isEditionReady ? 'Publicado' : 'En preparación' }}
                    </span>
                </div>

                <!-- Mini-vista previa de capítulos del índice (estilo curso/temario) -->
                <div
                    v-if="previewChapters.length > 0"
                    class="mt-2 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200/70 dark:border-slate-800/80 p-2.5 space-y-1"
                >
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                        Contenido temático
                    </p>
                    <div
                        v-for="(chap, cIdx) in previewChapters"
                        :key="cIdx"
                        class="flex items-center justify-between gap-2 text-[11px] text-slate-600 dark:text-slate-300"
                    >
                        <span class="truncate">• {{ chap.title }}</span>
                        <span class="font-mono text-[10px] text-slate-400 shrink-0">P.{{ chap.page }}</span>
                    </div>
                </div>
            </div>

            <!-- 3. Vista Previa del Índice Temático + 4. Footer Paywall-Aware -->
            <div class="space-y-3 pt-3 border-t border-slate-200/80 dark:border-slate-800/80">
                <button
                    type="button"
                    class="w-full inline-flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-sky-600 dark:hover:text-sky-400 hover:bg-slate-100/80 dark:hover:bg-slate-800/60 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500"
                    @click="handleOpenToc"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <ListTree class="h-3.5 w-3.5 text-sky-500" />
                        <span>Ver índice de contenidos</span>
                    </span>
                    <span class="text-[11px] text-sky-600 dark:text-sky-400 font-mono">
                        {{ tocItemsCount > 0 ? `${tocItemsCount} temas` : 'Explorar' }} &rarr;
                    </span>
                </button>

                <!-- Acción Primaria con Membresía Activa -->
                <div v-if="hasActiveMembership">
                    <button
                        type="button"
                        :disabled="!isEditionReady"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-sky-500 hover:bg-sky-400 disabled:opacity-50 disabled:pointer-events-none text-slate-950 font-semibold text-xs sm:text-sm px-4 py-2.5 shadow-sm transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                        @click="handleOpenReader"
                    >
                        <BookOpen class="h-4 w-4" />
                        <span>
                            {{
                                lastPage > 0
                                    ? `Continuar lectura (Pág. ${lastPage})`
                                    : 'Leer libro completo'
                            }}
                        </span>
                    </button>
                </div>

                <!-- Acciones Paywall-Aware en Plan Gratuito -->
                <div v-else class="space-y-2">
                    <button
                        type="button"
                        :disabled="!isEditionReady"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-slate-100 dark:hover:bg-white disabled:opacity-50 disabled:pointer-events-none text-white dark:text-slate-950 font-semibold text-xs sm:text-sm px-4 py-2.5 shadow-sm transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400"
                        @click="handleOpenReader"
                    >
                        <Play class="h-3.5 w-3.5 fill-current" />
                        <span>
                            {{
                                lastPage > 0
                                    ? `Continuar muestra (Pág. ${lastPage})`
                                    : 'Leer muestra gratis'
                            }}
                        </span>
                    </button>

                    <a
                        :href="membershipUrl"
                        class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl border border-amber-500/25 bg-amber-500/10 hover:bg-amber-500/15 px-3 py-2 text-xs font-semibold text-amber-700 dark:text-amber-300 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                    >
                        <Lock class="h-3.5 w-3.5" />
                        <span>Desbloquear libro completo</span>
                    </a>
                </div>
            </div>
        </div>
    </article>
</template>
