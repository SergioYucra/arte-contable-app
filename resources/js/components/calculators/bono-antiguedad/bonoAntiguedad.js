/**
 * Bono de antigüedad (DS 21060 art. 60) y su reliquidación cuando sube el salario mínimo.
 *
 * Copia exacta de la fórmula de https://boliviaimpuestos.com/maletin/bono-antiguedad.php
 * (decisión del equipo: seguir su cálculo). La escala viene de parametros.json;
 * la revisión diaria (php artisan calculators:check) avisa si ellos la cambian.
 *
 * Las fechas viajan como texto ISO ("2026-11-17") y se calculan en UTC: así el resultado no
 * depende de la zona horaria del navegador. Igual que en la página original, sumar años a un
 * 29 de febrero cae en 1 de marzo cuando el año no es bisiesto.
 */

import { parseIsoDate } from '../shared/formatters.js';

// Se sigue exportando desde aquí (las pruebas y otras pantallas la importan de este archivo).
export { parseIsoDate };

const DAY_MS = 86400000;

function toIso(t) {
    return new Date(t).toISOString().slice(0, 10);
}

/** Suma años a una fecha ISO (29/02 + 1 año → 01/03). */
export function addYears(iso, years) {
    const t = parseIsoDate(iso);
    if (t === null) return null;
    const d = new Date(t);
    return toIso(Date.UTC(d.getUTCFullYear() + years, d.getUTCMonth(), d.getUTCDate()));
}

/** Años completos entre dos fechas ISO (si el aniversario aún no llegó, no cuenta). */
export function aniosCumplidos(ingreso, calculo) {
    const a = parseIsoDate(ingreso);
    const b = parseIsoDate(calculo);
    if (a === null || b === null || a > b) return 0;
    let years = new Date(b).getUTCFullYear() - new Date(a).getUTCFullYear();
    if (parseIsoDate(addYears(ingreso, years)) > b) years -= 1;
    return Math.max(0, years);
}

/** Índice del tramo de la escala para esos años, o -1 si no le corresponde bono. */
export function buscarTramo(anios, escala) {
    for (let i = escala.length - 1; i >= 0; i--) {
        if (anios >= escala[i].desde) return i;
    }
    return -1;
}

/**
 * @param {object} e
 * @param {'fecha'|'anios'} e.modo
 * @param {string} [e.ingreso]   fecha ISO (modo fecha)
 * @param {string} [e.calculo]   fecha ISO (modo fecha)
 * @param {number} [e.anios]     años cumplidos (modo anios)
 * @param {number} e.smn         salario mínimo nacional
 * @param {number} e.multiplicador  cuántos salarios mínimos forman la base (3, 1 u otro)
 * @param {number} e.dias        días trabajados en el mes (0 a 30)
 * @param {number} [e.haber]     haber básico (opcional)
 * @param {number} e.smnAnterior salario mínimo anterior (reliquidación)
 * @param {number} e.meses       meses a reliquidar
 * @param {object} p  parametros.bonoAntiguedad
 */
export function calcularBono(e, p) {
    const escala = p.escala;
    const conFechas = e.modo === 'fecha' && parseIsoDate(e.ingreso) !== null;
    const anios = e.modo === 'fecha' ? aniosCumplidos(e.ingreso, e.calculo) : Math.max(0, Math.trunc(e.anios || 0));

    const tramoIndice = buscarTramo(anios, escala);
    const tramo = tramoIndice >= 0 ? escala[tramoIndice] : null;
    const porcentaje = tramo ? tramo.porcentaje : 0;
    const dias = Math.min(p.diasDelMes, Math.max(0, Math.trunc(e.dias || 0)));
    const haber = e.haber || 0;

    const baseBs = e.smn * e.multiplicador;
    const bonoPleno = (baseBs * porcentaje) / 100;
    const bono = (bonoPleno * dias) / p.diasDelMes;

    // Próximo tramo: cuándo llega y cuánto sube.
    let proximo = null;
    const siguiente = tramoIndice + 1 < escala.length ? escala[tramoIndice + 1] : null;
    if (siguiente) {
        const bonoSiguiente = (baseBs * siguiente.porcentaje) / 100;
        proximo = {
            tramo: siguiente,
            bono: bonoSiguiente,
            diferencia: bonoSiguiente - bonoPleno,
            faltan: siguiente.desde - anios,
            fecha: conFechas ? addYears(e.ingreso, siguiente.desde) : null,
            desde: null,
            avance: null,
        };
        if (conFechas) {
            const inicio = tramo ? addYears(e.ingreso, tramo.desde) : e.ingreso;
            const ini = parseIsoDate(inicio);
            const fin = parseIsoDate(proximo.fecha);
            const hoy = parseIsoDate(e.calculo) ?? ini;
            proximo.desde = inicio;
            proximo.avance = Math.max(0, Math.min(100, ((hoy - ini) / (fin - ini)) * 100));
        }
    }

    // Reliquidación: lo que se debe cuando sube el salario mínimo con efecto retroactivo.
    const bonoAnterior = (e.smnAnterior * e.multiplicador * porcentaje) / 100;
    const diferenciaMensual = bonoPleno - bonoAnterior;
    const meses = Math.max(0, Math.trunc(e.meses || 0));

    return {
        anios,
        tramoIndice,
        tramo,
        porcentaje,
        dias,
        baseBs,
        bonoPleno,
        bono,
        bonoAnual: bonoPleno * 12,
        haber,
        totalGanado: haber ? haber + bono : null,
        primerBono: !tramo && conFechas ? addYears(e.ingreso, escala[0].desde) : null,
        proximo,
        reliquidacion: {
            bonoAnterior,
            diferenciaMensual,
            meses,
            retroactivo: diferenciaMensual * meses,
        },
    };
}

/**
 * Cuánto falta de una fecha a otra, en palabras: "mañana", "en 12 días", "en 3 meses", "en 2 años".
 * (Su página decía "en 1 año" aunque faltara un día: aquí se dice el tiempo real.)
 */
export function tiempoHasta(desde, hasta) {
    const a = parseIsoDate(desde);
    const b = parseIsoDate(hasta);
    if (a === null || b === null) return '';
    const days = Math.round((b - a) / DAY_MS);
    if (days <= 0) return 'hoy';
    if (days === 1) return 'mañana';
    if (days < 60) return `en ${days} días`;
    const [da, db] = [new Date(a), new Date(b)];
    const months = (db.getUTCFullYear() - da.getUTCFullYear()) * 12 + (db.getUTCMonth() - da.getUTCMonth()) - (db.getUTCDate() < da.getUTCDate() ? 1 : 0);
    if (months < 24) return `en ${months} ${months === 1 ? 'mes' : 'meses'}`;
    const years = Math.floor(months / 12);
    return `en ${years} años`;
}
