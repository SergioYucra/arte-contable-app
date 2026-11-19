// Captura resultados reales del balance de apertura de boliviaimpuestos.com:
// llena su formulario con cada caso y lee lo que muestra su página.
// Así se generó tests/js/fixtures/balance-apertura-casos.json (npm run test:js).
//
// Uso: PLAYWRIGHT_CORE=<ruta a playwright-core> node capturar-casos.cjs <carpeta-salida>
// (playwright-core no es dependencia del proyecto: sirve el que deja @playwright/mcp en el caché de npx).
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
const fs = require('fs');
const path = require('path');

const out = process.argv[2];
const URL = 'https://boliviaimpuestos.com/maletin/balance-apertura.php';

const vacio = { muebles: [], equipos: [], herramientas: [], otros: [] };
const CASOS = [
    {
        nombre: 'unipersonal sin activos fijos',
        tipo: 'unipersonal', fecha: '2026-01-02', razonSocial: 'Mi Empresa', nit: '1023456789', ciudad: 'La Paz - Bolivia', capital: 300000,
        activos: vacio, socios: [],
    },
    {
        nombre: 'unipersonal con activos en tres grupos',
        tipo: 'unipersonal', fecha: '2026-03-15', razonSocial: 'Librería El Saber', nit: '4455667', ciudad: 'Cochabamba - Bolivia', capital: 300000,
        activos: {
            muebles: [{ descripcion: 'Escritorio', cantidad: 2, precio: 1500 }, { descripcion: 'Silla', cantidad: 4, precio: 350 }],
            equipos: [{ descripcion: 'Laptop', cantidad: 3, precio: 6500.5 }],
            herramientas: [],
            otros: [{ descripcion: 'Vitrina', cantidad: 1, precio: 2000 }],
        },
        socios: [],
    },
    {
        nombre: 'activos fijos mayores que el capital',
        tipo: 'unipersonal', fecha: '2026-06-30', razonSocial: 'Taller Rápido', nit: '998877', ciudad: 'Santa Cruz - Bolivia', capital: 10000,
        activos: { ...vacio, equipos: [{ descripcion: 'Servidor', cantidad: 1, precio: 15000 }] },
        socios: [],
    },
    {
        nombre: 'activos fijos iguales al capital',
        tipo: 'unipersonal', fecha: '2026-06-30', razonSocial: 'Justo', nit: '1', ciudad: 'Oruro - Bolivia', capital: 5000,
        activos: { ...vacio, herramientas: [{ descripcion: 'Taladro', cantidad: 10, precio: 500 }] },
        socios: [],
    },
    {
        nombre: 'S.R.L. con dos socios al 60 y 40 %',
        tipo: 'srl', fecha: '2026-02-01', razonSocial: 'Andes Consultores S.R.L.', nit: '302010025', ciudad: 'Sucre - Bolivia', capital: 100000,
        activos: { ...vacio, muebles: [{ descripcion: 'Mesa de reuniones', cantidad: 1, precio: 4200.75 }], equipos: [{ descripcion: 'Impresora', cantidad: 2, precio: 1899.9 }] },
        socios: [{ nombre: 'Ana Quispe', porcentaje: 60 }, { nombre: 'Luis Mamani', porcentaje: 40 }],
    },
    {
        nombre: 'S.R.L. con tres socios que suman 90 %',
        tipo: 'srl', fecha: '2026-02-01', razonSocial: 'Tres Hermanos S.R.L.', nit: '556677', ciudad: 'Tarija - Bolivia', capital: 90000,
        activos: vacio,
        socios: [{ nombre: 'Uno', porcentaje: 30 }, { nombre: 'Dos', porcentaje: 30 }, { nombre: 'Tres', porcentaje: 30 }],
    },
    {
        nombre: 'S.R.L. con porcentajes con decimales',
        tipo: 'srl', fecha: '2026-12-31', razonSocial: 'Decimales S.R.L.', nit: '123', ciudad: 'Potosí - Bolivia', capital: 150000,
        activos: { ...vacio, otros: [{ descripcion: 'Software', cantidad: 1, precio: 3333.33 }] },
        socios: [{ nombre: 'A', porcentaje: 33.33 }, { nombre: 'B', porcentaje: 33.33 }, { nombre: 'C', porcentaje: 33.34 }],
    },
    {
        nombre: 'filas incompletas no cuentan',
        tipo: 'unipersonal', fecha: '2026-05-05', razonSocial: 'Filas', nit: '777', ciudad: 'Beni - Bolivia', capital: 50000,
        activos: {
            muebles: [{ descripcion: '', cantidad: 3, precio: 100 }, { descripcion: 'Estante', cantidad: 0, precio: 800 }],
            equipos: [{ descripcion: 'Monitor', cantidad: 2, precio: 0 }, { descripcion: 'Teclado', cantidad: 5, precio: 120 }],
            herramientas: [],
            otros: [],
        },
        socios: [],
    },
    {
        nombre: 'S.R.L. con un socio sin nombre',
        tipo: 'srl', fecha: '2026-08-08', razonSocial: 'Socio Anónimo S.R.L.', nit: '808080', ciudad: 'Pando - Bolivia', capital: 40000,
        activos: vacio,
        socios: [{ nombre: 'Carla', porcentaje: 50 }, { nombre: '', porcentaje: 50 }],
    },
];

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const result = [];

    for (const c of CASOS) {
        await page.goto(URL, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#baCats .bacat');
        const raw = await page.evaluate((c) => {
            const $ = (id) => document.getElementById(id);
            const fire = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
            const set = (el, v) => { el.value = v; fire(el); };
            $('baTipo').value = c.tipo; fire($('baTipo'));
            set($('baFecha'), c.fecha); set($('baNom'), c.razonSocial); set($('baNit'), c.nit); set($('baCiudad'), c.ciudad); set($('baCap'), String(c.capital));
            for (const [k, items] of Object.entries(c.activos)) {
                const cat = document.querySelector(`.bacat[data-cat="${k}"]`);
                items.forEach((it, i) => {
                    if (i > 0) cat.querySelector('.btn-add').click();
                    const row = cat.querySelectorAll('.barow')[i];
                    set(row.querySelector('.bad'), it.descripcion); set(row.querySelector('.bac'), String(it.cantidad)); set(row.querySelector('.bap'), String(it.precio));
                });
            }
            if (c.tipo === 'srl') {
                while (document.querySelectorAll('.socio-item').length < c.socios.length) $('baAddSoc').click();
                c.socios.forEach((s, i) => {
                    const row = document.querySelectorAll('.socio-item')[i];
                    set(row.querySelector('.socn'), s.nombre); set(row.querySelector('.socp'), String(s.porcentaje));
                });
            }
            fire($('baCap'));
            return {
                datos: [...document.querySelectorAll('#baStats .stat')].map((s) => [s.querySelector('.stat__k').textContent, s.querySelector('.stat__v').textContent]),
                cuadre: $('baCheck').textContent,
                veredicto: $('baVerdict').textContent.trim(),
                avisoSocios: c.tipo === 'srl' ? $('baSocWarn').textContent.trim() : null,
                aportes: [...document.querySelectorAll('.socio-item .soca')].map((x) => x.textContent),
                subtotales: [...document.querySelectorAll('.bacat')].map((x) => [x.querySelector('b').textContent, x.querySelector('.bacat__st').textContent]),
                documento: {
                    empresa: (document.querySelector('#baReport .barep__co') || {}).textContent || null,
                    lineas: [...document.querySelectorAll('#baReport .barep__sub')].map((x) => x.textContent),
                    filas: [...document.querySelectorAll('#baReport tr')].map((tr) => [tr.className || '', ...[...tr.children].map((td) => td.textContent)]),
                },
            };
        }, c);
        result.push({ nombre: c.nombre, entradas: { ...c, nombre: undefined }, pantalla: raw });
    }

    await browser.close();
    fs.writeFileSync(path.join(out, 'balance-apertura-casos.json'), JSON.stringify({ fuente: URL, capturado: new Date().toISOString(), casos: result }, null, 2) + '\n');
    console.log('casos', result.length);
})().catch((e) => { console.error(e); process.exit(1); });
