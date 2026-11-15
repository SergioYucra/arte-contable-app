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
        class="group relative flex flex-col rounded-[12px] border border-border bg-surface hover:border-primary/45 transition-all duration-200 shadow-2xs hover:shadow-xl hover:shadow-slate-950/10 dark:hover:shadow-black/40 overflow-hidden"
    >
        <!-- 1. Escenario de Portada -->
        <div
            class="relative min-h-[245px] w-full overflow-hidden bg-gradient-to-br from-[#eff4f5] to-[#e5ebed] dark:from-slate-900 dark:to-slate-950 border-b border-border flex items-center justify-center p-5 cursor-pointer"
            @click="handleOpenReader"
        >
            <!-- Imagen de portada con efecto lomo de libro -->
            <div class="relative aspect-[3/4] w-36 sm:w-40 rounded-[6px] overflow-hidden shadow-[0_14px_26px_rgba(15,35,53,0.22)] transition-transform duration-300 ease-out group-hover:-translate-y-1">
                <img
                    v-if="book.cover_url"
                    :src="book.cover_url"
                    :alt="`Portada de ${book.title}`"
                    loading="lazy"
                    class="h-full w-full object-cover object-center"
                />

                <!-- Cubierta editorial académica si no hay imagen -->
                <div
                    v-else
                    class="h-full w-full p-3.5 flex flex-col justify-between bg-[linear-gradient(145deg,#246e8a,#124568_65%,#0d3049)] text-white relative overflow-hidden select-none"
                >
                    <div class="relative z-10 flex items-center justify-between font-mono text-[7px] uppercase tracking-[1.4px] text-white/80">
                        <span>Colección Contable</span>
                    </div>

                    <div class="relative z-10 my-auto space-y-1.5 border-l-2 border-gold pl-2.5">
                        <p class="font-serif text-[15px] font-medium leading-[1.08] text-white line-clamp-3">
                            {{ book.title }}
                        </p>
                        <p v-if="book.subtitle" class="text-[10px] text-white/75 line-clamp-2">
                            {{ book.subtitle }}
                        </p>
                    </div>

                    <div class="relative z-10 flex items-center justify-between font-mono text-[7px] uppercase tracking-wider text-white/75 pt-2 border-t border-white/15">
                        <span class="truncate">{{ book.author_name || 'Edición Oficial' }}</span>
                        <BookOpen class="h-3 w-3 text-gold shrink-0" />
                    </div>
                </div>

                <!-- Reflejo de lomo de libro en el borde izquierdo -->
                <div
                    class="pointer-events-none absolute inset-y-0 left-0 w-2 bg-gradient-to-r from-black/35 via-white/15 to-transparent"
                    aria-hidden="true"
                />
            </div>

            <!-- Badge Superior Dinámico según Membresía -->
            <div class="absolute top-3 left-3 right-3 flex items-center justify-between gap-2 z-10">
                <span
                    v-if="hasActiveMembership"
                    class="inline-flex items-center gap-1 rounded-full bg-emerald-500/95 backdrop-blur-md px-2.5 py-0.5 text-[10.5px] font-semibold text-slate-950 shadow-xs"
                >
                    <CheckCircle2 class="h-3 w-3 stroke-[2.3]" />
                    <span>Acceso Completo</span>
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1 rounded-full bg-surface/95 dark:bg-slate-900/90 border border-border backdrop-blur-md px-2.5 py-0.5 text-[10.5px] font-semibold text-foreground shadow-xs"
                >
                    <Sparkles class="h-3 w-3 text-primary" />
                    <span>Muestra gratis · {{ samplePagesCount }} págs.</span>
                </span>

                <span
                    v-if="notesCount > 0"
                    class="inline-flex items-center gap-1 rounded-full bg-surface/95 dark:bg-slate-900/90 border border-border backdrop-blur-md px-2 py-0.5 font-mono text-[10px] font-semibold text-foreground"
                    :title="`${notesCount} apuntes de estudio`"
                >
                    <StickyNote class="h-3 w-3 text-primary" />
                    {{ notesCount }}
                </span>
            </div>

            <!-- Overlay de acción rápida al pasar el cursor -->
            <div
                v-if="isEditionReady"
                class="pointer-events-none absolute inset-0 bg-[#0f2335]/20 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center"
            >
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface text-foreground border border-border font-semibold text-[11px] px-3.5 py-1.5 shadow-lg transform translate-y-1.5 group-hover:translate-y-0 transition-transform duration-200">
                    <Eye class="h-3.5 w-3.5 text-primary" />
                    <span>{{ lastPage > 0 ? `Continuar en pág. ${lastPage}` : 'Abrir lector' }}</span>
                </span>
            </div>

            <!-- Barra de progreso de lectura superpuesta al pie -->
            <div
                v-if="lastPage > 0 && totalPages > 0"
                class="absolute inset-x-0 bottom-0 bg-surface/95 dark:bg-slate-900/95 backdrop-blur-md px-3.5 py-2 border-t border-border z-10"
            >
                <div class="flex items-center justify-between font-mono text-[10px] font-medium text-muted mb-1">
                    <span>AVANCE · PÁG. {{ lastPage }} DE {{ totalPages }}</span>
                    <span class="text-primary font-bold">{{ progressPercent }}%</span>
                </div>
                <div class="h-1 w-full rounded-full bg-border overflow-hidden">
                    <div
                        class="h-full rounded-full bg-accent transition-all duration-300"
                        :style="{ width: `${Math.max(4, progressPercent)}%` }"
                    />
                </div>
            </div>
        </div>

        <!-- 2. Cuerpo de la Ficha Académica -->
        <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between space-y-4">
            <div class="space-y-2.5">
                <!-- Autor / Tiempo estimado -->
                <div class="flex items-center justify-between gap-2 font-mono text-[10px] uppercase tracking-[0.5px] text-muted">
                    <span class="truncate font-medium">{{ book.author_name || 'Editorial Arte Contable' }}</span>
                    <span
                        v-if="estimatedHours"
                        class="inline-flex items-center gap-1 shrink-0"
                    >
                        <Clock class="h-3 w-3" />
                        {{ estimatedHours }}
                    </span>
                </div>

                <!-- Título editorial -->
                <h3
                    class="font-serif text-lg font-medium leading-snug text-foreground truncate group-hover:text-primary transition-colors"
                    :title="book.title"
                >
                    {{ book.title }}
                </h3>

                <!-- Breve descripción del libro (clamp de 2 líneas) -->
                <p class="text-xs text-muted line-clamp-2 leading-relaxed min-h-[2.25rem]">
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
                        class="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground focus:border-primary focus:ring-1 focus:ring-primary"
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
                    <span class="inline-flex items-center gap-1 rounded-[6px] bg-background border border-border px-2 py-0.5 text-[10.5px] font-medium text-foreground">
                        {{ editionLabel }}
                    </span>

                    <span class="inline-flex items-center gap-1 rounded-[6px] bg-background border border-border px-2 py-0.5 text-[10.5px] font-medium text-muted">
                        {{ totalPages }} págs.
                    </span>

                    <span
                        class="inline-flex items-center gap-1 rounded-[6px] px-2 py-0.5 text-[10.5px] font-medium border"
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

                <!-- Mini-vista previa de capítulos del índice -->
                <div
                    v-if="previewChapters.length > 0"
                    class="mt-2 rounded-[9px] bg-background border border-border p-2.5 space-y-1"
                >
                    <p class="font-mono text-[9.5px] font-medium uppercase tracking-[0.8px] text-muted">
                        Contenido temático
                    </p>
                    <div
                        v-for="(chap, cIdx) in previewChapters"
                        :key="cIdx"
                        class="flex items-center justify-between gap-2 text-[11px] text-foreground/85"
                    >
                        <span class="truncate">
                            <span class="font-mono text-[10px] text-muted mr-1">{{ String(cIdx + 1).padStart(2, '0') }}</span>
                            {{ chap.title }}
                        </span>
                        <span class="font-mono text-[10px] text-muted shrink-0">pág. {{ chap.page }}</span>
                    </div>
                </div>
            </div>

            <!-- 3. Vista Previa del Índice Temático + 4. Footer Paywall-Aware -->
            <div class="space-y-2.5 pt-3 border-t border-border">
                <button
                    type="button"
                    class="w-full inline-flex items-center justify-between rounded-lg px-2 py-1 text-xs font-semibold text-muted hover:text-primary hover:bg-background transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    @click="handleOpenToc"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <ListTree class="h-3.5 w-3.5 text-primary" />
                        <span>Ver índice de contenidos</span>
                    </span>
                    <span class="font-mono text-[10.5px] text-primary">
                        {{ tocItemsCount > 0 ? `${tocItemsCount} temas` : 'Explorar' }} &rarr;
                    </span>
                </button>

                <!-- Acción Primaria con Membresía Activa -->
                <div v-if="hasActiveMembership">
                    <button
                        type="button"
                        :disabled="!isEditionReady"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-[9px] bg-primary hover:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none text-primary-foreground font-semibold text-xs px-4 py-2.5 shadow-2xs transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="handleOpenReader"
                    >
                        <BookOpen class="h-4 w-4" />
                        <span>
                            {{
                                lastPage > 0
                                    ? `Continuar lectura · pág. ${lastPage}`
                                    : 'Leer libro completo'
                            }}
                        </span>
                    </button>
                </div>

                <!-- Acciones Paywall-Aware en Plan Gratuito -->
                <div v-else class="space-y-1.5">
                    <button
                        type="button"
                        :disabled="!isEditionReady"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-[9px] bg-primary hover:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none text-primary-foreground font-semibold text-xs px-4 py-2.5 shadow-2xs transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        @click="handleOpenReader"
                    >
                        <Play class="h-3.5 w-3.5 fill-current" />
                        <span>
                            {{
                                lastPage > 0
                                    ? `Continuar · pág. ${lastPage}`
                                    : 'Leer muestra gratis'
                            }}
                        </span>
                    </button>

                    <a
                        :href="membershipUrl"
                        class="w-full inline-flex items-center justify-center gap-1.5 rounded-[9px] border border-border bg-background hover:border-primary/40 hover:text-primary px-3 py-2 text-[11px] font-semibold text-muted transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                        <Lock class="h-3 w-3" />
                        <span>Desbloquear libro completo</span>
                    </a>
                </div>
            </div>
        </div>
    </article>
</template>
