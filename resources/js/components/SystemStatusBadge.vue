<script setup>
import { ref } from 'vue';
import { Activity, ChevronDown, CircleCheck, Cpu, Database, RefreshCw } from 'lucide-vue-next';
import { useSystemStore } from '@/stores/system';

const props = defineProps({
    environment: { type: String, default: 'local' },
    label: { type: String, default: 'Sistema operativo' },
});

const system = useSystemStore();
const open = ref(false);
const spinning = ref(false);

function check() {
    spinning.value = true;
    system.runCheck();
    setTimeout(() => (spinning.value = false), 600);
}
</script>

<template>
    <div class="inline-flex flex-col rounded-xl border border-emerald-200 bg-white shadow-sm overflow-hidden min-w-[18rem]">
        <button
            type="button"
            class="flex items-center gap-3 px-4 py-3 text-left hover:bg-emerald-50/60 transition-colors"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="relative flex h-3 w-3">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span>
            </span>

            <span class="flex-1">
                <span class="block text-sm font-semibold text-gray-800">{{ props.label }}</span>
                <span class="block text-xs text-gray-500">Vue {{ system.vueVersion }} · Pinia activo</span>
            </span>

            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-emerald-700">
                {{ props.environment }}
            </span>

            <ChevronDown class="h-4 w-4 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" />
        </button>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div v-if="open" class="border-t border-gray-100 px-4 py-3 space-y-2 text-sm text-gray-600">
                <p class="flex items-center gap-2"><Cpu class="h-4 w-4 text-emerald-600" /> Vue 3 montado como isla sobre Blade</p>
                <p class="flex items-center gap-2"><Database class="h-4 w-4 text-emerald-600" /> Store Pinia compartido</p>
                <p class="flex items-center gap-2"><CircleCheck class="h-4 w-4 text-emerald-600" /> Iconos lucide-vue-next</p>

                <div class="flex items-center justify-between pt-2">
                    <span class="flex items-center gap-1 text-xs text-gray-500">
                        <Activity class="h-3.5 w-3.5" />
                        Chequeos: <strong class="text-gray-800">{{ system.checks }}</strong> · {{ system.uptimeLabel }}
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                        @click="check"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': spinning }" />
                        Verificar
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>
