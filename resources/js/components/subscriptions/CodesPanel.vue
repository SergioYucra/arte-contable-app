<script setup>
/**
 * Códigos de acceso: se generan aquí y se entregan a mano. Cada uno se usa una sola vez.
 */
import { ref, watch, inject, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { Plus, Search, Copy, Check, KeyRound } from 'lucide-vue-next';
import BaseModal from '../ui/BaseModal.vue';
import CodeGeneratorModal from './CodeGeneratorModal.vue';
import { PANEL_CONTEXT, btn, field, readError, plural } from './shared';

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

async function load({ append = false } = {}) {
    const current = ++requestId;
    loading.value = true;
    try {
        const { data } = await axios.get(urls.accessCodes, {
            params: { q: search.value.trim() || undefined, page: append ? nextPage.value : 1 },
        });
        if (current !== requestId) return;
        codes.value = append ? [...codes.value, ...data.items] : data.items;
        total.value = data.total;
        nextPage.value = data.next_page;
    } catch (e) {
        if (current === requestId) notify('error', 'No se pudieron cargar los códigos', readError(e).message);
    } finally {
        if (current === requestId) loading.value = false;
    }
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => load(), 300);
});
onBeforeUnmount(() => clearTimeout(debounce));

async function copy(code) {
    try {
        await navigator.clipboard.writeText(code.code);
        copiedId.value = code.id;
        setTimeout(() => copiedId.value === code.id && (copiedId.value = null), 2000);
    } catch {
        notify('error', 'No se pudo copiar', 'Selecciona el código y cópialo a mano.');
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
    available: 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/30',
    used: 'bg-primary/10 text-primary border-primary/30',
    revoked: 'bg-background text-muted border-border line-through decoration-1',
};
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
            <div>
                <h3 class="font-serif text-xl text-foreground">Códigos de acceso</h3>
                <p class="text-sm text-muted max-w-prose">
                    Para regalar acceso sin pago: lectores del libro impreso, becas o promociones. Cada código sirve una sola vez y sus días se suman a lo que la persona ya tenga.
                </p>
            </div>
            <button type="button" :class="btn.primary" @click="generatorOpen = true">
                <Plus class="h-4 w-4" aria-hidden="true" />
                Crear códigos
            </button>
        </div>

        <div class="relative sm:w-80">
            <label for="codes-search" class="sr-only">Buscar código, para quién o persona que lo usó</label>
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" aria-hidden="true" />
            <input
                id="codes-search"
                v-model="search"
                type="search"
                maxlength="100"
                autocomplete="off"
                spellcheck="false"
                placeholder="Buscar código, nota o persona…"
                :class="[field, 'pl-9']"
            />
        </div>

        <p class="text-sm text-muted" aria-live="polite">{{ loading && !codes.length ? 'Cargando…' : plural(total, 'código', 'códigos') }}</p>

        <ul v-if="codes.length" class="divide-y divide-border rounded-brand border border-border bg-surface">
            <li v-for="code in codes" :key="code.id" class="p-4 grid gap-3 sm:grid-cols-[minmax(0,15rem)_1fr_auto] sm:items-center">
                <div class="flex items-center gap-2">
                    <code translate="no" class="font-mono text-base font-semibold tracking-wide text-foreground select-all">{{ code.code }}</code>
                    <button
                        v-if="code.is_available"
                        type="button"
                        class="p-1.5 rounded-brand text-muted hover:text-foreground hover:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
                        :aria-label="`Copiar el código ${code.code}`"
                        @click="copy(code)"
                    >
                        <Check v-if="copiedId === code.id" class="h-4 w-4 text-emerald-600" aria-hidden="true" />
                        <Copy v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
                    <span v-if="copiedId === code.id" class="text-xs text-emerald-700 dark:text-emerald-400" role="status">Copiado</span>
                </div>

                <div class="min-w-0 text-sm">
                    <p class="text-foreground">
                        {{ code.duration_label }}<span v-if="code.plan_name" class="text-muted"> · {{ code.plan_name }}</span>
                    </p>
                    <p v-if="code.reference" class="text-muted truncate" :title="code.reference">{{ code.reference }}</p>
                    <p v-if="code.status === 'used'" class="text-muted">Usado por {{ code.redeemer_name || 'cuenta eliminada' }} · {{ code.redeemed_at }}</p>
                </div>

                <div class="flex items-center gap-3 sm:justify-end">
                    <span class="px-2.5 py-1 rounded-full border text-xs font-semibold" :class="statusStyle[code.status]">{{ code.status_label }}</span>
                    <button v-if="code.is_available" type="button" :class="btn.dangerQuiet" @click="voidTarget = code">
                        Anular
                        <span class="sr-only"> el código {{ code.code }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <div v-else-if="!loading" class="rounded-brand border border-dashed border-border bg-surface/60 px-6 py-10 text-center">
            <KeyRound class="mx-auto h-8 w-8 text-muted" aria-hidden="true" />
            <p class="mt-3 text-sm text-foreground">
                {{ search.trim() ? `Ningún código coincide con «${search.trim()}».` : 'Todavía no creaste códigos.' }}
            </p>
        </div>

        <div v-if="nextPage" class="text-center">
            <button type="button" :class="btn.secondary" :disabled="loading" @click="load({ append: true })">
                {{ loading ? 'Cargando…' : 'Ver más' }}
            </button>
        </div>

        <CodeGeneratorModal v-model="generatorOpen" @generated="onGenerated" />

        <BaseModal
            :model-value="Boolean(voidTarget)"
            :title="voidTarget ? `¿Anular ${voidTarget.code}?` : ''"
            max-width="md"
            :closeable="!voiding"
            @update:model-value="!$event && (voidTarget = null)"
        >
            <p class="text-sm text-foreground">Nadie podrá usarlo. Esto no se puede deshacer; si hace falta, crea un código nuevo.</p>
            <template #footer="{ close }">
                <button type="button" :class="btn.secondary" :disabled="voiding" @click="close">Cancelar</button>
                <button type="button" :class="btn.danger" :disabled="voiding" @click="voidCode">
                    {{ voiding ? 'Anulando…' : 'Anular código' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
