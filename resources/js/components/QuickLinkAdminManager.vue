<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import axios from 'axios';
import {
    Plus,
    Search,
    ExternalLink,
    FolderKanban,
    Link2,
    Eye,
    EyeOff,
    Pencil,
    Archive,
    RotateCcw,
    CheckCircle2,
    X,
    Globe,
    ImagePlus,
    Trash2,
} from 'lucide-vue-next';
import BaseModal from './ui/BaseModal.vue';
import BaseAlert from './ui/BaseAlert.vue';
import BaseToast from './ui/BaseToast.vue';

const props = defineProps({
    initialCategories: {
        type: Array,
        required: true,
    },
    initialLinks: {
        type: Array,
        required: true,
    },
    storeCategoryUrl: {
        type: String,
        required: true,
    },
    storeLinkUrl: {
        type: String,
        required: true,
    },
    studentPreviewUrl: {
        type: String,
        default: '',
    },
});

function cloneData(value) {
    return JSON.parse(JSON.stringify(value));
}

// --- Estado reactivo principal ---
const categories = ref(cloneData(props.initialCategories));
const links = ref(cloneData(props.initialLinks));

// Pestaña principal: 'links' | 'categories'
const activeTab = ref('links');

// Filtros de enlaces
const linkSearch = ref('');
const linkStatusFilter = ref('ACTIVE'); // 'ACTIVE' | 'HIDDEN' | 'ARCHIVED' | 'ALL'
const selectedCategoryFilter = ref('ALL');

// Filtros de categorías
const categorySearch = ref('');
const categoryStatusFilter = ref('ACTIVE'); // 'ACTIVE' | 'HIDDEN' | 'ARCHIVED' | 'ALL'

// Estado de carga de acciones rápidas por fila
const togglingLinkId = ref(null);
const togglingCategoryId = ref(null);
const restoringLinkId = ref(null);
const restoringCategoryId = ref(null);

// --- Notificaciones Toast ---
const notification = ref(null);
let notificationTimer = null;

function notify(type, title, message = '') {
    if (notificationTimer) {
        clearTimeout(notificationTimer);
        notificationTimer = null;
    }

    notification.value = {
        type,
        title,
        message,
    };

    if (type === 'success' || type === 'info') {
        notificationTimer = setTimeout(() => {
            notification.value = null;
        }, 3800);
    }
}

function extractErrors(error, fallbackMessage = 'No se pudo completar la operación. Revisa los datos e intenta nuevamente.') {
    const responseData = error?.response?.data;
    const fieldErrors = {};

    if (responseData?.errors && typeof responseData.errors === 'object') {
        Object.entries(responseData.errors).forEach(([key, messages]) => {
            fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
        });
    }

    const firstFieldError = Object.values(fieldErrors)[0];
    const message = firstFieldError || responseData?.message || fallbackMessage;

    return { fieldErrors, message };
}

function revokeBlob(url) {
    if (url && typeof url === 'string' && url.startsWith('blob:')) {
        URL.revokeObjectURL(url);
    }
}

/**
 * Estandariza cualquier imagen (PNG/JPG/WebP) en el navegador a un lienzo cuadrado
 * de 160x160 px con fondo transparente y la codifica en formato WebP ultraligero.
 */
function standardizeLogoToWebp(file, targetSize = 160, quality = 0.86) {
    return new Promise((resolve, reject) => {
        if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            reject(new Error('Selecciona una imagen en formato PNG, JPG o WebP.'));
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        const img = new Image();

        img.onload = () => {
            URL.revokeObjectURL(objectUrl);

            if (!img.width || !img.height) {
                reject(new Error('No se pudieron leer las dimensiones de la imagen.'));
                return;
            }

            const canvas = document.createElement('canvas');
            canvas.width = targetSize;
            canvas.height = targetSize;

            const ctx = canvas.getContext('2d', { alpha: true });
            ctx.clearRect(0, 0, targetSize, targetSize);

            // Ajuste proporcional centrado con margen interno suave
            const maxInner = targetSize - 12;
            const scale = Math.min(maxInner / img.width, maxInner / img.height, 1);
            const drawWidth = Math.max(1, Math.round(img.width * scale));
            const drawHeight = Math.max(1, Math.round(img.height * scale));
            const offsetX = Math.round((targetSize - drawWidth) / 2);
            const offsetY = Math.round((targetSize - drawHeight) / 2);

            ctx.drawImage(img, offsetX, offsetY, drawWidth, drawHeight);

            canvas.toBlob(
                (blob) => {
                    canvas.width = 0;
                    canvas.height = 0;

                    if (!blob || blob.type !== 'image/webp') {
                        reject(new Error('Tu navegador no pudo generar la imagen estandarizada WebP.'));
                        return;
                    }

                    const standardizedFile = new File([blob], 'logo-institucional.webp', {
                        type: 'image/webp',
                    });
                    resolve(standardizedFile);
                },
                'image/webp',
                quality
            );
        };

        img.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('El archivo seleccionado no es una imagen válida.'));
        };

        img.src = objectUrl;
    });
}

