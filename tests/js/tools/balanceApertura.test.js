// Balance de apertura contra resultados reales de boliviaimpuestos.com
// (tests/js/fixtures/balance-apertura-casos.json). Correr con: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { armarBalance } from '../../../resources/js/components/tools/balance-apertura/balanceApertura.js';
import { GRUPOS_ACTIVO_FIJO } from '../../../resources/js/components/tools/balance-apertura/catalogos.js';
import { bs as money, formatBs, longDate } from '../../../resources/js/components/calculators/shared/formatters.js';

// Su página separa "Bs" del número con un espacio común; la nuestra, con uno que no se parte.
const bs = (n) => money(n).replace(/ /g, ' ');
const { casos } = JSON.parse(readFileSync(new URL('../fixtures/balance-apertura-casos.json', import.meta.url), 'utf8'));

function entradas(c) {
    const e = c.entradas;
    return {
        tipo: e.tipo,
        capital: e.capital,
        activos: Object.fromEntries(Object.entries(e.activos).map(([k, items]) => [k, items.map((i) => ({ descripcion: i.descripcion, cantidad: i.cantidad, precio: i.precio }))])),
        socios: e.socios,
    };
}

for (const caso of casos) {
    test(`Balance igual a boliviaimpuestos: ${caso.nombre}`, () => {
        const r = armarBalance(entradas(caso), GRUPOS_ACTIVO_FIJO);
        const p = caso.pantalla;
        const datos = Object.fromEntries(p.datos);

        assert.equal(bs(r.caja), datos['Activo disponible (caja)'], 'caja');
        assert.equal(bs(r.activoFijo), datos['Activo fijo'], 'activo fijo');
        assert.equal(bs(r.activo), datos['Total activo'], 'total activo');
        assert.equal(bs(r.capital), datos['Patrimonio (capital)'], 'patrimonio');

        assert.equal(r.cuadra, p.cuadre === 'CUADRA', 'cuadre');
        if (r.activosSuperanCapital) assert.match(p.veredicto, /superan el capital/);

        assert.deepEqual(r.categorias.map((c) => [c.nombre, bs(c.subtotal)]), p.subtotales, 'subtotales por grupo');

        if (caso.entradas.tipo === 'srl') {
            assert.deepEqual(r.socios.map((s) => bs(s.aporte)), p.aportes, 'aporte de cada socio');
            assert.equal(r.porcentajesOk, p.avisoSocios.includes('suman 100%'), 'aviso de porcentajes');
            if (!r.porcentajesOk) assert.ok(p.avisoSocios.includes(formatBs(r.sumaPorcentajes)), 'suma de porcentajes');
        }

        // El documento: mismas filas, mismos textos y montos.
        assert.deepEqual(
            r.filas.map((f) => [f.texto, formatBs(f.monto)]),
            p.documento.filas.map(([, texto, monto]) => [texto, monto]),
            'filas del balance',
        );
        assert.equal(p.documento.lineas[1], `Al ${longDate(caso.entradas.fecha)} — (expresado en bolivianos)`, 'fecha del balance');
    });
}

test('una fila con espacios en la descripción no cuenta', () => {
    const r = armarBalance({ tipo: 'unipersonal', capital: 1000, activos: { muebles: [{ descripcion: '   ', cantidad: 1, precio: 100 }] } }, GRUPOS_ACTIVO_FIJO);
    assert.equal(r.activoFijo, 0);
});

test('unipersonal: los socios no se usan aunque vengan', () => {
    const r = armarBalance({ tipo: 'unipersonal', capital: 1000, activos: {}, socios: [{ nombre: 'X', porcentaje: 50 }] }, GRUPOS_ACTIVO_FIJO);
    assert.deepEqual(r.socios, []);
    assert.equal(r.porcentajesOk, true);
    assert.equal(r.filas.at(-1).texto, 'Capital');
});
