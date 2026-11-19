/**
 * Opciones del balance de apertura, tal como las ofrece
 * https://boliviaimpuestos.com/maletin/balance-apertura.php
 */

export const TIPOS_EMPRESA = [
    { valor: 'unipersonal', nombre: 'Unipersonal' },
    { valor: 'srl', nombre: 'S.R.L.' },
];

export const CIUDADES = [
    'Santa Cruz - Bolivia',
    'La Paz - Bolivia',
    'Cochabamba - Bolivia',
    'Sucre - Bolivia',
    'Potosí - Bolivia',
    'Oruro - Bolivia',
    'Tarija - Bolivia',
    'Beni - Bolivia',
    'Pando - Bolivia',
];

/** Grupos de activo fijo, en el orden en que aparecen en el balance. */
export const GRUPOS_ACTIVO_FIJO = [
    { clave: 'muebles', nombre: 'Muebles y Enseres' },
    { clave: 'equipos', nombre: 'Equipo de Computación' },
    { clave: 'herramientas', nombre: 'Herramientas' },
    { clave: 'otros', nombre: 'Otros Activos' },
];

/** Límites de la pantalla (no del cálculo). */
export const LIMITES = {
    itemsPorGrupo: 20,
    socios: 10,
    texto: 80,
    razonSocial: 120,
    nit: 15,
};
