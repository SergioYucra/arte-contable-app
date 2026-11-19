/**
 * Balance de apertura: Caja/Bancos = capital − activos fijos, para que el Activo iguale al
 * Patrimonio (capital) el día que empieza la empresa.
 *
 * Copia exacta del cálculo de https://boliviaimpuestos.com/maletin/balance-apertura.php
 * (mismas reglas: una fila de activo cuenta si tiene descripción, cantidad y precio mayores
 * que cero; el aporte de cada socio es capital × % ÷ 100). Sin Vue ni DOM: también lo usan las pruebas.
 *
 * Diferencia a propósito: la caja y los aportes se redondean al centavo. Su página no lo hace y, con
 * decimales, puede marcar "no cuadra" por -0,0000000000001 o imprimir aportes que no suman el capital
 * por un centavo. Las cifras que se ven son las mismas.
 */

import { round2 } from '../../calculators/shared/formatters.js';

/**
 * @param {object} e
 * @param {'unipersonal'|'srl'} e.tipo
 * @param {number} e.capital
 * @param {Record<string, Array<{descripcion: string, cantidad: number, precio: number}>>} e.activos  por clave de grupo
 * @param {Array<{nombre: string, porcentaje: number}>} [e.socios]  solo S.R.L.
 * @param {Array<{clave: string, nombre: string}>} grupos  GRUPOS_ACTIVO_FIJO
 */
export function armarBalance(e, grupos) {
    const capital = e.capital;

    // Activos fijos: solo cuentan las filas completas.
    const categorias = grupos.map((g) => {
        const items = [];
        let subtotal = 0;
        for (const fila of e.activos?.[g.clave] ?? []) {
            const descripcion = String(fila.descripcion ?? '').trim();
            const cantidad = Math.trunc(Number(fila.cantidad) || 0);
            const precio = Number(fila.precio) || 0;
            if (descripcion && cantidad > 0 && precio > 0) {
                const valor = cantidad * precio;
                subtotal += valor;
                items.push({ descripcion, cantidad, precio, valor });
            }
        }
        return { clave: g.clave, nombre: g.nombre, subtotal, items };
    });
    const activoFijo = categorias.reduce((sum, c) => sum + c.subtotal, 0);

    // Al centavo: con decimales, capital − activos puede dar -0,0000000000001 en vez de 0.
    const caja = round2(capital - activoFijo) || 0; // || 0: sin "-0,00"
    const activo = caja + activoFijo;
    // Activo siempre iguala al capital; el balance no cuadra solo si la caja queda en negativo.
    const cuadra = caja >= 0;

    // Socios (solo S.R.L.): aporte = capital × % ÷ 100. Van al balance los que tienen nombre y %.
    const esSrl = e.tipo === 'srl';
    const socios = esSrl
        ? (e.socios ?? []).map((s) => {
              const nombre = String(s.nombre ?? '').trim();
              const porcentaje = Number(s.porcentaje) || 0;
              return { nombre, porcentaje, aporte: round2((capital * porcentaje) / 100), enBalance: Boolean(nombre) && porcentaje > 0 };
          })
        : [];
    const enBalance = socios.filter((s) => s.enBalance);
    const sumaPorcentajes = enBalance.reduce((sum, s) => sum + s.porcentaje, 0);
    const porcentajesOk = !esSrl || Math.abs(sumaPorcentajes - 100) < 0.01;
    // Si suman 100%, el último socio absorbe el centavo que pueda sobrar al redondear:
    // así los aportes del documento suman exactamente el capital.
    if (esSrl && porcentajesOk && enBalance.length) {
        const otros = enBalance.slice(0, -1).reduce((sum, s) => sum + s.aporte, 0);
        enBalance[enBalance.length - 1].aporte = round2(capital - otros);
    }

    // Filas del documento, en el orden del balance.
    const filas = [
        { nivel: 'grupo', texto: 'ACTIVO', monto: activo },
        { nivel: 'subgrupo', texto: 'Activo Disponible', monto: caja },
        { nivel: 'cuenta', texto: 'Caja / Bancos', monto: caja },
    ];
    if (activoFijo > 0) {
        filas.push({ nivel: 'subgrupo', texto: 'Activo Fijo', monto: activoFijo });
        for (const c of categorias) if (c.subtotal > 0) filas.push({ nivel: 'cuenta', texto: c.nombre, monto: c.subtotal });
    }
    filas.push({ nivel: 'grupo', texto: 'PASIVO + PATRIMONIO', monto: capital });
    filas.push({ nivel: 'subgrupo', texto: 'Patrimonio', monto: capital });
    if (enBalance.length) {
        for (const s of enBalance) filas.push({ nivel: 'cuenta', texto: `Aporte ${s.nombre} (${porcentajeTexto(s.porcentaje)})`, monto: s.aporte });
    } else {
        filas.push({ nivel: 'cuenta', texto: 'Capital', monto: capital });
    }

    return {
        capital,
        categorias,
        activoFijo,
        caja,
        activo,
        cuadra,
        activosSuperanCapital: caja < 0,
        socios,
        sumaPorcentajes,
        porcentajesOk,
        filas,
    };
}

/** 60 → "60,00%" (así lo escribe el balance). */
export function porcentajeTexto(n) {
    return `${Number(n).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}
