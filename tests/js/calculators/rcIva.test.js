// Fórmula RC-IVA contra resultados reales de boliviaimpuestos.com (tests/js/fixtures/rc-iva-casos.json).
// Correr con: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { calcularRcIva } from '../../../resources/js/components/calculators/rcIva.js';
import { bs as money } from '../../../resources/js/components/calculators/format.js';

// Su página separa "Bs" del número con un espacio común; la nuestra, con uno que no se parte.
const bs = (...args) => money(...args).replace(/\u00A0/g, ' ');

const read = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
const params = read('../../../resources/js/components/calculators/parametros.json');
const { casos } = read('../fixtures/rc-iva-casos.json');

for (const caso of casos) {
    test(`RC-IVA igual a boliviaimpuestos: ${caso.nombre}`, () => {
        const r = calcularRcIva(caso.entradas, params.rcIva);
        const pantalla = Object.fromEntries(caso.pantalla.datos);

        // Mismo texto que su pantalla, al centavo.
        assert.equal(bs(r.saldo), caso.pantalla.saldo, 'saldo a pagar');
        assert.equal(bs(r.afp), pantalla['Aporte AFP (12,71%)'], 'aporte AFP');
        assert.equal(bs(r.aporteSolidario), pantalla['Aporte Nac. Solidario'], 'aporte solidario');
        assert.equal(bs(r.base), pantalla['Base imponible'], 'base imponible');
        assert.equal(bs(r.impuesto), pantalla['Impuesto (13%)'], 'impuesto');

        const compensacion = caso.pantalla.cascada.find(([label]) => label.includes('Comp. automática'));
        assert.equal(bs(-r.compensacion), compensacion[1], 'compensación automática');

        if (r.saldo > 0) {
            assert.match(caso.pantalla.veredicto, /Debes pagar/);
            assert.ok(caso.pantalla.veredicto.includes(bs(r.facturasQueFaltan)), 'facturas que faltan');
        } else {
            assert.match(caso.pantalla.veredicto, /No pagas RC-IVA/);
            assert.equal(r.facturasQueFaltan, 0);
        }
    });
}

test('RC-IVA: cada tramo del aporte solidario suma por separado', () => {
    const r = calcularRcIva({ ganado: 50000, smn: 3300 }, params.rcIva);
    assert.deepEqual(
        r.tramosSolidario.map((t) => [t.desde, t.monto]),
        [[13000, 12000], [25000, 10000], [35000, 15000]],
    );
});

test('RC-IVA: sin saldo a favor ni facturas, el saldo es el impuesto determinado', () => {
    const r = calcularRcIva({ ganado: 20000, smn: 3300 }, params.rcIva);
    assert.equal(r.saldo, r.determinado);
    assert.equal(r.creditoFacturas, 0);
});
