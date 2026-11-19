/**
 * Reexporta los catálogos compartidos para mantener compatibilidad con ACTUALIZACION.
 */
export {
    ACTIVOS_FIJOS,
    ACTIVOS_INTANGIBLES,
    CUENTAS_PATRIMONIALES,
} from '../shared/catalogos.js';

export const DEFAULTS_ACTUALIZACION = {
    fechaInicial: '2025-01-01',
    fechaAjuste: '2025-12-31',
    activoFijo: {
        nombre: 'Muebles y enseres de oficina',
        valorLibros: 60000,
        acumulada: 25000,
    },
    activoIntangible: {
        nombre: 'Gastos de Organización',
        valorLibros: 15000,
        acumulada: 2600,
    },
    rubroPatrimonial: {
        cuenta: 'Capital',
        valorLibros: 80000,
    },
};
