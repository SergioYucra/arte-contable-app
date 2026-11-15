<script setup>
import { computed } from 'vue';
import { CheckCircle2, AlertCircle, AlertTriangle, Info, X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue', 'dismiss']);

function dismiss() {
    emit('update:modelValue', null);
    emit('dismiss');
}

const typeConfig = computed(() => {
    const type = props.modelValue?.type || 'success';
    switch (type) {
        case 'error':
            return {
                border: 'border-rose-500/20',
                bg: 'bg-surface/95 dark:bg-surface/95',
                icon: AlertCircle,
                iconColor: 'text-rose-500',
                badgeBg: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
            };
        case 'warning':
            return {
                border: 'border-amber-500/20',
                bg: 'bg-surface/95 dark:bg-surface/95',
                icon: AlertTriangle,
                iconColor: 'text-amber-500',
                badgeBg: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
            };
        case 'info':
            return {
                border: 'border-primary/20',
                bg: 'bg-surface/95 dark:bg-surface/95',
                icon: Info,
                iconColor: 'text-primary',
                badgeBg: 'bg-primary/10 text-primary',
            };
        case 'success':
        default:
            return {
                border: 'border-emerald-500/20',
                bg: 'bg-surface/95 dark:bg-surface/95',
                icon: CheckCircle2,
                iconColor: 'text-emerald-500',
                badgeBg: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
            };
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-x-3 bottom-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:bottom-5 sm:right-5 z-[60] pointer-events-none flex flex-col items-stretch sm:items-end gap-2 sm:max-w-sm sm:w-full"
            aria-live="polite"
        >
            <Transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-4 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-2 scale-95"
            >
                <div
                    v-if="modelValue"
                    class="pointer-events-auto w-full p-4 rounded-brand border shadow-xl backdrop-blur-md flex items-start gap-3 select-none"
                    :class="[typeConfig.bg, typeConfig.border]"
                    role="alert"
                >
                    <component
                        :is="typeConfig.icon"
                        class="h-5 w-5 shrink-0 mt-0.5"
                        :class="typeConfig.iconColor"
                    />

                    <div class="flex-1 min-w-0 pr-1">
                        <h4 class="text-sm font-semibold text-foreground tracking-tight leading-snug">
                            {{ modelValue.title }}
                        </h4>
                        <p v-if="modelValue.message" class="text-xs text-muted mt-0.5 leading-relaxed">
                            {{ modelValue.message }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="p-1 rounded-brand text-muted hover:text-foreground hover:bg-background/80 transition-colors -mr-1 -mt-1 shrink-0"
                        aria-label="Cerrar notificación"
                        @click="dismiss"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </Transition>
        </div>
    </Teleport>
</template>

