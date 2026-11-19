<script setup>
import { Loader2, RefreshCw } from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';

/**
 * Lo que se ve mientras se descarga una calculadora o herramienta, o si la descarga falla
 * (por ejemplo, justo después de publicar una versión nueva: la pestaña pide archivos viejos).
 */
defineProps({
    failed: { type: Boolean, default: false },
});

function reload() {
    window.location.reload();
}
</script>

<template>
    <BaseAlert v-if="failed" type="error" title="No se pudo abrir">
        <p>Puede que se haya publicado una versión nueva mientras tenías la página abierta.</p>
        <button
            type="button"
            class="mt-2 inline-flex items-center gap-1.5 rounded-brand border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            @click="reload"
        >
            <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" /> Recargar la página
        </button>
    </BaseAlert>
    <p v-else class="flex items-center justify-center gap-2 py-10 text-sm text-muted" role="status">
        <Loader2 class="h-4 w-4 animate-spin" aria-hidden="true" /> Cargando…
    </p>
</template>
