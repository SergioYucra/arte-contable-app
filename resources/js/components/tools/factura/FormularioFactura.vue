<script setup>
import { computed, ref } from 'vue';
import { AlertTriangle, FileText, Plus, Printer, RotateCcw, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    form: {
        type: Object,
        required: true,
    },
    calculo: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['limpiar', 'agregar-item', 'eliminar-item', 'ver-factura', 'imprimir']);

const confirmarLimpiar = ref(false);

const esComprando = computed(() => props.form.modo === 'COMPRANDO');

const labelReferenciaPago = computed(() => {
    switch (props.form.tipo_pago) {
        case 'EFECTIVO':
            return 'N° de recibo';
        case 'CHEQUE':
            return 'N° de cheque';
        case 'TRANSFERENCIA':
            return 'N° de transferencia';
        default:
            return '';
    }
});

function onCodigoAutorizacionInput(event) {
    const cleaned = String(event.target.value || '')
        .toUpperCase()
        .replace(/[^0-9A-F]/g, '')
        .slice(0, 56);
    props.form.codigo_autorizacion = cleaned;
}

function handleLimpiarClick() {
    if (!confirmarLimpiar.value) {
        confirmarLimpiar.value = true;
        return;
    }
    confirmarLimpiar.value = false;
    emit('limpiar');
}
</script>

