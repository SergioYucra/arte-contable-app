<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
    Plus,
    Search,
    BookOpen,
    ArrowLeft,
    Upload,
    FileText,
    ListOrdered,
    Settings2,
    Eye,
    Trash2,
    Archive,
    RotateCcw,
    CheckCircle2,
    Clock,
    HardDrive,
    ImagePlus,
    X,
    ChevronLeft,
    ChevronRight,
    ArrowUpDown,
    Sparkles,
    AlertTriangle,
} from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseToast from '../ui/BaseToast.vue';
import {
    inspectPdfFile,
    convertAndUploadPdfBatches,
    optimizeCoverImage,
    formatBytesHuman,
} from './pdfProcessor';

const props = defineProps({
    initialBooks: {
        type: Array,
        required: true,
    },
    storeBookUrl: {
        type: String,
        required: true,
    },
});

function cloneData(value) {
    return JSON.parse(JSON.stringify(value));
}

// --- Estado principal del catálogo ---
const books = ref(cloneData(props.initialBooks));
const searchQuery = ref('');
const statusFilter = ref('ACTIVE'); // ACTIVE | PUBLISHED | DRAFT | ARCHIVED

// --- Navegación interna sin recargas ---
const activeBookId = ref(null);
const activeTab = ref('editions'); // editions | toc | details
const selectedEditionId = ref(null);

// --- Notificaciones globales ---
const notification = ref(null);
let notificationTimer = null;

function notify(type, title, message = '') {
    if (notificationTimer) {
        clearTimeout(notificationTimer);
        notificationTimer = null;
    }

    notification.value = {
        id: Date.now() + Math.random(),
        type,
        title,
        message,
    };

    if (type === 'success' || type === 'info') {
        notificationTimer = setTimeout(() => {
            notification.value = null;
        }, 3500);
    }
}

function extractErrors(error, fallbackMessage = 'No se pudo completar la operación. Intenta nuevamente.') {
    const responseData = error?.response?.data;
    const fieldErrors = {};

    if (responseData?.errors && typeof responseData.errors === 'object') {
        Object.entries(responseData.errors).forEach(([key, messages]) => {
            fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
        });
    }

    const firstFieldError = Object.values(fieldErrors)[0];
    const message = firstFieldError || responseData?.message || error?.message || fallbackMessage;

    return { fieldErrors, message };
}

function syncBookInList(updatedBook) {
    const idx = books.value.findIndex((item) => item.id === updatedBook.id);
    if (idx !== -1) {
        books.value[idx] = updatedBook;
    } else {
        books.value.unshift(updatedBook);
    }
}

function syncEditionInBook(updatedEdition) {
    const book = books.value.find((item) => item.id === updatedEdition.book_id);
    if (!book) return;

    const edIdx = book.editions.findIndex((ed) => ed.id === updatedEdition.id);
    if (edIdx !== -1) {
        book.editions[edIdx] = updatedEdition;
    }
}

// --- Computados de catálogo y libro activo ---
const counts = computed(() => {
    const active = books.value.filter((b) => !b.is_archived);
    return {
        ACTIVE: active.length,
        PUBLISHED: active.filter((b) => b.is_published).length,
        DRAFT: active.filter((b) => !b.is_published).length,
        ARCHIVED: books.value.filter((b) => b.is_archived).length,
    };
});

const filteredBooks = computed(() => {
    const term = searchQuery.value.trim().toLowerCase();

    return books.value.filter((book) => {
        if (statusFilter.value === 'ACTIVE' && book.is_archived) return false;
        if (statusFilter.value === 'PUBLISHED' && (book.is_archived || !book.is_published)) return false;
        if (statusFilter.value === 'DRAFT' && (book.is_archived || book.is_published)) return false;
        if (statusFilter.value === 'ARCHIVED' && !book.is_archived) return false;

        if (!term) return true;

        return (
            book.title.toLowerCase().includes(term) ||
            (book.subtitle || '').toLowerCase().includes(term) ||
            book.author_name.toLowerCase().includes(term)
        );
    });
});

const activeBook = computed(() => {
    if (activeBookId.value === null) return null;
    return books.value.find((b) => b.id === activeBookId.value) || null;
});

const selectedEdition = computed(() => {
    if (!activeBook.value || !activeBook.value.editions?.length) return null;
    return (
        activeBook.value.editions.find((ed) => ed.id === selectedEditionId.value) ||
        activeBook.value.editions.find((ed) => ed.is_current) ||
        activeBook.value.editions[0]
    );
});

const currentEditionOfBook = (book) => {
    if (!book.editions?.length) return null;
    return book.editions.find((ed) => ed.is_current) || book.editions[0];
};

// --- Formularios del espacio de trabajo ---
const bookDetailsForm = ref({
    title: '',
    subtitle: '',
    author_name: '',
    description: '',
    is_published: false,
    remove_cover: false,
    coverFile: null,
    coverPreviewUrl: null,
});
const bookDetailsErrors = ref({});
const isSavingBookDetails = ref(false);

const editionForm = ref({
    edition_name: '',
    sample_pages_count: 12,
    is_current: true,
});
const editionFormErrors = ref({});
const isSavingEdition = ref(false);

const tocRows = ref([]);
const newTocTitle = ref('');
const newTocPage = ref('');
const tocErrors = ref({});
const isSavingToc = ref(false);

function revokeObjectUrlIfBlob(url) {
    if (url && typeof url === 'string' && url.startsWith('blob:')) {
        URL.revokeObjectURL(url);
    }
}

function hydrateBookDetailsForm(book) {
    revokeObjectUrlIfBlob(bookDetailsForm.value.coverPreviewUrl);
    bookDetailsErrors.value = {};

    if (!book) return;

    bookDetailsForm.value = {
        title: book.title || '',
        subtitle: book.subtitle || '',
        author_name: book.author_name || '',
        description: book.description || '',
        is_published: Boolean(book.is_published),
        remove_cover: false,
        coverFile: null,
        coverPreviewUrl: book.cover_url || null,
    };
}

function hydrateEditionState(edition) {
    editionFormErrors.value = {};
    tocErrors.value = {};

    if (!edition) {
        tocRows.value = [];
        return;
    }

    editionForm.value = {
        edition_name: edition.edition_name || '',
        sample_pages_count: edition.sample_pages_count ?? 12,
        is_current: Boolean(edition.is_current),
    };

    tocRows.value = (edition.toc || []).map((row, idx) => ({
        uid: `${edition.id}-${idx}-${Date.now()}`,
        title: row.title || '',
        page: row.page ?? 1,
    }));
}

watch(activeBook, (book) => {
    hydrateBookDetailsForm(book);

    if (!book) {
        selectedEditionId.value = null;
        return;
    }

    const exists = book.editions?.some((ed) => ed.id === selectedEditionId.value);
    if (!exists) {
        const preferred = currentEditionOfBook(book);
        selectedEditionId.value = preferred ? preferred.id : null;
    }
});

watch(selectedEdition, (edition) => {
    hydrateEditionState(edition);
    showReplaceUploader.value = false;
    resetPdfSelection();
});

function openBookWorkspace(book, initialTab = 'editions') {
    activeBookId.value = book.id;
    activeTab.value = initialTab;
    const preferred = currentEditionOfBook(book);
    selectedEditionId.value = preferred ? preferred.id : null;
    hydrateBookDetailsForm(book);
    hydrateEditionState(preferred);
}

function backToCatalog() {
    if (isUploadingPdf.value) return;
    activeBookId.value = null;
}

// --- Modal: Crear nuevo libro ---
const isCreateModalOpen = ref(false);
const createForm = ref({
    title: '',
    subtitle: '',
    author_name: '',
    description: '',
    coverFile: null,
    coverPreviewUrl: null,
});
const createErrors = ref({});
const isCreatingBook = ref(false);

function openCreateBookModal() {
    revokeObjectUrlIfBlob(createForm.value.coverPreviewUrl);
    createForm.value = {
        title: '',
        subtitle: '',
        author_name: '',
        description: '',
        coverFile: null,
        coverPreviewUrl: null,
    };
    createErrors.value = {};
    isCreateModalOpen.value = true;
}

async function handleCreateCoverSelect(event) {
    const rawFile = event.target.files?.[0];
    event.target.value = '';
    if (!rawFile) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(rawFile.type)) {
        createErrors.value.cover = 'Selecciona una imagen en formato JPG, PNG o optimizada.';
        return;
    }

    const optimized = await optimizeCoverImage(rawFile);
    if (optimized.size > 2 * 1024 * 1024) {
        createErrors.value.cover = 'La portada supera el límite máximo de 2 MB.';
        return;
    }

    delete createErrors.value.cover;
    revokeObjectUrlIfBlob(createForm.value.coverPreviewUrl);
    createForm.value.coverFile = optimized;
    createForm.value.coverPreviewUrl = URL.createObjectURL(optimized);
}

function clearCreateCover() {
    revokeObjectUrlIfBlob(createForm.value.coverPreviewUrl);
    createForm.value.coverFile = null;
    createForm.value.coverPreviewUrl = null;
}

function validateBookClientFields(formState) {
    const errors = {};
    if (!formState.title || formState.title.trim().length < 3) {
        errors.title = 'El título debe tener al menos 3 caracteres.';
    }
    if (!formState.author_name || formState.author_name.trim().length < 2) {
        errors.author_name = 'Ingresa el nombre del autor o equipo editorial.';
    }
    return errors;
}

