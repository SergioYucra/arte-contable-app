<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import {
    ArrowRight,
    BookMarked,
    BookOpen,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Compass,
    Expand,
    GraduationCap,
    Layers,
    ListTree,
    Lock,
    Maximize2,
    Minimize2,
    Moon,
    Play,
    RefreshCw,
    Search,
    Shrink,
    Sparkles,
    StickyNote,
    Sun,
    Sunset,
    Unlock,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-vue-next';
import BookCard from './books/BookCard.vue';
import BookTocDrawer from './books/BookTocDrawer.vue';
import LibrarySkeleton from './books/LibrarySkeleton.vue';
import BaseToast from './ui/BaseToast.vue';

/**
 * Propiedades del Catálogo de Biblioteca.
 * Soporta simultáneamente:
 * 1) El contrato directo solicitado (`books`, `activeReading`, `userMembership`)
 * 2) El contrato de Isla Blade de LibraryController (`initialBooks`, `initialContinueReading`, `initialMembership`)
 */
const props = defineProps({
    books: {
        type: Array,
        default: null,
    },
    activeReading: {
        type: Object,
        default: null,
    },
    userMembership: {
        type: Object,
        default: null,
    },
    initialBooks: {
        type: Array,
        default: () => [],
    },
    initialContinueReading: {
        type: Object,
        default: null,
    },
    initialMembership: {
        type: Object,
        default: () => ({
            is_active: false,
            has_active_membership: false,
            status_label: 'Muestra Gratuita',
            plan_name: 'Plan Gratuito',
            days_remaining: 0,
            expires_at: null,
        }),
    },
    initialOpenReader: {
        type: Object,
        default: null,
    },
    membershipUrl: {
        type: String,
        default: '/membresia',
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    userName: {
        type: String,
        default: 'Estudiante',
    },
});

// =========================================================================
// NORMALIZACIÓN REACTIVA DE CONTRATOS DE DATOS
// =========================================================================
function normalizeEdition(rawEd, bookId) {
    if (!rawEd || typeof rawEd !== 'object') return null;
    const id = Number(rawEd.id || rawEd.edition_id || 0);
    const totalPages = Number(rawEd.total_pages || 0);
    const samplePages = Number(rawEd.sample_pages_count || 0);
    const lastPage = Number(rawEd.last_page || rawEd.current_page || 0);
    const progressPercent =
        rawEd.progress_percent !== undefined
            ? Number(rawEd.progress_percent)
            : totalPages > 0 && lastPage > 0
              ? Math.min(100, Math.round((lastPage / totalPages) * 100))
              : 0;
    const tocArray = Array.isArray(rawEd.toc)
        ? rawEd.toc
        : Array.isArray(rawEd.toc_json)
          ? rawEd.toc_json
          : [];

    return {
        ...rawEd,
        id,
        book_id: bookId,
        name: rawEd.name || rawEd.edition_name || '1ra Edición',
        edition_name: rawEd.edition_name || rawEd.name || '1ra Edición',
        total_pages: totalPages,
        sample_pages_count: samplePages,
        last_page: lastPage,
        progress_percent: progressPercent,
        toc: tocArray,
        toc_json: tocArray,
        is_ready:
            typeof rawEd.is_ready === 'boolean'
                ? rawEd.is_ready
                : (rawEd.status ? rawEd.status === 'ready' : true) && totalPages > 0,
        is_current: Boolean(rawEd.is_current ?? true),
        notes_count: Number(rawEd.notes_count || 0),
        page_url_template:
            rawEd.page_url_template || `/biblioteca/ediciones/${id}/paginas/__PAGE__`,
        save_progress_url:
            rawEd.save_progress_url || `/biblioteca/ediciones/${id}/progreso`,
    };
}

function normalizeBookList(sourceBooks) {
    if (!Array.isArray(sourceBooks)) return [];
    return sourceBooks.map((b) => {
        const rawEditions = Array.isArray(b.editions)
            ? b.editions
            : b.edition
              ? [b.edition]
              : [];
        const normalizedEditions = rawEditions
            .map((ed) => normalizeEdition(ed, b.id))
            .filter(Boolean);

        return {
            ...b,
            editions: normalizedEditions,
            edition: normalizedEditions[0] || null,
        };
    });
}

function normalizeActiveReading(rawReading) {
    if (!rawReading || typeof rawReading !== 'object') return null;
    const currentPage = Number(rawReading.current_page ?? rawReading.last_page ?? 0);
    const totalPages = Number(rawReading.total_pages ?? 0);
    if (currentPage <= 0) return null;

    const percentage =
        rawReading.percentage !== undefined
            ? Number(rawReading.percentage)
            : rawReading.progress_percent !== undefined
              ? Number(rawReading.progress_percent)
              : totalPages > 0
                ? Math.min(100, Math.round((currentPage / totalPages) * 100))
                : 0;

    return {
        ...rawReading,
        book_id: rawReading.book_id || null,
        book_title: rawReading.book_title || rawReading.title || 'Libro en curso',
        edition_id: Number(rawReading.edition_id || 0),
        edition_name: rawReading.edition_name || rawReading.edition || 'Edición Activa',
        current_page: currentPage,
        last_page: currentPage,
        total_pages: totalPages,
        percentage,
        progress_percent: percentage,
        cover_url: rawReading.cover_url || null,
        current_chapter: rawReading.current_chapter || null,
        updated_at_human: rawReading.updated_at_human || 'Reciente',
    };
}

function normalizeMembership(rawMembership) {
    const src = rawMembership || {};
    const isActive = Boolean(src.has_active_membership ?? src.is_active ?? false);
    return {
        ...src,
        is_active: isActive,
        has_active_membership: isActive,
        plan_name: src.plan_name || (isActive ? 'Membresía Activa' : 'Plan Gratuito'),
        status_label: src.status_label || (isActive ? 'Acceso Completo' : 'Muestra Gratuita'),
        days_remaining: Number(src.days_remaining || 0),
        expires_at: src.expires_at || null,
    };
}

// =========================================================================
// ESTADO REACTIVO PRINCIPAL
// =========================================================================
const isLoadingState = ref(Boolean(props.isLoading));
const catalogBooks = ref(
    normalizeBookList(props.books !== null ? props.books : props.initialBooks)
);
const continueReadingState = ref(
    normalizeActiveReading(
        props.activeReading !== null ? props.activeReading : props.initialContinueReading
    )
);
const membershipState = ref(
    normalizeMembership(
        props.userMembership !== null ? props.userMembership : props.initialMembership
    )
);

const hasActiveMembership = computed(() => Boolean(membershipState.value.has_active_membership));

// Mapa reactivo de edición seleccionada por libro: { [bookId]: editionId }
const selectedEditionIds = ref({});
function initSelectedEditions() {
    catalogBooks.value.forEach((book) => {
        const preferred =
            book.editions.find((e) => e.is_ready && e.is_current) ||
            book.editions.find((e) => e.is_ready) ||
            book.editions[0];
        if (preferred) {
            selectedEditionIds.value[book.id] = preferred.id;
        }
    });
}
initSelectedEditions();

watch(
    () => [props.books, props.initialBooks],
    () => {
        catalogBooks.value = normalizeBookList(
            props.books !== null ? props.books : props.initialBooks
        );
        initSelectedEditions();
    }
);

watch(
    () => props.isLoading,
    (val) => {
        isLoadingState.value = Boolean(val);
    }
);

// Búsqueda y filtros del catálogo
const searchQuery = ref('');
const catalogSearchRef = ref(null);
const activeFilter = ref('all'); // 'all' | 'in_progress' | 'with_notes'

// Slide-over Drawer del Índice de Contenidos (TOC)
const isTocDrawerOpen = ref(false);
const tocDrawerBook = ref(null);
const tocDrawerEdition = ref(null);

// Visor de Lectura Protegido Integrado (Reader Studio)
const isReaderOpen = ref(false);
const readerBook = ref(null);
const readerEdition = ref(null);
const currentPage = ref(1);
const zoomLevel = ref(100);
const isFitToPage = ref(true);
const readerBackdropMode = ref('night'); // 'night' | 'sepia' | 'day'
const isFullscreen = ref(false);
const readerScrollContainer = ref(null);
const isReaderTocOpen = ref(true);
const isPageLoading = ref(false);
const pageLoadError = ref(false);
const isSavingProgress = ref(false);

// Notificaciones Toast
const toast = ref({
    show: false,
    type: 'success',
    title: '',
    message: '',
});

// =========================================================================
// COMPUTED PROPERTIES Y HELPERS DE FILTRADO
// =========================================================================
function getActiveEdition(book) {
    if (!book || !Array.isArray(book.editions) || book.editions.length === 0) {
        return null;
    }
    const selectedId = selectedEditionIds.value[book.id];
    return book.editions.find((e) => Number(e.id) === Number(selectedId)) || book.editions[0];
}

function handleSelectEdition({ bookId, editionId }) {
    selectedEditionIds.value[bookId] = Number(editionId);
}

const totalBooksCount = computed(() => catalogBooks.value.length);

const inProgressCount = computed(
    () =>
        catalogBooks.value.filter((b) => {
            const ed = getActiveEdition(b);
            return ed && ed.last_page > 0;
        }).length
);

const withNotesCount = computed(
    () =>
        catalogBooks.value.filter((b) => {
            const ed = getActiveEdition(b);
            return ed && ed.notes_count > 0;
        }).length
);

const filteredBooks = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    return catalogBooks.value.filter((book) => {
        const activeEd = getActiveEdition(book);

        if (activeFilter.value === 'in_progress' && (!activeEd || activeEd.last_page <= 0)) {
            return false;
        }
        if (activeFilter.value === 'with_notes' && (!activeEd || activeEd.notes_count <= 0)) {
            return false;
        }

        if (!q) {
            return true;
        }

        const inTitle = (book.title || '').toLowerCase().includes(q);
        const inSubtitle = (book.subtitle || '').toLowerCase().includes(q);
        const inAuthor = (book.author_name || '').toLowerCase().includes(q);
        const inDesc = (book.description || '').toLowerCase().includes(q);
        const inToc =
            activeEd &&
            Array.isArray(activeEd.toc) &&
            activeEd.toc.some((ch) => (ch.title || '').toLowerCase().includes(q));

        return inTitle || inSubtitle || inAuthor || inDesc || inToc;
    });
});