<template>
    <div
        class="mx-auto w-full max-w-[760px] rounded-brand border border-slate-300 bg-white text-slate-900 shadow-xs overflow-hidden"
        style="font-family: Aptos, Calibri, system-ui, -apple-system, sans-serif;"
    >
        <!-- Título -->
        <div class="border-b border-slate-300 bg-[#E7E6E6]/60 px-4 py-3 text-center">
            <h2 class="text-[20px] font-bold tracking-tight text-[#002060]">
                MACRO FACTURADOR
            </h2>
        </div>

        <div class="p-4 sm:p-5 space-y-5">
            <!-- SECCIÓN A: INFORMACIÓN DE LA EMPRESA -->
            <section class="border border-slate-300">
                <header class="bg-[#375623] px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-white">
                    INFORMACIÓN DE LA EMPRESA
                </header>
                <div class="p-3.5 space-y-3 bg-white">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-800 mb-1">
                            ¿Estás comprando o vendiendo?
                        </label>
                        <select
                            v-model="form.modo"
                            class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#375623] focus:outline-none"
                        >
                            <option value="VENDIENDO">VENDIENDO</option>
                            <option value="COMPRANDO">COMPRANDO</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                ¿Cuál es el nombre de tu empresa?
                            </label>
                            <input
                                v-model="form.empresa_nombre"
                                type="text"
                                placeholder="Razón social"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#375623] focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                ¿Cuál es el NIT de tu empresa?
                            </label>
                            <input
                                v-model="form.empresa_nit"
                                type="text"
                                inputmode="numeric"
                                placeholder="Ej. 1020304050"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#375623] focus:outline-none"
                            />
                        </div>
                    </div>

                    <div v-if="!esComprando">
                        <label class="block text-[11px] font-bold text-slate-800 mb-1">
                            ¿Cuál es tu dirección?
                        </label>
                        <input
                            v-model="form.empresa_direccion"
                            type="text"
                            placeholder="Dirección fiscal"
                            class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#375623] focus:outline-none"
                        />
                    </div>
                </div>
            </section>

            <!-- SECCIÓN B: INFORMACIÓN DEL COMPRADOR -->
            <section class="border border-slate-300">
                <header class="bg-[#1F3864] px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-white">
                    INFORMACIÓN DEL COMPRADOR
                </header>
                <div class="p-3.5 space-y-3 bg-white">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-800 mb-1">
                            {{ esComprando ? '¿Qué estás comprando?' : '¿Qué estás vendiendo?' }}
                        </label>
                        <select
                            v-model="form.tipo_item"
                            class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#1F3864] focus:outline-none"
                        >
                            <option value="PRODUCTO">PRODUCTO</option>
                            <option value="SERVICIO">SERVICIO</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                {{ esComprando ? '¿A quién estás comprando?' : '¿A quién estás vendiendo?' }}
                            </label>
                            <input
                                v-model="form.contraparte_nombre"
                                type="text"
                                :placeholder="esComprando ? 'Nombre o razón social del proveedor' : 'Nombre o razón social del cliente'"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#1F3864] focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                {{ esComprando ? '¿Cuál es el NIT del proveedor?' : '¿Cuál es el NIT del cliente?' }}
                            </label>
                            <input
                                v-model="form.contraparte_nit"
                                type="text"
                                inputmode="numeric"
                                placeholder="NIT / CI / CEX"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#1F3864] focus:outline-none"
                            />
                        </div>
                    </div>

                    <div v-if="esComprando">
                        <label class="block text-[11px] font-bold text-slate-800 mb-1">
                            ¿Cuál es la dirección del proveedor?
                        </label>
                        <input
                            v-model="form.contraparte_direccion"
                            type="text"
                            placeholder="Dirección del proveedor"
                            class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#1F3864] focus:outline-none"
                        />
                    </div>
                </div>
            </section>

            <!-- SECCIÓN C: DATOS DE LA FACTURA -->
            <section class="border border-slate-300">
                <header class="bg-[#002060] px-3 py-1.5 text-center text-xs font-bold uppercase tracking-wide text-white">
                    DATOS DE LA FACTURA
                </header>
                <div class="p-3.5 space-y-3 bg-white">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                N° de Factura
                            </label>
                            <input
                                v-model="form.numero_factura"
                                type="number"
                                min="0"
                                step="1"
                                placeholder="Ej. 101"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                {{ esComprando ? 'Fecha de compra' : 'Fecha de venta' }}
                            </label>
                            <input
                                v-model="form.fecha"
                                type="date"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-[11px] font-bold text-slate-800">
                                Código de autorización (56 caracteres hexadecimales)
                            </label>
                            <span class="text-[10px] font-semibold tabular-nums text-slate-500">
                                {{ (form.codigo_autorizacion || '').length }}/56
                            </span>
                        </div>
                        <input
                            :value="form.codigo_autorizacion"
                            type="text"
                            maxlength="56"
                            placeholder="Ej. A1B2C3D4..."
                            class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-mono uppercase text-slate-900 focus:border-[#002060] focus:outline-none"
                            @input="onCodigoAutorizacionInput"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                Tipo de pago
                            </label>
                            <select
                                v-model="form.tipo_pago"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#002060] focus:outline-none"
                            >
                                <option value="">Seleccionar...</option>
                                <option value="EFECTIVO">EFECTIVO</option>
                                <option value="CHEQUE">CHEQUE</option>
                                <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                            </select>
                        </div>

                        <div v-if="form.tipo_pago">
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                {{ labelReferenciaPago }}
                            </label>
                            <input
                                v-model="form.referencia_pago"
                                type="text"
                                placeholder="Número de referencia"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                {{ esComprando ? 'Monto pagado (Bs)' : 'Monto recibido (Bs)' }}
                            </label>
                            <input
                                v-model="form.monto_pagado"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0,00"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-slate-200">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                Descuento global (Bs)
                            </label>
                            <input
                                v-model="form.descuento_global"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0,00"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-800 mb-1">
                                Monto Gift Card (Bs)
                            </label>
                            <input
                                v-model="form.gift_card"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0,00"
                                class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN D: ÍTEMS (en líneas) -->
            <section class="space-y-2.5">
                <p class="text-center text-xs font-bold text-[#C00000]">
                    {{ esComprando ? 'Ahora llena los datos de los ítems que compraste' : 'Ahora llena los datos de los ítems que vendiste' }}
                </p>

                <div class="space-y-2">
                    <div
                        v-for="(item, idx) in form.items"
                        :key="idx"
                        class="border border-slate-300 bg-[#E7E6E6]/30 px-3 py-2.5"
                    >
                        <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-end">
                            <div class="sm:col-span-5">
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-[11px] font-bold text-slate-800">
                                        <span class="text-[#C00000]">#{{ idx + 1 }}</span> · {{ esComprando ? '¿Qué compraste?' : '¿Qué vendiste?' }}
                                    </label>
                                    <button
                                        v-if="form.items.length > 1"
                                        type="button"
                                        class="sm:hidden inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-[#C00000]"
                                        :aria-label="`Eliminar ítem ${idx + 1}`"
                                        @click="emit('eliminar-item', idx)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                        <span>Quitar</span>
                                    </button>
                                </div>
                                <input
                                    v-model="item.descripcion"
                                    type="text"
                                    placeholder="Descripción del bien o servicio"
                                    class="w-full border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#002060] focus:outline-none"
                                />
                            </div>

                            <div class="grid grid-cols-3 sm:col-span-6 gap-2">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-800 mb-1 truncate" :title="esComprando ? '¿Cuánto compraste?' : '¿Cuánto vendiste?'">
                                        Cantidad
                                    </label>
                                    <input
                                        v-model="item.cantidad"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0"
                                        class="w-full border border-slate-300 bg-white px-2 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                                    />
                                </div>

                                <div>
                                    <label class="block text-[11px] font-bold text-slate-800 mb-1 truncate">
                                        Precio unit.
                                    </label>
                                    <input
                                        v-model="item.precio_unitario"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0,00"
                                        class="w-full border border-slate-300 bg-white px-2 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                                    />
                                </div>

                                <div>
                                    <label class="block text-[11px] font-bold text-slate-800 mb-1 truncate">
                                        Descuento
                                    </label>
                                    <input
                                        v-model="item.descuento"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0,00"
                                        class="w-full border border-slate-300 bg-white px-2 py-1.5 text-xs tabular-nums text-slate-900 focus:border-[#002060] focus:outline-none"
                                    />
                                </div>
                            </div>

                            <div class="hidden sm:flex sm:col-span-1 justify-end">
                                <button
                                    v-if="form.items.length > 1"
                                    type="button"
                                    class="inline-flex h-[30px] w-[30px] items-center justify-center border border-slate-300 bg-white text-slate-500 hover:border-[#C00000] hover:text-[#C00000] transition-colors"
                                    :aria-label="`Eliminar ítem ${idx + 1}`"
                                    title="Quitar ítem"
                                    @click="emit('eliminar-item', idx)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 border border-dashed border-[#002060]/50 bg-white px-3.5 py-1.5 text-xs font-bold text-[#002060] hover:bg-[#002060]/5 transition-colors"
                        @click="emit('agregar-item')"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        <span>Agregar ítem</span>
                    </button>
                </div>
            </section>

            <!-- Validaciones -->
            <div
                v-if="calculo.errores.length > 0"
                class="border border-rose-400 bg-rose-50 p-3 text-xs text-rose-800 space-y-1"
            >
                <p v-for="err in calculo.errores" :key="err" class="flex items-center gap-1.5 font-semibold">
                    <AlertTriangle class="h-3.5 w-3.5 shrink-0" />
                    <span>{{ err }}</span>
                </p>
            </div>

            <!-- Botones de acción -->
            <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-200">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 border px-3 py-1.5 text-xs font-bold transition-colors"
                        :class="
                            confirmarLimpiar
                                ? 'border-[#C00000] bg-[#C00000] text-white'
                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'
                        "
                        @click="handleLimpiarClick"
                        @blur="confirmarLimpiar = false"
                    >
                        <RotateCcw class="h-3.5 w-3.5" />
                        <span>{{ confirmarLimpiar ? 'Confirmar limpiar' : 'Limpiar' }}</span>
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="lg:hidden inline-flex items-center gap-1.5 border border-[#1F3864] bg-[#1F3864] px-3 py-1.5 text-xs font-bold text-white hover:opacity-90 transition-opacity"
                        @click="emit('ver-factura')"
                    >
                        <FileText class="h-3.5 w-3.5" />
                        <span>Ver factura</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 border border-[#002060] bg-[#002060] px-3.5 py-1.5 text-xs font-bold text-white hover:opacity-90 transition-opacity"
                        @click="emit('imprimir')"
                    >
                        <Printer class="h-3.5 w-3.5" />
                        <span>Imprimir PDF</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
