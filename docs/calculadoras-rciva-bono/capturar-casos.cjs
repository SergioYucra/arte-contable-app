// Captura resultados reales de las calculadoras de boliviaimpuestos.com (RC-IVA y Bono de antigüedad):
// llena sus campos con cada caso y lee lo que muestra su página. Así se generaron
// tests/js/fixtures/*.json, contra los que se prueban nuestras fórmulas (npm run test:js).
//
// Uso: PLAYWRIGHT_CORE=<ruta a playwright-core> node capturar-casos.cjs <carpeta-salida>
// (playwright-core no es dependencia del proyecto: sirve el que deja @playwright/mcp en el caché de npx).
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
const fs = require('fs');
const path = require('path');

const out = process.argv[2];

const RC_CASES = [
    { nombre: 'valores por defecto de su página', ganado: '15000', smn: '2750', saldoFavor: '0', facturas: '0' },
    { nombre: 'sueldo bajo, sin impuesto', ganado: '8000', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'justo en 13.000 (sin aporte solidario)', ganado: '13000', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'primer tramo del aporte solidario', ganado: '20000', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'segundo tramo del aporte solidario', ganado: '30000', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'justo en 35.000', ganado: '35000', smn: '2750', saldoFavor: '0', facturas: '0' },
    { nombre: 'tercer tramo del aporte solidario', ganado: '50000', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'con saldo a favor y facturas', ganado: '15000', smn: '3300', saldoFavor: '200', facturas: '3000' },
    { nombre: 'facturas que cubren todo', ganado: '15000', smn: '3300', saldoFavor: '0', facturas: '50000' },
    { nombre: 'sin ingresos', ganado: '0', smn: '3300', saldoFavor: '0', facturas: '0' },
    { nombre: 'con decimales', ganado: '25000.50', smn: '3300', saldoFavor: '10.25', facturas: '1234.56' },
    { nombre: 'sueldo alto', ganado: '120000', smn: '3300', saldoFavor: '500', facturas: '10000' },
];

const BONO_CASES = [
    { nombre: '6 años por fecha (caso base)', modo: 'fecha', ingreso: '2020-11-17', calculo: '2026-11-17', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: 'menos de 2 años: sin bono', modo: 'fecha', ingreso: '2025-01-10', calculo: '2026-11-17', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: 'un día antes de cumplir 2 años', modo: 'fecha', ingreso: '2024-11-18', calculo: '2026-11-17', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: 'el día que cumple 2 años', modo: 'fecha', ingreso: '2024-11-17', calculo: '2026-11-17', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: 'ingreso 29 de febrero, un día antes del aniversario', modo: 'fecha', ingreso: '2016-02-29', calculo: '2026-02-28', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '1' },
    { nombre: 'ingreso 29 de febrero, al día siguiente', modo: 'fecha', ingreso: '2016-02-29', calculo: '2026-03-01', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '2' },
    { nombre: 'ingreso 29 de febrero en año bisiesto', modo: 'fecha', ingreso: '2020-02-29', calculo: '2024-02-29', smn: '2500', base: '3', dias: '30', haber: '', smnAnterior: '2362', meses: '3' },
    { nombre: 'ingreso posterior a la fecha de cálculo', modo: 'fecha', ingreso: '2027-01-01', calculo: '2026-11-17', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '5 años cumplidos', modo: 'anios', anios: '5', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '8 años cumplidos', modo: 'anios', anios: '8', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '11 años cumplidos', modo: 'anios', anios: '11', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '15 años cumplidos', modo: 'anios', anios: '15', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '20 años cumplidos', modo: 'anios', anios: '20', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '25 años cumplidos (tramo máximo)', modo: 'anios', anios: '25', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '40 años cumplidos', modo: 'anios', anios: '40', smn: '3300', base: '3', dias: '30', haber: '', smnAnterior: '2750', meses: '4' },
    { nombre: '15 días trabajados y haber básico', modo: 'anios', anios: '9', smn: '3300', base: '3', dias: '15', haber: '5000', smnAnterior: '2750', meses: '4' },
    { nombre: 'ONG: base de 1 SMN', modo: 'anios', anios: '12', smn: '3300', base: '1', dias: '30', haber: '', smnAnterior: '2750', meses: '6' },
    { nombre: 'otra base por convenio (2,5 SMN)', modo: 'anios', anios: '3', smn: '3300', base: 'otra', mult: '2.5', dias: '30', haber: '', smnAnterior: '2750', meses: '10' },
    { nombre: 'SMN escrito a mano y reliquidación larga', modo: 'fecha', ingreso: '2009-05-15', calculo: '2026-06-30', smn: '3000', base: '3', dias: '30', haber: '7000.50', smnAnterior: '2500', meses: '10' },
];

