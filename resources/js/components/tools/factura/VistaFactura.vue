<script setup>
import { computed } from 'vue';
import { formatBs } from '../../calculators/shared/formatters.js';

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

const fechaFormateada = computed(() => {
    const iso = props.form.fecha;
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '-';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
});

const etiquetaReferencia = computed(() => {
    switch (props.form.tipo_pago) {
        case 'EFECTIVO':
            return 'N° de recibo';
        case 'CHEQUE':
            return 'N° de cheque';
        case 'TRANSFERENCIA':
            return 'N° de transferencia';
        default:
            return 'Referencia';
    }
});
</script>

<template>
    <div class="w-full overflow-x-auto">
        <div
            class="factura-sheet mx-auto min-w-[680px] max-w-[816px] border border-black bg-white p-5 sm:p-6 text-black"
            style="font-family: Aptos, Calibri, system-ui, -apple-system, sans-serif;"
        >
            <!-- Cabecera emisor / datos fiscales -->
            <div class="grid grid-cols-12 border border-black">
                <div class="col-span-7 border-r border-black p-3 space-y-0.5 text-[11px] leading-snug">
                    <p class="text-sm font-bold uppercase text-black">
                        {{ calculo.emisor.nombre || 'RAZÓN SOCIAL DEL EMISOR' }}
                    </p>
                    <p class="font-semibold">Sucursal N° 1</p>
                    <p>No. Punto de Venta 0</p>
                    <p class="text-[10.5px]">
                        {{ calculo.emisor.direccion || 'Dirección no especificada' }}
                    </p>
                </div>

                <div class="col-span-5 p-3 space-y-1 text-[10.5px] leading-snug">
                    <div class="flex justify-between gap-2 border-b border-black/30 pb-1">
                        <span class="font-bold">NIT:</span>
                        <span class="font-bold tabular-nums">{{ calculo.emisor.nit || '-' }}</span>
                    </div>
                    <div class="flex justify-between gap-2 border-b border-black/30 pb-1">
                        <span class="font-bold">FACTURA N°:</span>
                        <span class="font-bold tabular-nums">{{ form.numero_factura || '-' }}</span>
                    </div>
                    <div class="pt-0.5">
                        <span class="block font-bold">COD. AUTORIZACIÓN:</span>
                        <span class="block font-mono text-[9px] leading-tight break-all text-black">
                            {{ form.codigo_autorizacion || '-' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Título FACTURA -->
            <div class="py-3 text-center">
                <h2 class="text-[16px] font-bold tracking-wide text-black">
                    FACTURA
                </h2>
                <p class="text-[11px] text-black">
                    (Con Derecho a Crédito Fiscal)
                </p>
            </div>

            <!-- Datos del cliente -->
            <div class="grid grid-cols-12 border border-black text-[10.5px] mb-3">
                <div class="col-span-6 border-r border-b border-black px-2.5 py-1.5">
                    <span class="font-bold">Fecha: </span>
                    <span>{{ fechaFormateada }}</span>
                </div>
                <div class="col-span-6 border-b border-black px-2.5 py-1.5">
                    <span class="font-bold">NIT/CI/CEX: </span>
                    <span class="tabular-nums">{{ calculo.cliente.nit || '-' }}</span>
                </div>
                <div class="col-span-8 border-r border-black px-2.5 py-1.5">
                    <span class="font-bold">Nombre/Razón Social: </span>
                    <span>{{ calculo.cliente.nombre || '-' }}</span>
                </div>
                <div class="col-span-4 px-2.5 py-1.5">
                    <span class="font-bold">Cód. Cliente: </span>
                    <span class="tabular-nums">{{ calculo.cliente.codCliente || '-' }}</span>
                </div>
            </div>

            <!-- Tabla de ítems (7 columnas) -->
            <table class="w-full border-collapse border border-black text-[10px]">
                <thead>
                    <tr class="bg-[#002060] text-white font-bold text-center">
                        <th class="border border-black px-1.5 py-1.5 w-[13%]">CÓDIGO PRODUCTO / SERVICIO</th>
                        <th class="border border-black px-1.5 py-1.5 w-[9%]">CANTIDAD</th>
                        <th class="border border-black px-1.5 py-1.5 w-[15%]">UNIDAD DE MEDIDA</th>
                        <th class="border border-black px-2 py-1.5 w-[27%]">DESCRIPCIÓN</th>
                        <th class="border border-black px-1.5 py-1.5 w-[12%]">PRECIO UNITARIO</th>
                        <th class="border border-black px-1.5 py-1.5 w-[11%]">DESCUENTO</th>
                        <th class="border border-black px-1.5 py-1.5 w-[13%]">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="calculo.itemsActivos.length === 0">
                        <td colspan="7" class="border border-black px-3 py-4 text-center text-slate-500">
                            Agrega al menos un ítem con descripción para visualizar el detalle.
                        </td>
                    </tr>
                    <tr v-for="item in calculo.itemsActivos" :key="item.numero">
                        <td class="border border-black px-1.5 py-1.5 text-center font-semibold tabular-nums">
                            {{ item.codigo }}
                        </td>
                        <td class="border border-black px-1.5 py-1.5 text-right tabular-nums">
                            {{ formatBs(item.cantidad) }}
                        </td>
                        <td class="border border-black px-1.5 py-1.5 text-center">
                            {{ item.unidad }}
                        </td>
                        <td class="border border-black px-2 py-1.5">
                            {{ item.descripcion }}
                        </td>
                        <td class="border border-black px-1.5 py-1.5 text-right tabular-nums">
                            {{ formatBs(item.precioUnitario) }}
                        </td>
                        <td class="border border-black px-1.5 py-1.5 text-right tabular-nums">
                            {{ formatBs(item.descuento) }}
                        </td>
                        <td class="border border-black px-1.5 py-1.5 text-right font-semibold tabular-nums">
                            {{ formatBs(item.subtotal) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Totales y detalle de pago -->
            <div class="grid grid-cols-12 border-x border-b border-black text-[10.5px]">
                <div class="col-span-7 border-r border-black p-2.5 flex flex-col justify-between gap-2">
                    <div class="space-y-1">
                        <p class="font-bold">
                            Son: <span class="font-normal italic">{{ calculo.montoEnLetras }}</span>
                        </p>
                    </div>

                    <div v-if="form.tipo_pago || calculo.montoPagado > 0" class="border-t border-black/20 pt-1.5 text-[10px] space-y-0.5">
                        <p v-if="form.tipo_pago">
                            <span class="font-bold">Tipo de pago:</span> {{ form.tipo_pago }}
                            <span v-if="form.referencia_pago">
                                · <span class="font-bold">{{ etiquetaReferencia }}:</span> {{ form.referencia_pago }}
                            </span>
                        </p>
                        <p v-if="calculo.montoPagado > 0">
                            <span class="font-bold">{{ form.modo === 'COMPRANDO' ? 'Monto pagado:' : 'Monto recibido:' }}</span>
                            {{ formatBs(calculo.montoPagado) }} Bs
                        </p>
                    </div>
                </div>

                <div class="col-span-5">
                    <table class="w-full border-collapse text-[10px]">
                        <tbody>
                            <tr class="border-b border-black/30">
                                <td class="px-2 py-1 text-right font-semibold">SUBTOTAL Bs</td>
                                <td class="px-2 py-1 text-right tabular-nums w-24">{{ formatBs(calculo.subtotal) }}</td>
                            </tr>
                            <tr class="border-b border-black/30">
                                <td class="px-2 py-1 text-right font-semibold">DESCUENTO Bs</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ formatBs(calculo.descuentoGlobal) }}</td>
                            </tr>
                            <tr class="border-b border-black/30">
                                <td class="px-2 py-1 text-right font-semibold">TOTAL Bs</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ formatBs(calculo.total) }}</td>
                            </tr>
                            <tr class="border-b border-black/30">
                                <td class="px-2 py-1 text-right font-semibold">MONTO GIFT CARD Bs</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ formatBs(calculo.giftCard) }}</td>
                            </tr>
                            <tr class="border-b border-black bg-[#E7E6E6] font-bold">
                                <td class="px-2 py-1.5 text-right">MONTO A PAGAR Bs</td>
                                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatBs(calculo.montoAPagar) }}</td>
                            </tr>
                            <tr>
                                <td class="px-2 py-1 text-right font-semibold">IMPORTE BASE CRÉDITO FISCAL Bs</td>
                                <td class="px-2 py-1 text-right tabular-nums font-bold">{{ formatBs(calculo.importeBaseCreditoFiscal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pie legal + espacio QR -->
            <div class="mt-3 flex items-center justify-between gap-4">
                <div class="flex-1 text-center space-y-1">
                    <p class="text-[7.5px] leading-tight text-black">
                        "ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY."
                    </p>
                    <p class="text-[7.5px] leading-tight text-black">
                        Ley N° 453: El proveedor debe brindar atención sin discriminación, con respeto, calidez y cordialidad a los usuarios y consumidores.
                    </p>
                    <p class="no-print text-[9px] text-slate-500 pt-1">
                        Plantilla de formato de práctica · No genera facturas válidas ante el SIN.
                    </p>
                </div>

                <div class="h-16 w-16 shrink-0 border border-black flex items-center justify-center text-[9px] font-bold text-slate-500">
                    QR
                </div>
            </div>
        </div>
    </div>
</template>