function formatKb(bytes) {
    if (!bytes || bytes <= 0) return '';
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

// --- Recálculo reactivo de contadores y relaciones en memoria ---
function recalculateCategoryMeta() {
    categories.value = categories.value.map((cat) => {
        const catLinks = links.value.filter((l) => Number(l.quick_link_category_id) === Number(cat.id));
        const activeLinks = catLinks.filter((l) => !l.is_archived && l.is_active);
        return {
            ...cat,
            total_links_count: catLinks.length,
            active_links_count: activeLinks.length,
        };
    });

    const catMap = new Map(categories.value.map((c) => [Number(c.id), c]));
    links.value = links.value.map((link) => {
        const cat = catMap.get(Number(link.quick_link_category_id));
        return {
            ...link,
            category_name: cat ? cat.name : link.category_name,
            category_is_active: cat ? Boolean(cat.is_active && !cat.is_archived) : false,
        };
    });
}

function sortCategoriesInPlace() {
    categories.value.sort((a, b) => {
        if (a.is_archived !== b.is_archived) return a.is_archived ? 1 : -1;
        if (a.sort_order !== b.sort_order) return a.sort_order - b.sort_order;
        return a.name.localeCompare(b.name, 'es');
    });
}

function sortLinksInPlace() {
    links.value.sort((a, b) => {
        if (a.is_archived !== b.is_archived) return a.is_archived ? 1 : -1;
        if (a.sort_order !== b.sort_order) return a.sort_order - b.sort_order;
        return a.title.localeCompare(b.title, 'es');
    });
}

// --- Computados para Accesos Rápidos ---
const availableCategoriesForSelect = computed(() => {
    return categories.value.filter((c) => !c.is_archived);
});

const linkStatusCounts = computed(() => {
    const nonArchived = links.value.filter((l) => !l.is_archived);
    return {
        ACTIVE: nonArchived.filter((l) => l.is_active).length,
        HIDDEN: nonArchived.filter((l) => !l.is_active).length,
        ARCHIVED: links.value.filter((l) => l.is_archived).length,
        ALL: links.value.length,
    };
});

const categoryFilterCounts = computed(() => {
    let base = links.value;
    if (linkStatusFilter.value === 'ACTIVE') {
        base = base.filter((l) => !l.is_archived && l.is_active);
    } else if (linkStatusFilter.value === 'HIDDEN') {
        base = base.filter((l) => !l.is_archived && !l.is_active);
    } else if (linkStatusFilter.value === 'ARCHIVED') {
        base = base.filter((l) => l.is_archived);
    }

    const counts = { ALL: base.length };
    categories.value.forEach((cat) => {
        counts[cat.id] = base.filter((l) => Number(l.quick_link_category_id) === Number(cat.id)).length;
    });
    return counts;
});

const filteredLinks = computed(() => {
    let result = links.value;

    if (linkStatusFilter.value === 'ACTIVE') {
        result = result.filter((l) => !l.is_archived && l.is_active);
    } else if (linkStatusFilter.value === 'HIDDEN') {
        result = result.filter((l) => !l.is_archived && !l.is_active);
    } else if (linkStatusFilter.value === 'ARCHIVED') {
        result = result.filter((l) => l.is_archived);
    }

    if (selectedCategoryFilter.value !== 'ALL') {
        result = result.filter((l) => Number(l.quick_link_category_id) === Number(selectedCategoryFilter.value));
    }

    const query = linkSearch.value.trim().toLowerCase();
    if (query) {
        result = result.filter(
            (l) =>
                l.title.toLowerCase().includes(query) ||
                (l.description || '').toLowerCase().includes(query) ||
                l.url.toLowerCase().includes(query) ||
                (l.category_name || '').toLowerCase().includes(query)
        );
    }

    return result;
});

// --- Computados para Categorías ---
const categoryStatusCounts = computed(() => {
    const nonArchived = categories.value.filter((c) => !c.is_archived);
    return {
        ACTIVE: nonArchived.filter((c) => c.is_active).length,
        HIDDEN: nonArchived.filter((c) => !c.is_active).length,
        ARCHIVED: categories.value.filter((c) => c.is_archived).length,
        ALL: categories.value.length,
    };
});

const filteredCategories = computed(() => {
    let result = categories.value;

    if (categoryStatusFilter.value === 'ACTIVE') {
        result = result.filter((c) => !c.is_archived && c.is_active);
    } else if (categoryStatusFilter.value === 'HIDDEN') {
        result = result.filter((c) => !c.is_archived && !c.is_active);
    } else if (categoryStatusFilter.value === 'ARCHIVED') {
        result = result.filter((c) => c.is_archived);
    }

    const query = categorySearch.value.trim().toLowerCase();
    if (query) {
        result = result.filter(
            (c) => c.name.toLowerCase().includes(query) || c.slug.toLowerCase().includes(query)
        );
    }

    return result;
});

// =========================================================================
// MODAL Y ACCIONES CRUD DE ACCESOS RÁPIDOS
// =========================================================================
const isLinkModalOpen = ref(false);
const editingLinkId = ref(null);
const editingLinkUpdateUrl = ref('');
const isSavingLink = ref(false);
const linkErrors = ref({});

const linkForm = ref({
    quick_link_category_id: '',
    title: '',
    description: '',
    url: '',
    sort_order: 1,
    is_active: true,
    remove_logo: false,
    logoFile: null,
    logoPreviewUrl: null,
    logoSizeBytes: 0,
});

onBeforeUnmount(() => {
    revokeBlob(linkForm.value.logoPreviewUrl);
});

function openCreateLinkModal() {
    if (availableCategoriesForSelect.value.length === 0) {
        activeTab.value = 'categories';
        openCreateCategoryModal();
        notify('info', 'Crea primero una categoría', 'Registra al menos una categoría activa para clasificar tus accesos rápidos.');
        return;
    }

    editingLinkId.value = null;
    editingLinkUpdateUrl.value = '';
    revokeBlob(linkForm.value.logoPreviewUrl);

    const defaultCatId =
        selectedCategoryFilter.value !== 'ALL'
            ? Number(selectedCategoryFilter.value)
            : availableCategoriesForSelect.value[0]?.id || '';

    const linksInCat = links.value.filter((l) => Number(l.quick_link_category_id) === Number(defaultCatId));

    linkForm.value = {
        quick_link_category_id: defaultCatId,
        title: '',
        description: '',
        url: 'https://',
        sort_order: linksInCat.length + 1,
        is_active: true,
        remove_logo: false,
        logoFile: null,
        logoPreviewUrl: null,
        logoSizeBytes: 0,
    };
    linkErrors.value = {};
    isLinkModalOpen.value = true;
}

function openEditLinkModal(link) {
    editingLinkId.value = link.id;
    editingLinkUpdateUrl.value = link.update_url;
    revokeBlob(linkForm.value.logoPreviewUrl);

    linkForm.value = {
        quick_link_category_id: link.quick_link_category_id,
        title: link.title,
        description: link.description || '',
        url: link.url,
        sort_order: link.sort_order ?? 0,
        is_active: Boolean(link.is_active),
        remove_logo: false,
        logoFile: null,
        logoPreviewUrl: link.logo_url || null,
        logoSizeBytes: 0,
    };
    linkErrors.value = {};
    isLinkModalOpen.value = true;
}

async function handleLogoSelect(event) {
    const rawFile = event.target.files?.[0];
    event.target.value = '';
    if (!rawFile) return;

    try {
        const standardized = await standardizeLogoToWebp(rawFile, 160, 0.86);
        if (standardized.size > 256 * 1024) {
            linkErrors.value.logo = 'La imagen estandarizada supera los 256 KB permitidos.';
            return;
        }

        delete linkErrors.value.logo;
        revokeBlob(linkForm.value.logoPreviewUrl);
        linkForm.value.logoFile = standardized;
        linkForm.value.remove_logo = false;
        linkForm.value.logoPreviewUrl = URL.createObjectURL(standardized);
        linkForm.value.logoSizeBytes = standardized.size;
    } catch (err) {
        linkErrors.value.logo = err?.message || 'No se pudo procesar la imagen seleccionada.';
    }
}

function removeLogoImage() {
    revokeBlob(linkForm.value.logoPreviewUrl);
    linkForm.value.logoFile = null;
    linkForm.value.logoPreviewUrl = null;
    linkForm.value.logoSizeBytes = 0;
    linkForm.value.remove_logo = true;
}

function validateLinkClient() {
    const errs = {};
    if (!linkForm.value.quick_link_category_id) {
        errs.quick_link_category_id = 'Selecciona una categoría.';
    }
    if (!linkForm.value.title || linkForm.value.title.trim().length < 3) {
        errs.title = 'El título debe tener al menos 3 caracteres.';
    }
    const rawUrl = (linkForm.value.url || '').trim();
    if (!rawUrl || !/^https?:\/\/.+/i.test(rawUrl)) {
        errs.url = 'Ingresa una dirección web válida que comience con https:// o http://.';
    }
    return errs;
}

async function submitLinkForm() {
    const clientErrors = validateLinkClient();
    if (Object.keys(clientErrors).length > 0) {
        linkErrors.value = clientErrors;
        return;
    }

    isSavingLink.value = true;
    linkErrors.value = {};

    const formData = new FormData();
    formData.append('quick_link_category_id', String(linkForm.value.quick_link_category_id));
    formData.append('title', linkForm.value.title.trim());
    formData.append('description', linkForm.value.description.trim());
    formData.append('url', linkForm.value.url.trim());
    formData.append('sort_order', String(linkForm.value.sort_order || 0));
    formData.append('is_active', linkForm.value.is_active ? '1' : '0');
    formData.append('remove_logo', linkForm.value.remove_logo ? '1' : '0');

    if (linkForm.value.logoFile) {
        formData.append('logo', linkForm.value.logoFile, 'logo-institucional.webp');
    }

    try {
        if (editingLinkId.value) {
            const { data } = await axios.post(editingLinkUpdateUrl.value, formData);
            const idx = links.value.findIndex((l) => l.id === editingLinkId.value);
            if (idx !== -1) {
                links.value[idx] = data.link;
            }
            notify('success', 'Acceso actualizado', data.message);
        } else {
            const { data } = await axios.post(props.storeLinkUrl, formData);
            links.value.push(data.link);
            notify('success', 'Acceso registrado', data.message);
        }

        revokeBlob(linkForm.value.logoPreviewUrl);
        sortLinksInPlace();
        recalculateCategoryMeta();
        isLinkModalOpen.value = false;
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        linkErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            linkErrors.value.general = message;
        }
    } finally {
        isSavingLink.value = false;
    }
}