function buildReaderHref(editionId, page = 1) {
    const safePage = Math.max(1, Number(page || 1));
    return `/leer/${editionId}?page=${safePage}`;
}

const heroRemainingMinutes = computed(() => {
    if (!continueReadingState.value) return 0;
    const remainingPages = Math.max(
        0,
        continueReadingState.value.total_pages - continueReadingState.value.current_page
    );
    return Math.max(5, Math.round(remainingPages * 2));
});

// =========================================================================
// ACCIONES DEL DRAWER DE ÍNDICE (TOC)
// =========================================================================
function openTocDrawer({ book, edition }) {
    tocDrawerBook.value = book;
    tocDrawerEdition.value = edition || getActiveEdition(book);
    isTocDrawerOpen.value = true;
}

function openHeroTocDrawer() {
    if (!continueReadingState.value) return;
    const targetEdId = Number(continueReadingState.value.edition_id);
    for (const book of catalogBooks.value) {
        const foundEd = book.editions.find((e) => Number(e.id) === targetEdId);
        if (foundEd) {
            openTocDrawer({ book, edition: foundEd });
            return;
        }
    }
}

// =========================================================================
// VISOR INTEGRADO DE ESTUDIO Y GUARDADO DE PROGRESO (ReadingState)
// =========================================================================
const maxNavigablePage = computed(() => {
    if (!readerEdition.value) return 1;
    const total = Math.max(1, Number(readerEdition.value.total_pages || 1));
    if (hasActiveMembership.value) {
        return total;
    }
    const sample = Number(readerEdition.value.sample_pages_count || 0);
    // Permite avanzar hasta sample + 1 para presentar la cortina de Paywall
    return Math.min(total, sample + 1);
});

const isPaywallGatePage = computed(() => {
    if (!readerEdition.value || hasActiveMembership.value) return false;
    const sample = Number(readerEdition.value.sample_pages_count || 0);
    return currentPage.value > sample;
});

const currentReaderPageUrl = computed(() => {
    if (!readerEdition.value || isPaywallGatePage.value) return '';
    const template =
        readerEdition.value.page_url_template ||
        `/biblioteca/ediciones/${readerEdition.value.id}/paginas/__PAGE__`;
    return template.replace('__PAGE__', String(currentPage.value));
});

const readerProgressPercent = computed(() => {
    if (!readerEdition.value) return 0;
    const total = Math.max(1, Number(readerEdition.value.total_pages || 1));
    const safePage = Math.min(total, currentPage.value);
    return Math.min(100, Math.round((safePage / total) * 100));
});

