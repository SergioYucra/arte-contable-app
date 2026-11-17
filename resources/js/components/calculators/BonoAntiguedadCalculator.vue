<script setup>
import { computed, reactive, shallowRef, useId, watch } from 'vue';
import BaseAlert from '../ui/BaseAlert.vue';
import CalculationSteps from './CalculationSteps.vue';
import MoneyField from './MoneyField.vue';
import SmnField from './SmnField.vue';
import parametros from './parametros.json';
import { addYears, calcularBono, parseIsoDate, tiempoHasta } from './bonoAntiguedad';
import { bs, bsCompact, longDate, parseAmount, parseWhole, todayIso } from './format';
import { errorText, field, hint, label } from '../subscriptions/shared';

/**
 * Calculadora del bono de antigüedad (DS 21060 art. 60) y su reliquidación.
 * Calcula en el navegador con la misma fórmula que boliviaimpuestos.com (ver bonoAntiguedad.js).
 * La abre el catálogo de Calculadoras (StudentCalculatorsCatalog) y recibe la tarjeta en `item`.
 */
defineProps({
    item: { type: Object, default: null },
});

const uid = useId();
const P = parametros.bonoAntiguedad;
const salarios = parametros.salariosMinimos;
const today = todayIso();

const form = reactive({
    modo: 'fecha',
    ingreso: addYears(today, -6),
    calculo: today,
    anios: '6',
    smn: String(salarios[0].monto),
    base: P.bases[0].clave,
    multiplicador: '',
    dias: String(P.diasDelMes),
    haber: '',
    smnAnterior: String(salarios[1].monto),
    // Igual que la página original: los meses que van del año (enero = 1).
    meses: String(Math.max(1, new Date().getMonth())),
});

const MIN_DATE = '1940-01-01';
const MAX_DATE = '2100-12-31';
const inRange = (iso) => parseIsoDate(iso) !== null && iso >= MIN_DATE && iso <= MAX_DATE;

const baseOption = computed(() => P.bases.find((b) => b.clave === form.base) ?? null);
const multiplicador = computed(() => (baseOption.value ? baseOption.value.salarios : parseAmount(form.multiplicador)));
const baseLabel = computed(() => {
    const m = multiplicador.value;
    return m === 1 ? '1 salario mínimo' : `${(m ?? 0).toLocaleString('es-BO')} salarios mínimos`;
});

const errors = computed(() => {
    const e = {};
    if (form.modo === 'fecha') {
        if (!inRange(form.ingreso)) e.ingreso = 'Elige la fecha de ingreso.';
        if (!inRange(form.calculo)) e.calculo = 'Elige la fecha del cálculo.';
        if (!e.ingreso && !e.calculo && form.ingreso > form.calculo) e.ingreso = 'La fecha de ingreso no puede ser posterior a la del cálculo.';
    } else if (parseWhole(form.anios, 0, 80) === null) {
        e.anios = 'Escribe los años cumplidos, de 0 a 80.';
    }
    const smn = parseAmount(form.smn);
    if (smn === null || smn < 1) e.smn = 'Escribe el salario mínimo, por ejemplo 3.300.';
    if (!baseOption.value && (multiplicador.value === null || multiplicador.value <= 0 || multiplicador.value > 10)) {
        e.multiplicador = 'Escribe cuántos salarios mínimos, por ejemplo 2,5 (hasta 10).';
    }
    if (parseWhole(form.dias, 0, P.diasDelMes) === null) e.dias = `Escribe los días trabajados, de 0 a ${P.diasDelMes}.`;
    if (form.haber.trim() !== '' && parseAmount(form.haber) === null) e.haber = 'Escribe un monto válido o déjalo vacío.';
    return e;
});

const retroErrors = computed(() => {
    const e = {};
    const smn = parseAmount(form.smnAnterior);
    if (smn === null || smn < 1) e.smnAnterior = 'Escribe el salario mínimo anterior, por ejemplo 2.750.';
    if (parseWhole(form.meses, 0, 120) === null) e.meses = 'Escribe los meses, de 0 a 120.';
    return e;
});

