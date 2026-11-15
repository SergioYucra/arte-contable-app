<script setup>
/**
 * Códigos de acceso: se generan aquí y se entregan a mano. Cada uno se usa una sola vez.
 */
import { ref, watch, inject, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { Plus, Search, Copy, Check, KeyRound, X, Ban } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import CodeGeneratorModal from './CodeGeneratorModal.vue';
import { PANEL_CONTEXT, btn, readError, plural } from './shared';

const props = defineProps({
    initialCodes: { type: Object, required: true },
});

const { urls, notify, updateSummary } = inject(PANEL_CONTEXT);

const codes = ref([...props.initialCodes.items]);
const total = ref(props.initialCodes.total);
const nextPage = ref(props.initialCodes.next_page);
const search = ref('');
const loading = ref(false);
const generatorOpen = ref(false);
const copiedId = ref(null);

let debounce = null;
let requestId = 0;
let activeController = null;

async function load({ append = false } = {}) {
    if (!append && activeController) {
        activeController.abort();
    }
    const controller = new AbortController();
    activeController = controller;

    const current = ++requestId;
    loading.value = true;
    try {
        const { data } = await axios.get(urls.accessCodes, {
            params: { q: search.value.trim() || undefined, page: append ? nextPage.value : 1 },
            signal: controller.signal,
        });
        if (current !== requestId) return;
        codes.value = append ? [...codes.value, ...data.items] : data.items;
        total.value = data.total;
        nextPage.value = data.next_page;
    } catch (e) {
        if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return;
        if (current === requestId) notify('error', 'No se pudieron cargar los códigos', readError(e).message);
    } finally {
        if (current === requestId) loading.value = false;
    }
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => load(), 300);
});
onBeforeUnmount(() => {
    clearTimeout(debounce);
    activeController?.abort();
});

async function copy(code) {
    try {
        await navigator.clipboard.writeText(code.code);
        copiedId.value = code.id;
        setTimeout(() => copiedId.value === code.id && (copiedId.value = null), 2000);
    } catch {
        notify('error', 'No se pudo copiar', 'Selecciona el código y cópialo manualmente.');
    }
}

function onGenerated({ codes: created, summary }) {
    codes.value = [...created, ...codes.value];
    total.value += created.length;
    updateSummary(summary);
}

// Anular
const voidTarget = ref(null);
const voiding = ref(false);

async function voidCode() {
    if (!voidTarget.value || voiding.value) return;
    voiding.value = true;
    try {
        const { data } = await axios.post(voidTarget.value.revoke_url);
        const index = codes.value.findIndex((c) => c.id === data.access_code.id);
        if (index !== -1) codes.value[index] = data.access_code;
        updateSummary(data.summary);
        notify('success', 'Código anulado', data.message);
        voidTarget.value = null;
    } catch (e) {
        notify('error', 'No se pudo anular', readError(e).message);
    } finally {
        voiding.value = false;
    }
}

const statusStyle = {
    available: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
    used: 'bg-primary/10 text-primary border-primary/20',
    revoked: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20 line-through decoration-1',
};
</script>

