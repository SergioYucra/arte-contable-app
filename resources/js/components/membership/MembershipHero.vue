<script setup>
import { computed } from 'vue';
import { ArrowDown, BookOpen, Check, KeyRound } from 'lucide-vue-next';

/**
 * Cabecera de la página de membresía: responde "¿puedo leer todo y hasta cuándo?".
 * Sigue el estilo del Inicio del estudiante (dashboard.blade.php): verde petróleo cuando
 * la membresía está activa, el azul de la Biblioteca cuando todavía no lo está.
 */
const props = defineProps({
    membership: { type: Object, required: true },
    hasPending: { type: Boolean, default: false },
    libraryUrl: { type: String, required: true },
});

defineEmits(['plans', 'redeem']);

const state = computed(() => {
    const m = props.membership;
    if (m.is_active) return m.expiring_soon ? 'expiring' : 'active';
    if (props.hasPending) return 'pending';
    if (m.previous?.state === 'expired') return 'expired';
    if (m.previous?.state === 'revoked') return 'retired';
    return 'sample';
});

const isActive = computed(() => props.membership.is_active);
const days = computed(() => Number(props.membership.days_remaining) || 0);

const view = computed(() => {
    const m = props.membership;

    switch (state.value) {
        case 'active':
            return {
                eyebrow: 'Membresía activa',
                title: `Puedes leer todo hasta el ${m.expires_at}.`,
                text: 'Todos los libros están abiertos completos, junto con tu cuaderno de apuntes.',
                actions: ['library', 'plans', 'redeem'],
            };
        case 'expiring':
            return {
                eyebrow: 'Tu membresía vence pronto',
                title: days.value === 1 ? 'Te queda 1 día de lectura completa.' : `Te quedan ${days.value} días de lectura completa.`,
                text: `Vence el ${m.expires_at}. Si sumas días ahora, se agregan a los que ya tienes: no pierdes ninguno.`,
                actions: ['plans', 'library', 'redeem'],
            };
        case 'pending':
            return {
                eyebrow: 'Pago en revisión',
                title: 'Recibimos tu comprobante.',
                text: 'Apenas se apruebe se abren todos los libros. Puedes dejar esta página abierta o volver más tarde: no hace falta recargarla.',
                actions: ['library', 'redeem'],
            };
        case 'expired':
            return {
                eyebrow: 'Membresía vencida',
                title: `Tu acceso terminó el ${m.previous.until}.`,
                text: 'Tus apuntes y tu avance siguen guardados. Elige un plan o usa un código para volver a leer todo.',
                actions: ['plans', 'redeem'],
            };
        case 'retired':
            return {
                eyebrow: 'Acceso retirado',
                title: 'La administración retiró tu acceso.',
                text: 'Tus apuntes y tu avance siguen guardados. Si crees que es un error, escribe a la administración.',
                actions: ['plans', 'redeem'],
            };
        default:
            return {
                eyebrow: 'Muestra gratis',
                title: 'Lee todos los libros completos.',
                text: 'Hoy puedes leer las primeras páginas de cada libro. Con la membresía se abren enteros.',
                actions: ['plans', 'redeem'],
            };
    }
});

const actionLabels = computed(() => ({
    library: state.value === 'pending' ? 'Seguir con la muestra' : 'Ir a mis libros',
    plans: isActive.value ? 'Sumar más días' : 'Ver planes',
    redeem: 'Tengo un código',
}));

const needsAttention = computed(() => state.value === 'expiring' || state.value === 'pending');

const background = computed(() =>
    isActive.value
        ? 'radial-gradient(circle at 96% 2%, rgba(61, 153, 145, 0.26), transparent 36%), linear-gradient(118deg, #102f43 0%, #155767 68%, #176e6c 100%)'
        : 'radial-gradient(circle at 96% 2%, rgba(56, 132, 176, 0.24), transparent 36%), linear-gradient(112deg, #0f2335 0%, #143c56 62%, #175c80 100%)',
);

// Línea del periodo: el tramo iluminado es lo que queda por delante; el punto marca hoy.
const remainingPercent = computed(() => {
    const total = Math.max(days.value, Number(props.membership.total_days) || days.value || 1);
    return Math.min(100, Math.max(4, Math.round((days.value / total) * 100)));
});
const todayPercent = computed(() => Math.min(94, Math.max(6, 100 - remainingPercent.value)));

const included = [
    'Todos los libros, completos',
    'Tu cuaderno de apuntes en cada libro',
    'Un solo pago: no se cobra solo cada mes',
];

const primaryAction =
    'inline-flex min-h-[42px] sm:min-h-[38px] items-center justify-center gap-2 rounded-[7px] border border-[#a5e3c9] bg-[#a9e5cd] px-4 text-xs font-bold text-[#173f46] transition-colors hover:bg-[#c2f0dd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#143c56]';
const secondaryAction =
    'inline-flex min-h-[42px] sm:min-h-[38px] items-center justify-center gap-2 rounded-[7px] border border-white/25 bg-white/10 px-3 sm:px-4 text-xs font-semibold text-[#edf5f1] transition-colors hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#143c56]';

// La primera acción es la principal. Con solo dos, la segunda también ocupa todo el ancho en el celular.
function actionClass(index) {
    const wide = index === 0 || view.value.actions.length === 2;
    return [index === 0 ? primaryAction : secondaryAction, wide ? 'col-span-2' : ''];
}

