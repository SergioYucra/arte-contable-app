import { computed, reactive, watch } from 'vue';
import { formatBs, round2 } from '../../../calculators/shared/formatters.js';
import { numeroALetras } from '../utils/numeroALetras.js';

export const FACTURA_STORAGE_KEY = 'arte_contable_macro_facturador_v1';

export function createEmptyItem() {
    return { descripcion: '', cantidad: '', precio_unitario: '', descuento: 0 };
}

export function createEmptyFacturaState() {
    return {
        modo: 'VENDIENDO', // 'COMPRANDO' | 'VENDIENDO'
        empresa_nombre: '',
        empresa_nit: '',
        empresa_direccion: '',
        tipo_item: 'PRODUCTO', // 'PRODUCTO' | 'SERVICIO'
        contraparte_nombre: '',
        contraparte_nit: '',
        contraparte_direccion: '',
        numero_factura: '',
        fecha: '',
        codigo_autorizacion: '',
        tipo_pago: '', // '' | 'EFECTIVO' | 'CHEQUE' | 'TRANSFERENCIA'
        referencia_pago: '',
        monto_pagado: '',
        descuento_global: 0,
        gift_card: 0,
        items: [createEmptyItem(), createEmptyItem()],
    };
}

function toNumber(val) {
    if (val === '' || val === null || val === undefined) return 0;
    const n = Number(val);
    return Number.isFinite(n) ? n : 0;
}

/**
 * Calcula todos los valores derivados de la factura de forma pura (sin DOM).
 */
export function calcularFactura(state) {
    const esComprando = state.modo === 'COMPRANDO';
    const esServicio = state.tipo_item === 'SERVICIO';

    const emisor = esComprando
        ? {
              nombre: state.contraparte_nombre || '',
              nit: state.contraparte_nit || '',
              direccion: state.contraparte_direccion || '',
          }
        : {
              nombre: state.empresa_nombre || '',
              nit: state.empresa_nit || '',
              direccion: state.empresa_direccion || '',
          };

    const cliente = esComprando
        ? {
              nombre: state.empresa_nombre || '',
              nit: state.empresa_nit || '',
              codCliente: state.empresa_nit || '',
          }
        : {
              nombre: state.contraparte_nombre || '',
              nit: state.contraparte_nit || '',
              codCliente: state.contraparte_nit || '',
          };

    const prefijoCodigo = esServicio ? 'SV' : 'PV';
    const unidadMedida = esServicio ? 'Unidad (Servicios)' : 'Unidad (Bienes)';

    const errores = [];

    if (state.empresa_nit && !/^\d+$/.test(String(state.empresa_nit).trim())) {
        errores.push('El NIT de tu empresa debe ser numérico.');
    }
    if (state.contraparte_nit && !/^\d+$/.test(String(state.contraparte_nit).trim())) {
        errores.push(
            esComprando
                ? 'El NIT del proveedor debe ser numérico.'
                : 'El NIT del cliente debe ser numérico.'
        );
    }

    const itemsActivos = [];
    (state.items || []).forEach((item, index) => {
        const desc = String(item?.descripcion || '').trim();
        if (!desc) return;

        const cantidad = toNumber(item.cantidad);
        const precio = toNumber(item.precio_unitario);
        const descuento = toNumber(item.descuento);
        const bruto = round2(cantidad * precio);

        if (cantidad <= 0) {
            errores.push(`Ítem ${index + 1}: la cantidad debe ser mayor a 0.`);
        }
        if (precio < 0) {
            errores.push(`Ítem ${index + 1}: el precio unitario no puede ser negativo.`);
        }
        if (descuento < 0) {
            errores.push(`Ítem ${index + 1}: el descuento no puede ser negativo.`);
        }
        if (descuento > bruto) {
            errores.push(`Ítem ${index + 1}: el descuento no puede superar el importe (cantidad × precio).`);
        }

        const subtotal = round2(Math.max(0, bruto - descuento));

        itemsActivos.push({
            numero: index + 1,
            codigo: `${prefijoCodigo}-${String(index + 1).padStart(3, '0')}`,
            cantidad,
            unidad: unidadMedida,
            descripcion: desc,
            precioUnitario: round2(precio),
            descuento: round2(descuento),
            subtotal,
        });
    });

    const subtotal = round2(itemsActivos.reduce((acc, it) => acc + it.subtotal, 0));
    const descuentoGlobal = round2(Math.max(0, toNumber(state.descuento_global)));
    const total = round2(Math.max(0, subtotal - descuentoGlobal));
    const giftCard = round2(Math.max(0, toNumber(state.gift_card)));
    const montoAPagar = round2(Math.max(0, total - giftCard));
    const importeBaseCreditoFiscal = montoAPagar;
    const montoPagado = round2(Math.max(0, toNumber(state.monto_pagado)));

    return {
        emisor,
        cliente,
        itemsActivos,
        subtotal,
        descuentoGlobal,
        total,
        giftCard,
        montoAPagar,
        importeBaseCreditoFiscal,
        montoPagado,
        montoEnLetras: numeroALetras(montoAPagar),
        errores,
    };
}

function loadSavedState() {
    const base = createEmptyFacturaState();
    try {
        const raw = globalThis.localStorage?.getItem(FACTURA_STORAGE_KEY);
        if (!raw) return base;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return base;

        return {
            ...base,
            ...parsed,
            items: Array.isArray(parsed.items) && parsed.items.length > 0
                ? parsed.items.map((it) => ({
                      descripcion: it?.descripcion ?? '',
                      cantidad: it?.cantidad ?? '',
                      precio_unitario: it?.precio_unitario ?? '',
                      descuento: it?.descuento ?? 0,
                  }))
                : base.items,
        };
    } catch {
        return base;
    }
}

export function useFactura() {
    const form = reactive(loadSavedState());

    watch(
        form,
        (val) => {
            try {
                globalThis.localStorage?.setItem(FACTURA_STORAGE_KEY, JSON.stringify(val));
            } catch {
                // Ignorar errores de cuota o modo privado
            }
        },
        { deep: true }
    );

    const calculo = computed(() => calcularFactura(form));

    function limpiar() {
        const empty = createEmptyFacturaState();
        Object.assign(form, empty);
        try {
            globalThis.localStorage?.removeItem(FACTURA_STORAGE_KEY);
        } catch {
            // Ignorar
        }
    }

    function agregarItem() {
        form.items.push(createEmptyItem());
    }

    function eliminarItem(index) {
        if (form.items.length <= 1) return;
        form.items.splice(index, 1);
    }

    return {
        form,
        calculo,
        limpiar,
        agregarItem,
        eliminarItem,
        formatBs,
    };
}
