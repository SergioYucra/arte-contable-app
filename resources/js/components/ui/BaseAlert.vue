<script setup>
import { ref } from 'vue';
import { Info, CheckCircle2, AlertTriangle, AlertCircle, X } from 'lucide-vue-next';

const props = defineProps({
    type: {
        type: String,
        default: 'info',
        validator: (v) => ['info', 'success', 'warning', 'error'].includes(v),
    },
    title: {
        type: String,
        default: '',
    },
    dismissible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['dismiss']);
const isVisible = ref(true);

function dismiss() {
    isVisible.value = false;
    emit('dismiss');
}

const config = {
    info: {
        bg: 'bg-primary/10 border-primary/20 text-foreground',
        iconColor: 'text-primary',
        icon: Info,
    },
    success: {
        bg: 'bg-emerald-500/10 border-emerald-500/20 text-foreground',
        iconColor: 'text-emerald-600',
        icon: CheckCircle2,
    },
    warning: {
        bg: 'bg-amber-500/10 border-amber-500/20 text-foreground',
        iconColor: 'text-amber-600',
        icon: AlertTriangle,
    },
    error: {
        bg: 'bg-rose-500/10 border-rose-500/20 text-foreground',
        iconColor: 'text-rose-600',
        icon: AlertCircle,
    },
}[props.type];
</script>

<template>
    <div
        v-if="isVisible"
        class="flex items-start gap-3 p-4 rounded-brand border text-sm transition-all duration-150"
        :class="config.bg"
        role="alert"
    >
        <component :is="config.icon" class="h-5 w-5 flex-shrink-0 mt-0.5" :class="config.iconColor" />

        <div class="flex-1">
            <h4 v-if="title" class="font-semibold text-foreground tracking-tight mb-0.5">{{ title }}</h4>
            <div class="text-muted leading-relaxed">
                <slot />
            </div>
        </div>

        <button
            v-if="dismissible"
            type="button"
            class="p-1 rounded-brand text-muted hover:text-foreground transition-colors -mr-1 -mt-1"
            @click="dismiss"
            aria-label="Cerrar alerta"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
