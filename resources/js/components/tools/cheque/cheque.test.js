import { describe, expect, it } from 'vitest';
import { numeroALetras } from '../recibo/numeroALetras.js';
import {
    capitalPorDepartamento,
    formatImporteCheque,
    formatearFechaLargaEs,
    incrementarNumeroCheque,
} from './chequeHelpers.js';

describe('numeroALetras - opción upper para Cheque', () => {
    const casosUpper = [
        [1, 'UN 00/100'],
        [21, 'VEINTIUNO 00/100'],
        [100, 'CIEN 00/100'],
        [101, 'CIENTO UNO 00/100'],
        [1000, 'MIL 00/100'],
        [1001, 'MIL UNO 00/100'],
        [21000, 'VEINTIÚN MIL 00/100'],
        [5000, 'CINCO MIL 00/100'],
        [1250.5, 'MIL DOSCIENTOS CINCUENTA 50/100'],
        [1000000, 'UN MILLÓN 00/100'],
        [2000000, 'DOS MILLONES 00/100'],
        [0.5, 'CERO 50/100'],
        [
            999999999.99,
            'NOVECIENTOS NOVENTA Y NUEVE MILLONES NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE 99/100',
        ],
    ];

    it.each(casosUpper)('convierte %s en upper -> "%s"', (monto, esperado) => {
        expect(numeroALetras(monto, { caso: 'upper' })).toBe(esperado);
    });

    it('mantiene el modo title por defecto', () => {
        expect(numeroALetras(5000)).toBe('Cinco Mil 00/100');
        expect(numeroALetras(5000, { caso: 'title' })).toBe('Cinco Mil 00/100');
    });
});

describe('chequeHelpers', () => {
    it('formatea fecha larga en español sin desfase de zona horaria (16/11/2026 es lunes)', () => {
        const res = formatearFechaLargaEs('2026-11-16');
        expect(res.toLowerCase()).toContain('lunes');
        expect(res.toLowerCase()).toContain('16');
        expect(res.toLowerCase()).toContain('noviembre');
        expect(res).toContain('2026');
    });

    it('autocompleta la capital correspondiente por departamento', () => {
        expect(capitalPorDepartamento('LA PAZ')).toBe('La Paz');
        expect(capitalPorDepartamento('ORURO')).toBe('Oruro');
        expect(capitalPorDepartamento('CHUQUISACA')).toBe('Sucre');
        expect(capitalPorDepartamento('BENI')).toBe('Trinidad');
        expect(capitalPorDepartamento('PANDO')).toBe('Cobija');
    });

    it('incrementa número de cheque (+1) preservando ceros a la izquierda', () => {
        expect(incrementarNumeroCheque('0099')).toBe('0100');
        expect(incrementarNumeroCheque('1234')).toBe('1235');
        expect(incrementarNumeroCheque('')).toBe('1');
    });

    it('formatea el importe 5000 como "5,000.00"', () => {
        expect(formatImporteCheque(5000)).toBe('5,000.00');
    });
});