async function toggleLinkActive(link) {
    if (togglingLinkId.value === link.id) return;
    togglingLinkId.value = link.id;

    try {
        const { data } = await axios.patch(link.toggle_url);
        const idx = links.value.findIndex((l) => l.id === link.id);
        if (idx !== -1) {
            links.value[idx] = data.link;
        }
        recalculateCategoryMeta();
        notify('success', data.link.is_active ? 'Enlace visible' : 'Enlace oculto', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        notify('error', 'No se pudo cambiar el estado', message);
    } finally {
        togglingLinkId.value = null;
    }
}

async function restoreLink(link) {
    if (restoringLinkId.value === link.id) return;
    restoringLinkId.value = link.id;

    try {
        const { data } = await axios.post(link.restore_url);
        const idx = links.value.findIndex((l) => l.id === link.id);
        if (idx !== -1) {
            links.value[idx] = data.link;
        }
        sortLinksInPlace();
        recalculateCategoryMeta();
        notify('success', 'Enlace restaurado', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        notify('error', 'No se pudo restaurar', message);
    } finally {
        restoringLinkId.value = null;
    }
}

// =========================================================================
// MODAL Y ACCIONES CRUD DE CATEGORÍAS
// =========================================================================
const isCategoryModalOpen = ref(false);
const editingCategoryId = ref(null);
const editingCategoryUpdateUrl = ref('');
const isSavingCategory = ref(false);
const categoryErrors = ref({});

const categoryForm = ref({
    name: '',
    sort_order: 1,
    is_active: true,
});

function openCreateCategoryModal() {
    editingCategoryId.value = null;
    editingCategoryUpdateUrl.value = '';
    categoryForm.value = {
        name: '',
        sort_order: categories.value.filter((c) => !c.is_archived).length + 1,
        is_active: true,
    };
    categoryErrors.value = {};
    isCategoryModalOpen.value = true;
}

function openEditCategoryModal(category) {
    editingCategoryId.value = category.id;
    editingCategoryUpdateUrl.value = category.update_url;
    categoryForm.value = {
        name: category.name,
        sort_order: category.sort_order ?? 0,
        is_active: Boolean(category.is_active),
    };
    categoryErrors.value = {};
    isCategoryModalOpen.value = true;
}

async function submitCategoryForm() {
    if (!categoryForm.value.name || categoryForm.value.name.trim().length < 2) {
        categoryErrors.value = { name: 'El nombre de la categoría debe tener al menos 2 caracteres.' };
        return;
    }

    isSavingCategory.value = true;
    categoryErrors.value = {};

    const payload = {
        name: categoryForm.value.name.trim(),
        sort_order: Number(categoryForm.value.sort_order || 0),
        is_active: Boolean(categoryForm.value.is_active),
    };

    try {
        if (editingCategoryId.value) {
            const { data } = await axios.put(editingCategoryUpdateUrl.value, payload);
            const idx = categories.value.findIndex((c) => c.id === editingCategoryId.value);
            if (idx !== -1) {
                categories.value[idx] = data.category;
            }
            notify('success', 'Categoría actualizada', data.message);
        } else {
            const { data } = await axios.post(props.storeCategoryUrl, payload);
            categories.value.push(data.category);
            notify('success', 'Categoría creada', data.message);
        }

        sortCategoriesInPlace();
        recalculateCategoryMeta();
        isCategoryModalOpen.value = false;
    } catch (error) {
        const { fieldErrors, message } = extractErrors(error);
        categoryErrors.value = fieldErrors;
        if (!Object.keys(fieldErrors).length) {
            categoryErrors.value.general = message;
        }
    } finally {
        isSavingCategory.value = false;
    }
}

async function toggleCategoryActive(category) {
    if (togglingCategoryId.value === category.id) return;
    togglingCategoryId.value = category.id;

    try {
        const { data } = await axios.patch(category.toggle_url);
        const idx = categories.value.findIndex((c) => c.id === category.id);
        if (idx !== -1) {
            categories.value[idx] = data.category;
        }
        recalculateCategoryMeta();
        notify('success', data.category.is_active ? 'Categoría visible' : 'Categoría oculta', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        notify('error', 'No se pudo cambiar el estado', message);
    } finally {
        togglingCategoryId.value = null;
    }
}

async function restoreCategory(category) {
    if (restoringCategoryId.value === category.id) return;
    restoringCategoryId.value = category.id;

    try {
        const { data } = await axios.post(category.restore_url);
        const idx = categories.value.findIndex((c) => c.id === category.id);
        if (idx !== -1) {
            categories.value[idx] = data.category;
        }
        sortCategoriesInPlace();
        recalculateCategoryMeta();
        notify('success', 'Categoría restaurada', data.message);
    } catch (error) {
        const { message } = extractErrors(error);
        notify('error', 'No se pudo restaurar', message);
    } finally {
        restoringCategoryId.value = null;
    }
}

// =========================================================================
// MODAL DE CONFIRMACIÓN DE ARCHIVADO (ENLACES Y CATEGORÍAS)
// =========================================================================
const confirmDialog = ref({
    open: false,
    title: '',
    description: '',
    confirmLabel: 'Archivar',
    error: '',
    loading: false,
    onConfirm: null,
});

function promptArchiveLink(link) {
    confirmDialog.value = {
        open: true,
        title: 'Archivar acceso rápido',
        description: `«${link.title}» dejará de mostrarse a los estudiantes. Podrás restaurarlo desde la pestaña de archivados cuando lo necesites.`,
        confirmLabel: 'Archivar enlace',
        error: '',
        loading: false,
        onConfirm: async () => {
            const { data } = await axios.delete(link.destroy_url);
            const idx = links.value.findIndex((l) => l.id === link.id);
            if (idx !== -1) {
                links.value[idx] = data.link;
            }
            sortLinksInPlace();
            recalculateCategoryMeta();
            notify('success', 'Acceso archivado', data.message);
        },
    };
}

function promptArchiveCategory(category) {
    if (category.total_links_count > 0) {
        const noun = category.total_links_count === 1 ? '1 enlace asociado' : `${category.total_links_count} enlaces asociados`;
        notify(
            'warning',
            'Categoría con enlaces asociados',
            `La categoría «${category.name}» aún tiene ${noun}. Reasigna o elimina primero sus enlaces antes de archivarla.`
        );
        return;
    }

    confirmDialog.value = {
        open: true,
        title: 'Archivar categoría',
        description: `¿Deseas archivar la categoría «${category.name}»? Podrás restaurarla más adelante si vuelves a necesitarla.`,
        confirmLabel: 'Archivar categoría',
        error: '',
        loading: false,
        onConfirm: async () => {
            const { data } = await axios.delete(category.destroy_url);
            const idx = categories.value.findIndex((c) => c.id === category.id);
            if (idx !== -1) {
                categories.value[idx] = data.category;
            }
            sortCategoriesInPlace();
            recalculateCategoryMeta();
            notify('success', 'Categoría archivada', data.message);
        },
    };
}

async function executeConfirmDialog() {
    if (!confirmDialog.value.onConfirm || confirmDialog.value.loading) return;
    confirmDialog.value.loading = true;
    confirmDialog.value.error = '';

    try {
        await confirmDialog.value.onConfirm();
        confirmDialog.value.open = false;
    } catch (error) {
        const { message } = extractErrors(error);
        confirmDialog.value.error = message;
    } finally {
        confirmDialog.value.loading = false;
    }
}

function filterLinksByCategory(categoryId) {
    selectedCategoryFilter.value = categoryId;
    linkStatusFilter.value = 'ALL';
    activeTab.value = 'links';
}
</script>

<template>
    <div class="space-y-4 sm:space-y-6">
        <!-- Notificaciones flotantes -->
        <BaseToast v-model="notification" />

        <!-- Barra superior: Conmutador de pestañas (Accesos Rápidos / Categorías) + Acción principal -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-1.5 p-1 bg-surface border border-border rounded-brand overflow-x-auto no-scrollbar">
                <button
                    type="button"
                    class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-brand text-xs font-semibold transition-all select-none"
                    :class="
                        activeTab === 'links'
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'text-muted hover:text-foreground hover:bg-background/60'
                    "
                    @click="activeTab = 'links'"
                >
                    <Link2 class="h-4 w-4" />
                    <span>Accesos rápidos</span>
                    <span
                        class="px-1.5 py-0.2 rounded-full text-[10px] font-bold"
                        :class="
                            activeTab === 'links'
                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                : 'bg-background text-muted border border-border'
                        "
                    >
                        {{ linkStatusCounts.ACTIVE }}
                    </span>
                </button>

                <button
                    type="button"
                    class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-brand text-xs font-semibold transition-all select-none"
                    :class="
                        activeTab === 'categories'
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'text-muted hover:text-foreground hover:bg-background/60'
                    "
                    @click="activeTab = 'categories'"
                >
                    <FolderKanban class="h-4 w-4" />
                    <span>Categorías</span>
                    <span
                        class="px-1.5 py-0.2 rounded-full text-[10px] font-bold"
                        :class="
                            activeTab === 'categories'
                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                : 'bg-background text-muted border border-border'
                        "
                    >
                        {{ categoryStatusCounts.ACTIVE }}
                    </span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                <a
                    v-if="studentPreviewUrl"
                    :href="studentPreviewUrl"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-brand border border-border bg-surface hover:border-primary/40 text-xs font-medium text-foreground hover:text-primary transition-colors"
                >
                    <Eye class="h-3.5 w-3.5 text-primary" />
                    <span>Vista de estudiante</span>
                </a>

                <button
                    v-if="activeTab === 'links'"
                    type="button"
                    class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold shadow-xs transition-colors"
                    @click="openCreateLinkModal"
                >
                    <Plus class="h-4 w-4" />
                    <span>Nuevo acceso rápido</span>
                </button>

                <button
                    v-else
                    type="button"
                    class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold shadow-xs transition-colors"
                    @click="openCreateCategoryModal"
                >
                    <Plus class="h-4 w-4" />
                    <span>Nueva categoría</span>
                </button>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- PESTAÑA 1: LISTADO Y GESTIÓN DE ACCESOS RÁPIDOS                   -->
        <!-- ================================================================= -->
        <div v-if="activeTab === 'links'" class="space-y-4">
            <!-- Panel de filtros -->
            <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 shadow-xs space-y-3">
                <!-- Filtros de estado + Buscador -->
                <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                    <div class="-mx-3 px-3 sm:mx-0 sm:px-0 flex items-center gap-1.5 overflow-x-auto no-scrollbar snap-x">
                        <button
                            type="button"
                            class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                            :class="
                                linkStatusFilter === 'ACTIVE'
                                    ? 'bg-primary text-primary-foreground shadow-xs'
                                    : 'bg-background text-muted hover:text-foreground border border-border'
                            "
                            @click="linkStatusFilter = 'ACTIVE'"
                        >
                            <CheckCircle2 class="h-3.5 w-3.5" />
                            Visibles ({{ linkStatusCounts.ACTIVE }})
                        </button>

                        <button
                            type="button"
                            class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                            :class="
                                linkStatusFilter === 'HIDDEN'
                                    ? 'bg-amber-600 text-white shadow-xs'
                                    : 'bg-background text-muted hover:text-foreground border border-border'
                            "
                            @click="linkStatusFilter = 'HIDDEN'"
                        >
                            <EyeOff class="h-3.5 w-3.5" />
                            Ocultos ({{ linkStatusCounts.HIDDEN }})
                        </button>

                        <button
                            type="button"
                            class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                            :class="
                                linkStatusFilter === 'ARCHIVED'
                                    ? 'bg-rose-600 text-white shadow-xs'
                                    : 'bg-background text-muted hover:text-foreground border border-border'
                            "
                            @click="linkStatusFilter = 'ARCHIVED'"
                        >
                            <Archive class="h-3.5 w-3.5" />
                            Archivados ({{ linkStatusCounts.ARCHIVED }})
                        </button>

                        <button
                            type="button"
                            class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                            :class="
                                linkStatusFilter === 'ALL'
                                    ? 'bg-secondary text-secondary-foreground shadow-xs'
                                    : 'bg-background text-muted hover:text-foreground border border-border'
                            "
                            @click="linkStatusFilter = 'ALL'"
                        >
                            Todos ({{ linkStatusCounts.ALL }})
                        </button>
                    </div>

                    <div class="relative w-full md:w-80">
                        <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                        <input
                            v-model="linkSearch"
                            type="search"
                            inputmode="search"
                            placeholder="Buscar portal, entidad o dirección web..."
                            class="w-full pl-9 pr-8 py-2 bg-background border border-border rounded-brand text-xs sm:text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                        />
                        <button
                            v-if="linkSearch"
                            type="button"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-foreground p-1"
                            aria-label="Limpiar búsqueda"
                            @click="linkSearch = ''"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>

                <!-- Píldoras de filtro por categoría -->
                <div class="-mx-3 px-3 sm:mx-0 sm:px-0 flex items-center gap-1.5 pt-2 border-t border-border overflow-x-auto no-scrollbar snap-x">
                    <button
                        type="button"
                        class="shrink-0 snap-start px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-all"
                        :class="
                            selectedCategoryFilter === 'ALL'
                                ? 'bg-primary/15 text-primary border border-primary/30 font-semibold'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="selectedCategoryFilter = 'ALL'"
                    >
                        Todas las categorías ({{ categoryFilterCounts.ALL || 0 }})
                    </button>

                    <button
                        v-for="cat in categories"
                        :key="`cat-pill-${cat.id}`"
                        type="button"
                        class="shrink-0 snap-start px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-all"
                        :class="
                            Number(selectedCategoryFilter) === Number(cat.id)
                                ? 'bg-primary/15 text-primary border border-primary/30 font-semibold'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="selectedCategoryFilter = cat.id"
                    >
                        {{ cat.name }} ({{ categoryFilterCounts[cat.id] || 0 }})
                    </button>
                </div>
            </div>

            <!-- Tarjetas móviles (< 768px) -->
            <div class="md:hidden space-y-3">
                <article
                    v-for="link in filteredLinks"
                    :key="`mobile-link-${link.id}`"
                    class="bg-surface border border-border rounded-brand p-3.5 shadow-xs space-y-3"
                    :class="{ 'opacity-70': link.is_archived }"
                >
                    <div class="flex items-start justify-between gap-2.5">
                        <div class="flex items-start gap-3 min-w-0">
                            <!-- Recuadro de logotipo estandarizado 44x44 -->
                            <div class="h-11 w-11 shrink-0 rounded-xl border border-border bg-background flex items-center justify-center overflow-hidden p-1">
                                <img
                                    v-if="link.logo_url"
                                    :src="link.logo_url"
                                    :alt="`Logotipo de ${link.title}`"
                                    class="h-full w-full object-contain"
                                    loading="lazy"
                                />
                                <Globe v-else class="h-5 w-5 text-primary/70" />
                            </div>

                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary/10 text-primary border border-primary/20">
                                        {{ link.category_name }}
                                    </span>
                                    <span class="text-[11px] font-mono text-muted">
                                        Orden #{{ link.sort_order }}
                                    </span>
                                </div>
                                <h3 class="text-sm font-semibold text-foreground leading-snug">
                                    {{ link.title }}
                                </h3>
                                <p v-if="link.description" class="text-xs text-muted leading-relaxed">
                                    {{ link.description }}
                                </p>
                            </div>
                        </div>

                        <!-- Estado -->
                        <span
                            v-if="link.is_archived"
                            class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                        >
                            Archivado
                        </span>
                        <button
                            v-else
                            type="button"
                            :disabled="togglingLinkId === link.id"
                            class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold border transition-colors disabled:opacity-50"
                            :class="
                                link.is_active
                                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20'
                                    : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20'
                            "
                            @click="toggleLinkActive(link)"
                        >
                            <Eye v-if="link.is_active" class="h-3 w-3" />
                            <EyeOff v-else class="h-3 w-3" />
                            {{ link.is_active ? 'Visible' : 'Oculto' }}
                        </button>
                    </div>

                    <div class="flex items-center justify-between gap-2 pt-2 border-t border-border text-xs">
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-primary font-medium hover:underline truncate max-w-[220px]"
                        >
                            <Globe class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate">{{ link.domain }}</span>
                            <ExternalLink class="h-3 w-3 shrink-0" />
                        </a>

                        <div class="flex items-center gap-1.5 shrink-0">
                            <template v-if="!link.is_archived">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-background border border-border text-foreground hover:border-primary/40 transition-colors"
                                    @click="openEditLinkModal(link)"
                                >
                                    <Pencil class="h-3.5 w-3.5 text-primary" />
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center p-1.5 rounded-brand bg-background border border-border text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-colors"
                                    title="Archivar enlace"
                                    @click="promptArchiveLink(link)"
                                >
                                    <Archive class="h-3.5 w-3.5" />
                                </button>
                            </template>
                            <button
                                v-else
                                type="button"
                                :disabled="restoringLinkId === link.id"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-colors"
                                @click="restoreLink(link)"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                Restaurar
                            </button>
                        </div>
                    </div>
                </article>

                <div
                    v-if="filteredLinks.length === 0"
                    class="bg-surface border border-border rounded-brand p-8 text-center space-y-2.5"
                >
                    <Link2 class="h-8 w-8 mx-auto text-muted/60" />
                    <h4 class="text-sm font-semibold text-foreground">No se encontraron accesos rápidos</h4>
                    <p class="text-xs text-muted">
                        No hay enlaces registrados que coincidan con el filtro seleccionado.
                    </p>
                </div>
            </div>

            <!-- Tabla de escritorio (>= 768px) -->
            <div class="hidden md:block bg-surface border border-border rounded-brand shadow-xs overflow-hidden">
                <div class="w-full overflow-x-auto">
                    <table class="min-w-[780px] w-full text-left text-sm divide-y divide-border">
                        <thead class="bg-background text-xs uppercase font-semibold text-muted tracking-wider">
                            <tr>
                                <th class="px-5 py-3.5">Portal / Institución</th>
                                <th class="px-5 py-3.5">Categoría</th>
                                <th class="px-5 py-3.5">Dirección oficial</th>
                                <th class="px-4 py-3.5 text-center">Orden</th>
                                <th class="px-5 py-3.5">Estado</th>
                                <th class="px-5 py-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border bg-surface">
                            <tr
                                v-for="link in filteredLinks"
                                :key="`desktop-link-${link.id}`"
                                class="hover:bg-background/60 transition-colors"
                                :class="{ 'opacity-65 bg-background/30': link.is_archived }"
                            >
                                <td class="px-5 py-4 max-w-sm">
                                    <div class="flex items-start gap-3">
                                        <div class="h-10 w-10 shrink-0 rounded-xl border border-border bg-background flex items-center justify-center overflow-hidden p-1">
                                            <img
                                                v-if="link.logo_url"
                                                :src="link.logo_url"
                                                :alt="`Logotipo de ${link.title}`"
                                                class="h-full w-full object-contain"
                                                loading="lazy"
                                            />
                                            <Globe v-else class="h-4 w-4 text-primary/70" />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-foreground leading-snug">{{ link.title }}</div>
                                            <p v-if="link.description" class="text-xs text-muted mt-0.5 line-clamp-2">
                                                {{ link.description }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                                        {{ link.category_name }}
                                    </span>
                                    <span
                                        v-if="!link.category_is_active && !link.is_archived"
                                        class="block text-[10px] text-amber-600 dark:text-amber-400 mt-1"
                                    >
                                        Categoría oculta
                                    </span>
                                </td>

                                <td class="px-5 py-4 max-w-[230px]">
                                    <a
                                        :href="link.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline max-w-full"
                                        :title="link.url"
                                    >
                                        <Globe class="h-3.5 w-3.5 shrink-0" />
                                        <span class="truncate">{{ link.domain }}</span>
                                        <ExternalLink class="h-3 w-3 shrink-0" />
                                    </a>
                                </td>

                                <td class="px-4 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center justify-center px-2 py-0.5 rounded font-mono text-xs bg-background border border-border text-muted">
                                        {{ link.sort_order }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        v-if="link.is_archived"
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                                    >
                                        Archivado
                                    </span>
                                    <button
                                        v-else
                                        type="button"
                                        :disabled="togglingLinkId === link.id"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border transition-all disabled:opacity-50"
                                        :class="
                                            link.is_active
                                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/20'
                                                : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20 hover:bg-amber-500/20'
                                        "
                                        :title="link.is_active ? 'Haz clic para ocultar este enlace' : 'Haz clic para mostrar este enlace'"
                                        @click="toggleLinkActive(link)"
                                    >
                                        <Eye v-if="link.is_active" class="h-3.5 w-3.5" />
                                        <EyeOff v-else class="h-3.5 w-3.5" />
                                        {{ link.is_active ? 'Visible' : 'Oculto' }}
                                    </button>
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <template v-if="!link.is_archived">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-foreground hover:bg-background hover:border-primary/40 transition-colors"
                                                @click="openEditLinkModal(link)"
                                            >
                                                <Pencil class="h-3.5 w-3.5 text-primary" />
                                                Editar
                                            </button>

                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 hover:border-rose-500/30 transition-colors"
                                                @click="promptArchiveLink(link)"
                                            >
                                                <Archive class="h-3.5 w-3.5" />
                                                Archivar
                                            </button>
                                        </template>

                                        <button
                                            v-else
                                            type="button"
                                            :disabled="restoringLinkId === link.id"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-colors"
                                            @click="restoreLink(link)"
                                        >
                                            <RotateCcw class="h-3.5 w-3.5" />
                                            Restaurar
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="filteredLinks.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <div class="max-w-xs mx-auto space-y-2.5">
                                        <Link2 class="h-8 w-8 mx-auto text-muted/60" />
                                        <h4 class="text-sm font-semibold text-foreground">Sin accesos rápidos en esta vista</h4>
                                        <p class="text-xs text-muted">
                                            Prueba cambiando los filtros o registra un nuevo enlace oficial.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- PESTAÑA 2: LISTADO Y GESTIÓN DE CATEGORÍAS                        -->
        <!-- ================================================================= -->
        <div v-else class="space-y-4">
            <!-- Panel de filtros de categorías -->
            <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 shadow-xs flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                <div class="-mx-3 px-3 sm:mx-0 sm:px-0 flex items-center gap-1.5 overflow-x-auto no-scrollbar snap-x">
                    <button
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                        :class="
                            categoryStatusFilter === 'ACTIVE'
                                ? 'bg-primary text-primary-foreground shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="categoryStatusFilter = 'ACTIVE'"
                    >
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        Visibles ({{ categoryStatusCounts.ACTIVE }})
                    </button>

                    <button
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                        :class="
                            categoryStatusFilter === 'HIDDEN'
                                ? 'bg-amber-600 text-white shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="categoryStatusFilter = 'HIDDEN'"
                    >
                        <EyeOff class="h-3.5 w-3.5" />
                        Ocultas ({{ categoryStatusCounts.HIDDEN }})
                    </button>

                    <button
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                        :class="
                            categoryStatusFilter === 'ARCHIVED'
                                ? 'bg-rose-600 text-white shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="categoryStatusFilter = 'ARCHIVED'"
                    >
                        <Archive class="h-3.5 w-3.5" />
                        Archivadas ({{ categoryStatusCounts.ARCHIVED }})
                    </button>

                    <button
                        type="button"
                        class="shrink-0 snap-start inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                        :class="
                            categoryStatusFilter === 'ALL'
                                ? 'bg-secondary text-secondary-foreground shadow-xs'
                                : 'bg-background text-muted hover:text-foreground border border-border'
                        "
                        @click="categoryStatusFilter = 'ALL'"
                    >
                        Todas ({{ categoryStatusCounts.ALL }})
                    </button>
                </div>

                <div class="relative w-full md:w-72">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                    <input
                        v-model="categorySearch"
                        type="search"
                        placeholder="Buscar categoría..."
                        class="w-full pl-9 pr-8 py-2 bg-background border border-border rounded-brand text-xs sm:text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                    />
                    <button
                        v-if="categorySearch"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-foreground p-1"
                        @click="categorySearch = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            <!-- Tarjetas móviles de categorías (< 768px) -->
            <div class="md:hidden space-y-3">
                <article
                    v-for="cat in filteredCategories"
                    :key="`mobile-cat-${cat.id}`"
                    class="bg-surface border border-border rounded-brand p-3.5 shadow-xs space-y-3"
                    :class="{ 'opacity-70': cat.is_archived }"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-foreground">{{ cat.name }}</h3>
                                <span class="text-[11px] font-mono text-muted">Orden #{{ cat.sort_order }}</span>
                            </div>
                            <p class="text-xs text-muted mt-0.5">
                                {{ cat.active_links_count }} visibles · {{ cat.total_links_count }} en total
                            </p>
                        </div>

                        <span
                            v-if="cat.is_archived"
                            class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                        >
                            Archivada
                        </span>
                        <button
                            v-else
                            type="button"
                            :disabled="togglingCategoryId === cat.id"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold border transition-colors"
                            :class="
                                cat.is_active
                                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20'
                                    : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20'
                            "
                            @click="toggleCategoryActive(cat)"
                        >
                            <Eye v-if="cat.is_active" class="h-3 w-3" />
                            <EyeOff v-else class="h-3 w-3" />
                            {{ cat.is_active ? 'Visible' : 'Oculta' }}
                        </button>
                    </div>

                    <div class="pt-2.5 border-t border-border flex items-center justify-between gap-2">
                        <button
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            @click="filterLinksByCategory(cat.id)"
                        >
                            Ver enlaces ({{ cat.total_links_count }}) &rarr;
                        </button>

                        <div class="flex items-center gap-1.5">
                            <template v-if="!cat.is_archived">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-background border border-border text-foreground hover:border-primary/40"
                                    @click="openEditCategoryModal(cat)"
                                >
                                    <Pencil class="h-3.5 w-3.5 text-primary" />
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center p-1.5 rounded-brand bg-background border border-border text-rose-600 dark:text-rose-400 hover:bg-rose-500/10"
                                    :title="
                                        cat.total_links_count > 0
                                            ? 'Reasigna o elimina sus enlaces antes de archivar'
                                            : 'Archivar categoría'
                                    "
                                    @click="promptArchiveCategory(cat)"
                                >
                                    <Archive class="h-3.5 w-3.5" />
                                </button>
                            </template>
                            <button
                                v-else
                                type="button"
                                :disabled="restoringCategoryId === cat.id"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30"
                                @click="restoreCategory(cat)"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                Restaurar
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Tabla de escritorio de categorías (>= 768px) -->
            <div class="hidden md:block bg-surface border border-border rounded-brand shadow-xs overflow-hidden">
                <div class="w-full overflow-x-auto">
                    <table class="min-w-[680px] w-full text-left text-sm divide-y divide-border">
                        <thead class="bg-background text-xs uppercase font-semibold text-muted tracking-wider">
                            <tr>
                                <th class="px-5 py-3.5">Nombre de la categoría</th>
                                <th class="px-5 py-3.5">Identificador</th>
                                <th class="px-5 py-3.5 text-center">Enlaces asociados</th>
                                <th class="px-4 py-3.5 text-center">Orden</th>
                                <th class="px-5 py-3.5">Estado</th>
                                <th class="px-5 py-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border bg-surface">
                            <tr
                                v-for="cat in filteredCategories"
                                :key="`desktop-cat-${cat.id}`"
                                class="hover:bg-background/60 transition-colors"
                                :class="{ 'opacity-65 bg-background/30': cat.is_archived }"
                            >
                                <td class="px-5 py-4 font-semibold text-foreground">
                                    {{ cat.name }}
                                </td>

                                <td class="px-5 py-4 font-mono text-xs text-muted">
                                    {{ cat.slug }}
                                </td>

                                <td class="px-5 py-4 text-center whitespace-nowrap">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-background border border-border text-foreground hover:border-primary/40 hover:text-primary transition-colors"
                                        @click="filterLinksByCategory(cat.id)"
                                    >
                                        <span>{{ cat.active_links_count }} visibles</span>
                                        <span class="text-muted">({{ cat.total_links_count }} total)</span>
                                    </button>
                                </td>

                                <td class="px-4 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center justify-center px-2 py-0.5 rounded font-mono text-xs bg-background border border-border text-muted">
                                        {{ cat.sort_order }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        v-if="cat.is_archived"
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                                    >
                                        Archivada
                                    </span>
                                    <button
                                        v-else
                                        type="button"
                                        :disabled="togglingCategoryId === cat.id"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border transition-all disabled:opacity-50"
                                        :class="
                                            cat.is_active
                                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/20'
                                                : 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/20 hover:bg-amber-500/20'
                                        "
                                        @click="toggleCategoryActive(cat)"
                                    >
                                        <Eye v-if="cat.is_active" class="h-3.5 w-3.5" />
                                        <EyeOff v-else class="h-3.5 w-3.5" />
                                        {{ cat.is_active ? 'Visible' : 'Oculta' }}
                                    </button>
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <template v-if="!cat.is_archived">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-foreground hover:bg-background hover:border-primary/40 transition-colors"
                                                @click="openEditCategoryModal(cat)"
                                            >
                                                <Pencil class="h-3.5 w-3.5 text-primary" />
                                                Editar
                                            </button>

                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border transition-colors"
                                                :class="
                                                    cat.total_links_count > 0
                                                        ? 'text-muted hover:text-amber-600 hover:border-amber-500/30'
                                                        : 'text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 hover:border-rose-500/30'
                                                "
                                                :title="
                                                    cat.total_links_count > 0
                                                        ? 'Tiene enlaces asociados: reasígnalos o elimínalos primero'
                                                        : 'Archivar categoría'
                                                "
                                                @click="promptArchiveCategory(cat)"
                                            >
                                                <Archive class="h-3.5 w-3.5" />
                                                Archivar
                                            </button>
                                        </template>

                                        <button
                                            v-else
                                            type="button"
                                            :disabled="restoringCategoryId === cat.id"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-colors"
                                            @click="restoreCategory(cat)"
                                        >
                                            <RotateCcw class="h-3.5 w-3.5" />
                                            Restaurar
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="filteredCategories.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <div class="max-w-xs mx-auto space-y-2">
                                        <FolderKanban class="h-8 w-8 mx-auto text-muted/60" />
                                        <h4 class="text-sm font-semibold text-foreground">No se encontraron categorías</h4>
                                        <p class="text-xs text-muted">
                                            Crea una nueva categoría para agrupar los portales oficiales.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- MODAL: CREAR / EDITAR ACCESO RÁPIDO                               -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isLinkModalOpen"
            :title="editingLinkId ? 'Editar acceso rápido' : 'Registrar nuevo acceso rápido'"
            max-width="lg"
        >
            <form id="quick-link-form" class="space-y-4" @submit.prevent="submitLinkForm">
                <BaseAlert v-if="linkErrors.general" type="error" title="Revisa los datos">
                    {{ linkErrors.general }}
                </BaseAlert>

                <!-- Logotipo / Ícono institucional estandarizado (160x160 WebP) -->
                <div class="p-3.5 rounded-brand border border-border bg-background/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3.5">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <div class="h-14 w-14 shrink-0 rounded-xl border border-border bg-surface flex items-center justify-center overflow-hidden p-1.5 shadow-2xs">
                            <img
                                v-if="linkForm.logoPreviewUrl"
                                :src="linkForm.logoPreviewUrl"
                                alt="Vista previa del logotipo"
                                class="h-full w-full object-contain"
                            />
                            <Globe v-else class="h-6 w-6 text-muted/60" />
                        </div>

                        <div class="min-w-0">
                            <span class="block text-xs font-semibold text-foreground">
                                Logotipo o emblema institucional (opcional)
                            </span>
                            <span class="block text-[11px] text-muted mt-0.5 leading-snug">
                                Se estandariza automáticamente a <strong class="text-foreground">160×160 px</strong> ligero al seleccionarlo.
                                <template v-if="linkForm.logoSizeBytes > 0">
                                    · <span class="text-emerald-600 dark:text-emerald-400 font-semibold">{{ formatKb(linkForm.logoSizeBytes) }}</span>
                                </template>
                            </span>
                            <p v-if="linkErrors.logo" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                {{ linkErrors.logo }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <label
                            class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand border border-border bg-surface hover:border-primary/40 text-xs font-semibold text-foreground hover:text-primary transition-colors"
                        >
                            <ImagePlus class="h-3.5 w-3.5 text-primary" />
                            <span>{{ linkForm.logoPreviewUrl ? 'Cambiar' : 'Subir imagen' }}</span>
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="sr-only"
                                @change="handleLogoSelect"
                            />
                        </label>

                        <button
                            v-if="linkForm.logoPreviewUrl"
                            type="button"
                            class="inline-flex items-center justify-center p-1.5 rounded-brand border border-border bg-surface text-muted hover:text-rose-600 hover:border-rose-500/30 transition-colors"
                            title="Quitar imagen"
                            @click="removeLogoImage"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            Categoría <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model="linkForm.quick_link_category_id"
                            class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                        >
                            <option value="" disabled>Selecciona una categoría...</option>
                            <option
                                v-for="cat in availableCategoriesForSelect"
                                :key="`opt-cat-${cat.id}`"
                                :value="cat.id"
                            >
                                {{ cat.name }}{{ !cat.is_active ? ' (Oculta)' : '' }}
                            </option>
                        </select>
                        <p v-if="linkErrors.quick_link_category_id" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                            {{ linkErrors.quick_link_category_id }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">
                            Orden de aparición
                        </label>
                        <input
                            v-model.number="linkForm.sort_order"
                            type="number"
                            min="0"
                            max="9999"
                            class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                        />
                        <p v-if="linkErrors.sort_order" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                            {{ linkErrors.sort_order }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        Título del portal o institución <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="linkForm.title"
                        type="text"
                        maxlength="120"
                        placeholder="Ej. SIAT en Línea — Impuestos Nacionales"
                        class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                    />
                    <p v-if="linkErrors.title" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {{ linkErrors.title }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        Dirección web oficial (https://) <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="linkForm.url"
                        type="url"
                        maxlength="500"
                        placeholder="https://siat.impuestos.gob.bo"
                        class="w-full px-3 py-2 rounded-brand bg-background border border-border font-mono text-xs sm:text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                    />
                    <p v-if="linkErrors.url" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {{ linkErrors.url }}
                    </p>
                    <p v-else class="mt-1 text-[11px] text-muted">
                        Solo se permiten direcciones seguras que inicien con <code class="font-mono">https://</code> o <code class="font-mono">http://</code>.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        Descripción breve para el estudiante (opcional)
                    </label>
                    <textarea
                        v-model="linkForm.description"
                        rows="2"
                        maxlength="255"
                        placeholder="Ej. Declaraciones juradas, facturación en línea y registro de compras y ventas."
                        class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                    ></textarea>
                    <p v-if="linkErrors.description" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {{ linkErrors.description }}
                    </p>
                </div>

                <label class="flex items-center gap-3 p-3 rounded-brand border border-border bg-background cursor-pointer">
                    <input
                        v-model="linkForm.is_active"
                        type="checkbox"
                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    />
                    <div>
                        <span class="block text-xs font-semibold text-foreground">Mostrar en el directorio de estudiantes</span>
                        <span class="block text-[11px] text-muted">Desmarca esta opción si deseas ocultar temporalmente el enlace sin borrarlo.</span>
                    </div>
                </label>
            </form>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 rounded-brand border border-border bg-surface text-xs font-medium text-foreground hover:bg-background transition-colors"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    form="quick-link-form"
                    :disabled="isSavingLink"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                >
                    <span>{{ isSavingLink ? 'Guardando...' : editingLinkId ? 'Guardar cambios' : 'Registrar enlace' }}</span>
                </button>
            </template>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL: CREAR / EDITAR CATEGORÍA                                   -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="isCategoryModalOpen"
            :title="editingCategoryId ? 'Editar categoría' : 'Nueva categoría de accesos'"
            max-width="md"
        >
            <form id="quick-category-form" class="space-y-4" @submit.prevent="submitCategoryForm">
                <BaseAlert v-if="categoryErrors.general" type="error" title="Operación no permitida">
                    {{ categoryErrors.general }}
                </BaseAlert>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        Nombre de la categoría <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="categoryForm.name"
                        type="text"
                        maxlength="100"
                        placeholder="Ej. Impuestos y Tributos"
                        class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                    />
                    <p v-if="categoryErrors.name" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {{ categoryErrors.name }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        Orden de aparición
                    </label>
                    <input
                        v-model.number="categoryForm.sort_order"
                        type="number"
                        min="0"
                        max="9999"
                        class="w-full px-3 py-2 rounded-brand bg-background border border-border text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                    />
                    <p v-if="categoryErrors.sort_order" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {{ categoryErrors.sort_order }}
                    </p>
                </div>

                <label class="flex items-center gap-3 p-3 rounded-brand border border-border bg-background cursor-pointer">
                    <input
                        v-model="categoryForm.is_active"
                        type="checkbox"
                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    />
                    <div>
                        <span class="block text-xs font-semibold text-foreground">Categoría visible para los estudiantes</span>
                        <span class="block text-[11px] text-muted">Si se oculta la categoría, todos sus enlaces dejarán de mostrarse en el portal estudiantil.</span>
                    </div>
                </label>
            </form>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 rounded-brand border border-border bg-surface text-xs font-medium text-foreground hover:bg-background transition-colors"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    form="quick-category-form"
                    :disabled="isSavingCategory"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs font-semibold transition-colors disabled:opacity-50"
                >
                    <span>{{ isSavingCategory ? 'Guardando...' : editingCategoryId ? 'Guardar cambios' : 'Crear categoría' }}</span>
                </button>
            </template>
        </BaseModal>

        <!-- ================================================================= -->
        <!-- MODAL: CONFIRMACIÓN DE ARCHIVADO                                  -->
        <!-- ================================================================= -->
        <BaseModal
            v-model="confirmDialog.open"
            :title="confirmDialog.title"
            max-width="sm"
        >
            <div class="space-y-3">
                <BaseAlert v-if="confirmDialog.error" type="error" title="No se pudo completar">
                    {{ confirmDialog.error }}
                </BaseAlert>

                <p class="text-xs sm:text-sm text-muted leading-relaxed">
                    {{ confirmDialog.description }}
                </p>
            </div>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-3.5 py-2 rounded-brand border border-border bg-surface text-xs font-medium text-foreground hover:bg-background transition-colors"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    :disabled="confirmDialog.loading"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-brand bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold transition-colors disabled:opacity-50"
                    @click="executeConfirmDialog"
                >
                    <span>{{ confirmDialog.loading ? 'Procesando...' : confirmDialog.confirmLabel }}</span>
                </button>
            </template>
        </BaseModal>
    </div>
</template>
