<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import Fuse from 'fuse.js';
import {
    AlertCircle,
    ArrowLeft,
    Bold,
    BookOpen,
    Check,
    Crown,
    ExternalLink,
    Eye,
    FileText,
    Heading3,
    Highlighter,
    Italic,
    List,
    Loader2,
    Lock,
    Maximize2,
    NotebookPen,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Sparkles,
    Table2,
    Trash2,
    X,
} from 'lucide-vue-next';
import { renderMiniMarkdown, stripMarkdown } from '../../utils/miniMarkdown';

const props = defineProps({
    editionId: {
        type: [Number, String],
        required: true,
    },
    currentPage: {
        type: Number,
        default: 1,
    },
    totalPages: {
        type: Number,
        default: 1,
    },
    hasActiveSubscription: {
        type: Boolean,
        default: false,
    },
    membershipUrl: {
        type: String,
        default: '/membresia',
    },
    notesIndexUrl: {
        type: String,
        default: '',
    },
    notesStoreUrl: {
        type: String,
        default: '',
    },
    noteUpdateBaseUrl: {
        type: String,
        default: '/biblioteca/apuntes',
    },
    theme: {
        type: String,
        default: 'reader', // 'reader' (oscuro) | 'light' (claro) | 'surface' (adaptativo app)
    },
    showCloseButton: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['jump-to-page', 'notes-count-changed', 'close']);

const ui = computed(() => {
    if (props.theme === 'light') {
        return {
            root: 'bg-white text-slate-900',
            border: 'border-slate-200',
            subBar: 'border-slate-200 bg-slate-50/90',
            mutedText: 'text-slate-500',
            subtleText: 'text-slate-400',
            iconBtn: 'text-slate-500 hover:text-slate-900 hover:bg-slate-100',
            accentText: 'text-sky-600',
            accentBadge: 'bg-sky-50 text-sky-700',
            lockBadge: 'bg-amber-50 text-amber-600',
            lockCard: 'bg-amber-50 border-amber-200 text-amber-600',
            primaryBtn: 'bg-sky-600 hover:bg-sky-500 text-white',
            countPill: 'bg-slate-100 text-slate-600',
            input: 'border-slate-200 bg-white text-slate-900 placeholder:text-slate-400 focus:border-sky-500',
            filterActive: 'bg-sky-100 text-sky-700',
            filterIdle: 'text-slate-500 hover:text-slate-900',
            errorBox: 'bg-rose-50 text-rose-700 border border-rose-200',
            skeleton: 'bg-slate-100',
            retryBtn: 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
            emptyIcon: 'bg-slate-100 text-sky-600',
            cardActive: 'border-sky-300 bg-sky-50/70 hover:bg-sky-50',
            cardIdle: 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/80',
            pageBadge: 'bg-sky-50 text-sky-700 hover:bg-sky-600 hover:text-white',
            generalBadge: 'bg-slate-100 text-slate-500',
            deleteIdle: 'text-slate-400 hover:text-rose-600 hover:bg-rose-50',
            editorInput: 'border-slate-200 text-slate-900 placeholder:text-slate-400 focus:border-sky-500',
            editorBody: 'text-slate-800 placeholder:text-slate-400',
            savingPill: 'bg-slate-100 text-slate-600',
            savedPill: 'bg-emerald-50 text-emerald-700',
        };
    }

    if (props.theme === 'surface') {
        return {
            root: 'bg-card text-foreground',
            border: 'border-border',
            subBar: 'border-border bg-muted/20',
            mutedText: 'text-muted-foreground',
            subtleText: 'text-muted-foreground',
            iconBtn: 'text-muted-foreground hover:text-foreground hover:bg-muted',
            accentText: 'text-primary',
            accentBadge: 'bg-primary/10 text-primary',
            lockBadge: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
            lockCard: 'bg-amber-500/10 border-amber-500/25 text-amber-600 dark:text-amber-400',
            primaryBtn: 'bg-primary hover:bg-primary/90 text-primary-foreground',
            countPill: 'bg-muted text-muted-foreground',
            input: 'border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-primary',
            filterActive: 'bg-primary/15 text-primary',
            filterIdle: 'text-muted-foreground hover:text-foreground',
            errorBox: 'bg-destructive/10 text-destructive border border-destructive/20',
            skeleton: 'bg-muted',
            retryBtn: 'border-border bg-background hover:bg-muted',
            emptyIcon: 'bg-muted text-primary',
            cardActive: 'border-primary/40 bg-primary/5 hover:bg-primary/10',
            cardIdle: 'border-border bg-background hover:border-primary/30 hover:bg-muted/40',
            pageBadge: 'bg-primary/10 text-primary hover:bg-primary hover:text-primary-foreground',
            generalBadge: 'bg-muted text-muted-foreground',
            deleteIdle: 'text-muted-foreground hover:text-destructive hover:bg-muted',
            editorInput: 'border-border text-foreground placeholder:text-muted-foreground focus:border-primary',
            editorBody: 'text-foreground placeholder:text-muted-foreground',
            savingPill: 'bg-muted text-muted-foreground',
            savedPill: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        };
    }

    // Default: 'reader' (modo oscuro del visor)
    return {
        root: 'bg-slate-900/95 text-slate-100',
        border: 'border-slate-800',
        subBar: 'border-slate-800/80 bg-slate-950/40',
        mutedText: 'text-slate-400',
        subtleText: 'text-slate-500',
        iconBtn: 'text-slate-400 hover:text-white hover:bg-slate-800',
        accentText: 'text-sky-400',
        accentBadge: 'bg-sky-500/15 text-sky-400',
        lockBadge: 'bg-amber-500/15 text-amber-400',
        lockCard: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
        primaryBtn: 'bg-sky-500 hover:bg-sky-400 text-slate-950',
        countPill: 'bg-slate-800 text-slate-300',
        input: 'border-slate-700/80 bg-slate-950 text-white placeholder:text-slate-500 focus:border-sky-400',
        filterActive: 'bg-sky-500/20 text-sky-300',
        filterIdle: 'text-slate-400 hover:text-slate-200',
        errorBox: 'bg-rose-500/15 text-rose-300 border border-rose-500/30',
        skeleton: 'bg-slate-800/70',
        retryBtn: 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700',
        emptyIcon: 'bg-slate-800 text-sky-400',
        cardActive: 'border-sky-500/40 bg-sky-500/10 hover:bg-sky-500/15',
        cardIdle: 'border-slate-800 bg-slate-950/50 hover:border-slate-700 hover:bg-slate-800/60',
        pageBadge: 'bg-sky-500/15 text-sky-300 hover:bg-sky-500 hover:text-slate-950',
        generalBadge: 'bg-slate-800 text-slate-400',
        deleteIdle: 'text-slate-400 hover:text-rose-400 hover:bg-slate-800',
        editorInput: 'border-slate-800 text-white placeholder:text-slate-500 focus:border-sky-400',
        editorBody: 'text-slate-200 placeholder:text-slate-500',
        savingPill: 'bg-slate-800 text-slate-300',
        savedPill: 'bg-emerald-500/15 text-emerald-400',
    };
});

// Estado de bloqueo en tiempo real (por prop o si la API responde 403 durante la sesión)
const runtimeLocked = ref(false);
const lockMembershipUrl = ref(props.membershipUrl || '/membresia');
const effectiveAccess = computed(() => Boolean(props.hasActiveSubscription) && !runtimeLocked.value);

// Estado de la lista y carga
const notes = ref([]);
const maxNotes = ref(500);
const isLoading = ref(false);
const isCreating = ref(false);
const loadError = ref('');
const actionError = ref('');
const searchQuery = ref('');
const onlyCurrentPage = ref(false);
const listLayout = ref('compact'); // 'compact' | 'formatted' (solo lectura con formato)
const isFullscreenOpen = ref(false);

// Estado del editor y autoguardado
const viewMode = ref('list'); // 'list' | 'editor'
const editorMode = ref('write'); // 'write' | 'preview'
const bodyTextareaRef = ref(null);
const activeNoteId = ref(null);
const draftTitle = ref('');
const draftBody = ref('');
const draftPage = ref(null);
const saveStatus = ref('idle'); // 'idle' | 'dirty' | 'saving' | 'saved' | 'error'
const confirmDeleteId = ref(null);
const isDeleting = ref(false);

let autosaveTimer = null;
let loadedEditionId = null;

const resolvedIndexUrl = computed(
    () => props.notesIndexUrl || `/biblioteca/ediciones/${props.editionId}/apuntes`
);
const resolvedStoreUrl = computed(
    () => props.notesStoreUrl || `/biblioteca/ediciones/${props.editionId}/apuntes`
);

const activeNote = computed(
    () => notes.value.find((n) => Number(n.id) === Number(activeNoteId.value)) || null
);

const notesOnCurrentPageCount = computed(
    () => notes.value.filter((n) => Number(n.page_number) === Number(props.currentPage)).length
);

const searchableNotes = computed(() =>
    notes.value.map((note) => ({
        ...note,
        page_str: note.page_number ? `pagina ${note.page_number} p.${note.page_number} ${note.page_number}` : 'general',
    }))
);

const fuseInstance = computed(
    () =>
        new Fuse(searchableNotes.value, {
            keys: [
                { name: 'title', weight: 0.5 },
                { name: 'body', weight: 0.35 },
                { name: 'page_str', weight: 0.15 },
            ],
            threshold: 0.35,
            ignoreLocation: true,
        })
);

const filteredNotes = computed(() => {
    let base = notes.value;

    if (onlyCurrentPage.value && props.currentPage > 0) {
        base = base.filter((n) => Number(n.page_number) === Number(props.currentPage));
    }

    const q = searchQuery.value.trim();
    if (!q) {
        return base;
    }

    // Atajo directo por número de página ("45", "p 45", "pág 45")
    const pageMatch = q.match(/^(?:p(?:[aá]g(?:ina)?)?\.?\s*)?(\d+)$/i);
    if (pageMatch) {
        const targetPage = Number(pageMatch[1]);
        const exactPageMatches = base.filter((n) => Number(n.page_number) === targetPage);
        if (exactPageMatches.length > 0) {
            return exactPageMatches;
        }
    }

    const allowedIds = new Set(base.map((n) => n.id));
    return fuseInstance.value
        .search(q)
        .map((result) => result.item)
        .filter((item) => allowedIds.has(item.id));
});

function emitNotesCount() {
    emit('notes-count-changed', {
        editionId: Number(props.editionId),
        count: notes.value.length,
    });
}

function sortNotes(list) {
    return [...list].sort((a, b) => {
        const pageA = a.page_number === null || a.page_number === undefined ? 0 : Number(a.page_number);
        const pageB = b.page_number === null || b.page_number === undefined ? 0 : Number(b.page_number);
        if (pageA !== pageB) return pageA - pageB;
        return new Date(b.updated_at || 0).getTime() - new Date(a.updated_at || 0).getTime();
    });
}

function handleSubscriptionLock(errorData) {
    const nextUrl = errorData?.membership_url;
    if (typeof nextUrl === 'string' && /^(\/|https?:\/\/)/i.test(nextUrl)) {
        lockMembershipUrl.value = nextUrl;
    }
    clearAutosaveTimer();
    isFullscreenOpen.value = false;
    runtimeLocked.value = true;
}

async function fetchNotes(force = false) {
    if (!effectiveAccess.value || !props.editionId) {
        return;
    }
    if (!force && loadedEditionId === Number(props.editionId) && notes.value.length > 0) {
        return;
    }

    isLoading.value = true;
    loadError.value = '';
    actionError.value = '';

    try {
        const { data } = await axios.get(resolvedIndexUrl.value);
        if (data?.success && Array.isArray(data.data)) {
            notes.value = sortNotes(data.data);
            if (data.meta?.max) {
                maxNotes.value = Number(data.meta.max);
            }
            loadedEditionId = Number(props.editionId);
            emitNotesCount();
        }
    } catch (error) {
        const status = error?.response?.status;
        const payload = error?.response?.data;
        if (status === 403 && payload?.code === 'subscription_required') {
            handleSubscriptionLock(payload);
            return;
        }
        loadError.value = payload?.message || 'No pudimos cargar tus apuntes. Intenta nuevamente.';
    } finally {
        isLoading.value = false;
    }
}

function openEditor(note, preferredMode = null) {
    clearAutosaveTimer();
    activeNoteId.value = note.id;
    draftTitle.value = note.title || '';
    draftBody.value = note.body || '';
    draftPage.value = note.page_number ?? null;
    // Si el apunte ya tiene texto y no se pidió editar explícitamente, se abre formateado para lectura limpia.
    editorMode.value = preferredMode || (note.body?.trim() ? 'preview' : 'write');
    saveStatus.value = 'saved';
    confirmDeleteId.value = null;
    actionError.value = '';
    viewMode.value = 'editor';
}

const renderedPreviewHtml = computed(() => renderMiniMarkdown(draftBody.value));

function applyBodyEdit(nextText, selectStart, selectEnd) {
    draftBody.value = nextText.slice(0, 10000);
    scheduleAutosave();
    nextTick(() => {
        const el = bodyTextareaRef.value;
        if (!el) return;
        el.focus();
        el.setSelectionRange(selectStart, selectEnd);
    });
}

function wrapSelection(before, after, placeholder) {
    if (editorMode.value === 'preview') editorMode.value = 'write';
    const el = bodyTextareaRef.value;
    const text = draftBody.value || '';
    const start = el ? el.selectionStart : text.length;
    const end = el ? el.selectionEnd : text.length;
    const selected = text.slice(start, end) || placeholder;
    const nextText = text.slice(0, start) + before + selected + after + text.slice(end);
    const cursorStart = start + before.length;
    applyBodyEdit(nextText, cursorStart, cursorStart + selected.length);
}

function insertLinePrefix(prefix, placeholder) {
    if (editorMode.value === 'preview') editorMode.value = 'write';
    const el = bodyTextareaRef.value;
    const text = draftBody.value || '';
    const start = el ? el.selectionStart : text.length;
    const end = el ? el.selectionEnd : text.length;
    const needsNewline = start > 0 && text[start - 1] !== '\n' ? '\n' : '';
    const selected = text.slice(start, end) || placeholder;
    const insertion = needsNewline + prefix + selected;
    const nextText = text.slice(0, start) + insertion + text.slice(end);
    const cursorStart = start + needsNewline.length + prefix.length;
    applyBodyEdit(nextText, cursorStart, cursorStart + selected.length);
}

function insertAccountingTable() {
    if (editorMode.value === 'preview') editorMode.value = 'write';
    const el = bodyTextareaRef.value;
    const text = draftBody.value || '';
    const start = el ? el.selectionStart : text.length;
    const needsNewline = start > 0 && text[start - 1] !== '\n' ? '\n\n' : '';
    const tableSnippet = `${needsNewline}| Cuenta | Debe | Haber |\n| --- | ---: | ---: |\n| Caja / Banco | 0.00 | 0.00 |\n`;
    const nextText = text.slice(0, start) + tableSnippet + text.slice(start);
    const cursorPos = start + tableSnippet.length;
    applyBodyEdit(nextText, cursorPos, cursorPos);
}

function handleEditorKeydown(event) {
    if (!(event.metaKey || event.ctrlKey)) return;
    const key = event.key.toLowerCase();
    if (key === 'b') {
        event.preventDefault();
        wrapSelection('**', '**', 'texto');
    } else if (key === 'i') {
        event.preventDefault();
        wrapSelection('*', '*', 'texto');
    }
}

async function backToList() {
    await flushPendingSave();
    viewMode.value = 'list';
    activeNoteId.value = null;
    confirmDeleteId.value = null;
    actionError.value = '';
}

async function createNote(linkedPage = null) {
    if (!effectiveAccess.value || isCreating.value) return;

    isCreating.value = true;
    actionError.value = '';

    const pageToAssign =
        linkedPage !== null && linkedPage > 0
            ? Math.min(Math.max(1, Number(linkedPage)), Math.max(1, Number(props.totalPages || linkedPage)))
            : null;

    try {
        const { data } = await axios.post(resolvedStoreUrl.value, {
            page_number: pageToAssign,
            title: '',
            body: '',
        });

        if (data?.success && data.data) {
            notes.value = sortNotes([...notes.value, data.data]);
            emitNotesCount();
            openEditor(data.data, 'write');
        }
    } catch (error) {
        const status = error?.response?.status;
        const payload = error?.response?.data;
        if (status === 403 && payload?.code === 'subscription_required') {
            handleSubscriptionLock(payload);
            return;
        }
        actionError.value = payload?.message || 'No se pudo crear el apunte.';
    } finally {
        isCreating.value = false;
    }
}

function clearAutosaveTimer() {
    if (autosaveTimer !== null) {
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
    }
}

function scheduleAutosave() {
    if (!effectiveAccess.value || !activeNoteId.value) return;
    saveStatus.value = 'dirty';
    actionError.value = '';
    clearAutosaveTimer();
    autosaveTimer = setTimeout(() => {
        saveActiveNote();
    }, 800);
}

async function flushPendingSave() {
    if (autosaveTimer !== null) {
        clearAutosaveTimer();
        await saveActiveNote();
    } else if (saveStatus.value === 'dirty' || saveStatus.value === 'error') {
        await saveActiveNote();
    }
}

async function saveActiveNote() {
    if (!effectiveAccess.value || !activeNoteId.value) return;

    const noteId = activeNoteId.value;
    const payloadToSend = {
        title: draftTitle.value,
        body: draftBody.value,
        page_number: draftPage.value,
    };

    saveStatus.value = 'saving';
    actionError.value = '';

    try {
        const { data } = await axios.put(`${props.noteUpdateBaseUrl}/${noteId}`, payloadToSend);

        if (data?.success && data.data) {
            const idx = notes.value.findIndex((n) => Number(n.id) === Number(noteId));
            if (idx !== -1) {
                notes.value[idx] = {
                    ...notes.value[idx],
                    title: data.data.title ?? draftTitle.value,
                    body: draftBody.value,
                    page_number: data.data.page_number ?? draftPage.value,
                    updated_at: data.data.updated_at || new Date().toISOString(),
                };
                notes.value = sortNotes(notes.value);
            }
            if (data.data.title && !draftTitle.value.trim()) {
                draftTitle.value = data.data.title;
            }
            saveStatus.value = 'saved';
        }
    } catch (error) {
        const status = error?.response?.status;
        const payload = error?.response?.data;
        if (status === 403 && payload?.code === 'subscription_required') {
            handleSubscriptionLock(payload);
            return;
        }
        saveStatus.value = 'error';
        actionError.value = payload?.message || 'Error al guardar el apunte.';
    }
}

function linkToCurrentPage() {
    if (!props.currentPage || props.currentPage < 1) return;
    draftPage.value = Number(props.currentPage);
    scheduleAutosave();
}

function unlinkPage() {
    draftPage.value = null;
    scheduleAutosave();
}

async function deleteNote(noteId) {
    if (!effectiveAccess.value || isDeleting.value) return;

    if (confirmDeleteId.value !== noteId) {
        confirmDeleteId.value = noteId;
        return;
    }

    clearAutosaveTimer();
    isDeleting.value = true;
    actionError.value = '';

    try {
        await axios.delete(`${props.noteUpdateBaseUrl}/${noteId}`);
        notes.value = notes.value.filter((n) => Number(n.id) !== Number(noteId));
        emitNotesCount();
        confirmDeleteId.value = null;
        if (Number(activeNoteId.value) === Number(noteId)) {
            viewMode.value = 'list';
            activeNoteId.value = null;
        }
    } catch (error) {
        const status = error?.response?.status;
        const payload = error?.response?.data;
        if (status === 403 && payload?.code === 'subscription_required') {
            handleSubscriptionLock(payload);
            return;
        }
        actionError.value = payload?.message || 'No se pudo eliminar el apunte.';
    } finally {
        isDeleting.value = false;
    }
}

function jumpToPage(pageNumber) {
    if (!pageNumber || pageNumber < 1) return;
    emit('jump-to-page', Number(pageNumber));
}

function formatUpdatedAt(isoString) {
    if (!isoString) return 'Reciente';
    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) return 'Reciente';

    const diffSeconds = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diffSeconds < 60) return 'Hace un momento';
    if (diffSeconds < 3600) return `Hace ${Math.floor(diffSeconds / 60)} min`;
    if (diffSeconds < 86400) return `Hace ${Math.floor(diffSeconds / 3600)} h`;

    return date.toLocaleDateString('es-BO', { day: '2-digit', month: 'short' });
}