async function submitCreateBook() {
    const clientErrors = validateBookClientFields(createForm.value);
    if (Object.keys(clientErrors).length > 0) {
        createErrors.value = clientErrors;
        return;
    }

    isCreatingBook.value = true;
    createErrors.value = {};

    try {
        const formData = new FormData();
        formData.append('title', createForm.value.title.trim());
        formData.append('subtitle', createForm.value.subtitle.trim());
        formData.append('author_name', createForm.value.author_name.trim());
        formData.append('description', createForm.value.description.trim());
        formData.append('is_published', '0');

        if (createForm.value.coverFile) {
            formData.append('cover', createForm.value.coverFile);
        }

        const { data } = await axios.post(props.storeBookUrl, formData);
        syncBookInList(data.book);
        isCreateModalOpen.value = false;
        clearCreateCover();
        openBookWorkspace(data.book, 'editions');
        notify('success', 'Libro registrado', data.message);
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        createErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            createErrors.value.general = message;
        }
    } finally {
        isCreatingBook.value = false;
    }
}

// --- Actualizar ficha del libro ---
async function handleDetailsCoverSelect(event) {
    const rawFile = event.target.files?.[0];
    event.target.value = '';
    if (!rawFile) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(rawFile.type)) {
        bookDetailsErrors.value.cover = 'Selecciona una imagen válida (JPG, PNG).';
        return;
    }

    const optimized = await optimizeCoverImage(rawFile);
    if (optimized.size > 2 * 1024 * 1024) {
        bookDetailsErrors.value.cover = 'La imagen supera el límite máximo de 2 MB.';
        return;
    }

    delete bookDetailsErrors.value.cover;
    revokeObjectUrlIfBlob(bookDetailsForm.value.coverPreviewUrl);
    bookDetailsForm.value.coverFile = optimized;
    bookDetailsForm.value.remove_cover = false;
    bookDetailsForm.value.coverPreviewUrl = URL.createObjectURL(optimized);
}

function removeDetailsCover() {
    revokeObjectUrlIfBlob(bookDetailsForm.value.coverPreviewUrl);
    bookDetailsForm.value.coverFile = null;
    bookDetailsForm.value.coverPreviewUrl = null;
    bookDetailsForm.value.remove_cover = true;
}

async function submitBookDetails() {
    if (!activeBook.value) return;

    const clientErrors = validateBookClientFields(bookDetailsForm.value);
    if (Object.keys(clientErrors).length > 0) {
        bookDetailsErrors.value = clientErrors;
        return;
    }

    isSavingBookDetails.value = true;
    bookDetailsErrors.value = {};

    try {
        const formData = new FormData();
        formData.append('title', bookDetailsForm.value.title.trim());
        formData.append('subtitle', bookDetailsForm.value.subtitle.trim());
        formData.append('author_name', bookDetailsForm.value.author_name.trim());
        formData.append('description', bookDetailsForm.value.description.trim());
        formData.append('is_published', bookDetailsForm.value.is_published ? '1' : '0');
        formData.append('remove_cover', bookDetailsForm.value.remove_cover ? '1' : '0');

        if (bookDetailsForm.value.coverFile) {
            formData.append('cover', bookDetailsForm.value.coverFile);
        }

        const { data } = await axios.post(activeBook.value.update_url, formData);
        syncBookInList(data.book);
        hydrateBookDetailsForm(data.book);
        notify('success', 'Ficha actualizada', data.message);
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        bookDetailsErrors.value = fieldErrors;
        notify('error', 'Revisa los datos de la ficha', message);
    } finally {
        isSavingBookDetails.value = false;
    }
}

// --- Archivar / Restaurar libro ---
const confirmDialog = ref({
    open: false,
    title: '',
    description: '',
    confirmLabel: 'Confirmar',
    variant: 'danger',
    loading: false,
    onConfirm: null,
});

function openConfirmDialog({ title, description, confirmLabel, variant = 'danger', onConfirm }) {
    confirmDialog.value = {
        open: true,
        title,
        description,
        confirmLabel,
        variant,
        loading: false,
        onConfirm,
    };
}

async function executeConfirmDialog() {
    if (!confirmDialog.value.onConfirm) return;
    confirmDialog.value.loading = true;
    try {
        await confirmDialog.value.onConfirm();
        confirmDialog.value.open = false;
    } finally {
        confirmDialog.value.loading = false;
    }
}

function promptArchiveBook(book) {
    openConfirmDialog({
        title: 'Archivar libro',
        description: `«${book.title}» dejará de mostrarse en el catálogo público. Sus ediciones y apuntes asociados se conservarán intactos.`,
        confirmLabel: 'Archivar libro',
        variant: 'danger',
        onConfirm: async () => {
            try {
                const { data } = await axios.delete(book.destroy_url);
                syncBookInList(data.book);
                if (activeBookId.value === book.id) {
                    activeBookId.value = null;
                }
                notify('success', 'Libro archivado', data.message);
            } catch (error) {
                const { message } = extractErrors(error);
                notify('error', 'No se pudo archivar', message);
            }
        },
    });
}

async function restoreBook(book) {
    try {
        const { data } = await axios.post(book.restore_url);
        syncBookInList(data.book);
        notify('success', 'Libro restaurado', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        notify('error', 'No se pudo restaurar', message);
    }
}

// --- Gestión de Ediciones ---
const isCreateEditionModalOpen = ref(false);
const createEditionForm = ref({
    edition_name: '',
    sample_pages_count: 12,
    is_current: true,
});
const createEditionErrors = ref({});
const isCreatingEdition = ref(false);

function openCreateEditionModal() {
    createEditionForm.value = {
        edition_name: '',
        sample_pages_count: 12,
        is_current: true,
    };
    createEditionErrors.value = {};
    isCreateEditionModalOpen.value = true;
}

async function submitCreateEdition() {
    if (!activeBook.value) return;

    if (!createEditionForm.value.edition_name.trim()) {
        createEditionErrors.value = { edition_name: 'Ingresa el nombre de la nueva edición.' };
        return;
    }

    isCreatingEdition.value = true;
    createEditionErrors.value = {};

    try {
        const { data } = await axios.post(activeBook.value.create_edition_url, {
            edition_name: createEditionForm.value.edition_name.trim(),
            sample_pages_count: Number(createEditionForm.value.sample_pages_count),
            is_current: Boolean(createEditionForm.value.is_current),
        });

        syncBookInList(data.book);
        selectedEditionId.value = data.selected_edition_id;
        isCreateEditionModalOpen.value = false;
        notify('success', 'Edición creada', data.message);
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        createEditionErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            createEditionErrors.value.general = message;
        }
    } finally {
        isCreatingEdition.value = false;
    }
}

async function submitEditionSettings() {
    if (!selectedEdition.value) return;

    isSavingEdition.value = true;
    editionFormErrors.value = {};

    try {
        const { data } = await axios.patch(selectedEdition.value.update_url, {
            edition_name: editionForm.value.edition_name.trim(),
            sample_pages_count: Number(editionForm.value.sample_pages_count),
            is_current: Boolean(editionForm.value.is_current),
        });

        syncBookInList(data.book);
        notify('success', 'Edición actualizada', data.message);
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        editionFormErrors.value = fieldErrors;
        notify('error', 'Revisa la configuración', message);
    } finally {
        isSavingEdition.value = false;
    }
}

function promptDeleteEdition() {
    const edition = selectedEdition.value;
    if (!edition || edition.is_current) return;

    openConfirmDialog({
        title: 'Archivar edición',
        description: `Se archivará «${edition.edition_name}» y se liberará el espacio de sus páginas almacenadas (${edition.storage_human}).`,
        confirmLabel: 'Archivar y liberar espacio',
        variant: 'danger',
        onConfirm: async () => {
            try {
                const { data } = await axios.delete(edition.destroy_url);
                syncBookInList(data.book);
                const fallback = currentEditionOfBook(data.book);
                selectedEditionId.value = fallback ? fallback.id : null;
                notify('success', 'Edición archivada', data.message);
            } catch (error) {
                const { message } = extractErrors(error);
                notify('error', 'No se pudo archivar la edición', message);
            }
        },
    });
}

function promptPurgeEditionPages() {
    const edition = selectedEdition.value;
    if (!edition) return;

    openConfirmDialog({
        title: 'Liberar espacio de páginas',
        description: `Se eliminarán las ${edition.stored_pages_count} páginas cargadas de «${edition.edition_name}» (${edition.storage_human}) y la edición volverá al estado «En preparación».`,
        confirmLabel: 'Vaciar páginas',
        variant: 'danger',
        onConfirm: async () => {
            try {
                const { data } = await axios.delete(edition.purge_pages_url);
                syncEditionInBook(data.edition);
                notify('success', 'Espacio liberado', data.message);
            } catch (error) {
                const { message } = extractErrors(error);
                notify('error', 'Operación bloqueada', message);
            }
        },
    });
}

// --- Conversor y subida por lotes de PDF ---
const selectedPdfFile = ref(null);
const pdfPageCount = ref(0);
const isInspectingPdf = ref(false);
const isDraggingPdf = ref(false);
const isUploadingPdf = ref(false);
const showReplaceUploader = ref(false);
const pdfError = ref('');
const pdfSummary = ref(null);
const uploadProgress = ref({
    phase: 'idle',
    currentPage: 0,
    totalPages: 0,
    optimizedBytes: 0,
});
let uploadAbortController = null;

