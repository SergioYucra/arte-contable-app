<script setup>
import { computed, reactive, shallowRef, useId, watch } from 'vue';
import BaseAlert from '../ui/BaseAlert.vue';
import CalculationSteps from './CalculationSteps.vue';
import MoneyField from './MoneyField.vue';
import SmnField from './SmnField.vue';
import parametros from './parametros.json';
import { calcularRcIva } from './rcIva';
import { bs, bsCompact, parseAmount, percent } from './format';

/**
 * Calculadora de RC-IVA para una persona con sueldo (un mes).
 * Calcula en el navegador con la misma fórmula que boliviaimpuestos.com (ver rcIva.js).
 * La abre el catálogo de Calculadoras (StudentCalculatorsCatalog) y recibe la tarjeta en `item`.
 */
defineProps({
    item: { type: Object, default: null },
});

const uid = useId();
const P = parametros.rcIva;
const salarios = parametros.salariosMinimos;

const form = reactive({
    ganado: '15.000',
    smn: String(salarios[0].monto),
    saldoFavor: '',
    facturas: '',
});

const optional = (text) => (text.trim() === '' ? 0 : parseAmount(text));

const values = computed(() => ({
    ganado: parseAmount(form.ganado),
    smn: parseAmount(form.smn),
    saldoFavor: optional(form.saldoFavor),
    facturas: optional(form.facturas),
}));

const errors = computed(() => {
    const e = {};
    if (form.ganado.trim() === '') e.ganado = 'Escribe el total ganado del mes.';
    else if (values.value.ganado === null) e.ganado = 'Escribe un monto válido, por ejemplo 15.000 o 15.000,50.';
    if (values.value.smn === null || values.value.smn < 1) e.smn = 'Escribe el salario mínimo, por ejemplo 3.300.';
    if (values.value.saldoFavor === null) e.saldoFavor = 'Escribe un monto válido o déjalo vacío.';
    if (values.value.facturas === null) e.facturas = 'Escribe un monto válido o déjalo vacío.';
    return e;
});

const fresh = computed(() => (Object.keys(errors.value).length ? null : { ...calcularRcIva(values.value, P), entradas: { ...values.value } }));

// Mientras un dato está a medio escribir, se sigue viendo el último cálculo válido (atenuado):
// borrar un monto para corregirlo no hace saltar la pantalla.
const lastGood = shallowRef(null);
watch(fresh, (r) => { if (r) lastGood.value = r; }, { immediate: true });
const result = computed(() => fresh.value ?? lastGood.value);
const stale = computed(() => !fresh.value);

const rate = percent(P.tasa);
const mniLabel = `${P.minimoNoImponibleSmn} salarios mínimos`;
const compLabel = P.compensacionSmn === 1 ? '1 salario mínimo' : `${P.compensacionSmn} salarios mínimos`;
// La norma que cita su página para este descuento ("1 SMN × 13% (DS 5383)").
const compNorma = P.compensacionNorma ? ` (${P.compensacionNorma})` : '';

// Cómo se reparte el total ganado: aportes, parte sin impuesto y parte que paga el 13%.
const split = computed(() => {
    const r = result.value;
    const g = r?.entradas.ganado;
    if (!r || !g) return [];
    const parts = [
        { key: 'afp', label: `Aporte a la AFP (${percent(P.aporteAfp)})`, value: r.afp, color: 'bg-secondary dark:bg-slate-300' },
        { key: 'ans', label: 'Aporte solidario', value: r.aporteSolidario, color: 'bg-gold' },
        { key: 'mni', label: `No paga impuesto (${mniLabel})`, value: Math.min(r.minimoNoImponible, Math.max(0, r.neto)), color: 'bg-emerald-500/70' },
        { key: 'base', label: `Paga el ${rate}`, value: r.base, color: 'bg-primary' },
    ];
    return parts.filter((p) => p.value > 0).map((p) => ({ ...p, width: `${(p.value / g) * 100}%` }));
});

const ledger = computed(() => {
    const r = result.value;
    if (!r) return [];
    const rows = [
        { label: `Impuesto: ${rate} de ${bs(r.base)}`, value: r.impuesto },
        { label: `Descuento automático: ${rate} de ${compLabel}${compNorma}`, value: -r.compensacion },
    ];
    if (r.saldoFavor > 0) rows.push({ label: 'Saldo a tu favor del mes pasado', value: -r.saldoFavor });
    if (r.creditoFacturas > 0) rows.push({ label: `Facturas: ${rate} de ${bs(r.entradas.facturas)}`, value: -r.creditoFacturas });
    return rows;
});

// Quién cubre el impuesto, nombrando solo lo que la persona escribió.
const credits = computed(() => {
    const r = result.value;
    const both = r && r.saldoFavor > 0 && r.creditoFacturas > 0;
    if (both) return { who: 'Tu saldo a favor y tus facturas', verb: 'cubren' };
    if (r && r.saldoFavor > 0) return { who: 'Tu saldo a favor', verb: 'cubre' };
    return { who: 'Tus facturas', verb: 'cubren' };
});

