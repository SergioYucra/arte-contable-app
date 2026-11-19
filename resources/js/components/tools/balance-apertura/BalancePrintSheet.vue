<script setup>
import { computed } from 'vue';
import { Calendar, Check, Info, Landmark } from 'lucide-vue-next';
import BalanceDocument from './BalanceDocument.vue';
import { GRUPOS_ACTIVO_FIJO, TIPOS_EMPRESA } from './catalogos';
import { porcentajeTexto } from './balanceApertura';
import { bs } from '../../calculators/shared/formatters';

/**
 * La hoja que sale al imprimir o guardar como PDF. Reproduce la impresión de
 * https://boliviaimpuestos.com/maletin/balance-apertura.php (misma estructura, medidas y colores):
 * datos de la empresa, socios, activos fijos, estado del cuadre, las cuatro cifras y el balance.
 * Solo se ve al imprimir; la pantalla de la herramienta tiene su propio diseño.
 */
const props = defineProps({
    form: { type: Object, required: true }, // lo que escribió la persona, tal cual
    balance: { type: Object, required: true }, // resultado de armarBalance()
    empresa: { type: Object, required: true },
});

const tipoNombre = computed(() => TIPOS_EMPRESA.find((t) => t.valor === props.form.tipo)?.nombre ?? '');
const fechaCorta = computed(() => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.form.fecha || '');
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
});
const subtotal = (clave) => props.balance.categorias.find((c) => c.clave === clave)?.subtotal ?? 0;

const stats = computed(() => [
    { k: 'Activo disponible (caja)', v: props.balance.caja, color: props.balance.caja < 0 ? '#e24b4a' : '#006ba6' },
    { k: 'Activo fijo', v: props.balance.activoFijo, color: '#C77E12' },
    { k: 'Total activo', v: props.balance.activo, color: '#2d3663' },
    { k: 'Patrimonio (capital)', v: props.balance.capital, color: '#1E9E6E' },
]);
</script>

