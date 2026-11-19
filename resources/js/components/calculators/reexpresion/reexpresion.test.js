import { describe, expect, it } from 'vitest';
import { calcularReexpresion, CUENTA_REEXPRESION } from './useReexpresion.js';

describe('useReexpresion - Reexpresión de Moneda Extranjera (CAJA M/E)', () => {
    it('Método 1: $1.000, ajuste 6, adquisición 7 -> -1.000,00 PERDIDA (DIFERENCIA DE CAMBIO al Debe, CAJA M/E al Haber)', () => {
        const res = calcularReexpresion({
            metodo: 1,
            montoME: 1000,
            tcFinal: 6,
            tcInicial: 7,
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.sinDiferencia).toBe(false);
        expect(res.valorAjuste).toBe(6000);
        expect(res.valorAdquisicion).toBe(7000);
        expect(res.diferencia).toBe(-1000);
        expect(res.resultado).toBe('PERDIDA');
        expect(res.asiento.lineas).toEqual([
            { detalle: 'DIFERENCIA DE CAMBIO', debe: 1000, haber: null },
            { detalle: CUENTA_REEXPRESION, debe: null, haber: 1000 },
        ]);
        expect(res.asiento.totalDebe).toBe(1000);
        expect(res.asiento.totalHaber).toBe(1000);
        expect(res.asiento.glosa).toBe('POR ACTUALIZACION DE LA M/E');
    });

    it('Método 2: $1.000, final 8, inicial 7 -> +1.000,00 INGRESO (CAJA M/E al Debe, DIFERENCIA DE CAMBIO al Haber)', () => {
        const res = calcularReexpresion({
            metodo: 2,
            montoME: 1000,
            tcFinal: 8,
            tcInicial: 7,
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.diferencia).toBe(1000);
        expect(res.resultado).toBe('INGRESO');
        expect(res.asiento.lineas).toEqual([
            { detalle: CUENTA_REEXPRESION, debe: 1000, haber: null },
            { detalle: 'DIFERENCIA DE CAMBIO', debe: null, haber: 1000 },
        ]);
    });

    it('Caso realista: $1.000, inicial 6.86, final 6.96 -> +100,00 INGRESO', () => {
        const res = calcularReexpresion({
            metodo: 1,
            montoME: 1000,
            tcFinal: 6.96,
            tcInicial: 6.86,
            fechaAjuste: '2025-12-31',
        });

        expect(res.diferencia).toBe(100);
        expect(res.resultado).toBe('INGRESO');
        expect(res.asiento.totalDebe).toBe(100);
        expect(res.asiento.totalHaber).toBe(100);
    });

    it('Diferencia 0 -> sinDiferencia true y sin asiento', () => {
        const res = calcularReexpresion({
            metodo: 1,
            montoME: 1000,
            tcFinal: 6.96,
            tcInicial: 6.96,
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.sinDiferencia).toBe(true);
        expect(res.diferencia).toBe(0);
        expect(res.asiento).toBeNull();
    });

    it('Equivalencia entre Método 1 y Método 2 para múltiples valores', () => {
        const casos = [
            { montoME: 1000, tcFinal: 6.96, tcInicial: 6.86 },
            { montoME: 2500.5, tcFinal: 7.12, tcInicial: 6.96 },
            { montoME: 500, tcFinal: 6.5, tcInicial: 7.0 },
        ];

        for (const c of casos) {
            const m1 = calcularReexpresion({ metodo: 1, ...c, fechaAjuste: '2025-12-31' });
            const m2 = calcularReexpresion({ metodo: 2, ...c, fechaAjuste: '2025-12-31' });
            expect(m1.diferencia).toBe(m2.diferencia);
            expect(m1.resultado).toBe(m2.resultado);
            expect(m1.asiento.totalDebe).toBe(m2.asiento.totalDebe);
        }
    });

    it('Redondeo con decimales ($333,33 × T/C 6,96 y 6,86) cuadra a 2 decimales', () => {
        const res = calcularReexpresion({
            metodo: 1,
            montoME: 333.33,
            tcFinal: 6.96,
            tcInicial: 6.86,
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.asiento.totalDebe).toBe(res.asiento.totalHaber);
        expect(res.asiento.cuadrado).toBe(true);
    });

    it('Entradas inválidas (monto 0, T/C negativo) no calculan', () => {
        const res1 = calcularReexpresion({
            metodo: 1,
            montoME: 0,
            tcFinal: 6.96,
            tcInicial: 6.86,
            fechaAjuste: '2025-12-31',
        });
        expect(res1.calculable).toBe(false);
        expect(res1.asiento).toBeNull();

        const res2 = calcularReexpresion({
            metodo: 2,
            montoME: 1000,
            tcFinal: -1,
            tcInicial: 6.86,
            fechaAjuste: '2025-12-31',
        });
        expect(res2.calculable).toBe(false);
        expect(res2.asiento).toBeNull();
    });
});