function resetPdfSelection() {
    if (isUploadingPdf.value) return;
    selectedPdfFile.value = null;
    pdfPageCount.value = 0;
    pdfError.value = '';
    pdfSummary.value = null;
    uploadProgress.value = {
        phase: 'idle',
        currentPage: 0,
        totalPages: 0,
        optimizedBytes: 0,
    };
}

async function processSelectedPdf(file) {
    if (!file || isUploadingPdf.value) return;

    pdfError.value = '';
    pdfSummary.value = null;
    isInspectingPdf.value = true;

    try {
        const { pageCount } = await inspectPdfFile(file);
        selectedPdfFile.value = file;
        pdfPageCount.value = pageCount;
    } catch (error) {
        selectedPdfFile.value = null;
        pdfPageCount.value = 0;
        pdfError.value = error?.message || 'No se pudo leer el archivo PDF seleccionado.';
    } finally {
        isInspectingPdf.value = false;
    }
}

function onPdfInputChange(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (file) {
        processSelectedPdf(file);
    }
}

function onPdfDrop(event) {
    isDraggingPdf.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (file) {
        processSelectedPdf(file);
    }
}

const uploadPercentage = computed(() => {
    if (!uploadProgress.value.totalPages) return 0;
    return Math.min(100, Math.round((uploadProgress.value.currentPage / uploadProgress.value.totalPages) * 100));
});

function confirmAndStartPdfUpload() {
    const edition = selectedEdition.value;
    if (!edition || !selectedPdfFile.value) return;

    if (edition.stored_pages_count > 0) {
        openConfirmDialog({
            title: 'Reemplazar páginas existentes',
            description: `«${edition.edition_name}» ya cuenta con ${edition.stored_pages_count} páginas procesadas (${edition.storage_human}). Al continuar se reemplazarán por las ${pdfPageCount.value} páginas del nuevo archivo. Recuerda revisar luego el índice de temas.`,
            confirmLabel: 'Reemplazar y procesar',
            variant: 'warning',
            onConfirm: async () => {
                await startPdfConversionAndUpload();
            },
        });
        return;
    }

    startPdfConversionAndUpload();
}

async function startPdfConversionAndUpload() {
    const edition = selectedEdition.value;
    const file = selectedPdfFile.value;
    if (!edition || !file || isUploadingPdf.value) return;

    isUploadingPdf.value = true;
    pdfError.value = '';
    pdfSummary.value = null;
    uploadAbortController = new AbortController();

    const originalSize = file.size;

    try {
        const result = await convertAndUploadPdfBatches({
            file,
            batchSize: 5,
            targetWidth: 1200,
            quality: 0.82,
            signal: uploadAbortController.signal,
            onProgress: (progress) => {
                uploadProgress.value = progress;
            },
            uploadBatch: async ({ pages, totalPages, isFirstBatch, isFinalBatch, signal }) => {
                const formData = new FormData();
                formData.append('total_pages', String(totalPages));
                formData.append('is_first_batch', isFirstBatch ? '1' : '0');
                formData.append('is_final_batch', isFinalBatch ? '1' : '0');

                pages.forEach((item, index) => {
                    formData.append(`pages[${index}][number]`, String(item.number));
                    formData.append(`pages[${index}][file]`, item.blob, `page-${item.number}.webp`);
                });

                const { data } = await axios.post(edition.upload_pages_url, formData, { signal });
                if (data?.edition) {
                    syncEditionInBook(data.edition);
                }
            },
        });

        selectedPdfFile.value = null;
        pdfPageCount.value = 0;
        showReplaceUploader.value = false;
        pdfSummary.value = {
            pages: result.totalPages,
            originalHuman: formatBytesHuman(originalSize),
            optimizedHuman: formatBytesHuman(result.optimizedBytes),
        };
        notify(
            'success',
            'Páginas listas para lectura',
            `Se procesaron y guardaron ${result.totalPages} páginas correctamente.`,
        );
    } catch (error) {
        if (error?.name === 'AbortError' || error?.code === 'ERR_CANCELED') {
            pdfError.value = 'La carga fue detenida. Puedes reintentar cuando lo desees.';
        } else {
            const { message } = extractErrors(error, 'Ocurrió un error al procesar o guardar las páginas.');
            pdfError.value = message;
        }
    } finally {
        isUploadingPdf.value = false;
        uploadAbortController = null;
    }
}

function cancelPdfUpload() {
    uploadAbortController?.abort();
}

// --- Índice de temas (TOC) ---
function addTocRow() {
    const title = newTocTitle.value.trim();
    const suggestedPage = newTocPage.value
        ? Number(newTocPage.value)
        : tocRows.value.length > 0
          ? Number(tocRows.value[tocRows.value.length - 1].page) + 1
          : 1;

    if (!title) {
        tocErrors.value = { quickAdd: 'Escribe el título del capítulo o tema antes de agregarlo.' };
        return;
    }

    const maxPage = selectedEdition.value?.total_pages > 0 ? selectedEdition.value.total_pages : 2000;
    if (!Number.isInteger(suggestedPage) || suggestedPage < 1 || suggestedPage > maxPage) {
        tocErrors.value = { quickAdd: `Indica una página válida entre 1 y ${maxPage}.` };
        return;
    }

    tocErrors.value = {};
    tocRows.value.push({
        uid: `new-${Date.now()}-${Math.random()}`,
        title,
        page: suggestedPage,
    });

    newTocTitle.value = '';
    newTocPage.value = '';
}

function removeTocRow(index) {
    tocRows.value.splice(index, 1);
}

function sortTocRows() {
    tocRows.value.sort((a, b) => Number(a.page || 0) - Number(b.page || 0));
}

