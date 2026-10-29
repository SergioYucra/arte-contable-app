<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
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

const modalRef = ref(null);
const previouslyFocusedElement = ref(null);

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function getFocusableElements() {
    if (!modalRef.value) return [];
    return Array.from(modalRef.value.querySelectorAll(FOCUSABLE_SELECTOR))
        .filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
}

function close() {
    if (props.closeable) {
        emit('update:modelValue', false);
        emit('close');
    }
}

/**
 * UX-06: Focus Trap. Confinar el foco del teclado (Tab y Shift+Tab) dentro del modal
 * e impedir que navegue a elementos interactivos del fondo.
 */
function handleKeydown(e) {
    if (!props.modelValue) return;

    if (e.key === 'Escape' && props.closeable) {
        close();
        return;
    }

    if (e.key === 'Tab') {
        const focusables = getFocusableElements();
        if (focusables.length === 0) {
            e.preventDefault();
            return;
        }

        const firstElement = focusables[0];
        const lastElement = focusables[focusables.length - 1];

        if (e.shiftKey) {
            // Shift + Tab: Si está en el primer elemento o fuera del modal, redirigir al último
            if (document.activeElement === firstElement || !modalRef.value?.contains(document.activeElement)) {
                e.preventDefault();
                lastElement.focus();
            }
        } else {
            // Tab: Si está en el último elemento o fuera del modal, redirigir al primero
            if (document.activeElement === lastElement || !modalRef.value?.contains(document.activeElement)) {
                e.preventDefault();
                firstElement.focus();
            }
        }
    }
}

watch(
    () => props.modelValue,
    async (isOpen) => {
        if (isOpen) {
            previouslyFocusedElement.value = document.activeElement;
            document.body.classList.add('overflow-hidden');

            await nextTick();
            const focusables = getFocusableElements();
            if (focusables.length > 0) {
                // Enfocar automáticamente el primer elemento interactivo
                focusables[0].focus();
            } else if (modalRef.value) {
                modalRef.value.focus();
            }
        } else {
            document.body.classList.remove('overflow-hidden');
            if (previouslyFocusedElement.value && typeof previouslyFocusedElement.value.focus === 'function') {
                previouslyFocusedElement.value.focus();
            }
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
                :aria-label="title || 'Ventana emergente'"
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
                        ref="modalRef"
                        tabindex="-1"
                        class="w-full bg-surface border border-border rounded-brand shadow-2xl overflow-hidden flex flex-col focus:outline-none"
                        :class="maxWidthClass"
                    >
                        <!-- Header -->
                        <div v-if="$slots.header || title" class="flex items-center justify-between px-6 py-4 border-b border-border">
                            <slot name="header">
                                <h3 id="modal-title" class="text-base font-semibold text-foreground tracking-tight">{{ title }}</h3>
                            </slot>
                            <button
                                v-if="closeable"
                                type="button"
                                class="p-1 rounded-brand text-muted hover:text-foreground hover:bg-background transition-colors focus:outline-none focus:ring-2 focus:ring-primary/20"
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
