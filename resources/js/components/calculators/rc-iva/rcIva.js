/**
 * RC-IVA de una persona en relación de dependencia (un mes).
 *
 * Copia exacta de la fórmula de https://boliviaimpuestos.com/maletin/rc-iva.php
 * (decisión del equipo: seguir su cálculo). Las tasas vienen de parametros.json;
 * la revisión diaria (php artisan calculators:check) avisa si ellos las cambian.
 * Los pasos intermedios no se redondean, igual que en la página original.
 *
 * @param {{ganado: number, smn: number, saldoFavor?: number, facturas?: number}} entradas
 * @param {object} p  parametros.rcIva
 */
export function calcularRcIva({ ganado, smn, saldoFavor = 0, facturas = 0 }, p) {
    const afp = ganado * p.aporteAfp;

    // Aporte Nacional Solidario: un porcentaje por cada tramo del total ganado.
    const tramosSolidario = [];
    let aporteSolidario = 0;
    for (const t of p.aporteSolidario) {
        if (ganado <= t.desde) continue;
        const ancho = t.hasta === null ? Infinity : t.hasta - t.desde;
        const monto = Math.min(Math.max(ganado - t.desde, 0), ancho);
        const aporte = monto * t.tasa;
        aporteSolidario += aporte;
        if (monto > 0) tramosSolidario.push({ desde: t.desde, hasta: t.hasta, tasa: t.tasa, monto, aporte });
    }

    const neto = ganado - afp - aporteSolidario;
    const minimoNoImponible = smn * p.minimoNoImponibleSmn;
    const base = Math.max(0, neto - minimoNoImponible);
    const impuesto = base * p.tasa;
    const compensacion = smn * p.compensacionSmn * p.tasa;
    const determinado = Math.max(0, impuesto - compensacion);
    const creditoFacturas = facturas * p.tasa;
    const saldo = Math.max(0, determinado - saldoFavor - creditoFacturas);
    const facturasQueFaltan = saldo > 0 ? saldo / p.tasa : 0;

    return {
        afp,
        aporteSolidario,
        tramosSolidario,
        neto,
        minimoNoImponible,
        base,
        impuesto,
        compensacion,
        determinado,
        saldoFavor,
        creditoFacturas,
        saldo,
        facturasQueFaltan,
    };
}