<template>
    <div class="bi-hoja">
        <div class="cbar">
            <div class="crumb"><span>Herramientas</span><span class="sep">/</span><b>Documentos y contabilidad</b></div>
        </div>

        <div class="hero">
            <div class="hero__ic"><Landmark aria-hidden="true" /></div>
            <div class="hero__t">
                <div class="hero__row">
                    <h1>Balance de apertura</h1>
                    <span class="hero__cat">Documentos y contabilidad</span>
                </div>
                <p>Estructura contable requerida.</p>
            </div>
        </div>

        <div class="tool-grid">
            <!-- Datos de la empresa -->
            <div class="card">
                <div class="card__h">Datos de la empresa</div>
                <div class="card__b">
                    <div class="row2">
                        <div class="fld" style="flex: 1">
                            <span class="lbl">Tipo de empresa</span>
                            <div class="v2sel2 sel">{{ tipoNombre }}</div>
                        </div>
                        <div class="fld" style="flex: none; width: 150px">
                            <span class="lbl">Fecha del balance</span>
                            <div class="v2sel2 fecha">{{ fechaCorta }}<Calendar aria-hidden="true" /></div>
                        </div>
                    </div>
                    <div class="fld">
                        <span class="lbl">Nombre / razón social</span>
                        <div class="inbox"><span class="val" :class="!form.razonSocial && 'ph'">{{ form.razonSocial || 'Mi Empresa S.R.L.' }}</span></div>
                    </div>
                    <div class="row2">
                        <div class="fld" style="flex: 1">
                            <span class="lbl">NIT</span>
                            <div class="inbox"><span class="val" :class="!form.nit && 'ph'">{{ form.nit || '1023456789' }}</span></div>
                        </div>
                        <div class="fld" style="flex: 1">
                            <span class="lbl">Ciudad</span>
                            <div class="v2sel2 sel">{{ form.ciudad }}</div>
                        </div>
                    </div>
                    <div class="fld">
                        <span class="lbl">Capital total (Bs)</span>
                        <div class="inbox"><span class="bs">Bs</span><span class="val">{{ form.capital }}</span></div>
                    </div>

                    <div v-if="form.tipo === 'srl'">
                        <div class="card__h sub" style="padding: 12px 0 8px">Socios y aportes</div>
                        <div v-for="(socio, i) in form.socios" :key="socio.key" class="barow">
                            <span class="in" style="flex: 2" :class="!socio.nombre && 'ph'">{{ socio.nombre || `Nombre del socio ${i + 1}` }}</span>
                            <span class="in" style="flex: 1; max-width: 70px" :class="!socio.porcentaje && 'ph'">{{ socio.porcentaje || '%' }}</span>
                            <span class="soca">{{ bs(balance.socios[i]?.aporte ?? 0) }}</span>
                            <span class="badel">✕</span>
                        </div>
                        <span class="btn-ghost" style="margin-top: 8px; padding: 8px 14px">+ Agregar socio</span>
                        <div class="socwarn" :style="{ color: balance.porcentajesOk ? '#1E9E6E' : '#e24b4a' }">
                            {{ balance.porcentajesOk ? '✓ Aportes suman 100%' : `Los % suman ${porcentajeTexto(balance.sumaPorcentajes)} (deben sumar 100%)` }}
                        </div>
                    </div>

                    <div class="card__h sub" style="padding: 14px 0 8px">Activos fijos</div>
                    <div>
                        <div v-for="g in GRUPOS_ACTIVO_FIJO" :key="g.clave" class="bacat">
                            <div class="bacat__h">
                                <b>{{ g.nombre }}</b>
                                <span class="bacat__st">{{ bs(subtotal(g.clave)) }}</span>
                            </div>
                            <div v-for="row in form.activos[g.clave]" :key="row.key" class="barow">
                                <span class="in" style="flex: 2" :class="!row.descripcion && 'ph'">{{ row.descripcion || 'Descripción' }}</span>
                                <span class="in" style="flex: 1" :class="!row.cantidad && 'ph'">{{ row.cantidad || 'Cant.' }}</span>
                                <span class="in" style="flex: 1" :class="!row.precio && 'ph'">{{ row.precio || 'P. unit.' }}</span>
                                <span class="badel">✕</span>
                            </div>
                            <span class="btn-add">+ ítem</span>
                        </div>
                    </div>

                    <div class="note">
                        <Info aria-hidden="true" />
                        <span>La <b>Caja/Bancos</b> se calcula como capital − activos fijos, para que el <b>Activo iguale al Patrimonio</b> (capital) en la apertura.</span>
                    </div>
                </div>
            </div>

            <!-- Resultado -->
            <div class="rescol">
                <div class="reshero">
                    <div class="reshero__glow"></div>
                    <div class="reshero__lbl">
                        <span class="reshero__dot" :style="{ background: balance.cuadra ? '#57D08F' : '#e24b4a' }"></span>
                        <span>Estado del cuadre</span>
                    </div>
                    <div class="reshero__date">{{ balance.cuadra ? 'CUADRA' : bs(balance.activo - balance.capital) }}</div>
                    <div class="reshero__sub">
                        <span v-if="balance.cuadra" class="verdict verdict--yes"><Check aria-hidden="true" />Activo = Patrimonio = {{ bs(balance.capital) }}</span>
                        <span v-else-if="balance.activosSuperanCapital" class="verdict verdict--no">
                            Los activos fijos ({{ bs(balance.activoFijo) }}) superan el capital. Aumenta el capital.
                        </span>
                    </div>
                </div>

                <div class="stats">
                    <div v-for="s in stats" :key="s.k" class="stat">
                        <div class="stat__k">{{ s.k }}</div>
                        <div style="margin-top: 2px"><span class="stat__v" :style="{ color: s.color }">{{ bs(s.v) }}</span></div>
                    </div>
                </div>

                <div class="cal">
                    <div class="cal__t" style="margin-bottom: 12px">Balance de apertura</div>
                    <BalanceDocument :empresa="empresa" :balance="balance" />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Medidas y colores de la hoja impresa de boliviaimpuestos.com (su _v2.css, en el ancho de impresión). */
