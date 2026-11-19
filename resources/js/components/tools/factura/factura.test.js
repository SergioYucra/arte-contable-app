import { describe, expect, it } from 'vitest';
import { calcularFactura, createEmptyFacturaState } from './composables/useFactura.js';
import { numeroALetras } from './utils/numeroALetras.js';

describe('numeroALetras', () => {
    it('convierte 1250.50 a "Mil doscientos cincuenta 50/100 Bolivianos"', () => {
        expect(numeroALetras(1250.5)).toBe('Mil doscientos cincuenta 50/100 Bolivianos');
    });

    it('convierte 0 a "Cero 00/100 Bolivianos"', () => {
        expect(numeroALetras(0)).toBe('Cero 00/100 Bolivianos');
    });

    it('convierte 100.05 y 1000000 correctamente', () => {
        expect(numeroALetras(100.05)).toBe('Cien 05/100 Bolivianos');
        expect(numeroALetras(1000000)).toBe('Un millón 00/100 Bolivianos');
    });
});

describe('useFactura - calcularFactura', () => {
    it('calcula subtotales, descuento global, gift card y monto a pagar sin NaN', () => {
        const state = createEmptyFacturaState();
        state.modo = 'VENDIENDO';
        state.tipo_item = 'PRODUCTO';
        state.empresa_nombre = 'Mi Empresa SRL';
        state.empresa_nit = '123456789';
        state.empresa_direccion = 'Av. Principal 100';
        state.contraparte_nombre = 'Cliente SA';
        state.contraparte_nit = '987654321';
        state.descuento_global = 50;
        state.gift_card = 100;
        state.items[0] = {
            descripcion: 'Laptop Pro',
            cantidad: 2,
            precio_unitario: 1000,
            descuento: 100,
        };
        state.items[1] = {
            descripcion: '',
            cantidad: '',
            precio_unitario: '',
            descuento: 0,
        };

        const res = calcularFactura(state);

        expect(res.errores).toEqual([]);
        expect(res.itemsActivos).toHaveLength(1);
        expect(res.itemsActivos[0].codigo).toBe('PV-001');
        expect(res.itemsActivos[0].unidad).toBe('Unidad (Bienes)');
        expect(res.itemsActivos[0].subtotal).toBe(1900);
        expect(res.subtotal).toBe(1900);
        expect(res.total).toBe(1850);
        expect(res.montoAPagar).toBe(1750);
        expect(res.importeBaseCreditoFiscal).toBe(1750);
        expect(res.montoEnLetras).toBe('Mil setecientos cincuenta 00/100 Bolivianos');
    });

    it('invierte emisor y cliente cuando modo = COMPRANDO', () => {
        const state = createEmptyFacturaState();
        state.modo = 'COMPRANDO';
        state.tipo_item = 'SERVICIO';
        state.empresa_nombre = 'Mi Empresa';
        state.empresa_nit = '111222';
        state.contraparte_nombre = 'Proveedor SRL';
        state.contraparte_nit = '333444';
        state.contraparte_direccion = 'Calle Comercio 50';
        state.items[0] = {
            descripcion: 'Consultoría contable',
            cantidad: 1,
            precio_unitario: 500,
            descuento: 0,
        };

        const res = calcularFactura(state);

        expect(res.emisor).toEqual({
            nombre: 'Proveedor SRL',
            nit: '333444',
            direccion: 'Calle Comercio 50',
        });
        expect(res.cliente).toEqual({
            nombre: 'Mi Empresa',
            nit: '111222',
            codCliente: '111222',
        });
        expect(res.itemsActivos[0].codigo).toBe('SV-001');
        expect(res.itemsActivos[0].unidad).toBe('Unidad (Servicios)');
    });

    it('valida descuento mayor al importe bruto', () => {
        const state = createEmptyFacturaState();
        state.items[0] = {
            descripcion: 'Servicio',
            cantidad: 1,
            precio_unitario: 100,
            descuento: 150,
        };

        const res = calcularFactura(state);
        expect(res.errores.length).toBeGreaterThan(0);
    });
});