// Resetear y cargar cuando cambia la edición o el acceso
watch(
    () => [props.editionId, props.hasActiveSubscription],
    async ([newEditionId, hasAccess], oldValues) => {
        const oldEditionId = Array.isArray(oldValues) ? oldValues[0] : undefined;
        if (oldEditionId && Number(newEditionId) !== Number(oldEditionId)) {
            await flushPendingSave();
            notes.value = [];
            viewMode.value = 'list';
            activeNoteId.value = null;
            searchQuery.value = '';
            onlyCurrentPage.value = false;
            isFullscreenOpen.value = false;
            loadedEditionId = null;
        }

        if (hasAccess) {
            runtimeLocked.value = false;
            fetchNotes(true);
        } else {
            clearAutosaveTimer();
            notes.value = [];
            loadedEditionId = null;
        }
    },
    { immediate: true }
);

onBeforeUnmount(() => {
    flushPendingSave();
});
</script>

<template>
    <div
        class="flex flex-col h-full min-h-0 select-text transition-colors duration-200"
        :class="ui.root"
    >
        <!-- =============================================================== -->
        <!-- ESTADO BLOQUEADO (Sin membresía vigente — Cero peticiones API) -->
        <!-- =============================================================== -->
        <div v-if="!effectiveAccess" class="flex-1 flex flex-col">
            <div
                class="px-4 py-3.5 border-b flex items-center justify-between gap-2 shrink-0"
                :class="ui.border"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-lg shrink-0"
                        :class="ui.lockBadge"
                    >
                        <Lock class="h-3.5 w-3.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-bold tracking-tight truncate">Mi cuaderno</p>
                        <p class="text-[11px] truncate" :class="ui.mutedText">
                            Solo miembros activos
                        </p>
                    </div>
                </div>
                <button
                    v-if="showCloseButton"
                    type="button"
                    aria-label="Cerrar cuaderno"
                    class="p-1.5 rounded-lg transition-colors"
                    :class="ui.iconBtn"
                    @click="emit('close')"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="flex-1 flex flex-col items-center justify-center p-6 text-center">
                <div
                    class="h-14 w-14 rounded-2xl flex items-center justify-center mb-4 border"
                    :class="ui.lockCard"
                >
                    <Crown class="h-7 w-7" />
                </div>

                <h4 class="text-sm font-bold leading-snug max-w-[240px]">
                    Cuaderno exclusivo para miembros activos
                </h4>

                <p class="mt-2 text-xs leading-relaxed max-w-[260px]" :class="ui.mutedText">
                    Tus apuntes se mantienen guardados. Renueva tu suscripción o canjea un código para ver y crear notas.
                </p>

                <a
                    :href="lockMembershipUrl"
                    class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold shadow-sm transition-colors"
                    :class="ui.primaryBtn"
                >
                    <Sparkles class="h-3.5 w-3.5" />
                    Renovar o canjear código
                    <ExternalLink class="h-3 w-3 opacity-80" />
                </a>
            </div>
        </div>

        <!-- =============================================================== -->
        <!-- ESTADO ACTIVO: VISTA 1 — TODOS MIS APUNTES                      -->
        <!-- =============================================================== -->
        <template v-else-if="viewMode === 'list'">
            <!-- Cabecera del Panel -->
            <div
                class="px-4 py-3.5 border-b flex items-center justify-between gap-2 shrink-0"
                :class="ui.border"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-lg shrink-0"
                        :class="ui.accentBadge"
                    >
                        <NotebookPen class="h-3.5 w-3.5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-xs font-bold uppercase tracking-wider" :class="ui.accentText">
                                Mi cuaderno
                            </p>
                            <span
                                class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                :class="ui.countPill"
                            >
                                {{ notes.length }}
                            </span>
                        </div>
                        <p class="text-[11px] truncate" :class="ui.mutedText">
                            Todos tus apuntes de esta edición
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    <button
                        v-if="notes.length > 0"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition-colors"
                        :class="
                            listLayout === 'formatted'
                                ? [ui.filterActive, ui.border]
                                : [ui.retryBtn]
                        "
                        title="Ver todos tus apuntes completos con su formato"
                        @click="listLayout = listLayout === 'formatted' ? 'compact' : 'formatted'"
                    >
                        <Eye class="h-3.5 w-3.5" />
                        <span>{{ listLayout === 'formatted' ? 'Compacto' : 'Leer todo' }}</span>
                    </button>

                    <button
                        v-if="notes.length > 0"
                        type="button"
                        class="p-1.5 rounded-lg border transition-colors"
                        :class="ui.retryBtn"
                        title="Ver cuaderno en pantalla completa"
                        aria-label="Ver cuaderno en pantalla completa"
                        @click="isFullscreenOpen = true"
                    >
                        <Maximize2 class="h-3.5 w-3.5" />
                    </button>

                    <button
                        v-if="showCloseButton"
                        type="button"
                        aria-label="Cerrar cuaderno"
                        class="p-1.5 rounded-lg transition-colors"
                        :class="ui.iconBtn"
                        @click="emit('close')"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Buscador + Botón Nuevo Apunte -->
            <div class="p-3 border-b space-y-2.5 shrink-0" :class="ui.subBar">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <Search
                            class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
                            :class="ui.mutedText"
                        />
                        <input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Buscar título, texto o pág..."
                            aria-label="Buscar en mis apuntes"
                            class="w-full rounded-xl border pl-8 pr-3 py-1.5 text-xs focus:outline-none transition-colors"
                            :class="ui.input"
                        />
                    </div>

                    <button
                        type="button"
                        :disabled="isCreating || notes.length >= maxNotes"
                        class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-bold transition-colors shrink-0 disabled:opacity-50"
                        :class="ui.primaryBtn"
                        :title="currentPage > 0 ? `Crear apunte en página ${currentPage}` : 'Crear apunte'"
                        @click="createNote(currentPage > 0 ? currentPage : null)"
                    >
                        <Loader2 v-if="isCreating" class="h-3.5 w-3.5 animate-spin" />
                        <Plus v-else class="h-3.5 w-3.5" />
                        <span>Nuevo apunte</span>
                    </button>
                </div>

                <!-- Filtro rápido página actual vs todos -->
                <div v-if="currentPage > 0 && notes.length > 0" class="flex items-center justify-between gap-2 text-[11px]">
                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded-lg px-2 py-0.5 font-medium transition-colors"
                            :class="!onlyCurrentPage ? ui.filterActive : ui.filterIdle"
                            @click="onlyCurrentPage = false"
                        >
                            Todos ({{ notes.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2 py-0.5 font-medium transition-colors"
                            :class="onlyCurrentPage ? ui.filterActive : ui.filterIdle"
                            @click="onlyCurrentPage = true"
                        >
                            Pág. {{ currentPage }} ({{ notesOnCurrentPageCount }})
                        </button>
                    </div>

                    <span
                        v-if="notes.length >= maxNotes * 0.9"
                        class="text-[10px] text-amber-500"
                    >
                        {{ notes.length }}/{{ maxNotes }}
                    </span>
                </div>

                <div
                    v-if="actionError"
                    class="rounded-lg px-2.5 py-1.5 text-[11px] flex items-center gap-1.5"
                    :class="ui.errorBox"
                >
                    <AlertCircle class="h-3.5 w-3.5 shrink-0" />
                    <span>{{ actionError }}</span>
                </div>
            </div>

            <!-- Listado Global de Apuntes -->
            <div class="flex-1 overflow-y-auto p-3 space-y-2">
                <!-- Cargando -->
                <div v-if="isLoading" class="space-y-2.5 py-2">
                    <div
                        v-for="i in 3"
                        :key="i"
                        class="h-20 rounded-xl animate-pulse"
                        :class="ui.skeleton"
                    />
                </div>

                <!-- Error de carga -->
                <div v-else-if="loadError" class="py-10 px-4 text-center space-y-3">
                    <p class="text-xs text-rose-500">{{ loadError }}</p>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold border transition-colors"
                        :class="ui.retryBtn"
                        @click="fetchNotes(true)"
                    >
                        <RefreshCw class="h-3.5 w-3.5" />
                        Reintentar
                    </button>
                </div>

                <!-- Vacío sin notas -->
                <div v-else-if="notes.length === 0" class="py-12 px-4 text-center">
                    <div
                        class="mx-auto h-11 w-11 rounded-2xl flex items-center justify-center mb-3"
                        :class="ui.emptyIcon"
                    >
                        <FileText class="h-5 w-5" />
                    </div>
                    <p class="text-xs font-semibold">Aún no tienes apuntes en este libro</p>
                    <p class="mt-1 text-[11px] leading-relaxed" :class="ui.mutedText">
                        Crea tu primer apunte mientras lees y encuéntralo rápido desde aquí sin buscar página por página.
                    </p>
                    <button
                        type="button"
                        class="mt-4 inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold transition-colors"
                        :class="ui.primaryBtn"
                        @click="createNote(currentPage > 0 ? currentPage : null)"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        {{ currentPage > 0 ? `Apunte en página ${currentPage}` : 'Crear primer apunte' }}
                    </button>
                </div>

                <!-- Sin resultados para el filtro/búsqueda -->
                <div v-else-if="filteredNotes.length === 0" class="py-10 px-4 text-center">
                    <p class="text-xs font-medium">No hay apuntes que coincidan</p>
                    <button
                        type="button"
                        class="mt-2 text-xs font-semibold underline"
                        :class="ui.accentText"
                        @click="
                            searchQuery = '';
                            onlyCurrentPage = false;
                        "
                    >
                        Limpiar filtros
                    </button>
                </div>

                <!-- Tarjetas de Apuntes (Vista lectura completa formateada vs Vista compacta) -->
                <template v-else-if="listLayout === 'formatted'">
                    <article
                        v-for="note in filteredNotes"
                        :key="`full-${note.id}`"
                        class="rounded-xl border p-3.5 space-y-2.5 transition-colors"
                        :class="Number(note.page_number) === Number(currentPage) ? ui.cardActive : ui.cardIdle"
                    >
                        <div class="flex items-start justify-between gap-2 pb-2 border-b" :class="ui.border">
                            <div class="min-w-0">
                                <h5 class="text-xs font-bold leading-snug">
                                    {{ note.title || 'Sin título' }}
                                </h5>
                                <span class="text-[10px]" :class="ui.subtleText">
                                    {{ formatUpdatedAt(note.updated_at) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    v-if="note.page_number"
                                    type="button"
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-bold transition-colors"
                                    :class="ui.pageBadge"
                                    :title="`Ir a la página ${note.page_number}`"
                                    @click="jumpToPage(note.page_number)"
                                >
                                    Pág. {{ note.page_number }}
                                </button>
                                <span
                                    v-else
                                    class="rounded-md px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="ui.generalBadge"
                                >
                                    General
                                </span>

                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold transition-colors"
                                    :class="ui.iconBtn"
                                    title="Editar este apunte"
                                    @click="openEditor(note, 'write')"
                                >
                                    <Pencil class="h-3 w-3" />
                                    <span>Editar</span>
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="note.body?.trim()"
                            class="text-xs leading-relaxed"
                            :class="ui.editorBody"
                            v-html="renderMiniMarkdown(note.body)"
                        />
                        <p v-else class="text-[11px] italic" :class="ui.mutedText">
                            Apunte vacío.
                        </p>
                    </article>
                </template>

                <div
                    v-for="note in filteredNotes"
                    v-else
                    :key="note.id"
                    role="button"
                    tabindex="0"
                    class="group rounded-xl border p-3 text-left transition-all cursor-pointer"
                    :class="Number(note.page_number) === Number(currentPage) ? ui.cardActive : ui.cardIdle"
                    @click="openEditor(note)"
                    @keydown.enter="openEditor(note)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <h5 class="text-xs font-bold leading-snug line-clamp-1">
                            {{ note.title || 'Sin título' }}
                        </h5>

                        <button
                            v-if="note.page_number"
                            type="button"
                            class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-bold transition-colors"
                            :class="ui.pageBadge"
                            :title="`Ir a la página ${note.page_number}`"
                            @click.stop="jumpToPage(note.page_number)"
                        >
                            Página {{ note.page_number }}
                        </button>
                        <span
                            v-else
                            class="shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium"
                            :class="ui.generalBadge"
                        >
                            General
                        </span>
                    </div>

                    <p class="mt-1.5 text-[11px] leading-relaxed line-clamp-2" :class="ui.mutedText">
                        {{ stripMarkdown(note.body) || 'Apunte vacío — haz clic para escribir...' }}
                    </p>

                    <div class="mt-2 flex items-center justify-between text-[10px]">
                        <span :class="ui.subtleText">
                            {{ formatUpdatedAt(note.updated_at) }}
                        </span>

                        <button
                            type="button"
                            class="opacity-0 group-hover:opacity-100 focus:opacity-100 inline-flex items-center gap-1 rounded px-1.5 py-0.5 transition-opacity"
                            :class="
                                confirmDeleteId === note.id
                                    ? 'opacity-100 bg-rose-500 text-white font-semibold'
                                    : ui.deleteIdle
                            "
                            @click.stop="deleteNote(note.id)"
                        >
                            <Trash2 class="h-3 w-3" />
                            <span>{{ confirmDeleteId === note.id ? 'Confirmar' : 'Borrar' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- =============================================================== -->
        <!-- ESTADO ACTIVO: VISTA 2 — EDITOR CON AUTOGUARDADO (800 ms)       -->
        <!-- =============================================================== -->
        <template v-else-if="activeNote">
            <!-- Barra superior del editor -->
            <div
                class="px-3.5 py-3 border-b flex items-center justify-between gap-2 shrink-0"
                :class="ui.border"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold transition-colors"
                    :class="ui.iconBtn"
                    @click="backToList"
                >
                    <ArrowLeft class="h-3.5 w-3.5" />
                    <span>Ver todos mis apuntes</span>
                </button>

                <div class="flex items-center gap-1.5">
                    <!-- Indicador de autoguardado -->
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        :class="
                            saveStatus === 'error'
                                ? 'bg-rose-500/15 text-rose-500'
                                : saveStatus === 'saving' || saveStatus === 'dirty'
                                  ? ui.savingPill
                                  : ui.savedPill
                        "
                    >
                        <Loader2 v-if="saveStatus === 'saving'" class="h-3 w-3 animate-spin" />
                        <AlertCircle v-else-if="saveStatus === 'error'" class="h-3 w-3" />
                        <Check v-else-if="saveStatus === 'saved'" class="h-3 w-3" />
                        <span v-if="saveStatus === 'saving'">Guardando...</span>
                        <span v-else-if="saveStatus === 'dirty'">Sin guardar</span>
                        <button
                            v-else-if="saveStatus === 'error'"
                            type="button"
                            class="underline"
                            @click="saveActiveNote"
                        >
                            Reintentar
                        </button>
                        <span v-else>Guardado</span>
                    </span>

                    <button
                        v-if="showCloseButton"
                        type="button"
                        aria-label="Cerrar cuaderno"
                        class="p-1 rounded-lg transition-colors"
                        :class="ui.iconBtn"
                        @click="emit('close')"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Barra de página vinculada -->
            <div
                class="px-4 py-2 border-b flex items-center justify-between gap-2 text-xs shrink-0"
                :class="ui.subBar"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <button
                        v-if="draftPage"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-bold transition-colors"
                        :class="ui.pageBadge"
                        @click="jumpToPage(draftPage)"
                    >
                        <BookOpen class="h-3 w-3" />
                        <span>Ir a la página {{ draftPage }}</span>
                    </button>
                    <span v-else class="text-[11px] font-medium" :class="ui.mutedText">
                        Apunte general (sin página)
                    </span>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <button
                        v-if="currentPage > 0 && Number(draftPage) !== Number(currentPage)"
                        type="button"
                        class="text-[11px] font-semibold underline"
                        :class="ui.accentText"
                        @click="linkToCurrentPage"
                    >
                        Vincular a pág. {{ currentPage }}
                    </button>
                    <button
                        v-if="draftPage"
                        type="button"
                        class="text-[11px]"
                        :class="ui.mutedText"
                        title="Convertir en apunte general"
                        @click="unlinkPage"
                    >
                        Quitar pág.
                    </button>
                </div>
            </div>

            <!-- Campos de edición -->
            <div class="flex-1 flex flex-col p-4 gap-3 min-h-0 overflow-y-auto">
                <input
                    v-model="draftTitle"
                    type="text"
                    maxlength="255"
                    placeholder="Título del apunte..."
                    aria-label="Título del apunte"
                    class="w-full bg-transparent border-b pb-2 text-sm font-bold focus:outline-none transition-colors"
                    :class="ui.editorInput"
                    @input="scheduleAutosave"
                />

                <!-- Barra de formato rápido + Editar / Vista previa -->
                <div
                    class="flex items-center justify-between gap-1.5 pb-2 border-b shrink-0 flex-wrap"
                    :class="ui.border"
                >
                    <div class="flex items-center gap-0.5">
                        <button
                            type="button"
                            title="Negrita (Ctrl/Cmd + B)"
                            class="p-1.5 rounded-lg transition-colors"
                            :class="ui.iconBtn"
                            @click="wrapSelection('**', '**', 'negrita')"
                        >
                            <Bold class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Cursiva (Ctrl/Cmd + I)"
                            class="p-1.5 rounded-lg transition-colors"
                            :class="ui.iconBtn"
                            @click="wrapSelection('*', '*', 'cursiva')"
                        >
                            <Italic class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Resaltar concepto"
                            class="p-1.5 rounded-lg transition-colors"
                            :class="ui.iconBtn"
                            @click="wrapSelection('==', '==', 'resaltado')"
                        >
                            <Highlighter class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Subtítulo"
                            class="p-1.5 rounded-lg transition-colors"
                            :class="ui.iconBtn"
                            @click="insertLinePrefix('### ', 'Subtítulo')"
                        >
                            <Heading3 class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Lista con viñetas"
                            class="p-1.5 rounded-lg transition-colors"
                            :class="ui.iconBtn"
                            @click="insertLinePrefix('- ', 'Elemento')"
                        >
                            <List class="h-3.5 w-3.5" />
                        </button>
                        <button
                            type="button"
                            title="Insertar tabla contable (Debe / Haber)"
                            class="inline-flex items-center gap-1 px-1.5 py-1 rounded-lg text-[10px] font-semibold transition-colors"
                            :class="ui.iconBtn"
                            @click="insertAccountingTable"
                        >
                            <Table2 class="h-3.5 w-3.5" />
                            <span>Asiento</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-bold transition-colors"
                        :class="editorMode === 'preview' ? ui.filterActive : ui.iconBtn"
                        @click="editorMode = editorMode === 'write' ? 'preview' : 'write'"
                    >
                        <Pencil v-if="editorMode === 'preview'" class="h-3 w-3" />
                        <Eye v-else class="h-3 w-3" />
                        <span>{{ editorMode === 'preview' ? 'Editar' : 'Vista previa' }}</span>
                    </button>
                </div>

                <!-- Vista previa formateada -->
                <div
                    v-if="editorMode === 'preview'"
                    class="flex-1 w-full min-h-[200px] overflow-y-auto text-xs leading-relaxed"
                    :class="ui.editorBody"
                >
                    <div
                        v-if="draftBody.trim()"
                        v-html="renderedPreviewHtml"
                    />
                    <p v-else class="italic" :class="ui.mutedText">
                        Nada que previsualizar aún. Cambia a "Editar" para escribir tu apunte.
                    </p>
                </div>

                <!-- Editor de texto -->
                <textarea
                    v-else
                    ref="bodyTextareaRef"
                    v-model="draftBody"
                    maxlength="10000"
                    placeholder="Escribe tus apuntes, asientos contables, fórmulas o recordatorios aquí... (usa **negrita**, ==resaltado== o el botón Asiento)"
                    aria-label="Contenido del apunte"
                    class="flex-1 w-full min-h-[200px] resize-none bg-transparent text-xs leading-relaxed focus:outline-none"
                    :class="ui.editorBody"
                    @input="scheduleAutosave"
                    @keydown="handleEditorKeydown"
                />

                <div
                    v-if="actionError"
                    class="rounded-lg px-2.5 py-1.5 text-[11px] flex items-center gap-1.5"
                    :class="ui.errorBox"
                >
                    <AlertCircle class="h-3.5 w-3.5 shrink-0" />
                    <span>{{ actionError }}</span>
                </div>
            </div>

            <!-- Pie del editor -->
            <div
                class="px-4 py-2.5 border-t flex items-center justify-between gap-2 text-[11px] shrink-0"
                :class="[ui.border, ui.mutedText]"
            >
                <span>{{ draftBody.length.toLocaleString() }} / 10.000</span>

                <button
                    type="button"
                    :disabled="isDeleting"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-semibold transition-colors"
                    :class="
                        confirmDeleteId === activeNote.id
                            ? 'bg-rose-500 text-white hover:bg-rose-600'
                            : ui.deleteIdle
                    "
                    @click="deleteNote(activeNote.id)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                    <span>{{ confirmDeleteId === activeNote.id ? '¿Confirmar borrado?' : 'Eliminar apunte' }}</span>
                </button>
            </div>
        </template>

        <!-- =============================================================== -->
        <!-- MODAL PANTALLA COMPLETA: CUADERNO FORMATEADO (0 peticiones)     -->
        <!-- =============================================================== -->
        <Teleport to="body">
            <div
                v-if="isFullscreenOpen && effectiveAccess"
                class="fixed inset-0 z-[70] flex flex-col select-text"
                :class="ui.root"
                role="dialog"
                aria-modal="true"
                aria-label="Cuaderno de estudio en pantalla completa"
                @keydown.esc="isFullscreenOpen = false"
            >
                <!-- Cabecera amplia -->
                <header
                    class="px-4 sm:px-8 py-3.5 border-b flex items-center justify-between gap-3 shrink-0"
                    :class="ui.border"
                >
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-xl shrink-0"
                            :class="ui.accentBadge"
                        >
                            <NotebookPen class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-bold truncate">Mi cuaderno de estudio</h3>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="ui.countPill"
                                >
                                    {{ filteredNotes.length }} {{ filteredNotes.length === 1 ? 'apunte' : 'apuntes' }}
                                </span>
                            </div>
                            <p class="text-[11px] truncate" :class="ui.mutedText">
                                Vista completa para repaso y lectura con formato
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <div class="relative hidden sm:block w-64">
                            <Search
                                class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none"
                                :class="ui.mutedText"
                            />
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Buscar título, texto o pág..."
                                class="w-full rounded-xl border pl-8 pr-3 py-1.5 text-xs focus:outline-none transition-colors"
                                :class="ui.input"
                            />
                        </div>

                        <button
                            type="button"
                            :disabled="isCreating || notes.length >= maxNotes"
                            class="inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-bold transition-colors shrink-0 disabled:opacity-50"
                            :class="ui.primaryBtn"
                            @click="
                                isFullscreenOpen = false;
                                createNote(currentPage > 0 ? currentPage : null);
                            "
                        >
                            <Plus class="h-3.5 w-3.5" />
                            <span>Nuevo apunte</span>
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition-colors"
                            :class="ui.retryBtn"
                            @click="isFullscreenOpen = false"
                        >
                            <X class="h-4 w-4" />
                            <span class="hidden sm:inline">Cerrar</span>
                        </button>
                    </div>
                </header>

                <!-- Contenido centrado tipo guía de estudio -->
                <div class="flex-1 overflow-y-auto px-4 sm:px-8 py-6">
                    <div class="max-w-4xl mx-auto space-y-4">
                        <div
                            v-if="filteredNotes.length === 0"
                            class="py-16 text-center text-xs"
                            :class="ui.mutedText"
                        >
                            No hay apuntes que coincidan con tu búsqueda.
                        </div>

                        <article
                            v-for="note in filteredNotes"
                            v-else
                            :key="`fs-${note.id}`"
                            class="rounded-2xl border p-5 sm:p-6 space-y-3 transition-colors"
                            :class="Number(note.page_number) === Number(currentPage) ? ui.cardActive : ui.cardIdle"
                        >
                            <div class="flex items-start justify-between gap-3 pb-3 border-b" :class="ui.border">
                                <div class="min-w-0">
                                    <h4 class="text-sm sm:text-base font-bold leading-snug">
                                        {{ note.title || 'Sin título' }}
                                    </h4>
                                    <span class="text-[11px]" :class="ui.subtleText">
                                        Actualizado {{ formatUpdatedAt(note.updated_at) }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <button
                                        v-if="note.page_number"
                                        type="button"
                                        class="rounded-lg px-2.5 py-1 text-xs font-bold transition-colors"
                                        :class="ui.pageBadge"
                                        @click="
                                            isFullscreenOpen = false;
                                            jumpToPage(note.page_number);
                                        "
                                    >
                                        Página {{ note.page_number }}
                                    </button>
                                    <span
                                        v-else
                                        class="rounded-lg px-2.5 py-1 text-xs font-medium"
                                        :class="ui.generalBadge"
                                    >
                                        General
                                    </span>

                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold transition-colors"
                                        :class="ui.retryBtn"
                                        @click="
                                            isFullscreenOpen = false;
                                            openEditor(note, 'write');
                                        "
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                        <span>Editar</span>
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="note.body?.trim()"
                                class="text-xs sm:text-sm leading-relaxed"
                                :class="ui.editorBody"
                                v-html="renderMiniMarkdown(note.body)"
                            />
                            <p v-else class="text-xs italic" :class="ui.mutedText">
                                Apunte vacío.
                            </p>
                        </article>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