const activeReaderChapter = computed(() => {
    const toc = readerEdition.value?.toc || [];
    if (!Array.isArray(toc) || toc.length === 0) return null;
    let matched = toc[0];
    for (const entry of toc) {
        if (Number(entry.page || 1) <= currentPage.value) {
            matched = entry;
        }
    }
    return matched;
});

function isChapterActiveInReader(chapter, index, tocArray) {
    const startPage = Number(chapter.page || 1);
    const nextChapter = tocArray[index + 1];
    const endPage = nextChapter ? Number(nextChapter.page || 999999) - 1 : 999999;
    return currentPage.value >= startPage && currentPage.value <= endPage;
}

function isChapterCompletedInReader(chapter, index, tocArray) {
    const nextChapter = tocArray[index + 1];
    if (!nextChapter) return false;
    return currentPage.value >= Number(nextChapter.page || 999999);
}

function isReaderPageUnlocked(page) {
    if (hasActiveMembership.value) return true;
    const sample = Number(readerEdition.value?.sample_pages_count || 0);
    return Number(page) <= sample;
}

function resetReaderScroll() {
    if (readerScrollContainer.value) {
        readerScrollContainer.value.scrollTop = 0;
        readerScrollContainer.value.scrollLeft = 0;
    }
}

function openReader({ book, edition, page = 1 }) {
    const targetEdition = edition || getActiveEdition(book);
    if (!targetEdition) return;

    readerBook.value = book;
    readerEdition.value = targetEdition;

    const total = Math.max(1, Number(targetEdition.total_pages || 1));
    const requestedPage = Math.max(1, Math.min(total, Number(page || 1)));
    currentPage.value = Math.min(requestedPage, maxNavigablePage.value);
    zoomLevel.value = 100;
    isFitToPage.value = true;
    // En escritorio abrimos el índice lateral si la edición tiene capítulos
    isReaderTocOpen.value =
        typeof window !== 'undefined' &&
        window.innerWidth >= 1024 &&
        Array.isArray(targetEdition.toc) &&
        targetEdition.toc.length > 0;
    pageLoadError.value = false;
    isPageLoading.value = !isPaywallGatePage.value;
    isReaderOpen.value = true;
    document.body.classList.add('overflow-hidden');
    resetReaderScroll();

    if (!isPaywallGatePage.value) {
        persistReadingProgress(targetEdition, currentPage.value);
    }
}

function handleHeroContinueClick() {
    if (!continueReadingState.value) return;
    const targetEditionId = Number(continueReadingState.value.edition_id);
    const targetPage = Number(
        continueReadingState.value.current_page || continueReadingState.value.last_page || 1
    );

    for (const book of catalogBooks.value) {
        const foundEd = book.editions.find((e) => Number(e.id) === targetEditionId);
        if (foundEd) {
            openReader({ book, edition: foundEd, page: targetPage });
            return;
        }
    }

    window.location.href = buildReaderHref(targetEditionId, targetPage);
}

function closeReader() {
    if (document.fullscreenElement) {
        document.exitFullscreen?.().catch(() => {});
    }
    isFullscreen.value = false;
    isReaderOpen.value = false;
    isReaderTocOpen.value = false;
    document.body.classList.remove('overflow-hidden');
}

function goToReaderPage(targetPage) {
    if (!readerEdition.value) return;
    const clamped = Math.max(1, Math.min(maxNavigablePage.value, Number(targetPage || 1)));
    if (clamped === currentPage.value) return;

    currentPage.value = clamped;
    pageLoadError.value = false;
    isPageLoading.value = !isPaywallGatePage.value;
    resetReaderScroll();

    if (!isPaywallGatePage.value) {
        persistReadingProgress(readerEdition.value, clamped);
    }
}

async function persistReadingProgress(edition, pageNumber) {
    if (!edition || !edition.save_progress_url) return;
    const sample = Number(edition.sample_pages_count || 0);
    if (!hasActiveMembership.value && pageNumber > sample) return;

    isSavingProgress.value = true;
    try {
        const { data } = await axios.post(edition.save_progress_url, {
            last_page: pageNumber,
        });

        if (data?.success && data.reading_state) {
            const updatedState = data.reading_state;
            edition.last_page = updatedState.last_page;
            edition.progress_percent = updatedState.progress_percent;
            edition.current_chapter = updatedState.current_chapter;
            edition.updated_at_human = updatedState.updated_at_human || 'Hace unos segundos';

            const parentBook = catalogBooks.value.find(
                (b) => Number(b.id) === Number(edition.book_id)
            );

            continueReadingState.value = normalizeActiveReading({
                book_id: parentBook?.id || edition.book_id,
                book_title: parentBook?.title || readerBook.value?.title || 'Lectura actual',
                cover_url: parentBook?.cover_url || readerBook.value?.cover_url || null,
                edition_id: edition.id,
                edition_name: edition.edition_name || edition.name,
                current_page: updatedState.last_page,
                last_page: updatedState.last_page,
                total_pages: edition.total_pages,
                percentage: updatedState.progress_percent,
                progress_percent: updatedState.progress_percent,
                current_chapter: updatedState.current_chapter,
                updated_at_human: updatedState.updated_at_human || 'Hace unos segundos',
            });
        }
    } catch {
        // Fallo silencioso para no interrumpir la lectura
    } finally {
        isSavingProgress.value = false;
    }
}

function adjustZoom(delta) {
    isFitToPage.value = false;
    zoomLevel.value = Math.min(180, Math.max(70, zoomLevel.value + delta));
}

function setReaderFitMode(fitPage) {
    isFitToPage.value = Boolean(fitPage);
    if (isFitToPage.value) {
        zoomLevel.value = 100;
    } else {
        zoomLevel.value = 105;
    }
    resetReaderScroll();
}

async function toggleFullscreen() {
    try {
        if (!document.fullscreenElement) {
            await document.documentElement.requestFullscreen();
            isFullscreen.value = true;
        } else {
            await document.exitFullscreen();
            isFullscreen.value = false;
        }
    } catch {
        // Ignorar si el navegador restringe fullscreen
    }
}

