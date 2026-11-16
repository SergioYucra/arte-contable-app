// Reimplementación independiente de las fórmulas deducidas, comparada contra
// las respuestas reales capturadas de boliviaimpuestos.com (fap.php / deudas.php).
// Uso: node verificar-formulas.cjs [carpeta-capturas]   (por defecto ./capturas)
const fs = require('fs');
const path = require('path');
const dir = process.argv[2] || path.join(__dirname, 'capturas');
const load = f => { let o = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')); return typeof o === 'string' ? JSON.parse(o) : o; };
const sample = load('01-caso-base.json');
const probe = load('02-variantes.json');
const probe2 = load('03-vencimientos-y-varias-deudas.json');

const r2 = x => Math.round((x + Number.EPSILON) * 100) / 100;
const r5 = x => Math.round(x * 1e5) / 1e5;
const D = s => new Date(s + 'T00:00:00Z');
const days = (a, b) => Math.round((D(b) - D(a)) / 86400000);
const fmtD = d => d.toISOString().slice(0, 10);
const addDays = (s, n) => { const d = D(s); d.setUTCDate(d.getUTCDate() + n); return fmtD(d); };

let ok = 0, bad = 0;
const chk = (label, got, exp, tol = 0.011) => {
  if (Math.abs(got - exp) <= tol) ok++;
  else { bad++; console.log('  DIFERENCIA', label, 'calculado=', got, 'sitio=', exp); }
};

// ---------- 1) Deuda tributaria Art. 47 (deudas.php?action=calc) ----------
const TRAMOS = [{ anios: 4, tasa: 0.04 }, { anios: 3, tasa: 0.06 }, { anios: Infinity, tasa: 0.10 }];
function addYears(s, y) { const d = D(s); d.setUTCFullYear(d.getUTCFullYear() + y); return fmtD(d); }
function deuda({ TO, fechaVenc, fechaPago, ufvVenc, ufvPago, sancion = false }) {
  const c = r5(TO / ufvVenc);
  const tramos = []; let ini = fechaVenc, acum = 0;
  for (const t of TRAMOS) {
    const fin = t.anios === Infinity ? fechaPago : addYears(fechaVenc, acum + t.anios);
    const hasta = fin < fechaPago ? fin : fechaPago;
    const n = days(ini, hasta);
    if (n > 0) tramos.push({ tasa: t.tasa, dias: n, interesUFV: c * (Math.pow(1 + t.tasa / 360, n) - 1) });
    if (hasta >= fechaPago) break;
    ini = hasta; acum += t.anios;
  }
  const interesUFV = r5(tramos.reduce((a, t) => a + t.interesUFV, 0));
  const toAct = r2(c * ufvPago), mv = r2(toAct - TO), interes = r2(interesUFV * ufvPago);
  return { c, tramos, toAct, mv, interes, deuda: r2(TO + mv + interes), sancion: sancion ? r2(0.6 * c * ufvPago) : 0 };
}

console.log('1) Deuda Art. 47 (IVA 2019-01, TO 10.000, venc 2019-02-15, solicitud 2026-11-15)');
const cs = sample.calc;
const d1 = deuda({ TO: 10000, fechaVenc: '2019-02-15', fechaPago: '2026-11-15', ufvVenc: cs.ufvVenc, ufvPago: cs.ufvPago, sancion: true });
chk('cUFV', d1.c, cs.cUFV, 1e-5);
d1.tramos.forEach((t, i) => { chk('tramo' + i + ' dias', t.dias, cs.tramos[i].dias, 0); chk('tramo' + i + ' interesUFV', t.interesUFV, cs.tramos[i].interesUFV, 1e-6); });
chk('mvBs', d1.mv, cs.mvBs); chk('interesBs', d1.interes, cs.interesBs); chk('deudaBs', d1.deuda, cs.deudaBs);
chk('sancionBs 60%', d1.sancion, sample.calcSanc.sancionBs);

// ---------- 2) Proyección UFV (cuotas futuras) ----------
const ufvUlt = probe.ufv['2026-12-10'].ufv, ufvAnio = probe.ufv['2025-12-10'].ufv;
const infl = ufvUlt / ufvAnio - 1;
const dim = s => { const d = D(s); return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate(); };
function ufvProy(fecha) {
  let ln = 0, f = '2026-12-10';
  while (f < fecha) { f = addDays(f, 1); ln += Math.log(1 + infl) / 12 / dim(f); }
  return r5(ufvUlt * Math.exp(ln));
}
console.log('2) Proyección UFV: inflación 12 meses =', (infl * 100).toFixed(4) + '% (sitio: ' + sample.fap12.inflacionAnualEstim + '%)');
for (const c of probe2.multi.cronograma) if (c.proyectada) chk('ufv ' + c.fecha, ufvProy(c.fecha), c.ufv, 0.00002);

// ---------- 3) Plan FAP con alivio ----------
const PUB = '2026-05-27', ufvPub = sample.ufvPub.ufv, TASA_FAC = 0.04;
function fap(resp, ufvVencs) {
  const sol = resp.ufvSolicitud, minBs = r2(200 * sol);
  const regs = resp.deudas.filter(d => d.tratamiento === 'regularizable');
  const garPct = resp.porcentajeGarantia / 100;
  const planes = regs.map((d, i) => {
    const c = r5(d.tributoOmitido / ufvVencs[i]);
    const base = r2(c * ufvPub);
    const ini = r2(base * 0.05), gar = r2(base * garPct);
    return { d, c, base, ini, gar, saldo: r2(base - ini - gar), contado: r2(d.tributoOmitido + r2(0.5 * d.mv)) };
  });
  const saldoTot = planes.reduce((a, p) => a + p.saldo, 0);
  const maxPerm = Math.min(resp.topeCuotas, Math.floor(r2(saldoTot) / minBs));
  chk('maxCuotasPermitidas', maxPerm, resp.maxCuotasPermitidas, 0);
  planes.forEach(p => {
    chk(p.d.impuesto + ' base FAP', p.base, p.d.total); chk(p.d.impuesto + ' contado', p.contado, p.d.contado);
    chk(p.d.impuesto + ' inicial', p.ini, p.d.pagoInicial); chk(p.d.impuesto + ' saldo', p.saldo, p.d.saldo);
  });
  for (const cu of resp.cronograma) {
    for (const det of cu.detalle) {
      const p = planes.find(p => p.d.impuesto === det.impuesto && p.d.periodo === det.periodo);
      const cap = r2(p.saldo / p.d.cuotas);
      const capUFV = cap / ufvPub;
      const mv = r2(capUFV * cu.ufv - cap);
      const int = r2(capUFV * cu.ufv * (Math.pow(1 + TASA_FAC / 360, days(PUB, cu.fecha)) - 1));
      chk(`cuota ${cu.n} ${det.impuesto} capital`, cap, det.to);
      chk(`cuota ${cu.n} ${det.impuesto} mant.valor`, mv, det.mv);
      chk(`cuota ${cu.n} ${det.impuesto} interés`, int, det.interes);
    }
  }
}
const uV = f => ({ '2019-02-15': sample.ufvVenc.ufv })[f];
console.log('3) FAP 12 cuotas (caso de la captura)'); fap(sample.fap12, [sample.ufvVenc.ufv]);
console.log('   FAP 18 cuotas'); fap(sample.fap18, [sample.ufvVenc.ufv]);
console.log('   FAP garantía hipotecaria'); fap(sample.fapHipo, [sample.ufvVenc.ufv]);
console.log('   FAP 500.000 Bs / 36 cuotas'); fap(probe.fap.big36, [sample.ufvVenc.ufv]);
console.log('   FAP solicitud 2026-07-01 (cuotas ya vencidas, UFV real)'); fap(probe.fap.solPast, [sample.ufvVenc.ufv]);
// varias deudas: ufvVenc de cada una se despeja de la deuda total devuelta (mv = c*ufvSol - TO)
const ufvVencFrom = (d, sol) => d.tributoOmitido / ((d.tributoOmitido + d.mv) / sol);
console.log('   FAP 3 deudas / 36 cuotas'); fap(probe2.multi, probe2.multi.deudas.map(d => r5(ufvVencFrom(d, probe2.multi.ufvSolicitud))));

console.log(`\nRESULTADO: ${ok} comprobaciones coinciden, ${bad} difieren`);
