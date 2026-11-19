<script setup>
import { computed } from 'vue';
import { numeroALetras } from './numeroALetras.js';
import {
    derivarDatosRecibo,
    descomponerFecha,
    formatImporteRecibo,
} from './reciboHelpers.js';

const props = defineProps({
    empresa: {
        type: String,
        default: '',
    },
    fecha: {
        type: String,
        default: '',
    },
    tipo: {
        type: String,
        default: 'EGRESO',
    },
    numero: {
        type: [Number, String],
        default: '',
    },
    importe: {
        type: [Number, String],
        default: '',
    },
    receptor: {
        type: String,
        default: '',
    },
    concepto: {
        type: String,
        default: '',
    },
});

const fechaPartes = computed(() => descomponerFecha(props.fecha));
const derivado = computed(() => derivarDatosRecibo(props.tipo));

const importeValido = computed(() => {
    const n = Number(props.importe);
    return props.importe !== '' && props.importe !== null && Number.isFinite(n) && n > 0;
});

const importeNegativo = computed(() => {
    const n = Number(props.importe);
    return Number.isFinite(n) && n < 0;
});

const importeFormateado = computed(() => {
    if (!importeValido.value && !importeNegativo.value) return '0.00';
    return formatImporteRecibo(props.importe);
});

const importeEnLetras = computed(() => {
    if (!importeValido.value) return '';
    return `${numeroALetras(props.importe)} Bolivianos`;
});
</script>

<template>
    <div class="recibo-card">
        <!-- Fila superior: Fecha (3 casillas) + Empresa -->
        <div class="recibo-top-row">
            <div class="recibo-fecha-grid" aria-label="Fecha del recibo">
                <div class="recibo-fecha-cell">
                    <div class="recibo-fecha-val">{{ fechaPartes.dia }}</div>
                    <div class="recibo-fecha-lbl">DIA</div>
                </div>
                <div class="recibo-fecha-cell">
                    <div class="recibo-fecha-val">{{ fechaPartes.mes }}</div>
                    <div class="recibo-fecha-lbl">MES</div>
                </div>
                <div class="recibo-fecha-cell">
                    <div class="recibo-fecha-val">{{ fechaPartes.anio }}</div>
                    <div class="recibo-fecha-lbl">AÑO</div>
                </div>
            </div>

            <div class="recibo-empresa-top">
                <span :class="{ 'recibo-placeholder': !empresa.trim() }">
                    {{ empresa.trim() || '—' }}
                </span>
            </div>
        </div>

        <!-- Centro superior: N° y Título -->
        <div class="recibo-header-center">
            <div class="recibo-numero">
                N° {{ numero !== '' && numero !== null ? numero : '—' }}
            </div>
            <h2 class="recibo-titulo">
                {{ derivado.titulo }}
            </h2>
        </div>

        <!-- Monto -->
        <div class="recibo-monto-row">
            <span class="recibo-bs-label">Bs:</span>
            <span
                class="recibo-bs-valor"
                :class="{ 'recibo-negativo': importeNegativo }"
            >
                {{ importeFormateado }}
            </span>
        </div>

        <!-- Tres líneas de datos -->
        <div class="recibo-lineas">
            <div class="recibo-linea">
                <span class="recibo-linea-label">{{ derivado.primeraLineaLabel }}</span>
                <span class="recibo-linea-valor" :class="{ 'recibo-placeholder': !receptor.trim() }">
                    {{ receptor.trim() || '—' }}
                </span>
            </div>

            <div class="recibo-linea">
                <span class="recibo-linea-label">La suma de:</span>
                <span class="recibo-linea-valor" :class="{ 'recibo-placeholder': !importeEnLetras }">
                    {{ importeEnLetras || '—' }}
                </span>
            </div>

            <div class="recibo-linea">
                <span class="recibo-linea-label">Por concepto de:</span>
                <span class="recibo-linea-valor" :class="{ 'recibo-placeholder': !concepto.trim() }">
                    {{ concepto.trim() || '—' }}
                </span>
            </div>
        </div>

        <!-- Firmas -->
        <div class="recibo-firmas">
            <div class="recibo-firma-bloque">
                <div class="recibo-firma-nombre" :class="{ 'recibo-placeholder': !receptor.trim() }">
                    {{ receptor.trim() || '—' }}
                </div>
                <div class="recibo-firma-leyenda">
                    {{ derivado.firmaIzquierdaLeyenda }}
                </div>
            </div>

            <div class="recibo-firma-bloque">
                <div class="recibo-firma-nombre" :class="{ 'recibo-placeholder': !empresa.trim() }">
                    {{ empresa.trim() || '—' }}
                </div>
                <div class="recibo-firma-leyenda">
                    {{ derivado.firmaDerechaLeyenda }}
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.recibo-card {
    width: 600px;
    min-height: 230px;
    background: #ffffff;
    border: 3px solid #111827;
    color: #002060;
    font-family: Aptos, Inter, Calibri, system-ui, -apple-system, sans-serif;
    font-size: 13px;
    line-height: 1.25;
    padding-bottom: 10px;
    box-sizing: border-box;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.recibo-top-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
}

