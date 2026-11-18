<script setup>
/**
 * "Paso a paso" del cálculo. Cada paso es una lista de partes: texto suelto o { strong: '...' }
 * para resaltar un resultado. Sin v-html: todo se escapa.
 */
defineProps({
    steps: { type: Array, required: true },
    title: { type: String, default: 'Cómo se calcula, paso a paso' },
});
</script>

<template>
    <section class="rounded-brand border border-border bg-surface p-4 sm:p-5">
        <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
        <ol class="mt-3 space-y-2.5">
            <li v-for="(step, i) in steps" :key="i" class="flex gap-3 text-sm leading-relaxed text-muted">
                <span
                    class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold tabular-nums text-primary"
                    aria-hidden="true"
                >{{ i + 1 }}</span>
                <span>
                    <template v-for="(part, j) in step" :key="j">
                        <strong v-if="typeof part === 'object'" class="font-semibold text-foreground tabular-nums">{{ part.strong }}</strong>
                        <template v-else>{{ part }}</template>
                    </template>
                </span>
            </li>
        </ol>
    </section>
</template>