// Cuánto superan el saldo a favor y las facturas al impuesto del mes (su página no lo muestra:
// el saldo solo dice Bs 0). Es una resta de las filas de arriba; no cambia ningún monto.
const leftover = computed(() => {
    const r = result.value;
    if (!r || r.saldo > 0) return 0;
    return Math.max(0, r.saldoFavor + r.creditoFacturas - r.determinado);
});

const headline = computed(() => {
    const r = result.value;
    if (!r) return '';
    if (r.saldo > 0) return 'Este mes pagas de RC-IVA';
    return 'Este mes no pagas RC-IVA';
});

const explanation = computed(() => {
    const r = result.value;
    if (!r || r.saldo > 0) return '';
    if (r.base === 0) return `Después de los aportes, tu sueldo no pasa de ${mniLabel} (${bs(r.minimoNoImponible)}): no hay impuesto.`;
    if (r.impuesto <= r.compensacion) return `El descuento automático (${rate} de ${compLabel}) cubre todo el impuesto.`;
    return `${credits.value.who} ${credits.value.verb} todo el impuesto.`;
});

const steps = computed(() => {
    const r = result.value;
    if (!r) return [];
    const g = r.entradas.ganado;
    const s = [];
    s.push(['Aporte a la AFP: ', bs(g), ` × ${percent(P.aporteAfp)} = `, { strong: bs(r.afp) }, '.']);
    if (r.tramosSolidario.length) {
        const tramos = r.tramosSolidario.map((t) => `${bs(t.monto)} × ${percent(t.tasa)}`).join(' + ');
        s.push(['Aporte solidario (solo sobre lo que pasa de Bs 13.000): ', tramos, ' = ', { strong: bs(r.aporteSolidario) }, '.']);
    } else {
        s.push(['Aporte solidario: no corresponde, porque el total ganado no pasa de ', bs(P.aporteSolidario[0].desde), '.']);
    }
    s.push(['Sueldo después de aportes: ', bs(g), ' − ', bs(r.afp), ' − ', bs(r.aporteSolidario), ' = ', { strong: bs(r.neto) }, '.']);
    s.push(['Parte que paga impuesto: ', bs(r.neto), ` − ${mniLabel} (${bs(r.minimoNoImponible)}) = `, { strong: bs(r.base) }, `. El ${rate} es `, { strong: bs(r.impuesto) }, '.']);
    s.push([`Descuento automático${compNorma}: ${rate} de ${compLabel} = ${bs(r.compensacion)}. Queda `, { strong: bs(r.determinado) }, '.']);
    if (r.saldoFavor > 0 || r.creditoFacturas > 0) {
        const parts = [];
        if (r.saldoFavor > 0) parts.push(['tu saldo a favor (', bs(r.saldoFavor), ')']);
        if (r.creditoFacturas > 0) parts.push([`el ${rate} de tus facturas (`, bs(r.creditoFacturas), ')']);
        s.push([parts.length > 1 ? 'Se restan ' : 'Se resta ', ...parts.flatMap((p, i) => (i === 0 ? p : [' y ', ...p])), '.']);
    }
    s.push(
        r.saldo > 0
            ? ['Pagas ', { strong: bs(r.saldo) }, '. Con ', bs(r.facturasQueFaltan), ' más en facturas quedaría en cero.']
            : ['Los descuentos cubren el impuesto: pagas ', { strong: bs(0) }, '.'],
    );
    return s;
});
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-12 lg:gap-8">
        <!-- ============================ DATOS ============================ -->
        <form class="space-y-4 lg:col-span-5" novalidate @submit.prevent>
            <div>
                <h2 class="text-base font-semibold text-foreground">Tus datos del mes</h2>
                <p class="mt-0.5 text-xs text-muted">El resultado cambia mientras escribes.</p>
            </div>

            <MoneyField
                :id="`${uid}-ganado`"
                v-model="form.ganado"
                label="Total ganado en el mes"
                hint="Tu sueldo bruto, antes de cualquier descuento."
                :error="errors.ganado"
            />

            <SmnField :id="`${uid}-smn`" v-model="form.smn" :salarios="salarios" :error="errors.smn" />

            <MoneyField
                :id="`${uid}-saldo`"
                v-model="form.saldoFavor"
                label="Saldo a tu favor del mes pasado"
                hint="Si el mes pasado te sobró crédito, va aquí. Si no sabes, déjalo vacío."
                :error="errors.saldoFavor"
            />

            <MoneyField
                :id="`${uid}-facturas`"
                v-model="form.facturas"
                label="Facturas que presentas"
                :hint="`Suma de las facturas del Formulario 110. Cada Bs 100 en facturas te descuenta ${bsCompact(100 * P.tasa)}.`"
                :error="errors.facturas"
            />
        </form>

        <!-- ============================ RESULTADO ============================ -->
        <section class="space-y-4 lg:col-span-7" aria-label="Resultado">
            <BaseAlert v-if="stale" type="warning" title="Revisa los datos marcados">
                Corrige los campos en rojo. Mientras tanto ves el último cálculo válido, atenuado.
            </BaseAlert>

            <div v-if="result" class="space-y-4 motion-safe:transition-opacity" :class="stale && 'opacity-50'" :aria-hidden="stale ? 'true' : undefined">
                <div class="rounded-brand border border-border bg-paper p-5 sm:p-6" aria-live="polite">
                    <p class="text-sm text-muted">{{ headline }}</p>
                    <p
                        class="mt-1 font-serif text-4xl leading-none tracking-tight tabular-nums sm:text-5xl"
                        :class="result.saldo > 0 ? 'text-foreground' : 'text-emerald-700 dark:text-emerald-400'"
                    >
                        {{ bs(result.saldo) }}
                    </p>
                    <p
                        v-if="result.saldo > 0"
                        class="mt-3 inline-block rounded-lg bg-amber-500/15 px-3 py-1.5 text-sm leading-snug text-foreground"
                    >
                        Con <strong class="tabular-nums">{{ bs(result.facturasQueFaltan) }}</strong> más en facturas no pagarías nada.
                    </p>
                    <p v-else class="mt-3 text-sm leading-relaxed text-foreground">{{ explanation }}</p>

                    <!-- Cómo se reparte el sueldo -->
                    <div v-if="split.length" class="mt-6">
                        <p class="text-sm text-foreground">
                            Así se reparte tu sueldo de <span class="tabular-nums">{{ bs(result.entradas.ganado) }}</span>
                        </p>
                        <div
                            class="mt-2 flex h-4 overflow-hidden rounded-full bg-border/50"
                            role="img"
                            :aria-label="split.map((p) => `${p.label}: ${bs(p.value)}`).join('; ')"
                        >
                            <span
                                v-for="p in split"
                                :key="p.key"
                                class="h-full motion-safe:transition-[width] motion-safe:duration-500"
                                :class="p.color"
                                :style="{ width: p.width }"
                            ></span>
                        </div>
                        <dl class="mt-3 space-y-1.5 text-sm">
                            <div v-for="p in split" :key="p.key" class="flex items-baseline justify-between gap-3">
                                <dt class="inline-flex min-w-0 items-baseline gap-2 text-muted">
                                    <span class="h-2.5 w-2.5 shrink-0 translate-y-px rounded-sm" :class="p.color" aria-hidden="true"></span>
                                    <span>{{ p.label }}</span>
                                </dt>
                                <dd class="whitespace-nowrap tabular-nums text-foreground">{{ bs(p.value) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Del impuesto a lo que pagas -->
                <section class="rounded-brand border border-border bg-surface p-4 sm:p-5">
                    <h3 class="text-sm font-semibold text-foreground">Del impuesto a lo que pagas</h3>
                    <dl class="mt-3 divide-y divide-border/70 text-sm">
                        <div v-for="(row, i) in ledger" :key="i" class="flex items-baseline justify-between gap-4 py-2">
                            <dt class="text-muted">{{ row.label }}</dt>
                            <dd class="whitespace-nowrap tabular-nums" :class="row.value < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-foreground'">
                                {{ row.value < 0 ? `− ${bs(-row.value)}` : bs(row.value) }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pt-2.5">
                            <dt class="font-semibold text-foreground">Pagas este mes</dt>
                            <dd class="whitespace-nowrap font-semibold tabular-nums text-foreground">{{ bs(result.saldo) }}</dd>
                        </div>
                    </dl>
                    <p v-if="leftover > 0" class="mt-2 text-xs leading-relaxed text-muted">
                        {{ credits.who }} {{ credits.verb === 'cubren' ? 'superan' : 'supera' }} al impuesto por
                        <strong class="font-semibold text-foreground tabular-nums">{{ bs(leftover) }}</strong>: este mes no pagas nada.
                    </p>
                    <p v-else-if="result.determinado === 0 && result.impuesto > 0" class="mt-2 text-xs leading-relaxed text-muted">
                        El descuento automático es más grande que el impuesto: este mes no pagas nada.
                    </p>
                </section>

                <CalculationSteps :steps="steps" />

                <p class="text-xs leading-relaxed text-muted">
                    Usamos la misma fórmula que la
                    <a
                        :href="parametros.fuentes.rcIva"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 rounded"
                    >calculadora de RC-IVA de boliviaimpuestos.com</a>.
                    Es una guía para estudiar: antes de presentar tu declaración, confírmala con un contador.
                </p>
            </div>
        </section>
    </div>
</template>
