import { describe, expect, it } from 'vitest';
import { numeroALetras } from './numeroALetras.js';
import {
    derivarDatosRecibo,
    descomponerFecha,
    formatImporteRecibo,
} from './reciboHelpers.js';

describe('numeroALetras - Recibo (Title Case sin "Bolivianos")', () => {
    const casos = [
        [1, 'Un 00/100'],
        [21, 'Veintiuno 00/100'],
        [100, 'Cien 00/100'],
        [101, 'Ciento Uno 00/100'],
        [1000, 'Mil 00/100'],
        [1001, 'Mil Uno 00/100'],
        [21000, 'Veintiún Mil 00/100'],
        [5000, 'Cinco Mil 00/100'],
        [1250.5, 'Mil Doscientos Cincuenta 50/100'],
        [1000000, 'Un Millón 00/100'],
        [2000000, 'Dos Millones 00/100'],
        [
            999999999.99,
            'Novecientos Noventa Y Nueve Millones Novecientos Noventa Y Nueve Mil Novecientos Noventa Y Nueve 99/100',
        ],
        [0.5, 'Cero 50/100'],
    ];

    it.each(casos)('convierte %s -> "%s"', (monto, esperado) => {
        expect(numeroALetras(monto)).toBe(esperado);
    });
});

describe('reciboHelpers - derivación de tipo, fecha y formato', () => {
    it('deriva etiquetas y firmas correctamente para EGRESO', () => {
        const res = derivarDatosRecibo('EGRESO');
        expect(res.titulo).toBe('RECIBO DE EGRESO');
        expect(res.primeraLineaLabel).toBe('Entregue A:');
        expect(res.firmaIzquierdaLeyenda).toBe('Recibí conforme');
        expect(res.firmaDerechaLeyenda).toBe('Entregué conforme');
    });

    it('intercambia leyendas de firma y primera línea para INGRESO', () => {
        const res = derivarDatosRecibo('INGRESO');
        expect(res.titulo).toBe('RECIBO DE INGRESO');
        expect(res.primeraLineaLabel).toBe('Recibi de:');
        expect(res.firmaIzquierdaLeyenda).toBe('Entregué conforme');
        expect(res.firmaDerechaLeyenda).toBe('Recibí conforme');
    });

    it('descompone fecha YYYY-MM-DD con ceros a la izquierda sin desfase UTC', () => {
        expect(descomponerFecha('2026-03-05')).toEqual({
            dia: '05',
            mes: '03',
            anio: '2026',
        });
        expect(descomponerFecha('2026-11-16')).toEqual({
            dia: '16',
            mes: '11',
            anio: '2026',
        });
    });

    it('formatea el importe 5000 como "5,000.00"', () => {
        expect(formatImporteRecibo(5000)).toBe('5,000.00');
        expect(formatImporteRecibo(1250.5)).toBe('1,250.50');
    });
});
