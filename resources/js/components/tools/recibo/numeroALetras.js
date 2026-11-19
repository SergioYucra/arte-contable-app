/**
 * Convierte un número en el rango 0.01 a 999,999,999.99 a letras en español (Title Case)
 * con centavos en formato `NN/100`.
 * Sin dependencias externas. La palabra "Bolivianos" se agrega en el recibo.
 */

const UNIDADES = [
    '',
    'Un',
    'Dos',
    'Tres',
    'Cuatro',
    'Cinco',
    'Seis',
    'Siete',
    'Ocho',
    'Nueve',
];

const ESPECIALES = [
    'Diez',
    'Once',
    'Doce',
    'Trece',
    'Catorce',
    'Quince',
    'Dieciséis',
    'Diecisiete',
    'Dieciocho',
    'Diecinueve',
];

const VEINTIS = [
    'Veinte',
    'Veintiún',
    'Veintidós',
    'Veintitrés',
    'Veinticuatro',
    'Veinticinco',
    'Veintiséis',
    'Veintisiete',
    'Veintiocho',
    'Veintinueve',
];

const DECENAS = [
    '',
    '',
    'Veinte',
    'Treinta',
    'Cuarenta',
    'Cincuenta',
    'Sesenta',
    'Setenta',
    'Ochenta',
    'Noventa',
];

const CENTENAS = [
    '',
    'Ciento',
    'Doscientos',
    'Trescientos',
    'Cuatrocientos',
    'Quinientos',
    'Seiscientos',
    'Setecientos',
    'Ochocientos',
    'Novecientos',
];

function convertirGrupo(n, esFinal = false) {
    if (n === 0) return '';
    if (n === 100) return 'Cien';

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
            if (resto === 21 && esFinal) {
                texto += 'Veintiuno';
            } else {
                texto += VEINTIS[resto - 20];
            }
        } else {
            texto += DECENAS[d];
            if (u > 0) {
                texto += ` Y ${UNIDADES[u]}`;
            }
        }
    }

    return texto;
}

function convertirEntero(entero) {
    if (entero === 0) return 'Cero';

    const millones = Math.floor(entero / 1000000);
    const miles = Math.floor((entero % 1000000) / 1000);
    const unidades = entero % 1000;

    const partes = [];

    if (millones > 0) {
        if (millones === 1) {
            partes.push('Un Millón');
        } else {
            partes.push(`${convertirGrupo(millones, false)} Millones`);
        }
    }

    if (miles > 0) {
        if (miles === 1) {
            partes.push('Mil');
        } else {
            partes.push(`${convertirGrupo(miles, false)} Mil`);
        }
    }

    if (unidades > 0) {
        partes.push(convertirGrupo(unidades, true));
    }

    return partes.join(' ').trim();
}

/**
 * Convierte un monto numérico a letras en Title Case con `NN/100`.
 * Ej: `5000` -> `"Cinco Mil 00/100"`, `1250.50` -> `"Mil Doscientos Cincuenta 50/100"`.
 *
 * @param {number|string} valor
 * @returns {string}
 */
export function numeroALetras(valor) {
    const num = Number(valor);
    if (!Number.isFinite(num) || num < 0) {
        return 'Cero 00/100';
    }

    const redondeado = Math.round((num + Number.EPSILON) * 100) / 100;
    const entero = Math.floor(redondeado);
    const centavos = Math.round((redondeado - entero) * 100);

    const textoEntero = convertirEntero(entero);
    const centavosStr = String(centavos).padStart(2, '0');

    return `${textoEntero} ${centavosStr}/100`;
}
