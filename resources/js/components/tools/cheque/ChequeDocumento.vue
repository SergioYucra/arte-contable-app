<script setup>
import { computed } from 'vue';
import { numeroALetras } from '../recibo/numeroALetras.js';
import {
    formatImporteCheque,
    formatearFechaLargaEs,
} from './chequeHelpers.js';

const props = defineProps({
    empresa: { type: String, default: '' },
    departamento: { type: String, default: 'LA PAZ' },
    lugar: { type: String, default: '' },
    fecha: { type: String, default: '' },
    cuenta: { type: String, default: '' },
    numeroCheque: { type: String, default: '' },
    importe: { type: [Number, String], default: '' },
    receptor: { type: String, default: '' },
    direccion: { type: String, default: '' },
    lineaCodificacion: { type: String, default: '' },
    logoUrl: { type: String, default: '' },
    ocultarPlaceholderLogo: { type: Boolean, default: false },
    marcaModelo: { type: Boolean, default: true },
});

const fechaLarga = computed(() => formatearFechaLargaEs(props.fecha));

const lugarYFecha = computed(() => {
    const lugarStr = props.lugar.trim();
    if (!lugarStr && !fechaLarga.value) return '';
    if (lugarStr && fechaLarga.value) return `${lugarStr}, ${fechaLarga.value}`;
    return lugarStr || fechaLarga.value;
});

const importeValido = computed(() => {
    const n = Number(props.importe);
    return props.importe !== '' && props.importe !== null && Number.isFinite(n) && n > 0;
});

const importeFormateado = computed(() => {
    return importeValido.value ? formatImporteCheque(props.importe) : '0.00';
});

const importeEnLetras = computed(() => {
    if (!importeValido.value) return '';
    return numeroALetras(props.importe, { caso: 'upper' });
});

const letrasFontSize = computed(() => {
    const len = importeEnLetras.value.length;
    if (len > 85) return '6.5pt';
    if (len > 62) return '7pt';
    return '8pt';
});
</script>

<template>
    <div class="cheque-card">
        <!-- Marca de agua diagonal opcional -->
        <div v-if="marcaModelo" class="cheque-watermark" aria-hidden="true">
            MODELO – SIN VALOR
        </div>

        <!-- 1. Fila Empresa -->
        <div class="cheque-empresa-row">
            <span class="cheque-empresa-lbl">Empresa</span>
            <span class="cheque-empresa-val" :class="{ 'cheque-placeholder': !empresa.trim() }">
                {{ empresa.trim() || '—' }}
            </span>
        </div>

        <!-- 2. Fila de cuenta y número de cheque -->
        <div class="cheque-cuentas-row">
            <div class="cheque-cta-box">
                <span class="cheque-box-lbl">Cta. Cte.:</span>
                <span class="cheque-box-val" :class="{ 'cheque-placeholder': !cuenta.trim() }">
                    {{ cuenta.trim() || '—' }}
                </span>
            </div>

            <div class="cheque-num-box">
                <span class="cheque-box-lbl">Cheque N°</span>
                <span class="cheque-box-val" :class="{ 'cheque-placeholder': !numeroCheque.trim() }">
                    {{ numeroCheque.trim() || '—' }}
                </span>
            </div>
        </div>

        <!-- 3. Fila de fecha y monto -->
        <div class="cheque-fecha-monto-row">
            <div class="cheque-fecha-larga" :class="{ 'cheque-placeholder': !lugarYFecha }">
                {{ lugarYFecha || 'Lugar, fecha de emisión' }}
            </div>
            <div class="cheque-monto-box">
                <span class="cheque-bs-lbl">Bs.</span>
                <span class="cheque-bs-val">{{ importeFormateado }}</span>
            </div>
        </div>

        <!-- 4. Banda Páguese a la orden de -->
        <div class="cheque-orden-row">
            <div class="cheque-orden-box">
                <span class="cheque-orden-lbl">Páguese a la orden de:</span>
                <span class="cheque-orden-val" :class="{ 'cheque-placeholder': !receptor.trim() }">
                    {{ receptor.trim() || '—' }}
                </span>
            </div>
        </div>

        <!-- 5. Banda La suma de -->
        <div class="cheque-suma-band">
            <span class="cheque-suma-lbl">La suma de:</span>
            <span
                class="cheque-suma-val"
                :class="{ 'cheque-placeholder': !importeEnLetras }"
                :style="{ fontSize: letrasFontSize }"
            >
                {{ importeEnLetras || '—' }}
            </span>
            <span class="cheque-suma-moneda">bolivianos</span>
        </div>

        <!-- 6. Pie del cheque -->
        <div class="cheque-footer">
            <div class="cheque-footer-izq">
                <p>Oficina Central - {{ (departamento || 'LA PAZ').toUpperCase() }} BOLIVIA</p>
                <p v-if="direccion.trim()">{{ direccion.trim() }}</p>
            </div>

            <div class="cheque-footer-centro">
                <div
                    class="cheque-logo-box"
                    :class="{ 'cheque-logo-hide-print': !logoUrl && ocultarPlaceholderLogo }"
                >
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        alt="Logo del banco"
                        class="cheque-logo-img"
                    />
                    <span v-else class="cheque-logo-placeholder">LOGO BANCO</span>
                </div>
                <div v-if="lineaCodificacion.trim()" class="cheque-codificacion">
                    {{ lineaCodificacion.trim() }}
                </div>
            </div>

            <div class="cheque-footer-der">
                <div class="cheque-firma-linea">Firma</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cheque-card {
    position: relative;
    width: 680px;
    min-height: 250px;
    background: #ffffff;
    border: 1px solid #000000;
    color: #002060;
    font-family: Aptos, Inter, Calibri, system-ui, -apple-system, sans-serif;
    font-size: 12px;
    line-height: 1.25;
    padding: 4px 0 6px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.cheque-watermark {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 800;
    letter-spacing: 0.14em;
    color: rgba(0, 32, 96, 0.08);
    transform: rotate(-16deg);
    pointer-events: none;
    user-select: none;
    z-index: 1;
}

.cheque-empresa-row {
    display: flex;
    align-items: baseline;
    gap: 12px;
    padding: 2px 10px 4px;
    font-weight: 700;
    font-size: 12.5px;
    position: relative;
    z-index: 2;
}

.cheque-empresa-lbl {
    flex-shrink: 0;
}

.cheque-cuentas-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 0 10px 0 68px;
    position: relative;
    z-index: 2;
}