// En el celular las acciones secundarias van sin icono para que el texto quepa en media fila.
function iconClass(index) {
    return index === 0 || view.value.actions.length === 2 ? '' : 'hidden sm:block';
}
</script>

<template>
    <section
        aria-labelledby="membership-hero-title"
        class="relative overflow-hidden rounded-2xl border p-6 sm:p-8 lg:p-9 text-[#f2f8f5] shadow-[0_13px_30px_rgba(18,59,77,0.12)]"
        :class="isActive ? 'border-[#174b58]' : 'border-[#1d5273]'"
        :style="{ background }"
    >
        <div class="pointer-events-none absolute -top-24 right-[26%] h-64 w-64 rounded-full border border-white/10" aria-hidden="true"></div>

        <div class="relative z-10 grid grid-cols-1 lg:grid-cols-[minmax(0,1.15fr)_minmax(270px,0.85fr)] items-center gap-7 lg:gap-12">
            <div class="max-w-[590px]">
                <p class="inline-flex items-center gap-2 font-mono text-[10px] font-medium tracking-[0.1em] uppercase text-[#a7d8c8]">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="needsAttention ? 'bg-[#f5c26b] shadow-[0_0_0_4px_rgba(245,194,107,0.14)]' : 'bg-[#8be0b9] shadow-[0_0_0_4px_rgba(139,224,185,0.12)]'"
                        aria-hidden="true"
                    ></span>
                    {{ view.eyebrow }}
                </p>

                <h2
                    id="membership-hero-title"
                    class="mt-3.5 max-w-[520px] font-serif text-[27px] sm:text-[34px] font-medium leading-[1.1] tracking-[-0.015em] text-[#f5fbf8] text-balance"
                >
                    {{ view.title }}
                </h2>

                <p class="mt-2.5 max-w-[480px] text-[13px] sm:text-sm leading-relaxed text-[#c6dedf]">
                    {{ view.text }}
                </p>

                <!-- En el celular: la acción principal a todo el ancho y las otras dos lado a lado. -->
                <div class="mt-5 grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap sm:items-center sm:gap-3">
                    <template v-for="(action, index) in view.actions" :key="action">
                        <a
                            v-if="action === 'library'"
                            :href="libraryUrl"
                            :class="actionClass(index)"
                        >
                            <BookOpen class="h-4 w-4" :class="iconClass(index)" aria-hidden="true" />
                            {{ actionLabels.library }}
                        </a>
                        <button
                            v-else-if="action === 'plans'"
                            type="button"
                            :class="actionClass(index)"
                            @click="$emit('plans')"
                        >
                            {{ actionLabels.plans }}
                            <ArrowDown class="h-4 w-4" :class="iconClass(index)" aria-hidden="true" />
                        </button>
                        <button
                            v-else
                            type="button"
                            :class="actionClass(index)"
                            @click="$emit('redeem')"
                        >
                            <KeyRound class="h-4 w-4" :class="iconClass(index)" aria-hidden="true" />
                            {{ actionLabels.redeem }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- Con membresía: cuánto queda del periodo -->
            <div v-if="isActive" class="rounded-xl border border-white/15 bg-white/[0.06] p-4 sm:p-5">
                <p class="text-xs text-[#c7dddc]">{{ membership.plan_name }}</p>
                <p class="mt-1.5 flex flex-wrap items-baseline gap-x-2 text-[#f5fbf8]">
                    <span class="font-serif text-[34px] font-medium leading-none tracking-[-0.02em] tabular-nums">{{ days }}</span>
                    <span class="text-sm">{{ days === 1 ? 'día por delante' : 'días por delante' }}</span>
                </p>

                <div
                    class="relative mt-8 h-1.5 w-full rounded-full bg-white/15"
                    role="img"
                    :aria-label="`Tu periodo va del ${membership.starts_at} al ${membership.expires_at}. Te quedan ${days} de ${membership.total_days} días.`"
                >
                    <div
                        class="absolute inset-y-0 right-0 rounded-full"
                        :class="state === 'expiring' ? 'bg-[#f5c26b]' : 'bg-[#80d6b0]'"
                        :style="{ width: `${remainingPercent}%` }"
                    ></div>
                    <span
                        class="absolute -top-[22px] -translate-x-1/2 text-[11px] font-semibold text-[#f5fbf8]"
                        :style="{ left: `${todayPercent}%` }"
                        aria-hidden="true"
                    >Hoy</span>
                    <span
                        class="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[#12384a] bg-white"
                        :style="{ left: `${todayPercent}%` }"
                        aria-hidden="true"
                    ></span>
                </div>

                <dl class="mt-3 flex items-start justify-between gap-4 text-[11px] leading-snug text-[#c7dddc]">
                    <div>
                        <dt>Desde</dt>
                        <dd class="font-semibold text-[#edf5f1]">{{ membership.starts_at }}</dd>
                    </div>
                    <div class="text-right">
                        <dt>Hasta</dt>
                        <dd class="font-semibold text-[#edf5f1]">{{ membership.expires_at }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Sin membresía: qué se abre al tenerla -->
            <div v-else class="rounded-xl border border-white/15 bg-white/[0.06] p-4 sm:p-5">
                <p class="text-xs font-semibold text-[#edf5f1]">Con la membresía</p>
                <ul class="mt-3 space-y-2.5">
                    <li v-for="item in included" :key="item" class="flex items-start gap-2.5 text-[13px] leading-snug text-[#dcebea]">
                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-[#8be0b9]" aria-hidden="true" />
                        {{ item }}
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