async function saveToc() {
    const edition = selectedEdition.value;
    if (!edition) return;

    const maxPage = edition.total_pages > 0 ? edition.total_pages : 2000;
    for (let i = 0; i < tocRows.value.length; i += 1) {
        const row = tocRows.value[i];
        if (!row.title || !row.title.trim()) {
            tocErrors.value = { general: `La fila #${i + 1} no tiene título.` };
            return;
        }
        const pageNum = Number(row.page);
        if (!Number.isInteger(pageNum) || pageNum < 1 || pageNum > maxPage) {
            tocErrors.value = { general: `La página de «${row.title}» debe estar entre 1 y ${maxPage}.` };
            return;
        }
    }

    isSavingToc.value = true;
    tocErrors.value = {};

    try {
        const payload = tocRows.value.map((row) => ({
            title: row.title.trim(),
            page: Number(row.page),
        }));

        const { data } = await axios.put(edition.update_toc_url, { toc: payload });
        syncEditionInBook(data.edition);
        hydrateEditionState(data.edition);
        notify('success', 'Índice guardado', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        tocErrors.value = { general: message };
    } finally {
        isSavingToc.value = false;
    }
}

// --- Modal de vista previa de páginas protegidas ---
const isPreviewModalOpen = ref(false);
const previewPageNumber = ref(1);
const isPreviewImageLoading = ref(false);

const previewImageUrl = computed(() => {
    if (!isPreviewModalOpen.value || !selectedEdition.value) return '';
    return selectedEdition.value.preview_page_url_template.replace('__PAGE__', String(previewPageNumber.value));
});

function openPagePreview(page = 1) {
    if (!selectedEdition.value || selectedEdition.value.stored_pages_count < 1) return;
    const clamped = Math.min(Math.max(1, Number(page) || 1), selectedEdition.value.stored_pages_count);
    previewPageNumber.value = clamped;
    isPreviewImageLoading.value = true;
    isPreviewModalOpen.value = true;
}

function stepPreviewPage(delta) {
    if (!selectedEdition.value) return;
    const next = previewPageNumber.value + delta;
    if (next < 1 || next > selectedEdition.value.stored_pages_count) return;
    isPreviewImageLoading.value = true;
    previewPageNumber.value = next;
}

// --- Protección contra cierre accidental durante subida ---
function handleBeforeUnload(event) {
    if (isUploadingPdf.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    if (notificationTimer) clearTimeout(notificationTimer);
    revokeObjectUrlIfBlob(createForm.value.coverPreviewUrl);
    revokeObjectUrlIfBlob(bookDetailsForm.value.coverPreviewUrl);
});
</script>

<template>
    <div class="space-y-5">
        <!-- Notificación flotante suave y global (Toast) -->
        <BaseToast v-model="notification" />

        <!-- ================================================================ -->
        <!-- VISTA 1: CATÁLOGO DE LIBROS                                      -->
        <!-- ================================================================ -->
        <section v-if="!activeBook" class="space-y-4 sm:space-y-5">
            <!-- Barra de filtros, búsqueda y acción principal -->
            <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 sm:gap-4">
                <!-- Pestañas con desplazamiento horizontal en pantallas pequeñas -->
                <div class="-mx-3 px-3 sm:mx-0 sm:px-0 flex items-center gap-1.5 overflow-x-auto no-scrollbar snap-x">
                    <button
                        v-for="tab in [
                            { key: 'ACTIVE', label: 'Activos', count: counts.ACTIVE },
                            { key: 'PUBLISHED', label: 'Publicados', count: counts.PUBLISHED },
                            { key: 'DRAFT', label: 'En borrador', count: counts.DRAFT },
                            { key: 'ARCHIVED', label: 'Archivados', count: counts.ARCHIVED },
                        ]"
                        :key="tab.key"
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-2 sm:py-1.5 rounded-brand text-xs font-medium whitespace-nowrap transition-colors"
                        :class="
                            statusFilter === tab.key
                                ? 'bg-primary text-primary-foreground shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="statusFilter = tab.key"
                    >
                        <span>{{ tab.label }}</span>
                        <span
                            class="px-1.5 py-0.5 rounded-full text-[11px] font-semibold"
                            :class="
                                statusFilter === tab.key
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-surface text-muted'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="relative flex-1 min-w-0 lg:w-72">
                        <Search class="h-4 w-4 text-muted absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                        <input
                            v-model="searchQuery"
                            type="search"
                            inputmode="search"
                            placeholder="Buscar título o autor..."
                            class="w-full pl-9 pr-9 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <button
                            v-if="searchQuery"
                            type="button"
                            class="absolute right-1 top-1/2 -translate-y-1/2 p-2 text-muted hover:text-foreground"
                            aria-label="Limpiar búsqueda"
                            @click="searchQuery = ''"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 inline-flex items-center justify-center gap-2 h-10 sm:h-auto w-10 sm:w-auto sm:px-4 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-sm font-semibold transition-colors shadow-xs"
                        aria-label="Nuevo libro"
                        @click="openCreateBookModal"
                    >
                        <Plus class="h-4 w-4" />
                        <span class="hidden sm:inline">Nuevo libro</span>
                    </button>
                </div>
            </div>

            <!-- Estado vacío inicial o sin coincidencias -->
            <div
                v-if="filteredBooks.length === 0"
                class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-3"
            >
                <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                    <BookOpen class="h-6 w-6" />
                </div>
                <h2 class="text-base font-semibold text-foreground">
                    {{ books.length === 0 ? 'Aún no hay libros registrados' : 'No se encontraron libros con este filtro' }}
                </h2>
                <p class="text-sm text-muted max-w-md mx-auto">
                    {{
                        books.length === 0
                            ? 'Registra la primera obra editorial para cargar sus páginas optimizadas y armar su índice de temas.'
                            : 'Prueba cambiando el estado seleccionado o limpiando el término de búsqueda.'
                    }}
                </p>
                <div class="pt-2">
                    <button
                        v-if="books.length === 0"
                        type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-sm font-semibold transition-colors"
                        @click="openCreateBookModal"
                    >
                        <Plus class="h-4 w-4" />
                        <span>Registrar primer libro</span>
                    </button>
                    <button
                        v-else
                        type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-brand bg-background border border-border text-foreground hover:bg-surface text-sm font-medium transition-colors"
                        @click="
                            searchQuery = '';
                            statusFilter = 'ACTIVE';
                        "
                    >
                        Mostrar libros activos
                    </button>
                </div>
            </div>

            <!-- Cuadrícula adaptable de libros -->
            <div v-else class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-3 sm:gap-5">
                <article
                    v-for="book in filteredBooks"
                    :key="book.id"
                    class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col justify-between gap-3 sm:gap-4 hover:border-primary/40 transition-colors"
                >
                    <div class="flex gap-3 sm:gap-4">
                        <!-- Portada o cubierta tipográfica -->
                        <div
                            class="w-20 sm:w-28 aspect-[3/4] shrink-0 rounded-brand overflow-hidden border border-border bg-secondary text-secondary-foreground flex items-center justify-center relative"
                        >
                            <img
                                v-if="book.cover_url"
                                :src="book.cover_url"
                                :alt="`Portada de ${book.title}`"
                                class="w-full h-full object-cover"
                                loading="lazy"
                            />
                            <div v-else class="p-2.5 text-center space-y-1.5 select-none">
                                <BookOpen class="h-6 w-6 mx-auto text-accent opacity-90" />
                                <p class="text-[11px] font-semibold leading-tight line-clamp-3">
                                    {{ book.title }}
                                </p>
                            </div>
                        </div>

                        <!-- Información resumida -->
                        <div class="flex-1 min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-1.5">
                                <span
                                    v-if="book.is_archived"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                                >
                                    Archivado
                                </span>
                                <span
                                    v-else-if="book.is_published"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                                >
                                    Publicado
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20"
                                >
                                    En borrador
                                </span>
                            </div>

                            <div>
                                <h3 class="text-sm sm:text-base font-semibold text-foreground leading-snug line-clamp-2">
                                    {{ book.title }}
                                </h3>
                                <p v-if="book.subtitle" class="text-xs text-muted line-clamp-1 mt-0.5">
                                    {{ book.subtitle }}
                                </p>
                                <p class="text-xs font-medium text-muted mt-1">
                                    {{ book.author_name }}
                                </p>
                            </div>

                            <div
                                v-if="currentEditionOfBook(book)"
                                class="pt-1 text-xs text-muted flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <span class="font-medium text-foreground">
                                    {{ currentEditionOfBook(book).edition_name }}
                                </span>
                                <span>·</span>
                                <span>{{ currentEditionOfBook(book).stored_pages_count }} págs.</span>
                                <span>·</span>
                                <span>{{ currentEditionOfBook(book).storage_human }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Pie de acciones de tarjeta -->
                    <div class="pt-3 border-t border-border flex items-center justify-between gap-2">
                        <template v-if="!book.is_archived">
                            <button
                                type="button"
                                class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                                @click="openBookWorkspace(book, 'editions')"
                            >
                                <span>Gestionar obra</span>
                            </button>

                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-background hover:bg-rose-500/10 text-muted hover:text-rose-600 dark:hover:text-rose-400 border border-border text-xs font-medium transition-colors"
                                title="Archivar libro"
                                aria-label="Archivar libro"
                                @click="promptArchiveBook(book)"
                            >
                                <Archive class="h-4 w-4 sm:h-3.5 sm:w-3.5" />
                                <span class="hidden sm:inline">Archivar</span>
                            </button>
                        </template>

                        <template v-else>
                            <button
                                type="button"
                                class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                                @click="restoreBook(book)"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                <span>Restaurar libro</span>
                            </button>
                        </template>
                    </div>
                </article>
            </div>
        </section>

        <!-- ================================================================ -->
        <!-- VISTA 2: ESPACIO DE GESTIÓN DEL LIBRO SELECCIONADO               -->
        <!-- ================================================================ -->
        <section v-else class="space-y-4 sm:space-y-5">
            <!-- Cabecera compacta de la obra y pestañas (fija al desplazar en móvil) -->
            <div class="bg-surface border border-border rounded-brand overflow-hidden sticky top-16 z-10 lg:static">
                <div class="p-3 sm:p-5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 sm:gap-3.5 min-w-0">
                        <button
                            type="button"
                            :disabled="isUploadingPdf"
                            class="inline-flex items-center justify-center gap-1.5 h-9 w-9 sm:h-auto sm:w-auto sm:px-3 sm:py-1.5 rounded-brand bg-background border border-border text-xs font-semibold text-foreground hover:border-primary/40 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shrink-0"
                            aria-label="Volver al catálogo"
                            @click="backToCatalog"
                        >
                            <ArrowLeft class="h-4 w-4 sm:h-3.5 sm:w-3.5" />
                            <span class="hidden sm:inline">Catálogo</span>
                        </button>

                        <div class="min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <h2 class="text-base sm:text-lg font-semibold text-foreground truncate">
                                    {{ activeBook.title }}
                                </h2>
                                <span
                                    class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] sm:text-[11px] font-semibold border"
                                    :class="
                                        activeBook.is_published
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                                    "
                                >
                                    {{ activeBook.is_published ? 'Publicado' : 'En borrador' }}
                                </span>
                            </div>
                            <p class="text-xs text-muted truncate mt-0.5">
                                {{ activeBook.author_name }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            v-if="selectedEdition && selectedEdition.stored_pages_count > 0"
                            type="button"
                            class="inline-flex items-center justify-center gap-1.5 h-9 w-9 sm:h-auto sm:w-auto sm:px-3 sm:py-1.5 rounded-brand bg-background border border-border text-xs font-medium text-foreground hover:border-primary/40 transition-colors"
                            aria-label="Vista previa"
                            @click="openPagePreview(1)"
                        >
                            <Eye class="h-4 w-4 sm:h-3.5 sm:w-3.5 text-primary" />
                            <span class="hidden sm:inline">Vista previa</span>
                        </button>
                    </div>
                </div>

                <!-- Pestañas de trabajo: reparto equitativo en móvil, desplazables si no caben -->
                <div class="px-1 sm:px-5 border-t border-border bg-background/50 flex items-stretch gap-0 sm:gap-1 overflow-x-auto no-scrollbar" role="tablist">
                    <button
                        v-for="tab in [
                            { id: 'editions', label: 'Ediciones y páginas', short: 'Páginas', icon: Upload },
                            {
                                id: 'toc',
                                label: 'Índice de temas',
                                short: 'Índice',
                                icon: ListOrdered,
                                badge: selectedEdition ? selectedEdition.toc.length : 0,
                            },
                            { id: 'details', label: 'Ficha del libro', short: 'Ficha', icon: Settings2 },
                        ]"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === tab.id"
                        class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 sm:gap-2 px-2 sm:px-3.5 py-3 text-xs sm:text-sm font-medium border-b-2 whitespace-nowrap transition-colors"
                        :class="
                            activeTab === tab.id
                                ? 'border-primary text-primary font-semibold'
                                : 'border-transparent text-muted hover:text-foreground'
                        "
                        @click="activeTab = tab.id"
                    >
                        <component :is="tab.icon" class="h-4 w-4 shrink-0" />
                        <span class="sm:hidden">{{ tab.short }}</span>
                        <span class="hidden sm:inline">{{ tab.label }}</span>
                        <span
                            v-if="tab.badge !== undefined"
                            class="px-1.5 py-0.5 rounded-full text-[10px] sm:text-[11px] bg-surface border border-border text-muted"
                        >
                            {{ tab.badge }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- PESTAÑA 1: EDICIONES Y CARGA DE PÁGINAS                      -->
            <!-- ============================================================ -->
            <div v-if="activeTab === 'editions'" class="space-y-4 sm:space-y-5">
                <!-- Selector horizontal de ediciones -->
                <div class="flex items-center justify-between gap-2 sm:gap-3">
                    <div class="flex-1 min-w-0 flex items-center gap-2 overflow-x-auto no-scrollbar snap-x">
                        <button
                            v-for="edition in activeBook.editions"
                            :key="edition.id"
                            type="button"
                            :disabled="isUploadingPdf"
                            class="shrink-0 snap-start inline-flex items-center gap-2 px-3.5 py-2.5 sm:py-2 rounded-brand text-xs font-medium border whitespace-nowrap transition-colors disabled:opacity-60"
                            :class="
                                selectedEdition?.id === edition.id
                                    ? 'bg-primary text-primary-foreground border-primary shadow-xs'
                                    : 'bg-surface text-foreground border-border hover:border-primary/40'
                            "
                            @click="selectedEditionId = edition.id"
                        >
                            <span>{{ edition.edition_name }}</span>
                            <span
                                v-if="edition.is_current"
                                class="px-1.5 py-0.5 rounded-full text-[10px] font-semibold"
                                :class="
                                    selectedEdition?.id === edition.id
                                        ? 'bg-primary-foreground/20 text-primary-foreground'
                                        : 'bg-primary/10 text-primary'
                                "
                            >
                                Vigente
                            </span>
                        </button>
                    </div>

                    <button
                        type="button"
                        :disabled="isUploadingPdf"
                        class="shrink-0 inline-flex items-center justify-center gap-1.5 h-10 w-10 sm:h-auto sm:w-auto sm:px-3 sm:py-2 rounded-brand bg-surface border border-border hover:border-primary/40 text-xs font-semibold text-foreground transition-colors disabled:opacity-50"
                        aria-label="Nueva edición"
                        @click="openCreateEditionModal"
                    >
                        <Plus class="h-4 w-4 sm:h-3.5 sm:w-3.5 text-primary" />
                        <span class="hidden sm:inline">Nueva edición</span>
                    </button>
                </div>

                <div v-if="selectedEdition" class="grid grid-cols-1 xl:grid-cols-12 gap-4 sm:gap-5 items-start">
                    <!-- Columna izquierda: Configuración de la edición y administración de espacio -->
                    <div class="xl:col-span-5 space-y-4 sm:space-y-5">
                        <!-- Estado y espacio ocupado -->
                        <div class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wider text-muted">
                                    Estado y almacenamiento
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold border"
                                    :class="
                                        selectedEdition.status === 'ready'
                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                                    "
                                >
                                    <CheckCircle2 v-if="selectedEdition.status === 'ready'" class="h-3.5 w-3.5" />
                                    <Clock v-else class="h-3.5 w-3.5" />
                                    {{ selectedEdition.status_label }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="p-3 rounded-brand bg-background border border-border">
                                    <div class="text-xs text-muted">Páginas guardadas</div>
                                    <div class="text-lg font-bold text-foreground mt-0.5">
                                        {{ selectedEdition.stored_pages_count }}
                                    </div>
                                </div>
                                <div class="p-3 rounded-brand bg-background border border-border">
                                    <div class="text-xs text-muted flex items-center gap-1">
                                        <HardDrive class="h-3.5 w-3.5" />
                                        <span>Espacio en disco</span>
                                    </div>
                                    <div class="text-lg font-bold text-foreground mt-0.5">
                                        {{ selectedEdition.storage_human }}
                                    </div>
                                </div>
                            </div>

                            <div v-if="selectedEdition.stored_pages_count > 0" class="grid grid-cols-2 gap-2 pt-1">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-background border border-border hover:border-primary/40 text-xs font-semibold text-foreground transition-colors"
                                    @click="openPagePreview(1)"
                                >
                                    <Eye class="h-3.5 w-3.5 text-primary" />
                                    <span>Revisar páginas</span>
                                </button>

                                <button
                                    type="button"
                                    :disabled="isUploadingPdf || (selectedEdition.is_current && activeBook.is_published)"
                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-background border border-border hover:bg-rose-500/10 text-muted hover:text-rose-600 dark:hover:text-rose-400 text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                    title="Eliminar archivos de páginas para liberar espacio"
                                    @click="promptPurgeEditionPages"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    <span>Liberar espacio</span>
                                </button>
                            </div>

                            <p
                                v-if="
                                    selectedEdition.stored_pages_count > 0 &&
                                    selectedEdition.is_current &&
                                    activeBook.is_published
                                "
                                class="text-[11px] text-muted leading-relaxed"
                            >
                                Para vaciar las páginas de una edición vigente, primero pasa el libro a borrador o activa otra edición.
                            </p>
                        </div>

                        <!-- Ajustes de la edición -->
                        <form
                            class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4"
                            @submit.prevent="submitEditionSettings"
                        >
                            <h3 class="text-sm font-semibold text-foreground">Configuración de la edición</h3>

                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-foreground">Nombre de la edición</label>
                                <input
                                    v-model="editionForm.edition_name"
                                    type="text"
                                    maxlength="100"
                                    required
                                    class="w-full px-3 py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                                />
                                <p v-if="editionFormErrors.edition_name" class="text-xs text-rose-600 dark:text-rose-400">
                                    {{ editionFormErrors.edition_name }}
                                </p>
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-xs font-medium text-foreground">
                                    Cantidad de páginas de muestra gratuita
                                </label>
                                <input
                                    v-model.number="editionForm.sample_pages_count"
                                    type="number"
                                    min="0"
                                    max="500"
                                    required
                                    class="w-full px-3 py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                                />
                                <p class="text-[11px] text-muted">
                                    Los lectores con cuenta gratuita podrán leer hasta esta página sin costo.
                                </p>
                                <p
                                    v-if="editionFormErrors.sample_pages_count"
                                    class="text-xs text-rose-600 dark:text-rose-400"
                                >
                                    {{ editionFormErrors.sample_pages_count }}
                                </p>
                            </div>

                            <label class="flex items-start gap-2.5 cursor-pointer select-none pt-1">
                                <input
                                    v-model="editionForm.is_current"
                                    type="checkbox"
                                    :disabled="activeBook.editions.length <= 1"
                                    class="mt-0.5 rounded border-border text-primary focus:ring-primary/30"
                                />
                                <span class="text-xs text-foreground leading-snug">
                                    Establecer como <strong>edición vigente</strong> del libro
                                </span>
                            </label>

                            <div class="pt-2 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-2 sm:gap-3">
                                <button
                                    type="submit"
                                    :disabled="isSavingEdition || isUploadingPdf"
                                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                                >
                                    {{ isSavingEdition ? 'Guardando...' : 'Guardar ajustes' }}
                                </button>

                                <button
                                    v-if="!selectedEdition.is_current"
                                    type="button"
                                    :disabled="isUploadingPdf"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                    @click="promptDeleteEdition"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    <span>Archivar edición</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Columna derecha: Conversor de PDF (primero en móvil por ser la acción principal) -->
                    <div class="order-first xl:order-none xl:col-span-7 bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-foreground">
                                    Cargar páginas desde documento PDF
                                </h3>
                                <p class="text-xs text-muted mt-0.5">
                                    Tu navegador optimiza cada página y la envía en bloques de 5 en 5 sin sobrecargar el servidor.
                                </p>
                            </div>
                        </div>

                        <BaseAlert v-if="pdfError" type="error" title="No se pudo procesar el documento">
                            {{ pdfError }}
                        </BaseAlert>

                        <BaseAlert
                            v-if="pdfSummary"
                            type="success"
                            title="Carga optimizada completada"
                        >
                            Se almacenaron {{ pdfSummary.pages }} páginas. El peso pasó de
                            <strong>{{ pdfSummary.originalHuman }}</strong> a
                            <strong>{{ pdfSummary.optimizedHuman }}</strong>.
                        </BaseAlert>

                        <!-- Estado cuando la edición ya tiene páginas procesadas (bloqueo protector) -->
                        <div
                            v-if="!isUploadingPdf && !selectedPdfFile && selectedEdition.stored_pages_count > 0 && !showReplaceUploader"
                            class="rounded-brand border border-emerald-500/20 bg-emerald-500/5 p-4 sm:p-5 space-y-4"
                        >
                            <div class="flex items-start gap-3">
                                <div class="h-10 w-10 rounded-brand bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                                    <CheckCircle2 class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-sm font-semibold text-foreground">
                                        Páginas listas para lectura
                                    </h4>
                                    <p class="text-xs text-muted mt-0.5 leading-relaxed">
                                        Esta edición cuenta actualmente con
                                        <strong>{{ selectedEdition.stored_pages_count }} páginas procesadas</strong>
                                        ({{ selectedEdition.storage_human }}) en la bóveda privada.
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 pt-1">
                                <button
                                    type="button"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors"
                                    @click="openPagePreview(1)"
                                >
                                    <Eye class="h-3.5 w-3.5" />
                                    <span>Revisar páginas</span>
                                </button>

                                <button
                                    type="button"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 sm:py-2 rounded-brand bg-surface border border-border hover:border-amber-500/40 text-xs font-semibold text-foreground transition-colors"
                                    @click="showReplaceUploader = true"
                                >
                                    <Upload class="h-3.5 w-3.5 text-amber-500" />
                                    <span>Reemplazar con nuevo PDF</span>
                                </button>
                            </div>
                        </div>

                        <!-- Zona de selección de archivo cuando no está subiendo -->
                        <div
                            v-else-if="!isUploadingPdf && !selectedPdfFile"
                            class="border-2 border-dashed rounded-brand p-5 sm:p-8 text-center transition-colors relative"
                            :class="
                                isDraggingPdf
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border bg-background/50 hover:border-primary/40'
                            "
                            @dragover.prevent="isDraggingPdf = true"
                            @dragleave.prevent="isDraggingPdf = false"
                            @drop.prevent="onPdfDrop"
                        >
                            <!-- Botón para cancelar el modo de reemplazo si ya tenía páginas -->
                            <div
                                v-if="selectedEdition.stored_pages_count > 0 && showReplaceUploader"
                                class="flex items-center justify-between gap-2 mb-3 p-2.5 rounded-brand bg-amber-500/10 border border-amber-500/20 text-left"
                            >
                                <div class="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-300">
                                    <AlertTriangle class="h-4 w-4 shrink-0" />
                                    <span>Modo de reemplazo activo: se sobreescribirán las páginas existentes.</span>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 text-xs font-semibold text-muted hover:text-foreground underline underline-offset-2"
                                    @click="showReplaceUploader = false"
                                >
                                    Cancelar
                                </button>
                            </div>

                            <div class="mx-auto h-11 w-11 rounded-full bg-primary/10 text-primary flex items-center justify-center mb-3">
                                <FileText class="h-5 w-5" />
                            </div>
                            <p class="text-sm font-medium text-foreground">
                                <template v-if="isInspectingPdf">Analizando documento PDF...</template>
                                <template v-else>
                                    <span class="hidden md:inline">Arrastra el archivo PDF aquí o selecciónalo desde tu equipo</span>
                                    <span class="md:hidden">Selecciona el archivo PDF desde tu dispositivo</span>
                                </template>
                            </p>
                            <p class="text-xs text-muted mt-1">
                                {{
                                    selectedEdition.stored_pages_count > 0
                                        ? `Esta edición tiene ${selectedEdition.stored_pages_count} páginas que serán reemplazadas.`
                                        : 'El navegador optimizará cada página antes de enviarla al servidor.'
                                }}
                            </p>
                            <div class="mt-4">
                                <label
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-3 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-sm sm:text-xs font-semibold cursor-pointer transition-colors"
                                >
                                    <Upload class="h-3.5 w-3.5" />
                                    <span>Seleccionar archivo PDF</span>
                                    <input
                                        type="file"
                                        accept="application/pdf,.pdf"
                                        class="hidden"
                                        :disabled="isInspectingPdf"
                                        @change="onPdfInputChange"
                                    />
                                </label>
                            </div>
                        </div>

                        <!-- Tarjeta de archivo listo para procesar -->
                        <div
                            v-else-if="!isUploadingPdf && selectedPdfFile"
                            class="rounded-brand border border-border bg-background p-4 space-y-4"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="h-10 w-10 rounded-brand bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                        <FileText class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-foreground truncate">
                                            {{ selectedPdfFile.name }}
                                        </p>
                                        <p class="text-xs text-muted">
                                            {{ pdfPageCount }} páginas detectadas · Peso original:
                                            {{ formatBytesHuman(selectedPdfFile.size) }}
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="p-2 -mr-1 -mt-1 rounded-brand text-muted hover:text-foreground hover:bg-surface shrink-0"
                                    aria-label="Descartar archivo seleccionado"
                                    @click="resetPdfSelection"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <!-- Advertencia si la edición ya tiene páginas procesadas -->
                            <div
                                v-if="selectedEdition.stored_pages_count > 0"
                                class="p-3 rounded-brand bg-amber-500/10 border border-amber-500/20 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2.5"
                            >
                                <AlertTriangle class="h-4 w-4 shrink-0 mt-0.5" />
                                <div class="space-y-1">
                                    <p class="font-semibold">Atención: esta edición ya tiene páginas guardadas</p>
                                    <p class="text-[11px] opacity-90 leading-relaxed">
                                        Las {{ selectedEdition.stored_pages_count }} páginas anteriores se eliminarán y se guardarán {{ pdfPageCount }} páginas nuevas. Deberás verificar el índice de temas tras la conversión.
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-2.5">
                                <button
                                    type="button"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 py-3 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-sm sm:text-xs font-semibold transition-colors"
                                    @click="confirmAndStartPdfUpload"
                                >
                                    <Sparkles class="h-4 w-4 sm:h-3.5 sm:w-3.5" />
                                    <span>
                                        {{
                                            selectedEdition.stored_pages_count > 0
                                                ? `Reemplazar con ${pdfPageCount} páginas`
                                                : `Convertir y guardar ${pdfPageCount} páginas`
                                        }}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    class="w-full sm:w-auto px-3 py-2.5 sm:py-2 rounded-brand bg-surface border border-border text-xs font-medium text-muted hover:text-foreground transition-colors"
                                    @click="resetPdfSelection"
                                >
                                    Elegir otro archivo
                                </button>
                            </div>
                        </div>

                        <!-- Barra de progreso en vivo -->
                        <div
                            v-else
                            class="rounded-brand border border-primary/30 bg-primary/5 p-4 sm:p-5 space-y-4"
                        >
                            <div class="flex items-start sm:items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-foreground">
                                        {{
                                            uploadProgress.phase === 'uploading'
                                                ? `Guardando página ${uploadProgress.currentPage} de ${uploadProgress.totalPages}...`
                                                : `Convirtiendo página ${uploadProgress.currentPage} de ${uploadProgress.totalPages}...`
                                        }}
                                    </p>
                                    <p class="text-xs text-muted mt-0.5">
                                        Peso optimizado: {{ formatBytesHuman(uploadProgress.optimizedBytes) }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-base sm:text-sm font-bold text-primary">{{ uploadPercentage }}%</span>
                            </div>

                            <div class="w-full h-2.5 bg-background rounded-full overflow-hidden border border-border">
                                <div
                                    class="h-full bg-primary transition-all duration-200"
                                    :style="{ width: `${uploadPercentage}%` }"
                                ></div>
                            </div>

                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <span class="text-[11px] text-muted">
                                    No cierres ni bloquees la pantalla hasta completar el envío.
                                </span>
                                <button
                                    type="button"
                                    class="w-full sm:w-auto px-3 py-2.5 sm:py-1.5 rounded-brand bg-surface border border-border text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                    @click="cancelPdfUpload"
                                >
                                    Detener carga
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- PESTAÑA 2: ÍNDICE DE TEMAS                                   -->
            <!-- ============================================================ -->
            <div v-else-if="activeTab === 'toc' && selectedEdition" class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4 sm:space-y-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-foreground truncate">
                            Índice de temas · {{ selectedEdition.edition_name }}
                        </h3>
                        <p class="text-xs text-muted mt-0.5">
                            {{
                                selectedEdition.total_pages > 0
                                    ? `Rango permitido: páginas 1 a ${selectedEdition.total_pages}. Al guardar se ordena automáticamente por página.`
                                    : 'Agrega los capítulos y su página de inicio. Se ordenarán automáticamente al guardar.'
                            }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <button
                            v-if="tocRows.length > 1"
                            type="button"
                            class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-3 py-2.5 sm:py-2 rounded-brand bg-background border border-border text-xs font-medium text-foreground hover:border-primary/40 transition-colors"
                            @click="sortTocRows"
                        >
                            <ArrowUpDown class="h-3.5 w-3.5" />
                            <span>Ordenar</span>
                        </button>

                        <button
                            type="button"
                            :disabled="isSavingToc"
                            class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-4 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                            @click="saveToc"
                        >
                            <span>{{ isSavingToc ? 'Guardando...' : 'Guardar índice' }}</span>
                        </button>
                    </div>
                </div>

                <BaseAlert v-if="tocErrors.general" type="error" title="Revisa el índice">
                    {{ tocErrors.general }}
                </BaseAlert>

                <!-- Fila rápida para añadir temas -->
                <div class="p-3 sm:p-3.5 rounded-brand bg-background border border-border space-y-2">
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-2.5">
                        <input
                            v-model="newTocTitle"
                            type="text"
                            maxlength="180"
                            enterkeyhint="next"
                            placeholder="Ej. Capítulo 1: Ecuación contable fundamental"
                            class="flex-1 min-w-0 px-3 py-2.5 sm:py-2 text-sm bg-surface border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                            @keydown.enter.prevent="addTocRow"
                        />
                        <div class="flex items-center gap-2">
                            <input
                                v-model.number="newTocPage"
                                type="number"
                                inputmode="numeric"
                                min="1"
                                :max="selectedEdition.total_pages > 0 ? selectedEdition.total_pages : 2000"
                                placeholder="Pág."
                                class="w-24 px-3 py-2.5 sm:py-2 text-sm bg-surface border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                                @keydown.enter.prevent="addTocRow"
                            />
                            <button
                                type="button"
                                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 sm:py-2 rounded-brand bg-secondary text-secondary-foreground text-xs font-semibold hover:opacity-90 transition-opacity shrink-0"
                                @click="addTocRow"
                            >
                                <Plus class="h-3.5 w-3.5" />
                                <span>Agregar</span>
                            </button>
                        </div>
                    </div>
                    <p v-if="tocErrors.quickAdd" class="text-xs text-rose-600 dark:text-rose-400">
                        {{ tocErrors.quickAdd }}
                    </p>
                </div>

                <!-- Listado editable de temas -->
                <div v-if="tocRows.length === 0" class="py-8 text-center text-sm text-muted">
                    Esta edición aún no tiene temas registrados en su índice.
                </div>

                <div v-else class="divide-y divide-border border border-border rounded-brand overflow-hidden">
                    <div
                        v-for="(row, idx) in tocRows"
                        :key="row.uid"
                        class="p-3 bg-surface flex flex-wrap sm:flex-nowrap items-center gap-2 sm:gap-2.5"
                    >
                        <span class="text-xs font-semibold text-muted w-7 shrink-0">#{{ idx + 1 }}</span>

                        <input
                            v-model="row.title"
                            type="text"
                            maxlength="180"
                            aria-label="Título del tema"
                            class="flex-1 min-w-0 px-3 py-2 sm:py-1.5 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />

                        <div class="w-full sm:w-auto pl-9 sm:pl-0 flex items-center justify-between sm:justify-start gap-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-muted">Pág.</span>
                                <input
                                    v-model.number="row.page"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    :max="selectedEdition.total_pages > 0 ? selectedEdition.total_pages : 2000"
                                    aria-label="Página de inicio"
                                    class="w-20 px-2.5 py-2 sm:py-1.5 text-sm bg-background border border-border rounded-brand text-foreground text-center focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                                />
                            </div>

                            <div class="flex items-center gap-1">
                                <button
                                    v-if="selectedEdition.stored_pages_count >= Number(row.page) && Number(row.page) >= 1"
                                    type="button"
                                    class="p-2.5 sm:p-1.5 rounded-brand text-muted hover:text-primary hover:bg-background transition-colors"
                                    title="Ver esta página"
                                    aria-label="Ver esta página"
                                    @click="openPagePreview(row.page)"
                                >
                                    <Eye class="h-4 w-4" />
                                </button>

                                <button
                                    type="button"
                                    class="p-2.5 sm:p-1.5 rounded-brand text-muted hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                    title="Quitar tema"
                                    aria-label="Quitar tema"
                                    @click="removeTocRow(idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- PESTAÑA 3: FICHA EDITORIAL DEL LIBRO                         -->
            <!-- ============================================================ -->
            <form
                v-else-if="activeTab === 'details'"
                class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5 items-start"
                @submit.prevent="submitBookDetails"
            >
                <!-- Portada y visibilidad -->
                <div class="lg:col-span-4 space-y-4 sm:space-y-5">
                    <div class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-3 sm:space-y-4">
                        <h3 class="text-sm font-semibold text-foreground">Portada del libro</h3>

                        <div class="flex items-center gap-4 lg:block lg:space-y-4">
                            <div
                                class="w-24 sm:w-32 lg:mx-auto lg:w-44 aspect-[3/4] shrink-0 rounded-brand overflow-hidden border border-border bg-secondary text-secondary-foreground flex items-center justify-center"
                            >
                                <img
                                    v-if="bookDetailsForm.coverPreviewUrl"
                                    :src="bookDetailsForm.coverPreviewUrl"
                                    alt="Vista previa de portada"
                                    class="w-full h-full object-cover"
                                />
                                <div v-else class="p-3 sm:p-4 text-center space-y-1.5 sm:space-y-2">
                                    <BookOpen class="h-6 w-6 sm:h-8 sm:w-8 mx-auto text-accent" />
                                    <p class="text-[11px] sm:text-xs font-medium leading-snug line-clamp-3">
                                        {{ bookDetailsForm.title || 'Sin portada' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex-1 space-y-2.5 lg:text-center">
                                <p class="text-xs text-muted lg:hidden">
                                    Sube una imagen vertical clara (JPG o PNG, máx. 2 MB).
                                </p>

                                <p v-if="bookDetailsErrors.cover" class="text-xs text-rose-600 dark:text-rose-400">
                                    {{ bookDetailsErrors.cover }}
                                </p>

                                <div class="flex flex-wrap items-center lg:justify-center gap-2">
                                    <label
                                        class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 sm:py-1.5 rounded-brand bg-background border border-border hover:border-primary/40 text-xs font-semibold text-foreground cursor-pointer transition-colors"
                                    >
                                        <ImagePlus class="h-3.5 w-3.5 text-primary" />
                                        <span>Cambiar imagen</span>
                                        <input
                                            type="file"
                                            accept="image/webp,image/jpeg,image/png"
                                            class="hidden"
                                            @change="handleDetailsCoverSelect"
                                        />
                                    </label>

                                    <button
                                        v-if="bookDetailsForm.coverPreviewUrl"
                                        type="button"
                                        class="px-3 py-2 sm:py-1.5 rounded-brand text-xs font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                        @click="removeDetailsCover"
                                    >
                                        Quitar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer select-none">
                            <input
                                v-model="bookDetailsForm.is_published"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30"
                            />
                            <div>
                                <span class="block text-sm font-semibold text-foreground">
                                    Publicar en el catálogo
                                </span>
                                <span class="block text-xs text-muted mt-0.5">
                                    Cuando está activo, los visitantes y estudiantes pueden consultar el libro.
                                </span>
                            </div>
                        </label>

                        <BaseAlert
                            v-if="
                                bookDetailsForm.is_published &&
                                currentEditionOfBook(activeBook)?.status !== 'ready'
                            "
                            type="warning"
                            title="Edición aún sin páginas listas"
                        >
                            La edición vigente todavía está en preparación. Te sugerimos subir sus páginas antes de hacerlo público.
                        </BaseAlert>
                    </div>
                </div>

                <!-- Campos principales -->
                <div class="lg:col-span-8 bg-surface border border-border rounded-brand p-4 sm:p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground">Información principal</h3>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">Título del libro *</label>
                        <input
                            v-model="bookDetailsForm.title"
                            type="text"
                            maxlength="180"
                            required
                            class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="bookDetailsErrors.title" class="text-xs text-rose-600 dark:text-rose-400">
                            {{ bookDetailsErrors.title }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">Subtítulo</label>
                        <input
                            v-model="bookDetailsForm.subtitle"
                            type="text"
                            maxlength="255"
                            class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="bookDetailsErrors.subtitle" class="text-xs text-rose-600 dark:text-rose-400">
                            {{ bookDetailsErrors.subtitle }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">
                            Autor o equipo editorial *
                        </label>
                        <input
                            v-model="bookDetailsForm.author_name"
                            type="text"
                            maxlength="150"
                            required
                            class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        />
                        <p v-if="bookDetailsErrors.author_name" class="text-xs text-rose-600 dark:text-rose-400">
                            {{ bookDetailsErrors.author_name }}
                        </p>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-medium text-foreground">Descripción y enfoque</label>
                        <textarea
                            v-model="bookDetailsForm.description"
                            rows="5"
                            maxlength="5000"
                            class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                        ></textarea>
                        <p v-if="bookDetailsErrors.description" class="text-xs text-rose-600 dark:text-rose-400">
                            {{ bookDetailsErrors.description }}
                        </p>
                    </div>

                    <div class="pt-2 flex items-center justify-end">
                        <button
                            type="submit"
                            :disabled="isSavingBookDetails"
                            class="w-full sm:w-auto inline-flex items-center justify-center px-5 py-3 sm:py-2.5 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-sm font-semibold transition-colors disabled:opacity-50"
                        >
                            {{ isSavingBookDetails ? 'Guardando cambios...' : 'Guardar cambios' }}
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- ================================================================ -->
        <!-- MODAL: REGISTRAR NUEVO LIBRO                                     -->
        <!-- ================================================================ -->
        <BaseModal
            v-model="isCreateModalOpen"
            title="Registrar nuevo libro"
            max-width="xl"
            :closeable="!isCreatingBook"
        >
            <form id="create-book-form" class="space-y-4" @submit.prevent="submitCreateBook">
                <BaseAlert v-if="createErrors.general" type="error">
                    {{ createErrors.general }}
                </BaseAlert>

                <div class="flex flex-col sm:flex-row gap-4 items-start">
                    <!-- Selector compacto de portada -->
                    <div class="w-full sm:w-28 shrink-0 flex sm:block items-center gap-3.5 sm:space-y-2 p-2.5 sm:p-0 rounded-brand bg-background sm:bg-transparent border sm:border-0 border-border">
                        <div
                            class="w-16 sm:w-28 aspect-[3/4] shrink-0 rounded-brand overflow-hidden border border-border bg-secondary text-secondary-foreground flex items-center justify-center"
                        >
                            <img
                                v-if="createForm.coverPreviewUrl"
                                :src="createForm.coverPreviewUrl"
                                alt="Portada seleccionada"
                                class="w-full h-full object-cover"
                            />
                            <BookOpen v-else class="h-5 w-5 sm:h-6 sm:w-6 text-accent" />
                        </div>

                        <div class="flex-1 sm:w-full space-y-1.5">
                            <p class="text-xs font-medium text-foreground sm:hidden">Imagen de portada (opcional)</p>
                            <div class="flex sm:flex-col items-center gap-2 sm:gap-1.5">
                                <label
                                    class="sm:w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 sm:px-2.5 sm:py-1.5 rounded-brand bg-surface sm:bg-background border border-border text-xs sm:text-[11px] font-semibold text-foreground cursor-pointer hover:border-primary/40"
                                >
                                    <ImagePlus class="h-3.5 w-3.5 text-primary" />
                                    <span>{{ createForm.coverPreviewUrl ? 'Cambiar' : 'Portada' }}</span>
                                    <input
                                        type="file"
                                        accept="image/webp,image/jpeg,image/png"
                                        class="hidden"
                                        @change="handleCreateCoverSelect"
                                    />
                                </label>

                                <button
                                    v-if="createForm.coverPreviewUrl"
                                    type="button"
                                    class="px-2 py-1 sm:w-full text-xs sm:text-[11px] text-rose-600 dark:text-rose-400 hover:underline"
                                    @click="clearCreateCover"
                                >
                                    Quitar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 w-full space-y-3.5">
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-foreground">Título del libro *</label>
                            <input
                                v-model="createForm.title"
                                type="text"
                                maxlength="180"
                                required
                                placeholder="Ej. Contabilidad Básica Aplicada"
                                class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                            />
                            <p v-if="createErrors.title" class="text-xs text-rose-600 dark:text-rose-400">
                                {{ createErrors.title }}
                            </p>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-foreground">Subtítulo</label>
                            <input
                                v-model="createForm.subtitle"
                                type="text"
                                maxlength="255"
                                placeholder="Opcional"
                                class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                            />
                        </div>

                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-foreground">Autor *</label>
                            <input
                                v-model="createForm.author_name"
                                type="text"
                                maxlength="150"
                                required
                                placeholder="Nombre del autor"
                                class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                            />
                            <p v-if="createErrors.author_name" class="text-xs text-rose-600 dark:text-rose-400">
                                {{ createErrors.author_name }}
                            </p>
                        </div>
                    </div>
                </div>

                <p v-if="createErrors.cover" class="text-xs text-rose-600 dark:text-rose-400">
                    {{ createErrors.cover }}
                </p>

                <div class="space-y-1">
                    <label class="block text-xs font-medium text-foreground">Descripción breve</label>
                    <textarea
                        v-model="createForm.description"
                        rows="3"
                        maxlength="5000"
                        placeholder="Resumen de los temas y a quién va dirigido..."
                        class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    ></textarea>
                </div>

                <p class="text-xs text-muted">
                    El libro se creará inicialmente en borrador con su primera edición lista para subir las páginas.
                </p>
            </form>

            <template #footer="{ close }">
                <button
                    type="button"
                    :disabled="isCreatingBook"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground hover:bg-background transition-colors"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    form="create-book-form"
                    :disabled="isCreatingBook"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                >
                    {{ isCreatingBook ? 'Creando libro...' : 'Crear y continuar' }}
                </button>
            </template>
        </BaseModal>

        <!-- ================================================================ -->
        <!-- MODAL: NUEVA EDICIÓN                                             -->
        <!-- ================================================================ -->
        <BaseModal
            v-model="isCreateEditionModalOpen"
            title="Agregar nueva edición"
            max-width="md"
            :closeable="!isCreatingEdition"
        >
            <form id="create-edition-form" class="space-y-4" @submit.prevent="submitCreateEdition">
                <BaseAlert v-if="createEditionErrors.general" type="error">
                    {{ createEditionErrors.general }}
                </BaseAlert>

                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Nombre de la edición *</label>
                    <input
                        v-model="createEditionForm.edition_name"
                        type="text"
                        maxlength="100"
                        required
                        placeholder="Ej. Segunda Edición Revisada 2027"
                        class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                    <p v-if="createEditionErrors.edition_name" class="text-xs text-rose-600 dark:text-rose-400">
                        {{ createEditionErrors.edition_name }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-medium text-foreground">Páginas de muestra gratuita</label>
                    <input
                        v-model.number="createEditionForm.sample_pages_count"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        max="500"
                        required
                        class="w-full px-3 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                </div>

                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input
                        v-model="createEditionForm.is_current"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary/30"
                    />
                    <span class="text-xs text-foreground">
                        Marcar como edición vigente del libro
                    </span>
                </label>
            </form>

            <template #footer="{ close }">
                <button
                    type="button"
                    :disabled="isCreatingEdition"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    form="create-edition-form"
                    :disabled="isCreatingEdition"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold disabled:opacity-50"
                >
                    {{ isCreatingEdition ? 'Guardando...' : 'Crear edición' }}
                </button>
            </template>
        </BaseModal>

        <!-- ================================================================ -->
        <!-- MODAL: VISTA PREVIA DE PÁGINAS ALMACENADAS                       -->
        <!-- ================================================================ -->
        <BaseModal
            v-model="isPreviewModalOpen"
            :title="selectedEdition ? `Vista previa · ${selectedEdition.edition_name}` : 'Vista previa'"
            max-width="2xl"
        >
            <div v-if="selectedEdition" class="space-y-3 sm:space-y-4">
                <div class="flex items-center justify-between gap-2 bg-background border border-border rounded-brand px-2.5 py-2 sm:px-3">
                    <button
                        type="button"
                        :disabled="previewPageNumber <= 1"
                        class="inline-flex items-center justify-center gap-1 h-9 w-9 sm:h-auto sm:w-auto sm:px-2.5 sm:py-1 rounded-brand text-xs font-medium text-foreground hover:bg-surface disabled:opacity-40 shrink-0"
                        aria-label="Página anterior"
                        @click="stepPreviewPage(-1)"
                    >
                        <ChevronLeft class="h-4 w-4" />
                        <span class="hidden sm:inline">Anterior</span>
                    </button>

                    <div class="flex items-center gap-1.5 sm:gap-2 text-xs text-foreground">
                        <span class="hidden xs:inline">Pág.</span>
                        <input
                            v-model.number="previewPageNumber"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            :max="selectedEdition.stored_pages_count"
                            aria-label="Número de página"
                            class="w-14 sm:w-16 px-2 py-1.5 sm:py-1 text-center bg-surface border border-border rounded-brand text-xs font-semibold"
                            @change="openPagePreview(previewPageNumber)"
                        />
                        <span class="text-muted">de {{ selectedEdition.stored_pages_count }}</span>
                        <span
                            v-if="previewPageNumber <= selectedEdition.sample_pages_count"
                            class="hidden sm:inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary/10 text-primary"
                        >
                            Muestra gratuita
                        </span>
                    </div>

                    <button
                        type="button"
                        :disabled="previewPageNumber >= selectedEdition.stored_pages_count"
                        class="inline-flex items-center justify-center gap-1 h-9 w-9 sm:h-auto sm:w-auto sm:px-2.5 sm:py-1 rounded-brand text-xs font-medium text-foreground hover:bg-surface disabled:opacity-40 shrink-0"
                        aria-label="Página siguiente"
                        @click="stepPreviewPage(1)"
                    >
                        <span class="hidden sm:inline">Siguiente</span>
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>

                <div
                    class="min-h-[260px] sm:min-h-[360px] max-h-[65dvh] sm:max-h-[62vh] overflow-auto rounded-brand border border-border bg-background flex items-center justify-center p-1.5 sm:p-2 relative select-none"
                    @contextmenu.prevent
                >
                    <span v-if="isPreviewImageLoading" class="text-xs text-muted absolute">
                        Cargando página {{ previewPageNumber }}...
                    </span>
                    <img
                        :key="previewImageUrl"
                        :src="previewImageUrl"
                        :alt="`Página ${previewPageNumber}`"
                        class="max-w-full h-auto rounded shadow-sm relative z-10"
                        draggable="false"
                        @load="isPreviewImageLoading = false"
                        @error="isPreviewImageLoading = false"
                    />
                </div>
            </div>
        </BaseModal>

        <!-- ================================================================ -->
        <!-- MODAL DE CONFIRMACIÓN UNIFICADO                                  -->
        <!-- ================================================================ -->
        <BaseModal
            v-model="confirmDialog.open"
            :title="confirmDialog.title"
            max-width="md"
            :closeable="!confirmDialog.loading"
        >
            <p class="text-sm text-muted leading-relaxed">
                {{ confirmDialog.description }}
            </p>

            <template #footer="{ close }">
                <button
                    type="button"
                    :disabled="confirmDialog.loading"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand bg-surface border border-border text-xs font-medium text-foreground"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="confirmDialog.loading"
                    class="flex-1 sm:flex-none px-4 py-2.5 sm:py-2 rounded-brand text-xs font-semibold text-white transition-colors disabled:opacity-50"
                    :class="
                        confirmDialog.variant === 'warning'
                            ? 'bg-amber-600 hover:bg-amber-700'
                            : 'bg-rose-600 hover:bg-rose-700'
                    "
                    @click="executeConfirmDialog"
                >
                    {{ confirmDialog.loading ? 'Procesando...' : confirmDialog.confirmLabel }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>

