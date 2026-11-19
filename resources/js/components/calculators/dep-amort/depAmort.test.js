import { describe, expect, it } from 'vitest';
import {
    calcularAmortizacionIntangible,
    calcularDepreciacionFijo,
    round2,
} from './useDepAmort.js';

describe('useDepAmort - Depreciación de Activos Fijos', () => {
    it('cumple el test de aceptación para Edificaciones (70.856,70 / 12 meses)', () => {
        const res = calcularDepreciacionFijo({
            nombre: 'Edificaciones',
            valorLibros: 70856.7,
            meses: 12,
            fechaCalculo: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.esNoDepreciable).toBe(false);
        expect(res.errors).toEqual([]);
        expect(res.anualRaw).toBeCloseTo(1771.4175, 4);
        expect(round2(res.anualRaw)).toBe(1771.42);
        expect(res.periodo).toBe(1771.42);

        expect(res.asiento.fecha).toBe('2025-12-31');
        expect(res.asiento.cuadrado).toBe(true);
        expect(res.asiento.totalDebe).toBe(1771.42);
        expect(res.asiento.totalHaber).toBe(1771.42);
        expect(res.asiento.lineas).toEqual([
            { detalle: 'Depreciacion Edificaciones', debe: 1771.42, haber: null },
            { detalle: 'Depreciacion Acumulada Edificaciones', debe: null, haber: 1771.42 },
        ]);
        expect(res.asiento.glosa).toBe('POR LA DEPRECIACION DE LOS ACTIVOS FIJOS');
    });

    it('calcula Vehículos automotores 100.000 a 6 meses correctamente', () => {
        const res = calcularDepreciacionFijo({
            nombre: 'Vehículos automotores',
            valorLibros: 100000,
            meses: 6,
            fechaCalculo: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.anualRaw).toBe(20000);
        expect(res.periodo).toBe(10000);
        expect(res.asiento.totalDebe).toBe(10000);
        expect(res.asiento.totalHaber).toBe(10000);
    });

    it('maneja Terrenos (vidaUtil: null) con depreciación 0 y sin asiento', () => {
        const res = calcularDepreciacionFijo({
            nombre: 'Terrenos',
            valorLibros: 50000,
            meses: 12,
            fechaCalculo: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.esNoDepreciable).toBe(true);
        expect(res.aviso).toBe('Los terrenos no se deprecian.');
        expect(res.periodo).toBe(0);
        expect(res.asiento).toBeNull();
        expect(res.errors).toEqual([]);
    });

    it('rechaza meses inválidos (0, 13, decimal) sin calcular', () => {
        for (const meses of [0, 13, 6.5]) {
            const res = calcularDepreciacionFijo({
                nombre: 'Edificaciones',
                valorLibros: 10000,
                meses,
                fechaCalculo: '2025-12-31',
            });

            expect(res.calculable).toBe(false);
            expect(res.asiento).toBeNull();
            expect(res.errors.length).toBeGreaterThan(0);
        }
    });

    it('rechaza valor en libros 0 o vacío', () => {
        for (const valorLibros of [0, '', null]) {
            const res = calcularDepreciacionFijo({
                nombre: 'Edificaciones',
                valorLibros,
                meses: 12,
                fechaCalculo: '2025-12-31',
            });

            expect(res.calculable).toBe(false);
            expect(res.asiento).toBeNull();
        }
    });
});

describe('useDepAmort - Amortización de Activos Intangibles', () => {
    it('cumple el test de aceptación para Gastos de Organización (13.000 / 12 meses)', () => {
        const res = calcularAmortizacionIntangible({
            nombre: 'Gastos de Organización',
            valorLibros: 13000,
            meses: 12,
            fechaCalculo: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.anualRaw).toBe(3250);
        expect(res.periodo).toBe(3250);
        expect(res.asiento.totalDebe).toBe(3250);
        expect(res.asiento.totalHaber).toBe(3250);
        expect(res.asiento.lineas).toEqual([
            { detalle: 'Amortizacion Gastos de Organización', debe: 3250, haber: null },
            { detalle: 'Amortizacion Acumulada Gastos de Organización', debe: null, haber: 3250 },
        ]);
        expect(res.asiento.glosa).toBe('POR LA AMORTIZACION DE LOS ACTIVOS INTANGIBLES');
    });

    it('mantiene Debe idéntico al Haber tras redondeo en montos con decimales', () => {
        const res = calcularAmortizacionIntangible({
            nombre: 'Patentes',
            valorLibros: 12345.67,
            meses: 7,
            fechaCalculo: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.asiento.totalDebe).toBe(res.asiento.totalHaber);
        expect(res.asiento.cuadrado).toBe(true);
    });
});