const fresh = computed(() => {
    if (Object.keys(errors.value).length) return null;
    const retroOk = Object.keys(retroErrors.value).length === 0;
    const entradas = {
        modo: form.modo,
        ingreso: form.ingreso,
        calculo: form.calculo,
        anios: parseWhole(form.anios, 0, 80) ?? 0,
        smn: parseAmount(form.smn),
        multiplicador: multiplicador.value,
        dias: parseWhole(form.dias, 0, P.diasDelMes),
        haber: form.haber.trim() === '' ? 0 : parseAmount(form.haber),
        smnAnterior: retroOk ? parseAmount(form.smnAnterior) : 0,
        meses: retroOk ? parseWhole(form.meses, 0, 120) : 0,
    };
    return { ...calcularBono(entradas, P), entradas, baseLabel: baseLabel.value, norma: baseOption.value?.norma ?? 'convenio' };
});

// Mientras un dato está a medio escribir, se sigue viendo el último cálculo válido (atenuado):
// borrar una fecha o un monto para corregirlo no hace saltar la pantalla.
const lastGood = shallowRef(null);
watch(fresh, (r) => { if (r) lastGood.value = r; }, { immediate: true });
const result = computed(() => fresh.value ?? lastGood.value);
const stale = computed(() => !fresh.value);

const tramoLabel = (t) => (t.hasta === null ? `${t.desde} años o más` : `${t.desde} a ${t.hasta} años`);
const shortTramo = (t) => (t.hasta === null ? `${t.desde}+` : `${t.desde}–${t.hasta}`);
const yearsWord = (n) => (n === 1 ? '1 año' : `${n} años`);

// La escala como escalera: cada peldaño es un tramo; el de la persona va resaltado.
const stairs = computed(() => {
    const r = result.value;
    if (!r) return [];
    const top = Math.max(...P.escala.map((t) => t.porcentaje));
    return P.escala.map((t, i) => ({
        ...t,
        short: shortTramo(t),
        monto: (r.baseBs * t.porcentaje) / 100,
        height: `${Math.max(10, (t.porcentaje / top) * 100)}%`,
        state: i < r.tramoIndice ? 'past' : i === r.tramoIndice ? 'current' : 'future',
    }));
});

const nextText = computed(() => {
    const r = result.value;
    const n = r?.proximo;
    if (!n) return '';
    const cuando = n.fecha
        ? `el ${longDate(n.fecha)} (${tiempoHasta(r.entradas.calculo, n.fecha)})`
        : `al cumplir ${yearsWord(n.tramo.desde)} (en ${yearsWord(n.faltan)})`;
    const que = r.tramo ? `Sube al ${n.tramo.porcentaje}%` : `Empieza con el ${n.tramo.porcentaje}%`;
    return `${que} ${cuando}: ${bs(n.bono)} al mes, ${bs(n.diferencia)} más que ahora.`;
});

const steps = computed(() => {
    const r = result.value;
    if (!r) return [];
    const s = [];
    const e = r.entradas;
    if (e.modo === 'fecha') {
        s.push(['Antigüedad: del ', longDate(e.ingreso), ' al ', longDate(e.calculo), ' van ', { strong: `${yearsWord(r.anios)} cumplidos` }, ' (solo cuentan los años completos).']);
    } else {
        s.push(['Antigüedad: ', { strong: `${yearsWord(r.anios)} cumplidos` }, '.']);
    }
    s.push(
        r.tramo
            ? ['En la escala del DS 21060 (art. 60), ', yearsWord(r.anios), ' cae en el tramo de ', tramoLabel(r.tramo), ': ', { strong: `${r.porcentaje}%` }, '.']
            : ['Con menos de 2 años no hay tramo en la escala: ', { strong: '0%' }, '.'],
    );
    s.push(['Base: ', bsCompact(e.smn), ' × ', r.baseLabel, ' = ', { strong: bsCompact(r.baseBs) }, ` (${r.norma}).`]);
    s.push(['Bono del mes completo: ', bsCompact(r.baseBs), ` × ${r.porcentaje}% = `, { strong: bs(r.bonoPleno) }, '.']);
    if (r.dias < P.diasDelMes) {
        s.push(['Por ', `${r.dias} días trabajados`, ': ', bs(r.bonoPleno), ` × ${r.dias} ÷ ${P.diasDelMes} = `, { strong: bs(r.bono) }, '.']);
    }
    if (r.totalGanado !== null) {
        s.push(['Total ganado: ', bs(r.haber), ' de haber básico + ', bs(r.bono), ' de bono = ', { strong: bs(r.totalGanado) }, '. El haber básico no cambia el bono.']);
    }
    return s;
});