function handleReaderKeydown(event) {
    if (!isReaderOpen.value && !isTocDrawerOpen.value && (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        catalogSearchRef.value?.focus();
        return;
    }

    if (!isReaderOpen.value) return;
    if (event.target?.tagName === 'INPUT') return;

    if (event.key === 'Escape') {
        closeReader();
    } else if (event.key === 'ArrowRight') {
        goToReaderPage(currentPage.value + 1);
    } else if (event.key === 'ArrowLeft') {
        goToReaderPage(currentPage.value - 1);
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleReaderKeydown);

    const autoOpen = props.initialOpenReader;
    if (autoOpen && autoOpen.edition_id) {
        const targetEdId = Number(autoOpen.edition_id);
        const targetPage = Math.max(1, Number(autoOpen.page || 1));
        for (const book of catalogBooks.value) {
            const foundEd = book.editions.find((e) => Number(e.id) === targetEdId);
            if (foundEd) {
                selectedEditionIds.value[book.id] = foundEd.id;
                openReader({ book, edition: foundEd, page: targetPage });
                break;
            }
        }
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleReaderKeydown);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <div class="space-y-7 pb-12">
        <!-- ===============================================================
             ESTADO DE CARGA (SKELETON LOADER)
             =============================================================== -->
        <LibrarySkeleton
            v-if="isLoadingState"
            :cards-count="4"
            :show-hero="Boolean(continueReadingState)"
        />

        <template v-else>
            <!-- ===========================================================
                 A. SECCIÓN HERO: "CONTINUAR LECTURA" (EN PROGRESO)
                 Visible solo si el estudiante tiene un ReadingState activo
                 =========================================================== -->
            <section
                v-if="continueReadingState"
                aria-labelledby="continue-reading-heading"
                class="relative overflow-hidden rounded-[14px] border border-[#175c80]/40 bg-[linear-gradient(112deg,#0f2335_0%,#143c56_62%,#175c80_100%)] text-slate-100 p-5 sm:p-6 shadow-[0_13px_30px_rgba(15,35,53,0.12)]"
            >
                <div
                    class="pointer-events-none absolute -top-44 right-24 h-80 w-80 rounded-full border border-[#14b8a6]/15 shadow-[0_0_0_34px_rgba(20,184,166,0.06),0_0_0_75px_rgba(255,255,255,0.025)]"
                    aria-hidden="true"
                />

                <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <!-- Bloque Izquierdo: Portada con relieve + Metadatos + Barra de Progreso -->
                    <div class="flex flex-col sm:flex-row sm:items-center gap-5 min-w-0 flex-1">
                        <!-- Thumbnail de la portada del libro actual con efecto lomo -->
                        <div
                            class="relative h-36 w-[104px] shrink-0 rounded-[9px] overflow-hidden border border-white/15 bg-[#0f2335] shadow-xl shadow-black/45 cursor-pointer group"
                            @click="handleHeroContinueClick"
                        >
                            <img
                                v-if="continueReadingState.cover_url"
                                :src="continueReadingState.cover_url"
                                :alt="`Portada de ${continueReadingState.book_title}`"
                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                            />
                            <div
                                v-else
                                class="h-full w-full flex flex-col items-center justify-center p-2.5 text-center bg-[#132f46] text-slate-100"
                            >
                                <BookOpen class="h-6 w-6 text-[#78dacc] mb-1.5" />
                                <span class="font-serif text-[11px] font-medium line-clamp-3 leading-tight">
                                    {{ continueReadingState.book_title }}
                                </span>
                            </div>
                            <div class="pointer-events-none absolute inset-y-0 left-0 w-2 bg-gradient-to-r from-black/45 via-white/10 to-transparent" />
                        </div>

                        <!-- Metadatos y Barra de Progreso -->
                        <div class="min-w-0 flex-1 space-y-2.5">
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-[#bfd6df]">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#14b8a6]/20 border border-[#14b8a6]/35 px-2.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-wider text-[#78dacc]">
                                    <span class="h-1.5 w-1.5 rounded-full bg-[#14b8a6]" />
                                    <span>En progreso</span>
                                </span>

                                <span class="font-mono text-[10px] uppercase tracking-wider text-[#bfd6df]">
                                    {{ continueReadingState.edition_name }}
                                </span>

                                <span
                                    v-if="continueReadingState.updated_at_human"
                                    class="inline-flex items-center gap-1 text-[#9bb9c8]"
                                >
                                    <span>·</span>
                                    <Clock class="h-3 w-3" />
                                    <span>Última sesión: {{ continueReadingState.updated_at_human }}</span>
                                </span>
                            </div>

                            <div>
                                <p class="font-serif text-xl sm:text-2xl font-medium tracking-[-0.02em] text-white leading-tight">
                                    Continúa donde lo dejaste.
                                </p>
                                <h2
                                    id="continue-reading-heading"
                                    class="text-sm sm:text-base font-semibold text-[#e9f3f7] truncate mt-1"
                                    :title="continueReadingState.book_title"
                                >
                                    {{ continueReadingState.book_title }}
                                </h2>
                                <p
                                    v-if="continueReadingState.current_chapter"
                                    class="text-xs text-[#bfd6df] truncate mt-0.5"
                                >
                                    Capítulo actual: <strong class="text-white font-semibold">{{ continueReadingState.current_chapter }}</strong>
                                </p>
                            </div>

                            <!-- Barra de progreso interactiva con etiqueta Pág. X de Y (Z%) -->
                            <div class="max-w-xl space-y-1.5 pt-1">
                                <div class="h-1.5 w-full rounded-full bg-white/15 overflow-hidden">
                                    <div
                                        class="h-full rounded-full bg-[#14b8a6] transition-all duration-500 ease-out"
                                        :style="{ width: `${Math.max(3, continueReadingState.percentage)}%` }"
                                    />
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                                    <span class="font-medium text-[#d8e7ed]">
                                        Pág. <strong class="text-white">{{ continueReadingState.current_page }}</strong> de {{ continueReadingState.total_pages }}
                                        <span class="text-[#78dacc] font-bold ml-1">({{ continueReadingState.percentage }}%)</span>
                                    </span>
                                    <span class="font-mono text-[10px] text-[#9bb9c8]">
                                        {{
                                            Math.max(
                                                0,
                                                continueReadingState.total_pages - continueReadingState.current_page
                                            )
                                        }}
                                        págs. restantes · ~{{ heroRemainingMinutes }} min
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CTA de Acción Primaria Destacado + Acceso al Índice -->
                    <div class="flex flex-col sm:flex-row lg:flex-col items-stretch sm:items-center lg:items-stretch gap-2 shrink-0 lg:w-48">
                        <a
                            :href="buildReaderHref(continueReadingState.edition_id, continueReadingState.current_page)"
                            class="inline-flex items-center justify-center gap-2 rounded-[9px] bg-white hover:bg-[#f1f6f8] text-[#0f2335] font-semibold text-xs sm:text-[13px] px-4 py-2.5 shadow-sm transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#14b8a6]"
                            @click.prevent="handleHeroContinueClick"
                        >
                            <Play class="h-3.5 w-3.5 fill-current" />
                            <span>Continuar lectura</span>
                            <ArrowRight class="h-3.5 w-3.5" />
                        </a>

                        <button
                            type="button"
                            class="inline-flex items-center justify-center gap-1.5 rounded-[9px] border border-white/25 bg-white/10 hover:bg-white/15 text-white font-medium text-xs px-4 py-2 transition-colors"
                            @click="openHeroTocDrawer"
                        >
                            <ListTree class="h-3.5 w-3.5 text-[#78dacc]" />
                            <span>Ver índice del libro</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ===========================================================
                 BARRA DE CONTROL: BUSCADOR Y FILTROS DE CATÁLOGO
                 =========================================================== -->
            <section
                aria-label="Herramientas de filtrado del catálogo"
                class="space-y-4 pt-1"
            >
                <div class="flex flex-col-reverse lg:flex-row lg:items-center justify-between gap-3 border-b border-border pb-0">
                    <div class="flex items-center gap-5 overflow-x-auto no-scrollbar" role="tablist" aria-label="Filtrar libros">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="activeFilter === 'all'"
                            class="relative inline-flex shrink-0 items-center gap-2 pb-3 text-xs sm:text-[13px] font-medium transition-colors border-b-2 -mb-px"
                            :class="
                                activeFilter === 'all'
                                    ? 'border-primary text-foreground font-semibold'
                                    : 'border-transparent text-muted hover:text-foreground'
                            "
                            @click="activeFilter = 'all'"
                        >
                            <span>Todos</span>
                            <span
                                class="rounded-full px-1.5 py-0.5 font-mono text-[10px]"
                                :class="
                                    activeFilter === 'all'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-surface border border-border text-muted'
                                "
                            >
                                {{ totalBooksCount }}
                            </span>
                        </button>

                        <button
                            type="button"
                            role="tab"
                            :aria-selected="activeFilter === 'in_progress'"
                            class="relative inline-flex shrink-0 items-center gap-2 pb-3 text-xs sm:text-[13px] font-medium transition-colors border-b-2 -mb-px"
                            :class="
                                activeFilter === 'in_progress'
                                    ? 'border-primary text-foreground font-semibold'
                                    : 'border-transparent text-muted hover:text-foreground'
                            "
                            @click="activeFilter = 'in_progress'"
                        >
                            <span>En lectura</span>
                            <span
                                class="rounded-full px-1.5 py-0.5 font-mono text-[10px]"
                                :class="
                                    activeFilter === 'in_progress'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-surface border border-border text-muted'
                                "
                            >
                                {{ inProgressCount }}
                            </span>
                        </button>

                        <button
                            v-if="withNotesCount > 0"
                            type="button"
                            role="tab"
                            :aria-selected="activeFilter === 'with_notes'"
                            class="relative inline-flex shrink-0 items-center gap-2 pb-3 text-xs sm:text-[13px] font-medium transition-colors border-b-2 -mb-px"
                            :class="
                                activeFilter === 'with_notes'
                                    ? 'border-primary text-foreground font-semibold'
                                    : 'border-transparent text-muted hover:text-foreground'
                            "
                            @click="activeFilter = 'with_notes'"
                        >
                            <span>Con apuntes</span>
                            <span
                                class="rounded-full px-1.5 py-0.5 font-mono text-[10px]"
                                :class="
                                    activeFilter === 'with_notes'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-surface border border-border text-muted'
                                "
                            >
                                {{ withNotesCount }}
                            </span>
                        </button>
                    </div>

                    <!-- Buscador por título, tema o capítulo -->
                    <div class="w-full lg:w-80 pb-2.5 lg:pb-2">
                        <label class="relative flex items-center">
                            <Search class="h-4 w-4 text-muted absolute left-3 pointer-events-none" />
                            <input
                                ref="catalogSearchRef"
                                v-model="searchQuery"
                                type="search"
                                placeholder="Buscar libro, edición o tema..."
                                aria-label="Buscar libro, edición o tema del índice"
                                class="w-full rounded-[9px] border border-border bg-surface pl-9 pr-14 py-2 text-xs text-foreground placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none transition"
                            />
                            <kbd class="hidden sm:inline-flex items-center rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px] text-muted absolute right-2.5 pointer-events-none">
                                ⌘ K
                            </kbd>
                        </label>
                    </div>
                </div>
            </section>

            <!-- ===========================================================
                 B. GRID DEL CATÁLOGO DE LIBROS PUBLICADOS
                 =========================================================== -->
            <section
                v-if="filteredBooks.length > 0"
                aria-label="Catálogo de libros publicados"
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
            >
                <BookCard
                    v-for="book in filteredBooks"
                    :key="book.id"
                    :book="book"
                    :selected-edition-id="selectedEditionIds[book.id]"
                    :has-active-membership="hasActiveMembership"
                    :membership-url="membershipUrl"
                    @select-edition="handleSelectEdition"
                    @open-toc="openTocDrawer"
                    @open-reader="openReader"
                />
            </section>

            <!-- ===========================================================
                 C. EMPTY STATE PULIDO
                 =========================================================== -->
            <section
                v-else
                aria-label="Catálogo vacío"
                class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/50 p-10 sm:p-14 text-center max-w-2xl mx-auto my-4 shadow-xs"
            >
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sky-500">
                    <BookOpen class="h-8 w-8" />
                </div>

                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">
                    {{
                        catalogBooks.length === 0
                            ? 'Aún no hay libros publicados en el catálogo'
                            : 'No encontramos obras que coincidan con tu filtro'
                    }}
                </h3>

                <p class="mt-2 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed max-w-md mx-auto">
                    {{
                        catalogBooks.length === 0
                            ? 'El equipo editorial está preparando las próximas ediciones oficiales de estudio. En cuanto sean publicadas aparecerán automáticamente en tu biblioteca.'
                            : 'Intenta limpiar los términos de búsqueda o cambia la pestaña de filtro para explorar todas las ediciones disponibles.'
                    }}
                </p>

                <div
                    v-if="catalogBooks.length > 0 && (searchQuery || activeFilter !== 'all')"
                    class="mt-6 flex items-center justify-center gap-3"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-950 font-semibold text-xs sm:text-sm px-4 py-2.5 hover:opacity-90 transition"
                        @click="
                            searchQuery = '';
                            activeFilter = 'all';
                        "
                    >
                        <RefreshCw class="h-3.5 w-3.5" />
                        <span>Mostrar todos los libros ({{ catalogBooks.length }})</span>
                    </button>
                </div>
            </section>
        </template>

        <!-- ===============================================================
             SLIDE-OVER DRAWER: ÍNDICE TEMÁTICO (toc_json)
             =============================================================== -->
        <BookTocDrawer
            v-model="isTocDrawerOpen"
            :book="tocDrawerBook"
            :edition="tocDrawerEdition"
            :has-active-membership="hasActiveMembership"
            :membership-url="membershipUrl"
            @open-reader="openReader"
        />

        <!-- ===============================================================
             VISOR EDUCATIVO DE ESTUDIO (Estilo Perlego / O'Reilly / Kindle Web)
             =============================================================== -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="isReaderOpen && readerBook && readerEdition"
                    class="fixed inset-0 z-50 bg-slate-950 text-slate-100 flex flex-col select-none"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="`Modo Estudio: ${readerBook.title}`"
                    @contextmenu.prevent
                >
                    <!-- Barra superior continua de progreso del libro -->
                    <div class="h-1 w-full bg-slate-800 shrink-0 overflow-hidden">
                        <div
                            class="h-full bg-sky-400 transition-all duration-300"
                            :style="{ width: `${readerProgressPercent}%` }"
                        />
                    </div>

                    <!-- Cabecera Profesional de Estudio -->
                    <header class="h-14 sm:h-16 px-3 sm:px-5 border-b border-slate-800/90 bg-slate-900/95 backdrop-blur-md flex items-center justify-between gap-2 sm:gap-4 shrink-0">
                        <!-- Izquierda: Volver + Título + Capítulo Actual -->
                        <div class="flex items-center gap-2.5 sm:gap-3.5 min-w-0">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-slate-700/80 bg-slate-800/90 hover:bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 transition-colors shrink-0"
                                @click="closeReader"
                            >
                                <ChevronLeft class="h-4 w-4" />
                                <span class="hidden sm:inline">Biblioteca</span>
                            </button>

                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition-colors shrink-0"
                                :class="
                                    isReaderTocOpen
                                        ? 'border-sky-500/40 bg-sky-500/15 text-sky-300'
                                        : 'border-slate-700/80 bg-slate-800/90 hover:bg-slate-800 text-slate-200'
                                "
                                title="Mostrar u ocultar temario del libro"
                                @click="isReaderTocOpen = !isReaderTocOpen"
                            >
                                <ListTree class="h-4 w-4 text-sky-400" />
                                <span class="hidden md:inline">Temario</span>
                            </button>

                            <div class="min-w-0 hidden sm:block">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-xs sm:text-sm font-bold text-white truncate max-w-[220px] xl:max-w-xs">
                                        {{ readerBook.title }}
                                    </h3>
                                    <span class="rounded-md bg-slate-800 border border-slate-700 px-1.5 py-0.5 text-[10px] font-medium text-slate-300 shrink-0">
                                        {{ readerEdition.edition_name }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-400 truncate">
                                    <template v-if="activeReaderChapter">
                                        {{ activeReaderChapter.title }}
                                    </template>
                                    <template v-else>
                                        Lectura editorial protegida
                                    </template>
                                </p>
                            </div>
                        </div>

                        <!-- Centro: Salto directo de página + Modo de Ajuste + Zoom -->
                        <div class="flex items-center gap-2">
                            <!-- Input directo de página -->
                            <div class="flex items-center gap-1.5 rounded-xl border border-slate-800 bg-slate-950 px-2.5 py-1 text-xs">
                                <span class="text-slate-400 hidden xs:inline">Pág.</span>
                                <input
                                    :value="Math.min(currentPage, readerEdition.total_pages)"
                                    type="number"
                                    min="1"
                                    :max="maxNavigablePage"
                                    aria-label="Ir a la página"
                                    class="w-12 rounded-md border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-center text-xs font-bold text-white focus:border-sky-400 focus:outline-none"
                                    @change="goToReaderPage($event.target.value)"
                                />
                                <span class="text-slate-400">
                                    / {{ readerEdition.total_pages }}
                                </span>
                            </div>

                            <!-- Botones de modo de encaje (Página entera vs Ancho de lectura) y Zoom -->
                            <div class="hidden lg:flex items-center gap-1 rounded-xl border border-slate-800 bg-slate-950 p-1">
                                <button
                                    type="button"
                                    class="rounded-lg px-2.5 py-1 text-[11px] font-semibold transition-colors"
                                    :class="
                                        isFitToPage
                                            ? 'bg-sky-500 text-slate-950 shadow-xs'
                                            : 'text-slate-400 hover:text-white'
                                    "
                                    title="Ver la hoja completa sin recortes"
                                    @click="setReaderFitMode(true)"
                                >
                                    Página entera
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg px-2.5 py-1 text-[11px] font-semibold transition-colors"
                                    :class="
                                        !isFitToPage
                                            ? 'bg-sky-500 text-slate-950 shadow-xs'
                                            : 'text-slate-400 hover:text-white'
                                    "
                                    title="Expandir al ancho para lectura detallada"
                                    @click="setReaderFitMode(false)"
                                >
                                    Ancho lectura
                                </button>

                                <div class="h-4 w-px bg-slate-800 mx-1" />

                                <button
                                    type="button"
                                    class="p-1 text-slate-400 hover:text-white disabled:opacity-40"
                                    :disabled="!isFitToPage && zoomLevel <= 70"
                                    aria-label="Reducir zoom"
                                    @click="adjustZoom(-15)"
                                >
                                    <ZoomOut class="h-3.5 w-3.5" />
                                </button>
                                <span class="px-1.5 text-[11px] font-mono text-slate-300 min-w-[3rem] text-center">
                                    {{ isFitToPage ? 'Auto' : `${zoomLevel}%` }}
                                </span>
                                <button
                                    type="button"
                                    class="p-1 text-slate-400 hover:text-white disabled:opacity-40"
                                    :disabled="!isFitToPage && zoomLevel >= 180"
                                    aria-label="Aumentar zoom"
                                    @click="adjustZoom(15)"
                                >
                                    <ZoomIn class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>

                        <!-- Derecha: Ambiente de lectura (Noche / Sepia / Claro) + Fullscreen + Cerrar -->
                        <div class="flex items-center gap-1.5 sm:gap-2">
                            <!-- Selector de ambiente visual -->
                            <div
                                class="hidden md:flex items-center gap-0.5 rounded-xl border border-slate-800 bg-slate-950 p-1"
                                role="group"
                                aria-label="Ambiente de lectura"
                            >
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-xs transition-colors"
                                    :class="
                                        readerBackdropMode === 'night'
                                            ? 'bg-slate-800 text-sky-400'
                                            : 'text-slate-400 hover:text-slate-200'
                                    "
                                    title="Ambiente Noche"
                                    @click="readerBackdropMode = 'night'"
                                >
                                    <Moon class="h-3.5 w-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-xs transition-colors"
                                    :class="
                                        readerBackdropMode === 'sepia'
                                            ? 'bg-amber-500/20 text-amber-300'
                                            : 'text-slate-400 hover:text-slate-200'
                                    "
                                    title="Ambiente Cálido / Sepia (Descanso visual)"
                                    @click="readerBackdropMode = 'sepia'"
                                >
                                    <Sunset class="h-3.5 w-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-xs transition-colors"
                                    :class="
                                        readerBackdropMode === 'day'
                                            ? 'bg-slate-200 text-slate-900'
                                            : 'text-slate-400 hover:text-slate-200'
                                    "
                                    title="Ambiente Estudio Claro"
                                    @click="readerBackdropMode = 'day'"
                                >
                                    <Sun class="h-3.5 w-3.5" />
                                </button>
                            </div>

                            <button
                                type="button"
                                class="hidden sm:inline-flex p-2 rounded-xl border border-slate-800 bg-slate-900 text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                                :title="isFullscreen ? 'Salir de pantalla completa' : 'Pantalla completa'"
                                @click="toggleFullscreen"
                            >
                                <Shrink v-if="isFullscreen" class="h-4 w-4" />
                                <Expand v-else class="h-4 w-4" />
                            </button>

                            <button
                                type="button"
                                class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                                aria-label="Cerrar lector"
                                @click="closeReader"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                    </header>

                    <!-- Cuerpo Principal del Estudio de Lectura -->
                    <div class="flex-1 flex overflow-hidden relative">
                        <!-- Sidebar de Módulos / Capítulos (Estilo Curso Platzi / Perlego) -->
                        <aside
                            v-if="isReaderTocOpen"
                            class="w-72 sm:w-80 border-r border-slate-800/90 bg-slate-900/95 flex flex-col shrink-0 z-20"
                        >
                            <div class="p-4 border-b border-slate-800 flex items-center justify-between gap-2">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider text-sky-400">
                                        Contenido de la obra
                                    </p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">
                                        Progreso general: {{ readerProgressPercent }}%
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
                                    @click="isReaderTocOpen = false"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="flex-1 overflow-y-auto p-3 space-y-1.5">
                                <template v-if="Array.isArray(readerEdition.toc) && readerEdition.toc.length > 0">
                                    <button
                                        v-for="(ch, idx) in readerEdition.toc"
                                        :key="idx"
                                        type="button"
                                        class="w-full text-left rounded-xl p-3 text-xs flex items-start justify-between gap-2.5 border transition-all"
                                        :class="
                                            isChapterActiveInReader(ch, idx, readerEdition.toc)
                                                ? 'bg-sky-500/15 border-sky-500/40 text-white shadow-sm'
                                                : 'bg-slate-950/40 border-transparent text-slate-300 hover:bg-slate-800/70 hover:border-slate-800'
                                        "
                                        @click="goToReaderPage(ch.page || 1)"
                                    >
                                        <div class="flex items-start gap-2.5 min-w-0">
                                            <span
                                                class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold"
                                                :class="
                                                    isChapterCompletedInReader(ch, idx, readerEdition.toc)
                                                        ? 'bg-emerald-500/20 text-emerald-400'
                                                        : isChapterActiveInReader(ch, idx, readerEdition.toc)
                                                          ? 'bg-sky-400 text-slate-950'
                                                          : isReaderPageUnlocked(ch.page || 1)
                                                            ? 'bg-slate-800 text-slate-400'
                                                            : 'bg-amber-500/15 text-amber-400'
                                                "
                                            >
                                                <CheckCircle2
                                                    v-if="isChapterCompletedInReader(ch, idx, readerEdition.toc)"
                                                    class="h-3.5 w-3.5"
                                                />
                                                <Lock
                                                    v-else-if="!isReaderPageUnlocked(ch.page || 1)"
                                                    class="h-3 w-3"
                                                />
                                                <span v-else>{{ idx + 1 }}</span>
                                            </span>

                                            <div class="min-w-0">
                                                <p class="font-semibold leading-snug line-clamp-2">
                                                    {{ ch.title }}
                                                </p>
                                                <span
                                                    v-if="isChapterActiveInReader(ch, idx, readerEdition.toc)"
                                                    class="inline-block mt-1 text-[10px] font-semibold text-sky-400"
                                                >
                                                    • Leyendo ahora
                                                </span>
                                            </div>
                                        </div>

                                        <span class="font-mono text-[11px] text-slate-400 shrink-0">
                                            P.{{ ch.page || 1 }}
                                        </span>
                                    </button>
                                </template>
                                <div v-else class="text-xs text-slate-500 py-10 text-center px-4">
                                    Esta edición aún no tiene capítulos detallados en el índice.
                                </div>
                            </div>
                        </aside>

                        <!-- Escenario Central de Lectura con Botones Laterales Flotantes -->
                        <div class="flex-1 flex flex-col overflow-hidden relative">
                            <!-- Botón flotante izquierdo (Página anterior, estilo Kindle Web) -->
                            <button
                                v-if="currentPage > 1"
                                type="button"
                                aria-label="Página anterior"
                                class="hidden sm:flex fixed sm:absolute left-4 top-1/2 -translate-y-1/2 z-20 h-12 w-12 items-center justify-center rounded-full bg-slate-900/80 hover:bg-sky-500 text-slate-200 hover:text-slate-950 border border-slate-700/80 shadow-xl backdrop-blur-md transition-all"
                                @click="goToReaderPage(currentPage - 1)"
                            >
                                <ChevronLeft class="h-6 w-6" />
                            </button>

                            <!-- Botón flotante derecho (Página siguiente, estilo Kindle Web) -->
                            <button
                                v-if="currentPage < maxNavigablePage"
                                type="button"
                                aria-label="Página siguiente"
                                class="hidden sm:flex fixed sm:absolute right-4 top-1/2 -translate-y-1/2 z-20 h-12 w-12 items-center justify-center rounded-full bg-slate-900/80 hover:bg-sky-500 text-slate-200 hover:text-slate-950 border border-slate-700/80 shadow-xl backdrop-blur-md transition-all"
                                @click="goToReaderPage(currentPage + 1)"
                            >
                                <ChevronRight class="h-6 w-6" />
                            </button>

                            <!-- Contenedor Scrollable de la Hoja Editorial (items-start + m-auto para cero recortes) -->
                            <div
                                ref="readerScrollContainer"
                                class="flex-1 overflow-auto flex items-start justify-center p-2 sm:p-6 transition-colors duration-300"
                                :class="
                                    readerBackdropMode === 'day'
                                        ? 'bg-slate-200/95'
                                        : readerBackdropMode === 'sepia'
                                          ? 'bg-stone-900'
                                          : 'bg-[#090d16]'
                                "
                            >
                                <!-- Cortina de Paywall al superar las páginas de muestra gratis -->
                                <div
                                    v-if="isPaywallGatePage"
                                    class="max-w-lg w-full m-auto rounded-2xl border border-amber-500/30 bg-slate-900/95 p-6 sm:p-8 text-center space-y-5 shadow-2xl"
                                >
                                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/15 border border-amber-500/30 text-amber-400">
                                        <Lock class="h-7 w-7" />
                                    </div>
                                    <div class="space-y-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 px-3 py-1 text-xs font-semibold text-amber-300">
                                            Fin de la muestra gratuita ({{ readerEdition.sample_pages_count }} págs.)
                                        </span>
                                        <h4 class="text-xl font-bold text-white">
                                            Desbloquea las {{ readerEdition.total_pages }} páginas de esta obra
                                        </h4>
                                        <p class="text-xs sm:text-sm text-slate-400 leading-relaxed">
                                            Activa tu membresía estudiantil para acceder sin límites a todas las ediciones, casos prácticos y seguimiento automático de estudio.
                                        </p>
                                    </div>
                                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
                                        <a
                                            :href="membershipUrl"
                                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 font-semibold text-sm px-5 py-3 transition"
                                        >
                                            <Sparkles class="h-4 w-4" />
                                            <span>Desbloquear libro completo</span>
                                        </a>
                                        <button
                                            type="button"
                                            class="w-full sm:w-auto inline-flex items-center justify-center rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-3 transition"
                                            @click="goToReaderPage(readerEdition.sample_pages_count || 1)"
                                        >
                                            Volver a pág. {{ readerEdition.sample_pages_count || 1 }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Hoja Editorial Protegida WebP -->
                                <div
                                    v-else
                                    class="relative flex items-center justify-center m-auto transition-all duration-200"
                                    :style="
                                        isFitToPage
                                            ? { maxWidth: '100%' }
                                            : { width: `${Math.round((zoomLevel / 100) * 860)}px`, maxWidth: '100%' }
                                    "
                                >
                                    <div
                                        v-if="isPageLoading"
                                        class="aspect-[3/4] w-72 sm:w-[440px] rounded-xl bg-slate-900 border border-slate-800 animate-pulse flex flex-col items-center justify-center gap-2 text-slate-400"
                                    >
                                        <BookOpen class="h-8 w-8 text-sky-400 animate-bounce" />
                                        <span class="text-xs">Cargando página {{ currentPage }}...</span>
                                    </div>

                                    <div
                                        v-if="pageLoadError"
                                        class="aspect-[3/4] w-72 sm:w-[440px] rounded-xl bg-slate-900 border border-slate-800 p-8 flex flex-col items-center justify-center text-center gap-3"
                                    >
                                        <BookOpen class="h-8 w-8 text-slate-500" />
                                        <p class="text-sm font-semibold text-slate-200">
                                            No se pudo cargar la página {{ currentPage }}
                                        </p>
                                        <p class="text-xs text-slate-400 max-w-xs">
                                            Verifica que las láminas WebP de esta edición hayan sido procesadas en el depósito privado.
                                        </p>
                                    </div>

                                    <!-- Contenedor de hoja de papel con sombra realista -->
                                    <div
                                        v-show="!isPageLoading && !pageLoadError"
                                        class="relative rounded-md overflow-hidden shadow-[0_20px_50px_rgba(0,0,0,0.65)] ring-1 ring-black/15 bg-white"
                                    >
                                        <img
                                            :key="currentReaderPageUrl"
                                            :src="currentReaderPageUrl"
                                            :alt="`${readerBook.title} - Página ${currentPage}`"
                                            :class="[
                                                isFitToPage
                                                    ? 'max-h-[calc(100dvh-9.25rem)] w-auto max-w-full object-contain pointer-events-none select-none block'
                                                    : 'w-full h-auto pointer-events-none select-none block',
                                                readerBackdropMode === 'sepia'
                                                    ? 'sepia-[.16] brightness-[.96] contrast-[1.02]'
                                                    : '',
                                            ]"
                                            draggable="false"
                                            @load="isPageLoading = false"
                                            @error="
                                                isPageLoading = false;
                                                pageLoadError = true;
                                            "
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Barra Inferior con Scrubber Interactivo de Páginas -->
                    <footer class="h-14 sm:h-16 px-4 sm:px-6 border-t border-slate-800/90 bg-slate-900/95 flex items-center justify-between gap-4 shrink-0">
                        <button
                            type="button"
                            :disabled="currentPage <= 1"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:pointer-events-none px-3.5 py-2 text-xs font-semibold text-slate-200 transition shrink-0"
                            @click="goToReaderPage(currentPage - 1)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                            <span class="hidden xs:inline">Anterior</span>
                        </button>

                        <!-- Línea de tiempo / Scrubber de páginas -->
                        <div class="flex-1 max-w-xl flex items-center gap-3">
                            <span class="hidden sm:inline text-[11px] font-mono text-slate-400 shrink-0">
                                Pág. {{ Math.min(currentPage, readerEdition.total_pages) }}
                            </span>
                            <input
                                type="range"
                                min="1"
                                :max="maxNavigablePage"
                                :value="currentPage"
                                aria-label="Deslizador de páginas"
                                class="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-sky-400"
                                @input="goToReaderPage($event.target.value)"
                            />
                            <span class="text-[11px] font-mono text-sky-400 font-semibold shrink-0">
                                {{ readerProgressPercent }}%
                            </span>
                        </div>

                        <button
                            type="button"
                            :disabled="currentPage >= maxNavigablePage"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-sky-500 hover:bg-sky-400 disabled:opacity-40 disabled:pointer-events-none px-4 py-2 text-xs font-semibold text-slate-950 transition shrink-0"
                            @click="goToReaderPage(currentPage + 1)"
                        >
                            <span class="hidden xs:inline">Siguiente</span>
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </footer>
                </div>
            </Transition>
        </Teleport>

        <!-- Sistema de notificaciones -->
        <BaseToast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            @close="toast.show = false"
        />
    </div>
</template>
