// Formatos y lectura de lo que escribe la persona. Correr con: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bs, bsCompact, longDate, parseAmount, parseWhole, percent } from '../../../resources/js/components/calculators/shared/formatters.js';
import { tiempoHasta } from '../../../resources/js/components/calculators/bono-antiguedad/bonoAntiguedad.js';

test('montos en bolivianos', () => {
    const nbsp = '\u00A0';
    assert.equal(bs(1906.5), `Bs${nbsp}1.906,50`);
    assert.equal(bs(9900, 0), `Bs${nbsp}9.900`);
    assert.equal(bs(Number.NaN), '—');
    assert.equal(bsCompact(100 * 0.13), `Bs${nbsp}13`);
    assert.equal(bsCompact(3000.5), `Bs${nbsp}3.000,50`);
});

test('porcentajes y fechas', () => {
    assert.equal(percent(0.1271), '12,71%');
    assert.equal(percent(0.13), '13%');
    assert.equal(percent(5, { isPercent: true }), '5%');
    assert.equal(longDate('2027-03-01'), '1 de marzo de 2027');
    assert.equal(longDate('17/11/2026'), '—');
});

test('montos escritos de varias formas', () => {
    assert.equal(parseAmount('15000'), 15000);
    assert.equal(parseAmount('15.000'), 15000);
    assert.equal(parseAmount('15.000,50'), 15000.5);
    assert.equal(parseAmount('15000.5'), 15000.5);
    assert.equal(parseAmount('Bs 2.500'), 2500);
    assert.equal(parseAmount('2,5'), 2.5);
});

test('se rechaza lo que no es un monto o pasa del máximo', () => {
    assert.equal(parseAmount(''), null);
    assert.equal(parseAmount('-100'), null);
    assert.equal(parseAmount('1e5'), null);
    assert.equal(parseAmount('abc'), null);
    assert.equal(parseAmount('10.000.000'), null);
    assert.equal(parseAmount('12,345'), null);
});

test('enteros dentro de un rango', () => {
    assert.equal(parseWhole('30', 0, 30), 30);
    assert.equal(parseWhole('31', 0, 30), null);
    assert.equal(parseWhole('2.5', 0, 30), null);
    assert.equal(parseWhole('', 0, 30), null);
});

test('cuánto falta, en palabras', () => {
    assert.equal(tiempoHasta('2026-11-17', '2026-11-17'), 'hoy');
    assert.equal(tiempoHasta('2026-11-17', '2026-11-18'), 'mañana');
    assert.equal(tiempoHasta('2026-11-17', '2026-12-17'), 'en 30 días');
    assert.equal(tiempoHasta('2026-11-17', '2027-01-10'), 'en 54 días');
    assert.equal(tiempoHasta('2026-11-17', '2027-11-17'), 'en 12 meses');
    assert.equal(tiempoHasta('2026-11-17', '2028-11-17'), 'en 2 años');
    assert.equal(tiempoHasta('2026-11-17', '2031-11-16'), 'en 4 años');
});
