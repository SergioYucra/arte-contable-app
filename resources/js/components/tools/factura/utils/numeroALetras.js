/**
 * Convierte un número decimal a su representación en letras en español (Bolivia)
 * con centavos en formato `XX/100 Bolivianos`.
 * Sin dependencias externas.
 */

const UNIDADES = [
    '',
    'un',
    'dos',
    'tres',
    'cuatro',
    'cinco',
    'seis',
    'siete',
    'ocho',
    'nueve',
];

const ESPECIALES = [
    'diez',
    'once',
    'doce',
    'trece',
    'catorce',
    'quince',
    'dieciséis',
    'diecisiete',
    'dieciocho',
    'diecinueve',
];

const VEINTIS = [
    'veinte',
    'veintiún',
    'veintidós',
    'veintitrés',
    'veinticuatro',
    'veinticinco',
    'veintiséis',
    'veintisiete',
    'veintiocho',
    'veintinueve',
];

const DECENAS = [
    '',
    '',
    'veinte',
    'treinta',
    'cuarenta',
    'cincuenta',
    'sesenta',
    'setenta',
    'ochenta',
    'noventa',
];

const CENTENAS = [
    '',
    'ciento',
    'doscientos',
    'trescientos',
    'cuatrocientos',
    'quinientos',
    'seiscientos',
    'setecientos',
    'ochocientos',
    'novecientos',
];

function convertirGrupo(n) {
    if (n === 0) return '';
    if (n === 100) return 'cien';

    const c = Math.floor(n / 100);
    const resto = n % 100;
    const d = Math.floor(resto / 10);
    const u = resto % 10;

    let texto = c > 0 ? CENTENAS[c] : '';

    if (resto > 0) {
        if (texto) texto += ' ';
        if (resto < 10) {
            texto += UNIDADES[resto];
        } else if (resto < 20) {
            texto += ESPECIALES[resto - 10];
        } else if (resto < 30) {
            texto += VEINTIS[resto - 20];
        } else {
            texto += DECENAS[d];
            if (u > 0) {
                texto += ` y ${UNIDADES[u]}`;
            }
        }
    }

    return texto;
}

function convertirEntero(entero) {
    if (entero === 0) return 'cero';

    const millones = Math.floor(entero / 1000000);
    const miles = Math.floor((entero % 1000000) / 1000);
    const unidades = entero % 1000;

    const partes = [];

    if (millones > 0) {
        if (millones === 1) {
            partes.push('un millón');
        } else {
            partes.push(`${convertirGrupo(millones)} millones`);
        }
    }

    if (miles > 0) {
        if (miles === 1) {
            partes.push('mil');
        } else {
            partes.push(`${convertirGrupo(miles)} mil`);
        }
    }

    if (unidades > 0) {
        let grupo = convertirGrupo(unidades);
        // Al final del número, 'un' / 'veintiún' se escribe 'uno' / 'veintiuno' si corresponde a unidad aislada.
        if (grupo === 'un') grupo = 'uno';
        else if (grupo.endsWith(' veintiún')) grupo = grupo.replace(/ veintiún$/, ' veintiuno');
        else if (grupo === 'veintiún') grupo = 'veintiuno';
        else if (grupo.endsWith(' y un')) grupo = grupo.replace(/ y un$/, ' y uno');
        partes.push(grupo);
    }

    return partes.join(' ').trim();
}

/**
 * Convierte un monto numérico a letras con formato boliviano.
 * Ej: `1250.50` -> `"Mil doscientos cincuenta 50/100 Bolivianos"`.
 *
 * @param {number|string} valor
 * @returns {string}
 */
export function numeroALetras(valor) {
    const num = Number(valor);
    if (!Number.isFinite(num) || num < 0) {
        return 'Cero 00/100 Bolivianos';
    }

    const redondeado = Math.round((num + Number.EPSILON) * 100) / 100;
    const entero = Math.floor(redondeado);
    const centavos = Math.round((redondeado - entero) * 100);

    const textoEntero = convertirEntero(entero);
    const capitalizado = textoEntero.charAt(0).toUpperCase() + textoEntero.slice(1);
    const centavosStr = String(centavos).padStart(2, '0');

    return `${capitalizado} ${centavosStr}/100 Bolivianos`;
}
