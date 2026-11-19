/**
 * Catálogos contables compartidos para las calculadoras (NC 3 y Depreciación/Amortización - Bolivia).
 * `vidaUtil: null` representa "Indefinido" (p. ej. Terrenos) para evitar errores aritméticos.
 */

export const ACTIVOS_FIJOS = [
    { nombre: 'Terrenos', vidaUtil: null, coeficiente: 0 },
    { nombre: 'Edificaciones', vidaUtil: 40, coeficiente: 0.025 },
    { nombre: 'Muebles y enseres de oficina', vidaUtil: 10, coeficiente: 0.1 },
    { nombre: 'Maquinaria en general', vidaUtil: 8, coeficiente: 0.125 },
    { nombre: 'Equipos e instalaciones', vidaUtil: 8, coeficiente: 0.125 },
    { nombre: 'Vehículos automotores', vidaUtil: 5, coeficiente: 0.2 },
    { nombre: 'Herramientas en general', vidaUtil: 4, coeficiente: 0.25 },
    { nombre: 'Equipos de computación', vidaUtil: 4, coeficiente: 0.25 },
];

export const ACTIVOS_INTANGIBLES = [
    { nombre: 'Gastos de Organización', vidaUtil: 4, coeficiente: 1 / 4 },
    { nombre: 'Marca de Fabrica', vidaUtil: 5, coeficiente: 1 / 5 },
    { nombre: 'Patentes', vidaUtil: 5, coeficiente: 1 / 5 },
    { nombre: 'Derechos de Autor', vidaUtil: 5, coeficiente: 1 / 5 },
    { nombre: 'Licencias y Software', vidaUtil: 10, coeficiente: 1 / 10 },
];

export const CUENTAS_PATRIMONIALES = [
    { cuenta: 'Capital', cuentaAjuste: 'Ajuste de Capital' },
    { cuenta: 'Ajuste de Capital', cuentaAjuste: 'Ajuste de Capital' },
    { cuenta: 'Aportes por Capitalizar', cuentaAjuste: 'Ajuste de Capital' },
    { cuenta: 'Reserva Legal', cuentaAjuste: 'Ajuste de Reservas Patrimoniales' },
    { cuenta: 'Reserva Estatutaria', cuentaAjuste: 'Ajuste de Reservas Patrimoniales' },
    { cuenta: 'Reserva por Revaluo Tecnico', cuentaAjuste: 'Ajuste de Reservas Patrimoniales' },
    { cuenta: 'Ajuste de Reservas Patrimoniales', cuentaAjuste: 'Ajuste de Reservas Patrimoniales' },
    { cuenta: 'Resultados Acumulados', cuentaAjuste: 'Resultados Acumulados' },
];