<template>
    <div class="space-y-4">
        <!-- Barra superior de herramientas alineada a /admin/libros -->
        <div class="bg-surface border border-border rounded-brand p-3 sm:p-4 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 sm:gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-foreground">Códigos de acceso</h3>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-background border border-border text-muted tabular-nums">
                        {{ total }}
                    </span>
                </div>
                <p class="text-xs text-muted mt-0.5">
                    Genera códigos de un solo uso para lectores del libro impreso, becas o convenios.
                </p>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <div class="relative flex-1 min-w-0 lg:w-72">
                    <label for="codes-search" class="sr-only">Buscar código, nota o persona</label>
                    <Search class="h-4 w-4 text-muted absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
                    <input
                        id="codes-search"
                        v-model="search"
                        type="search"
                        inputmode="search"
                        maxlength="100"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Buscar código, nota o persona..."
                        class="w-full pl-9 pr-9 py-2.5 sm:py-2 text-sm bg-background border border-border rounded-brand text-foreground placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="absolute right-1 top-1/2 -translate-y-1/2 p-2 text-muted hover:text-foreground"
                        aria-label="Limpiar búsqueda"
                        @click="search = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <button
                    type="button"
                    class="shrink-0 inline-flex items-center justify-center gap-2 h-10 sm:h-auto w-10 sm:w-auto sm:px-4 sm:py-2 rounded-brand bg-primary hover:bg-primary-hover text-primary-foreground text-xs sm:text-sm font-semibold transition-colors shadow-xs"
                    aria-label="Crear códigos"
                    @click="generatorOpen = true"
                >
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    <span class="hidden sm:inline">Crear códigos</span>
                </button>
            </div>
        </div>

        <!-- Contador de resultados -->
        <div class="flex items-center justify-between text-xs text-muted px-1" aria-live="polite">
            <span class="flex items-center gap-1.5">
                <KeyRound class="h-3.5 w-3.5" aria-hidden="true" />
                <template v-if="loading && !codes.length">Cargando códigos...</template>
                <template v-else>
                    Mostrando <strong class="text-foreground tabular-nums">{{ codes.length }}</strong> de {{ plural(total, 'código', 'códigos') }}
                </template>
            </span>
        </div>

        <ul v-if="codes.length" class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
            <li
                v-for="code in codes"
                :key="code.id"
                class="p-3 sm:p-3.5 grid gap-2.5 sm:gap-3 sm:grid-cols-[minmax(0,14rem)_1fr_auto] sm:items-center hover:bg-background/40 transition-colors"
            >
                <div class="flex items-center gap-1.5">
                    <code
                        translate="no"
                        class="font-mono text-xs sm:text-sm font-semibold tracking-wide text-foreground bg-background border border-border px-2.5 py-1 rounded-brand select-all"
                    >
                        {{ code.code }}
                    </code>
                    <button
                        v-if="code.is_available"
                        type="button"
                        class="p-1.5 rounded-brand text-muted hover:text-primary hover:bg-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                        :aria-label="`Copiar el código ${code.code}`"
                        title="Copiar código"
                        @click="copy(code)"
                    >
                        <Check v-if="copiedId === code.id" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Copy v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
                    <span v-if="copiedId === code.id" class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400" role="status">
                        Copiado
                    </span>
                </div>

                <div class="min-w-0 text-xs space-y-0.5">
                    <p class="text-foreground font-medium">
                        {{ code.duration_label }}<span v-if="code.plan_name" class="text-muted font-normal"> · {{ code.plan_name }}</span>
                    </p>
                    <p v-if="code.reference" class="text-muted truncate" :title="code.reference">{{ code.reference }}</p>
                    <p v-if="code.status === 'used'" class="text-[11px] text-muted">
                        Canjeado por <strong class="font-medium text-foreground">{{ code.redeemer_name || 'cuenta eliminada' }}</strong> · {{ code.redeemed_at }}
                    </p>
                </div>

                <div class="flex items-center justify-between sm:justify-end gap-2.5">
                    <span
                        class="inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold"
                        :class="statusStyle[code.status]"
                    >
                        {{ code.status_label }}
                    </span>
                    <button v-if="code.is_available" type="button" :class="btn.dangerQuiet" @click="voidTarget = code">
                        <Ban class="h-3.5 w-3.5" aria-hidden="true" />
                        <span>Anular</span>
                        <span class="sr-only"> el código {{ code.code }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <div
            v-else-if="!loading"
            class="bg-surface border border-border rounded-brand px-5 py-10 sm:p-10 text-center space-y-2.5"
        >
            <div class="mx-auto h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                <KeyRound class="h-6 w-6" aria-hidden="true" />
            </div>
            <h4 class="text-base font-semibold text-foreground">
                {{ search.trim() ? 'No se encontraron códigos' : 'Aún no hay códigos generados' }}
            </h4>
            <p class="text-sm text-muted max-w-md mx-auto">
                {{
                    search.trim()
                        ? `Ningún código coincide con «${search.trim()}».`
                        : 'Crea códigos de un solo uso para otorgar acceso directo sin comprobante bancario.'
                }}
            </p>
            <div class="pt-1">
                <button v-if="!search.trim()" type="button" :class="btn.primary" @click="generatorOpen = true">
                    <Plus class="h-4 w-4" aria-hidden="true" />
                    <span>Generar primeros códigos</span>
                </button>
                <button v-else type="button" :class="btn.secondary" @click="search = ''">
                    Limpiar búsqueda
                </button>
            </div>
        </div>

        <div v-if="nextPage" class="text-center">
            <button type="button" :class="btn.secondary" :disabled="loading" @click="load({ append: true })">
                {{ loading ? 'Cargando…' : 'Cargar más códigos' }}
            </button>
        </div>

        <CodeGeneratorModal v-model="generatorOpen" @generated="onGenerated" />

        <BaseModal
            :model-value="Boolean(voidTarget)"
            :title="voidTarget ? `Anular código ${voidTarget.code}` : 'Anular código'"
            max-width="md"
            :closeable="!voiding"
            @update:model-value="!$event && (voidTarget = null)"
        >
            <p class="text-xs sm:text-sm text-foreground leading-relaxed">
                Este código quedará invalidado permanentemente y nadie podrá canjearlo. Si necesitas otorgar acceso más adelante, podrás generar uno nuevo.
            </p>
            <template #footer="{ close }">
                <button type="button" :class="btn.secondary" :disabled="voiding" @click="close">Cancelar</button>
                <button type="button" :class="btn.danger" :disabled="voiding" @click="voidCode">
                    {{ voiding ? 'Anulando…' : 'Anular código' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
