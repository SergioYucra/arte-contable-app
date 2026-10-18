<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="bg-surface border border-border rounded-brand shadow-xs overflow-hidden">
        <!-- Header -->
        <div v-if="$slots.header || title" class="px-6 py-4 border-b border-border flex items-center justify-between gap-4">
            <slot name="header">
                <div>
                    <h3 class="text-base font-semibold text-foreground tracking-tight">{{ title }}</h3>
                    <p v-if="subtitle" class="text-xs text-muted mt-0.5">{{ subtitle }}</p>
                </div>
            </slot>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <!-- Body -->
        <div class="p-6">
            <slot />
        </div>

        <!-- Footer -->
        <div v-if="$slots.footer" class="px-6 py-3 bg-background border-t border-border">
            <slot name="footer" />
        </div>
    </div>
</template>