.bi-hoja {
    font-family: 'Niramit', ui-sans-serif, system-ui, sans-serif;
    font-variant-ligatures: none;
    font-size: 16px;
    color: #37455c;
    background: #fff;
    padding: 0 6mm;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.cbar { padding: 0 0 10px; }
.crumb { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #98a2b2; }
.crumb .sep { color: #c4ccd8; }
.crumb b { color: #5b6b82; font-weight: 600; }

.hero { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 22px; }
.hero__ic { width: 58px; height: 58px; border-radius: 14px; background: #e6f0f8; display: flex; align-items: center; justify-content: center; flex: none; color: #006ba6; }
.hero__ic svg { width: 28px; height: 28px; }
.hero__t { min-width: 0; flex: 1; }
.hero__row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.hero h1 { margin: 0; font-size: 30px; font-weight: 700; letter-spacing: -0.6px; line-height: 1.15; color: #16305a; }
.hero__cat { font-size: 12.5px; font-weight: 700; color: #006ba6; background: #e6f0f8; padding: 3px 9px; border-radius: 7px; }
.hero p { margin: 7px 0 0; font-family: 'Lora', Georgia, serif; font-size: 16px; color: #5b6b82; line-height: 1.55; }

.tool-grid { display: grid; grid-template-columns: 1fr; gap: 20px; align-items: start; }
.card { background: #fff; border: 1px solid #e6ecf4; border-radius: 14px; overflow: hidden; break-inside: avoid; }
.card__h { padding: 16px 20px; border-bottom: 1px solid #eef2f7; font-size: 16px; font-weight: 700; color: #16305a; }
.card__h.sub { border: 0; }
.card__b { padding: 20px; display: flex; flex-direction: column; gap: 18px; }
.fld .lbl { display: block; font-size: 13px; font-weight: 700; color: #3a475c; margin-bottom: 7px; }
.row2 { display: flex; gap: 14px; }
.inbox { display: flex; align-items: center; gap: 9px; height: 46px; padding: 0 13px; border: 1px solid #e1e7f0; border-radius: 10px; background: #fff; }
.inbox .val { font-size: 16px; font-weight: 600; color: #16305a; letter-spacing: 0.4px; font-variant-numeric: tabular-nums; }
.inbox .bs { color: #98a2b2; font-weight: 600; }
.ph { color: #98a2b2 !important; font-weight: 400 !important; }
.v2sel2 { display: flex; align-items: center; width: 100%; height: 46px; padding: 0 12px; border: 1px solid #e1e7f0; border-radius: 10px; background: #fff; font-size: 15px; color: #16305a; box-sizing: border-box; }
.v2sel2.sel { padding-right: 30px; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none'><path d='M3 4.5l3 3 3-3' stroke='%238593AC' stroke-width='1.6' stroke-linecap='round'/></svg>"); background-repeat: no-repeat; background-position: right 12px center; }
.v2sel2.fecha { justify-content: space-between; padding: 0 10px; }
.v2sel2.fecha svg { width: 15px; height: 15px; color: #16305a; }

.barow { display: flex; gap: 6px; margin-bottom: 5px; align-items: center; }
.barow .in { display: block; min-width: 0; border: 1px solid #e6ecf4; border-radius: 7px; padding: 7px 9px; font-size: 13px; background: #fff; color: #16305a; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.soca { flex: 1; font-size: 12px; color: #5b6b82; text-align: right; }
.badel { color: #98a2b2; font-size: 14px; padding: 0 4px; }
.btn-ghost { display: inline-block; font-weight: 600; font-size: 13.5px; color: #006ba6; background: #fff; border: 1px solid #e6ecf4; border-radius: 10px; }
.socwarn { font-size: 12px; margin-top: 6px; }
.bacat { border: 1px solid #eef2f7; border-radius: 10px; padding: 11px 12px; margin-bottom: 10px; background: #f3f6fa; break-inside: avoid; }
.bacat__h { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.bacat__h b { font-size: 13px; color: #2d3663; }
.bacat__st { font-size: 12px; color: #006ba6; font-weight: 600; }
.btn-add { display: inline-block; background: #e6f0f8; color: #006ba6; border: 1px dashed #9dc4e4; border-radius: 8px; padding: 5px 12px; font-size: 12px; font-weight: 600; margin-top: 2px; }
.note { display: flex; align-items: flex-start; gap: 9px; padding: 12px 14px; background: #f5f8fc; border-radius: 10px; border: 1px solid #ebf0f7; font-size: 13.5px; color: #6b7789; line-height: 1.5; }
.note svg { width: 15px; height: 15px; flex: none; margin-top: 1px; color: #98a2b2; }
.note b { color: #3a475c; }

.rescol { display: flex; flex-direction: column; gap: 20px; }
.reshero { background: linear-gradient(135deg, #2d3663, #3c4a86); border-radius: 14px; padding: 24px; color: #eaf1fa; position: relative; overflow: hidden; break-inside: avoid; }
.reshero__glow { position: absolute; right: -30px; top: -30px; width: 150px; height: 150px; border-radius: 50%; background: radial-gradient(circle, rgba(46, 139, 230, 0.5), transparent 70%); }
.reshero__lbl { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #9fb4d4; font-weight: 600; position: relative; }
.reshero__dot { width: 7px; height: 7px; border-radius: 50%; box-shadow: 0 0 0 3px rgba(87, 208, 143, 0.2); }
.reshero__date { font-size: 38px; font-weight: 800; letter-spacing: -1px; line-height: 1.05; margin-top: 10px; color: #fff; position: relative; font-variant-numeric: tabular-nums; }
.reshero__sub { font-size: 15.5px; color: #b9c9df; margin-top: 8px; position: relative; }
.verdict { display: inline-flex; align-items: center; gap: 8px; padding: 9px 15px; border-radius: 11px; font-weight: 800; font-size: 15px; max-width: 100%; }
.verdict svg { width: 18px; height: 18px; }
.verdict--yes { background: #e5f4ee; color: #12805a; }
.verdict--no { background: #fdece0; color: #b4520e; }
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.stat { background: #fff; border: 1px solid #e6ecf4; border-radius: 12px; padding: 14px; break-inside: avoid; }
.stat__k { font-size: 12.5px; color: #98a2b2; font-weight: 600; line-height: 1.3; min-height: 30px; }
.stat__v { font-size: 15px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
.cal { background: #fff; border: 1px solid #e6ecf4; border-radius: 14px; padding: 18px 20px 20px; break-inside: avoid; }
.cal__t { font-size: 15px; font-weight: 700; color: #16305a; }
</style>