const segment = (active) =>
    `rounded-md px-3 py-2 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 ${
        active ? 'bg-surface font-semibold text-primary shadow-xs' : 'text-muted hover:text-foreground'
    }`;
const wholeField = (error) => [field, 'tabular-nums', error && 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/30'];
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-12 lg:gap-8">
        <!-- ============================ DATOS ============================ -->
        <form class="space-y-4 lg:col-span-5" novalidate @submit.prevent>
            <div>
                <h2 class="text-base font-semibold text-foreground">Datos del trabajador</h2>
                <p class="mt-0.5 text-xs text-muted">El resultado cambia mientras escribes.</p>
            </div>

            <!-- Antigüedad -->
            <fieldset>
                <legend :class="label">¿Cómo cuentas la antigüedad?</legend>
                <div class="mt-1.5 grid grid-cols-2 gap-1 rounded-lg border border-border bg-background p-1">
                    <button type="button" :class="segment(form.modo === 'fecha')" :aria-pressed="form.modo === 'fecha'" @click="form.modo = 'fecha'">
                        Con la fecha de ingreso
                    </button>
                    <button type="button" :class="segment(form.modo === 'anios')" :aria-pressed="form.modo === 'anios'" @click="form.modo = 'anios'">
                        Ya sé los años
                    </button>
                </div>
            </fieldset>

            <div v-if="form.modo === 'fecha'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                <div>
                    <label :for="`${uid}-ingreso`" :class="label">Fecha de ingreso</label>
                    <input
                        :id="`${uid}-ingreso`"
                        v-model="form.ingreso"
                        type="date"
                        :min="MIN_DATE"
                        :max="MAX_DATE"
                        :class="wholeField(errors.ingreso)"
                        class="mt-1.5"
                        :aria-invalid="!!errors.ingreso"
                        :aria-describedby="errors.ingreso ? `${uid}-ingreso-error` : undefined"
                    />
                    <p v-if="errors.ingreso" :id="`${uid}-ingreso-error`" class="mt-1" :class="errorText">{{ errors.ingreso }}</p>
                </div>
                <div>
                    <label :for="`${uid}-calculo`" :class="label">Fecha del cálculo</label>
                    <input
                        :id="`${uid}-calculo`"
                        v-model="form.calculo"
                        type="date"
                        :min="MIN_DATE"
                        :max="MAX_DATE"
                        :class="wholeField(errors.calculo)"
                        class="mt-1.5"
                        :aria-invalid="!!errors.calculo"
                        :aria-describedby="errors.calculo ? `${uid}-calculo-error` : undefined"
                    />
                    <p v-if="errors.calculo" :id="`${uid}-calculo-error`" class="mt-1" :class="errorText">{{ errors.calculo }}</p>
                </div>
            </div>
            <div v-else>
                <label :for="`${uid}-anios`" :class="label">Años cumplidos</label>
                <input
                    :id="`${uid}-anios`"
                    v-model.trim="form.anios"
                    type="text"
                    inputmode="numeric"
                    maxlength="2"
                    autocomplete="off"
                    :class="wholeField(errors.anios)"
                    class="mt-1.5"
                    :aria-invalid="!!errors.anios"
                    :aria-describedby="`${uid}-anios-help`"
                />
                <p :id="`${uid}-anios-help`" class="mt-1" :class="errors.anios ? errorText : hint">
                    {{ errors.anios || 'Solo años completos de trabajo en la misma empresa.' }}
                </p>
            </div>

            <SmnField :id="`${uid}-smn`" v-model="form.smn" :salarios="salarios" :error="errors.smn" />

            <!-- Base de cálculo -->
            <fieldset>
                <legend :class="label">¿Sobre cuántos salarios mínimos se calcula?</legend>
                <div class="mt-1.5 space-y-2">
                    <label
                        v-for="b in P.bases"
                        :key="b.clave"
                        class="flex cursor-pointer items-start gap-3 rounded-brand border p-3 transition-colors"
                        :class="form.base === b.clave ? 'border-primary/50 bg-primary/5' : 'border-border hover:border-primary/30'"
                    >
                        <input v-model="form.base" type="radio" :name="`${uid}-base`" :value="b.clave" class="mt-0.5 border-border text-primary focus:ring-primary/30" />
                        <span class="text-sm">
                            <span class="font-medium text-foreground">{{ b.titulo }}</span>
                            <span class="block text-xs leading-relaxed text-muted">{{ b.detalle }} ({{ b.norma }})</span>
                        </span>
                    </label>
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-brand border p-3 transition-colors"
                        :class="form.base === 'otra' ? 'border-primary/50 bg-primary/5' : 'border-border hover:border-primary/30'"
                    >
                        <input v-model="form.base" type="radio" :name="`${uid}-base`" value="otra" class="mt-0.5 border-border text-primary focus:ring-primary/30" />
                        <span class="text-sm">
                            <span class="font-medium text-foreground">Otra cifra</span>
                            <span class="block text-xs leading-relaxed text-muted">Cuando un convenio fija otra base.</span>
                        </span>
                    </label>
                    <div v-if="form.base === 'otra'" class="ps-9">
                        <label :for="`${uid}-mult`" class="sr-only">Cuántos salarios mínimos</label>
                        <div class="flex items-center gap-2">
                            <input
                                :id="`${uid}-mult`"
                                v-model.trim="form.multiplicador"
                                type="text"
                                inputmode="decimal"
                                maxlength="5"
                                autocomplete="off"
                                placeholder="2,5"
                                :class="wholeField(errors.multiplicador)"
                                class="w-24"
                                :aria-invalid="!!errors.multiplicador"
                                :aria-describedby="errors.multiplicador ? `${uid}-mult-error` : undefined"
                            />
                            <span class="text-sm text-muted">salarios mínimos</span>
                        </div>
                        <p v-if="errors.multiplicador" :id="`${uid}-mult-error`" class="mt-1" :class="errorText">{{ errors.multiplicador }}</p>
                    </div>
                </div>
            </fieldset>

            <div>
                <label :for="`${uid}-dias`" :class="label">Días trabajados en el mes</label>
                <input
                    :id="`${uid}-dias`"
                    v-model.trim="form.dias"
                    type="text"
                    inputmode="numeric"
                    maxlength="2"
                    autocomplete="off"
                    :class="wholeField(errors.dias)"
                    class="mt-1.5 w-24"
                    :aria-invalid="!!errors.dias"
                    :aria-describedby="`${uid}-dias-help`"
                />
                <p :id="`${uid}-dias-help`" class="mt-1" :class="errors.dias ? errorText : hint">
                    {{ errors.dias || `Si trabajó el mes completo, ${P.diasDelMes}.` }}
                </p>
            </div>

            <MoneyField
                :id="`${uid}-haber`"
                v-model="form.haber"
                label="Haber básico (opcional)"
                hint="Si lo escribes, te mostramos el total ganado: haber básico más bono."
                :error="errors.haber"
            />
        </form>

        <!-- ============================ RESULTADO ============================ -->
        <section class="space-y-4 lg:col-span-7" aria-label="Resultado">
            <BaseAlert v-if="stale" type="warning" title="Revisa los datos marcados">
                Corrige los campos en rojo. Mientras tanto ves el último cálculo válido, atenuado.
            </BaseAlert>

            <div v-if="result" class="space-y-4">
                <div
                    class="space-y-4 motion-safe:transition-opacity"
                    :class="stale && 'opacity-50'"
                    :aria-hidden="stale ? 'true' : undefined"
                >
                    <div class="rounded-brand border border-border bg-paper p-5 sm:p-6">
                        <div aria-live="polite">
                            <p class="text-sm text-muted">
                                <template v-if="!result.tramo">Todavía no corresponde bono</template>
                                <template v-else-if="result.dias < P.diasDelMes">Bono de antigüedad por {{ result.dias }} días trabajados</template>
                                <template v-else>Bono de antigüedad del mes</template>
                            </p>
                            <p
                                class="mt-1 font-serif text-4xl leading-none tracking-tight tabular-nums sm:text-5xl"
                                :class="result.tramo ? 'text-foreground' : 'text-muted'"
                            >
                                {{ bs(result.bono) }}
                            </p>
                            <p class="mt-3 text-sm leading-relaxed text-foreground">
                                <template v-if="result.tramo">
                                    Con {{ yearsWord(result.anios) }} cumplidos le corresponde el {{ result.porcentaje }}% de {{ result.baseLabel }}
                                    <span class="whitespace-nowrap tabular-nums">({{ bsCompact(result.baseBs) }}).</span>
                                </template>
                                <template v-else>
                                    Lleva {{ yearsWord(result.anios) }}. El bono empieza a los 2 años de trabajo<template v-if="result.primerBono">:
                                        los cumple el {{ longDate(result.primerBono) }} ({{ tiempoHasta(result.entradas.calculo, result.primerBono) }})</template>.
                                </template>
                            </p>
                            <p v-if="result.totalGanado !== null" class="mt-1 text-sm text-muted">
                                Total ganado: <span class="tabular-nums text-foreground">{{ bs(result.totalGanado) }}</span>
                                (haber básico más bono).
                            </p>
                            <p v-else-if="result.tramo" class="mt-1 text-sm text-muted">
                                En un año, con el mes completo: <span class="tabular-nums text-foreground">{{ bs(result.bonoAnual) }}</span>.
                            </p>
                        </div>

                        <!-- Escalera de la escala -->
                        <div class="mt-6">
                            <p class="text-sm text-foreground">Años de trabajo y porcentaje que le toca</p>
                            <ol class="mt-3 flex h-40 items-end gap-1 sm:h-44 sm:gap-2" aria-label="Escala del bono de antigüedad">
                                <li
                                    v-for="s in stairs"
                                    :key="s.desde"
                                    class="flex h-full min-w-0 flex-1 flex-col items-center justify-end"
                                    :aria-current="s.state === 'current' ? 'step' : undefined"
                                >
                                    <span
                                        v-if="s.state === 'current'"
                                        class="mb-1 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-foreground sm:text-[11px]"
                                        aria-hidden="true"
                                    >Aquí</span>
                                    <span
                                        class="mb-1 text-[11px] font-semibold tabular-nums sm:text-xs"
                                        :class="s.state === 'current' ? 'text-primary' : 'text-muted'"
                                    >{{ s.porcentaje }}%</span>
                                    <span
                                        class="w-full rounded-t-md motion-safe:transition-[height] motion-safe:duration-500"
                                        :class="{
                                            'bg-primary': s.state === 'current',
                                            'bg-primary/30': s.state === 'past',
                                            'bg-border dark:bg-slate-700': s.state === 'future',
                                        }"
                                        :style="{ height: s.height }"
                                    ></span>
                                    <span class="mt-1.5 text-[10px] tabular-nums text-muted sm:text-[11px]">{{ s.short }}</span>
                                    <span class="sr-only">{{ tramoLabel(s) }}: {{ bs(s.monto) }} al mes</span>
                                </li>
                            </ol>
                            <p class="mt-2 text-xs text-muted">Años cumplidos en cada tramo, según el DS 21060 (art. 60).</p>
                        </div>
                    </div>

                    <!-- Próximo tramo -->
                    <section class="rounded-brand border border-border bg-surface p-4 sm:p-5">
                        <h3 class="text-sm font-semibold text-foreground">{{ result.proximo ? 'Próximo aumento' : 'Tramo más alto' }}</h3>
                        <p v-if="result.proximo" class="mt-1.5 text-sm leading-relaxed text-muted">{{ nextText }}</p>
                        <p v-else class="mt-1.5 text-sm leading-relaxed text-muted">
                            Ya está en el tramo más alto ({{ result.porcentaje }}%). Desde aquí el bono solo sube cuando sube el salario mínimo.
                        </p>
                        <div v-if="result.proximo?.avance !== null && result.proximo?.avance !== undefined" class="mt-4">
                            <div
                                class="h-2 overflow-hidden rounded-full bg-border/60"
                                role="progressbar"
                                :aria-valuenow="Math.round(result.proximo.avance)"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="Avance hacia el próximo tramo"
                            >
                                <div class="h-full rounded-full bg-primary motion-safe:transition-[width] motion-safe:duration-500" :style="{ width: `${result.proximo.avance}%` }"></div>
                            </div>
                            <div class="mt-1.5 flex justify-between gap-4 text-xs text-muted">
                                <span>{{ result.tramo ? `${result.porcentaje}%` : 'Sin bono' }} desde el {{ longDate(result.proximo.desde) }}</span>
                                <span class="text-end">{{ result.proximo.tramo.porcentaje }}% desde el {{ longDate(result.proximo.fecha) }}</span>
                            </div>
                        </div>
                    </section>

                    <CalculationSteps :steps="steps" />
                </div>

                <!-- Reliquidación (fuera del bloque atenuado: tiene sus propios campos) -->
                <details class="group rounded-brand border border-border bg-surface">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-brand p-4 text-sm font-semibold text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 sm:p-5 [&::-webkit-details-marker]:hidden"
                    >
                        ¿Subió el salario mínimo? Calcula el retroactivo
                        <svg class="h-4 w-4 shrink-0 text-muted transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                    </summary>
                    <div class="space-y-4 border-t border-border p-4 sm:p-5">
                        <p class="text-sm leading-relaxed text-muted">
                            Cuando el aumento del salario mínimo rige desde antes (por ejemplo, desde enero), se paga la diferencia del bono
                            por los meses ya trabajados.
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <SmnField
                                :id="`${uid}-smn-anterior`"
                                v-model="form.smnAnterior"
                                :salarios="salarios"
                                label="Salario mínimo anterior"
                                :error="retroErrors.smnAnterior"
                            />
                            <div>
                                <label :for="`${uid}-meses`" :class="label">Meses a reliquidar</label>
                                <input
                                    :id="`${uid}-meses`"
                                    v-model.trim="form.meses"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="3"
                                    autocomplete="off"
                                    :class="wholeField(retroErrors.meses)"
                                    class="mt-1.5 w-24"
                                    :aria-invalid="!!retroErrors.meses"
                                    :aria-describedby="`${uid}-meses-help`"
                                />
                                <p :id="`${uid}-meses-help`" class="mt-1" :class="retroErrors.meses ? errorText : hint">
                                    {{ retroErrors.meses || 'Meses desde que rige el nuevo salario mínimo.' }}
                                </p>
                            </div>
                        </div>

                        <dl v-if="!Object.keys(retroErrors).length" class="divide-y divide-border/70 text-sm">
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-muted">Bono con el salario mínimo anterior</dt>
                                <dd class="whitespace-nowrap tabular-nums text-foreground">{{ bs(result.reliquidacion.bonoAnterior) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-muted">Bono con el salario mínimo nuevo</dt>
                                <dd class="whitespace-nowrap tabular-nums text-foreground">{{ bs(result.bonoPleno) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <dt class="text-muted">Diferencia por mes</dt>
                                <dd class="whitespace-nowrap tabular-nums text-foreground">{{ bs(result.reliquidacion.diferenciaMensual) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 pt-2.5">
                                <dt class="font-semibold text-foreground">
                                    Retroactivo por {{ result.reliquidacion.meses === 1 ? '1 mes' : `${result.reliquidacion.meses} meses` }}
                                </dt>
                                <dd class="whitespace-nowrap font-serif text-2xl tabular-nums text-foreground">{{ bs(result.reliquidacion.retroactivo) }}</dd>
                            </div>
                        </dl>
                    </div>
                </details>

                <p class="text-xs leading-relaxed text-muted">
                    Usamos la misma fórmula que la
                    <a
                        :href="parametros.fuentes.bonoAntiguedad"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    >calculadora de bono de antigüedad de boliviaimpuestos.com</a>.
                    Es una guía para estudiar: para una planilla real, confírmala con un contador.
                </p>
            </div>
        </section>
    </div>
</template>
