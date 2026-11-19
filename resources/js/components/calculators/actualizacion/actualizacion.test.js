import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    calcularActivoConAcumulada,
    calcularRubroPatrimonial,
    formatearAsientoTSV,
} from './useActualizacion.js';
import { clearUfvCache, getUfv, UfvApiError } from './ufvService.js';

describe('useActualizacion - cálculo NC 3', () => {
    it('cumple el test de aceptación para Activo Fijo (Muebles y enseres 60.000 / Dep. 25.000)', () => {
        const res = calcularActivoConAcumulada({
            tipo: 'fijo',
            nombre: 'Muebles y enseres de oficina',
            valorLibros: 60000,
            acumulada: 25000,
            ufvInicial: 2.57896,
            ufvFinal: 3.04561,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.errors).toEqual([]);
        expect(res.reiNegativo).toBe(false);

        expect(res.filas[0].valorActualizado).toBe(70856.7);
        expect(res.filas[0].incremento).toBe(10856.7);
        expect(res.filas[1].valorActualizado).toBe(29523.63);
        expect(res.filas[1].incremento).toBe(4523.63);

        expect(res.asiento.fecha).toBe('2025-12-31');
        expect(res.asiento.cuadrado).toBe(true);
        expect(res.asiento.lineas).toEqual([
            { detalle: 'Muebles y enseres de oficina', debe: 10856.7, haber: null },
            { detalle: 'Resultados por Exposicion a la Inflacion', debe: null, haber: 6333.07 },
            { detalle: 'Depreciacion Acumulada Muebles y enseres de oficina', debe: null, haber: 4523.63 },
        ]);
        expect(res.asiento.totalDebe).toBe(10856.7);
        expect(res.asiento.totalHaber).toBe(10856.7);
        expect(res.asiento.glosa).toBe('POR ACTUALIZACION DEL ACTIVO FIJO S/G NC.3');
    });

    it('calcula Activo Intangible con glosa y cuenta de amortización acumulada', () => {
        const res = calcularActivoConAcumulada({
            tipo: 'intangible',
            nombre: 'Gastos de Organización',
            valorLibros: 15000,
            acumulada: 2600,
            ufvInicial: 2.57896,
            ufvFinal: 3.04561,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.asiento.cuadrado).toBe(true);
        expect(res.asiento.lineas[2].detalle).toBe('Amortizacion Acumulada Gastos de Organización');
        expect(res.asiento.glosa).toBe('POR ACTUALIZACION DEL ACTIVO INTANGIBLE');
    });

    it('calcula Rubro Patrimonial usando la cuenta de ajuste del catálogo y fecha de ajuste', () => {
        const res = calcularRubroPatrimonial({
            cuenta: 'Reserva Legal',
            valorLibros: 80000,
            ufvInicial: 2.57896,
            ufvFinal: 3.04561,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.asiento.fecha).toBe('2025-12-31');
        expect(res.asiento.cuadrado).toBe(true);
        expect(res.asiento.lineas[0].detalle).toBe('Resultados por Exposicion a la Inflacion');
        expect(res.asiento.lineas[1].detalle).toBe('Ajuste de Reservas Patrimoniales');
        expect(res.asiento.glosa).toBe('POR ACTUALIZACION DEL RUBRO PATRIMONIAL');
    });

    it('evita división por cero si UFV inicial es 0, vacío o null', () => {
        for (const ui of [0, '', null]) {
            const res = calcularActivoConAcumulada({
                tipo: 'fijo',
                nombre: 'Terrenos',
                valorLibros: 10000,
                acumulada: 0,
                ufvInicial: ui,
                ufvFinal: 3.04561,
                fechaInicial: '2025-01-01',
                fechaAjuste: '2025-12-31',
            });
            expect(res.calculable).toBe(false);
            expect(res.asiento).toBeNull();
            expect(res.errors.length).toBeGreaterThan(0);
        }
    });

    it('cuadra el asiento tras redondeo incluso con montos decimales complejos', () => {
        const res = calcularActivoConAcumulada({
            tipo: 'fijo',
            nombre: 'Vehículos automotores',
            valorLibros: 33333.33,
            acumulada: 11111.11,
            ufvInicial: 2.51234,
            ufvFinal: 2.98765,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.asiento.totalDebe).toBe(res.asiento.totalHaber);
        expect(res.asiento.cuadrado).toBe(true);
    });

    it('marca reiNegativo cuando la depreciación acumulada supera al valor en libros', () => {
        const res = calcularActivoConAcumulada({
            tipo: 'fijo',
            nombre: 'Equipos de computación',
            valorLibros: 10000,
            acumulada: 12000,
            ufvInicial: 2.5,
            ufvFinal: 3.0,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });

        expect(res.calculable).toBe(true);
        expect(res.reiNegativo).toBe(true);
        expect(res.warnings.length).toBeGreaterThan(0);
        expect(res.asiento.cuadrado).toBe(true);
    });

    it('genera TSV válido para copiar a Excel', () => {
        const res = calcularRubroPatrimonial({
            cuenta: 'Capital',
            valorLibros: 80000,
            ufvInicial: 2.5,
            ufvFinal: 3.0,
            fechaInicial: '2025-01-01',
            fechaAjuste: '2025-12-31',
        });
        const tsv = formatearAsientoTSV(res.asiento);
        expect(tsv).toContain('Detalle\tDebe\tHaber');
        expect(tsv).toContain('Ajuste de Capital');
    });
});

describe('ufvService - consulta API BCB', () => {
    beforeEach(() => {
        clearUfvCache();
        vi.restoreAllMocks();
    });

    it('devuelve 2.57896 redondeado a 5 decimales para la respuesta del proxy', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ fecha: '2025-01-01', ufv: 2.57896 }),
        });

        const ufv = await getUfv('2025-01-01', { fetchImpl: mockFetch });
        expect(ufv).toBe(2.57896);
        expect(mockFetch).toHaveBeenCalledTimes(1);
    });

    it('devuelve null si el proxy responde ufv: null', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ fecha: '2026-12-31', ufv: null }),
        });

        const ufv = await getUfv('2026-12-31', { fetchImpl: mockFetch });
        expect(ufv).toBeNull();
    });

    it('lanza UfvApiError en error de red y no devuelve null', async () => {
        const mockFetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

        await expect(getUfv('2025-05-01', { fetchImpl: mockFetch })).rejects.toThrow(UfvApiError);
    });

    it('usa caché en memoria para evitar dos peticiones con la misma fecha', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ fecha: '2025-12-31', ufv: 3.04561 }),
        });

        const first = await getUfv('2025-12-31', { fetchImpl: mockFetch });
        const second = await getUfv('2025-12-31', { fetchImpl: mockFetch });

        expect(first).toBe(3.04561);
        expect(second).toBe(3.04561);
        expect(mockFetch).toHaveBeenCalledTimes(1);
    });
});
