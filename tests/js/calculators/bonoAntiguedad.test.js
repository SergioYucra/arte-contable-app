// Fórmula del bono de antigüedad contra resultados reales de boliviaimpuestos.com
// (tests/js/fixtures/bono-antiguedad-casos.json). Correr con: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { addYears, aniosCumplidos, calcularBono, parseIsoDate } from '../../../resources/js/components/calculators/bonoAntiguedad.js';
import { bs as money } from '../../../resources/js/components/calculators/format.js';

// Su página separa "Bs" del número con un espacio común; la nuestra, con uno que no se parte.
const bs = (...args) => money(...args).replace(/\u00A0/g, ' ');

const read = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
const params = read('../../../resources/js/components/calculators/parametros.json');
const { casos } = read('../fixtures/bono-antiguedad-casos.json');

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
/** Así escribe las fechas su página: "01 de marzo de 2027". */
const theirDate = (iso) => `${iso.slice(8, 10)} de ${MONTHS[Number(iso.slice(5, 7)) - 1]} de ${iso.slice(0, 4)}`;

function entradas(c) {
    const e = c.entradas;
    const multiplicador = e.base === 'otra' ? e.multiplicador : Number(e.base);
    return { ...e, multiplicador };
}

for (const caso of casos) {
    test(`Bono igual a boliviaimpuestos: ${caso.nombre}`, () => {
        const r = calcularBono(entradas(caso), params.bonoAntiguedad);
        const exp = caso.esperado;
        const pantalla = caso.pantalla;

        assert.equal(r.anios, exp.anios, 'años cumplidos');
        assert.equal(r.porcentaje, exp.porcentaje, 'porcentaje');
        assert.equal(bs(r.bono), pantalla.bono, 'bono del mes');
        assert.equal(r.baseBs, exp.baseBs, 'base');

        if (exp.bonoAnual !== null) assert.equal(bs(r.bonoAnual), bs(exp.bonoAnual), 'bono anual');
        if (exp.totalGanado !== null) assert.equal(bs(r.totalGanado), bs(exp.totalGanado), 'total ganado');
        else assert.equal(r.totalGanado, null);

        // Tramo actual resaltado en su tabla.
        if (r.tramo) {
            const label = r.tramo.hasta === null ? `${r.tramo.desde} años o más` : `${r.tramo.desde} a ${r.tramo.hasta} años`;
            assert.equal(pantalla.tramoActual, label, 'tramo actual');
        } else {
            assert.equal(pantalla.tramoActual, null);
        }

        // Próximo tramo: cuánto sube, cuándo y cuánto avanzó.
        if (exp.proximaDiferencia === null) {
            assert.equal(r.proximo, null, 'sin tramo siguiente');
        } else {
            assert.equal(`+${bs(r.proximo.diferencia)}`, pantalla.proximoValor, 'diferencia con el tramo siguiente');
            if (r.proximo.fecha) assert.ok(pantalla.proximoTexto.includes(theirDate(r.proximo.fecha)), `fecha del próximo tramo (${r.proximo.fecha})`);
            if (exp.avancePorcentaje === null) assert.equal(r.proximo.avance, null);
            // Su página escribe avance.toFixed(1) en un style.width; el navegador lo devuelve como "0%" o "33.3%".
            else assert.equal(Number(r.proximo.avance.toFixed(1)), parseFloat(pantalla.avance), 'avance hacia el próximo tramo');
        }

        if (r.primerBono) assert.ok(pantalla.resumen.includes(theirDate(r.primerBono)), 'fecha del primer bono');

        // Reliquidación.
        assert.equal(bs(r.reliquidacion.bonoAnterior), bs(exp.bonoSmnAnterior), 'bono con SMN anterior');
        assert.equal(bs(r.reliquidacion.diferenciaMensual), bs(exp.diferenciaMensual), 'diferencia mensual');
        assert.equal(bs(r.reliquidacion.retroactivo), bs(exp.retroactivo), 'retroactivo');
    });
}

test('fechas: 29 de febrero más un año cae en 1 de marzo', () => {
    assert.equal(addYears('2024-02-29', 1), '2025-03-01');
    assert.equal(addYears('2024-02-29', 4), '2028-02-29');
});

test('fechas: años completos, el aniversario cuenta desde ese mismo día', () => {
    assert.equal(aniosCumplidos('2020-11-17', '2026-11-16'), 5);
    assert.equal(aniosCumplidos('2020-11-17', '2026-11-17'), 6);
    assert.equal(aniosCumplidos('2027-01-01', '2026-11-17'), 0);
});

test('fechas: se rechazan fechas que no existen', () => {
    assert.equal(parseIsoDate('2026-02-30'), null);
    assert.equal(parseIsoDate('17/11/2026'), null);
    assert.notEqual(parseIsoDate('2024-02-29'), null);
});

test('días trabajados fuera de rango se acotan a 0..30', () => {
    const base = { modo: 'anios', anios: 6, smn: 3300, multiplicador: 3, smnAnterior: 2750, meses: 1 };
    assert.equal(calcularBono({ ...base, dias: 45 }, params.bonoAntiguedad).bono, 1089);
    assert.equal(calcularBono({ ...base, dias: -3 }, params.bonoAntiguedad).bono, 0);
});
