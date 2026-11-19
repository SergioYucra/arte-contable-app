import { formatBs, formatearAsientoTSV, round2 } from '../shared/formatters.js';

export { formatBs, formatearAsientoTSV, round2 };

export const CUENTA_REEXPRESION = 'CAJA M/E';

/**
 * Motor único de cálculo para Reexpresión de Moneda Extranjera (Métodos 1 y 2).
 *
 * @param {object} params
 * @param {1 | 2} [params.metodo=1]
 * @param {number|string} params.montoME
 * @param {number|string} params.tcFinal
 * @param {number|string} params.tcInicial
 * @param {string} [params.fechaAjuste='']
 */
export function calcularReexpresion({
    metodo = 1,
    montoME,
    tcFinal,
    tcInicial,
    fechaAjuste = '',
}) {
    const errors = [];

    const monto = Number(montoME);
    if (montoME === '' || montoME === null || !Number.isFinite(monto) || monto <= 0) {
        errors.push('Ingresa un monto en M/E mayor a 0.');
    }

    const tcFin = Number(tcFinal);
    if (tcFinal === '' || tcFinal === null || !Number.isFinite(tcFin) || tcFin <= 0) {
        errors.push('Ingresa un tipo de cambio final / de ajuste mayor a 0.');
    }

    const tcIni = Number(tcInicial);
    if (tcInicial === '' || tcInicial === null || !Number.isFinite(tcIni) || tcIni <= 0) {
        errors.push('Ingresa un tipo de cambio inicial / de adquisición mayor a 0.');
    }

    if (!fechaAjuste) {
        errors.push('Selecciona la fecha de ajuste.');
    }

    if (errors.length > 0) {
        return {
            calculable: false,
            sinDiferencia: false,
            errors,
            valorAjuste: 0,
            valorAdquisicion: 0,
            diferencia: 0,
            montoAsiento: 0,
            resultado: null,
            asiento: null,
        };
    }

    const valorAjuste = round2(monto * tcFin);
    const valorAdquisicion = round2(monto * tcIni);

    // Método 1: resta valores ya redondeados en Bs; Método 2: diferencia directa de T/C redondeada al final.
    const diferencia =
        metodo === 1
            ? round2(valorAjuste - valorAdquisicion)
            : round2(monto * (tcFin - tcIni));

    if (Math.abs(diferencia) < 0.005) {
        return {
            calculable: true,
            sinDiferencia: true,
            errors: [],
            valorAjuste,
            valorAdquisicion,
            diferencia: 0,
            montoAsiento: 0,
            resultado: null,
            asiento: null,
        };
    }

    const resultado = diferencia > 0 ? 'INGRESO' : 'PERDIDA';
    const montoAsiento = round2(Math.abs(diferencia));

    const lineas =
        resultado === 'INGRESO'
            ? [
                  { detalle: CUENTA_REEXPRESION, debe: montoAsiento, haber: null },
                  { detalle: 'DIFERENCIA DE CAMBIO', debe: null, haber: montoAsiento },
              ]
            : [
                  { detalle: 'DIFERENCIA DE CAMBIO', debe: montoAsiento, haber: null },
                  { detalle: CUENTA_REEXPRESION, debe: null, haber: montoAsiento },
              ];

    return {
        calculable: true,
        sinDiferencia: false,
        errors: [],
        valorAjuste,
        valorAdquisicion,
        diferencia,
        montoAsiento,
        resultado,
        asiento: {
            titulo: 'ASIENTO X',
            fecha: fechaAjuste,
            lineas,
            totalDebe: montoAsiento,
            totalHaber: montoAsiento,
            cuadrado: true,
            glosa: 'POR ACTUALIZACION DE LA M/E',
        },
    };
}
