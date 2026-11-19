<script setup>
import { ref } from 'vue';
import { FileText, PenLine } from 'lucide-vue-next';
import { useFactura } from './composables/useFactura.js';
import FormularioFactura from './FormularioFactura.vue';
import VistaFactura from './VistaFactura.vue';

const { form, calculo, limpiar, agregarItem, eliminarItem } = useFactura();
const mobileTab = ref('datos'); // 'datos' | 'factura'

function imprimirPdf() {
    const prevTitle = document.title;
    const empresa = (calculo.value.emisor.nombre || 'EMPRESA').trim();
    const nro = (form.numero_factura || 'S-N').toString().trim();
    document.title = `${empresa} FACTURA - ${nro}`;

    window.print();

    setTimeout(() => {
        document.title = prevTitle;
    }, 300);
}
</script>

<template>
    <div class="space-y-4 facturador-print-root">
        <!-- Pestañas solo en móvil/tablet -->
        <div class="no-print flex lg:hidden items-center gap-1.5 border-b border-border pb-3">
            <button
                type="button"
                class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold transition-all"
                :class="
                    mobileTab === 'datos'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'bg-background text-muted border border-border'
                "
                @click="mobileTab = 'datos'"
            >
                <PenLine class="h-3.5 w-3.5" />
                <span>Datos</span>
            </button>
            <button
                type="button"
                class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold transition-all"
                :class="
                    mobileTab === 'factura'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'bg-background text-muted border border-border'
                "
                @click="mobileTab = 'factura'"
            >
                <FileText class="h-3.5 w-3.5" />
                <span>Factura</span>
            </button>
        </div>

        <!-- Escritorio: dos paneles lado a lado -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            <div
                class="no-print lg:col-span-5"
                :class="mobileTab === 'datos' ? 'block' : 'hidden lg:block'"
            >
                <FormularioFactura
                    :form="form"
                    :calculo="calculo"
                    @limpiar="limpiar"
                    @agregar-item="agregarItem"
                    @eliminar-item="eliminarItem"
                    @ver-factura="mobileTab = 'factura'"
                    @imprimir="imprimirPdf"
                />
            </div>

            <div
                class="print-section lg:col-span-7"
                :class="mobileTab === 'factura' ? 'block' : 'hidden lg:block'"
            >
                <VistaFactura :form="form" :calculo="calculo" />
            </div>
        </div>
    </div>
</template>

<style>
@page {
    size: letter;
    margin: 1cm;
}

@media print {
    body * {
        visibility: hidden;
    }
    .facturador-print-root,
    .facturador-print-root .print-section,
    .facturador-print-root .print-section * {
        visibility: visible;
    }
    .facturador-print-root .no-print {
        display: none !important;
    }
    .facturador-print-root .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        display: block !important;
    }
    .facturador-print-root .factura-sheet {
        min-width: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
    }
}
</style>
