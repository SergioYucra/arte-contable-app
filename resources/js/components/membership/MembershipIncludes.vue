<script setup>
import { BookOpen, Bookmark, BookPlus, NotebookPen } from 'lucide-vue-next';
import { sectionTitle } from './ui';

/**
 * Qué incluye la membresía, para quien ya la tiene. Va al final de la página y solo en
 * pantallas medianas o grandes: es un recordatorio, no algo que haga falta para usarla.
 * Cada punto describe algo que el sistema hace de verdad.
 */
const items = [
    { icon: BookOpen, title: 'Todos los libros, completos', text: 'Sin páginas bloqueadas en ningún libro publicado.' },
    { icon: BookPlus, title: 'Las ediciones nuevas', text: 'Si se publica una edición mientras tu acceso está vigente, ya la puedes leer.' },
    { icon: NotebookPen, title: 'Tu cuaderno de apuntes', text: 'Notas en cada libro. Si tu acceso vence, quedan guardadas.' },
    { icon: Bookmark, title: 'Tu avance guardado', text: 'Al volver sigues donde lo dejaste, en la computadora o en el celular.' },
];
</script>

<template>
    <section aria-labelledby="membership-includes-title" class="hidden md:block space-y-4">
        <h2 id="membership-includes-title" :class="sectionTitle">Lo que incluye tu membresía</h2>

        <ul class="grid grid-cols-2 2xl:grid-cols-4 gap-3">
            <li v-for="item in items" :key="item.title" class="flex gap-3 rounded-brand border border-border bg-surface p-4">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#eaf3ed] text-[#1e5949] dark:bg-[#27392f] dark:text-[#87c9a6]">
                    <component :is="item.icon" class="h-4 w-4" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-foreground">{{ item.title }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-muted">{{ item.text }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
