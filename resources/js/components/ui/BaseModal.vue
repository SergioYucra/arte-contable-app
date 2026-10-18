<script setup>
import { watch, onMounted, onUnmounted } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    maxWidth: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg', 'xl', '2xl'].includes(v),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue', 'close']);

const maxWidthClass = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
}[props.maxWidth];

function close() {
    if (props.closeable) {
        emit('update:modelValue', false);
        emit('close');
    }
}

function handleKeydown(e) {
    if (e.key === 'Escape' && props.modelValue) {
        close();
    }
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            document.body.classList.add('overflow-hidden');
        } else {
            document.body.classList.remove('overflow-hidden');
        }
    }
);

onMounted(() => document.addEventListener('keydown', handleKeydown));
onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 overflow-y-auto bg-secondary/60 backdrop-blur-xs p-4 sm:p-6 flex items-center justify-center"
                @click.self="close"
                role="dialog"
                aria-modal="true"
            >
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0 scale-95 translate-y-2"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100 scale-100 translate-y-0"
                    leave-to-class="opacity-0 scale-95 translate-y-2"
                >
                    <div
                        v-if="modelValue"
                        class="w-full bg-surface border border-border rounded-brand shadow-2xl overflow-hidden flex flex-col"
                        :class="maxWidthClass"
                    >
                        <!-- Header -->
                        <div v-if="$slots.header || title" class="flex items-center justify-between px-6 py-4 border-b border-border">
                            <slot name="header">
                                <h3 class="text-base font-semibold text-foreground tracking-tight">{{ title }}</h3>
                            </slot>
                            <button
                                v-if="closeable"
                                type="button"
                                class="p-1 rounded-brand text-muted hover:text-foreground hover:bg-background transition-colors"
                                @click="close"
                                aria-label="Cerrar modal"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="px-6 py-5 text-sm text-foreground overflow-y-auto max-h-[75vh]">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <div v-if="$slots.footer" class="px-6 py-4 bg-background border-t border-border flex items-center justify-end gap-3">
                            <slot name="footer" :close="close" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