const num = (t) => {
    if (t == null) return null;
    const m = String(t).replace(/\s/g, ' ').match(/-?[\d.]+(,\d+)?/);
    if (!m) return null;
    return Number(m[0].replace(/\./g, '').replace(',', '.'));
};

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage();

    // ---------------- RC-IVA ----------------
    await page.goto('https://boliviaimpuestos.com/maletin/rc-iva.php', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#rSaldo');
    const rc = [];
    for (const c of RC_CASES) {
        const raw = await page.evaluate((c) => {
            const set = (id, v) => { const el = document.getElementById(id); el.value = v; el.dispatchEvent(new Event('input')); };
            set('rG', c.ganado); set('rSMN', c.smn); set('rSF', c.saldoFavor); set('rFac', c.facturas);
            return {
                saldo: document.getElementById('rSaldo').textContent,
                veredicto: document.getElementById('rVerdict').textContent.trim(),
                datos: [...document.querySelectorAll('#rStats .stat')].map((s) => [s.querySelector('.stat__k').textContent, s.querySelector('.stat__v').textContent]),
                cascada: [...document.querySelectorAll('#rWf .wf__row')].map((r) => [r.querySelector('.wf__lbl').textContent, r.querySelector('.wf__val').textContent]),
                pasos: [...document.querySelectorAll('#rSteps .step')].map((s) => s.textContent.trim()),
            };
        }, c);
        const datos = Object.fromEntries(raw.datos.map(([k, v]) => [k, num(v)]));
        const fac = raw.veredicto.match(/presenta\s*(Bs\s*[\d.,]+)/);
        rc.push({
            nombre: c.nombre,
            entradas: { ganado: Number(c.ganado), smn: Number(c.smn), saldoFavor: Number(c.saldoFavor), facturas: Number(c.facturas) },
            esperado: {
                afp: datos['Aporte AFP (12,71%)'],
                aporteSolidario: datos['Aporte Nac. Solidario'],
                base: datos['Base imponible'],
                impuesto: datos['Impuesto (13%)'],
                saldo: num(raw.saldo),
                facturasQueFaltan: fac ? num(fac[1]) : 0,
            },
            pantalla: raw,
        });
    }

    // ---------------- Bono ----------------
    await page.goto('https://boliviaimpuestos.com/maletin/bono-antiguedad.php', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#bOut');
    const bono = [];
    for (const c of BONO_CASES) {
        const raw = await page.evaluate((c) => {
            const $ = (id) => document.getElementById(id);
            const set = (id, v) => { const el = $(id); el.value = v; el.dispatchEvent(new Event('input')); el.dispatchEvent(new Event('change')); };
            document.querySelector(`#bMode button[data-s="${c.modo}"]`).click();
            document.querySelector(`#bBase button[data-s="${c.base}"]`).click();
            if (c.modo === 'fecha') { set('bIng', c.ingreso); set('bCalc', c.calculo); } else { set('bAnios', c.anios); }
            if (c.base === 'otra') set('bMult', c.mult);
            $('bSmn').value = c.smn; $('bSmn').dispatchEvent(new Event('input'));
            set('bHaber', c.haber); set('bDias', c.dias); set('bSmnAnt', c.smnAnterior); set('bMeses', c.meses);
            $('bGo').click();
            const fill = document.querySelector('#bNext .tl__fill');
            return {
                bono: $('bOut').textContent,
                titulo: $('bLbl').textContent,
                resumen: $('bSub').textContent,
                datos: [...document.querySelectorAll('#bStats .stat')].map((s) => [s.querySelector('.stat__k').textContent, s.querySelector('.stat__v').textContent]),
                tramoActual: (document.querySelector('#bTabla tbody tr.on td') || {}).textContent || null,
                proximoTexto: (document.querySelector('#bNext .bnx__d') || {}).textContent || null,
                proximoValor: (document.querySelector('#bNext .bnx__v') || {}).textContent || null,
                avance: fill ? fill.style.width : null,
                retro: [...document.querySelectorAll('#bRetro .cmp__card')].map((x) => [x.querySelector('.cmp__t').textContent, x.querySelector('.cmp__v').textContent]),
                pasos: [...document.querySelectorAll('#bSteps .step')].map((s) => s.textContent.trim()),
            };
        }, c);
        const datos = Object.fromEntries(raw.datos.map(([k, v]) => [k, v]));
        const retro = Object.fromEntries(raw.retro.map(([k, v]) => [k, num(v)]));
        const anual = Object.entries(datos).find(([k]) => k.startsWith('Bono anual'));
        const total = Object.entries(datos).find(([k]) => k === 'Total ganado');
        const baseKey = Object.keys(datos).find((k) => k.startsWith('Base'));
        const entradas = { modo: c.modo, smn: Number(c.smn), base: c.base, dias: Number(c.dias), haber: c.haber === '' ? 0 : Number(c.haber), smnAnterior: Number(c.smnAnterior), meses: Number(c.meses) };
        if (c.modo === 'fecha') { entradas.ingreso = c.ingreso; entradas.calculo = c.calculo; } else { entradas.anios = Number(c.anios); }
        if (c.base === 'otra') entradas.multiplicador = Number(c.mult);
        bono.push({
            nombre: c.nombre,
            entradas,
            esperado: {
                anios: num(datos['Antigüedad']),
                porcentaje: num(datos['Porcentaje']),
                baseBs: num(datos[baseKey]),
                bono: num(raw.bono),
                bonoAnual: anual ? num(anual[1]) : null,
                totalGanado: total ? num(total[1]) : null,
                proximaDiferencia: raw.proximoValor && raw.proximoValor.includes('Bs') ? num(raw.proximoValor) : null,
                avancePorcentaje: raw.avance ? Number(raw.avance.replace('%', '')) : null,
                bonoSmnAnterior: retro['Bono con SMN anterior'],
                diferenciaMensual: retro['Diferencia mensual'],
                retroactivo: retro['Retroactivo a pagar'],
            },
            pantalla: raw,
        });
    }

    await browser.close();
    const meta = { fuente: 'boliviaimpuestos.com/maletin', capturado: new Date().toISOString() };
    fs.writeFileSync(path.join(out, 'rc-iva-casos.json'), JSON.stringify({ ...meta, pagina: 'rc-iva.php', casos: rc }, null, 2) + '\n');
    fs.writeFileSync(path.join(out, 'bono-antiguedad-casos.json'), JSON.stringify({ ...meta, pagina: 'bono-antiguedad.php', casos: bono }, null, 2) + '\n');
    console.log('rc', rc.length, 'bono', bono.length);
})().catch((e) => { console.error(e); process.exit(1); });