.cheque-cta-box {
    flex: 1;
    max-width: 355px;
    display: flex;
    align-items: center;
    border: 1px solid #000000;
    padding: 2px 8px;
    background: #ffffff;
}

.cheque-num-box {
    width: 170px;
    display: flex;
    align-items: center;
    border: 1px solid #000000;
    padding: 2px 8px;
    background: #ffffff;
}

.cheque-box-lbl {
    flex-shrink: 0;
    font-size: 11.5px;
}

.cheque-box-val {
    flex: 1;
    text-align: center;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    font-size: 12px;
}

.cheque-fecha-monto-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 14px 14px 68px;
    position: relative;
    z-index: 2;
}

.cheque-fecha-larga {
    flex: 1;
    text-align: center;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cheque-monto-box {
    width: 170px;
    flex-shrink: 0;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    padding-left: 16px;
}

.cheque-bs-lbl {
    font-weight: 700;
    font-size: 12.5px;
}

.cheque-bs-val {
    font-weight: 700;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.cheque-orden-row {
    padding-left: 68px;
    position: relative;
    z-index: 2;
}

.cheque-orden-box {
    display: flex;
    align-items: baseline;
    gap: 14px;
    border-top: 1px solid #000000;
    border-bottom: 1px solid #000000;
    border-left: 1px solid #000000;
    padding: 3px 10px;
    background: #ffffff;
}

.cheque-orden-lbl {
    width: 155px;
    text-align: right;
    flex-shrink: 0;
    font-size: 12px;
}

.cheque-orden-val {
    flex: 1;
    font-weight: 700;
    font-size: 12.5px;
    word-break: break-word;
}

.cheque-suma-band {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-top: 1px solid #000000;
    border-bottom: 1px solid #000000;
    padding: 3px 16px 3px 6px;
    background: #ffffff;
    position: relative;
    z-index: 2;
}

.cheque-suma-lbl {
    width: 130px;
    text-align: right;
    flex-shrink: 0;
    font-size: 12px;
}

.cheque-suma-val {
    flex: 1;
    font-weight: 700;
    line-height: 1.15;
    word-break: break-word;
}

.cheque-suma-moneda {
    flex-shrink: 0;
    font-size: 12px;
}

.cheque-footer {
    margin-top: auto;
    padding: 10px 24px 0 24px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 2;
}

.cheque-footer-izq {
    text-align: center;
    font-size: 7pt;
    line-height: 1.45;
}

.cheque-footer-centro {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}

.cheque-logo-box {
    width: 66px;
    height: 43px;
    border: 1px solid #94a3b8;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.cheque-logo-img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.cheque-logo-placeholder {
    font-size: 8px;
    color: #475569;
    font-weight: 600;
    text-align: center;
}

.cheque-codificacion {
    font-family: 'Courier New', Courier, monospace;
    font-size: 8pt;
    letter-spacing: 0.06em;
    color: #002060;
}

.cheque-footer-der {
    display: flex;
    justify-content: center;
    padding-top: 10px;
}

.cheque-firma-linea {
    width: 84px;
    border-top: 1px solid #000000;
    text-align: center;
    padding-top: 3px;
    font-weight: 700;
    font-size: 11.5px;
}

.cheque-placeholder {
    color: #94a3b8;
}

@media print {
    .cheque-logo-hide-print {
        border-color: transparent !important;
        background: transparent !important;
    }
    .cheque-logo-hide-print .cheque-logo-placeholder {
        display: none !important;
    }
}
</style>