.recibo-fecha-grid {
    display: grid;
    grid-template-columns: repeat(3, 52px);
}

.recibo-fecha-cell {
    display: flex;
    flex-direction: column;
}

.recibo-fecha-val {
    background: #ffffff;
    color: #002060;
    font-style: italic;
    font-weight: 600;
    font-size: 12px;
    text-align: center;
    padding: 2px 4px;
    border-right: 1px solid #002060;
    border-bottom: 1px solid #002060;
}

.recibo-fecha-lbl {
    background: #002060;
    color: #ffffff;
    font-style: italic;
    font-weight: 700;
    font-size: 10px;
    text-align: center;
    padding: 2px 4px;
    border-right: 1px solid #ffffff;
}

.recibo-fecha-cell:last-child .recibo-fecha-lbl {
    border-right: 1px solid #002060;
}

.recibo-empresa-top {
    width: 50%;
    text-align: center;
    padding: 10px 12px 0;
    color: #002060;
    font-weight: 700;
    font-style: italic;
    font-size: 12px;
    word-break: break-word;
}

.recibo-header-center {
    text-align: center;
    margin-top: 2px;
}

.recibo-numero {
    font-size: 14pt;
    font-weight: 700;
    font-style: italic;
    color: #44546a;
    line-height: 1.15;
}

.recibo-titulo {
    font-size: 18pt;
    font-weight: 700;
    font-style: italic;
    color: #002060;
    line-height: 1.15;
    margin: 2px 0 0;
}

.recibo-monto-row {
    display: flex;
    justify-content: flex-end;
    align-items: baseline;
    gap: 24px;
    padding: 4px 28px 6px;
}

.recibo-bs-label {
    color: #000000;
    font-weight: 700;
    font-style: italic;
    font-size: 13px;
}

.recibo-bs-valor {
    color: #002060;
    font-weight: 700;
    font-style: italic;
    font-size: 12pt;
    font-variant-numeric: tabular-nums;
}

.recibo-negativo {
    color: #c00000;
}

.recibo-lineas {
    padding: 2px 10px 0 6px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.recibo-linea {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}

.recibo-linea-label {
    width: 124px;
    flex-shrink: 0;
    color: #002060;
    font-style: italic;
    font-size: 12.5px;
}

.recibo-linea-valor {
    flex: 1;
    min-width: 0;
    color: #002060;
    font-style: italic;
    font-size: 12.5px;
    padding-left: 8px;
    padding-bottom: 1px;
    border-bottom: 1px solid #002060;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.recibo-firmas {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 22px;
    padding: 0 18px;
    text-align: center;
}

.recibo-firma-bloque {
    min-width: 0;
}

.recibo-firma-nombre {
    color: #000000;
    font-weight: 700;
    font-style: italic;
    font-size: 12.5px;
    word-break: break-word;
}

.recibo-firma-leyenda {
    color: #002060;
    font-weight: 700;
    font-style: italic;
    font-size: 12.5px;
}

.recibo-placeholder {
    color: #94a3b8;
}
</style>
