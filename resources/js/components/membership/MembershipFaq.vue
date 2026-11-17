<script setup>
import { ref } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { sectionTitle } from './ui';

/**
 * Preguntas frecuentes de la membresía, en las palabras del estudiante.
 * Cada respuesta describe algo que el sistema hace de verdad.
 */
const questions = [
    {
        q: '¿Cómo pago?',
        a: 'Elige un plan y toca «Pagar con QR». Paga el monto desde la app de tu banco con el QR que aparece y sube la captura del comprobante. La administración lo revisa y, al aprobarlo, se abren todos los libros.',
    },
    {
        q: 'Estoy en el celular y no puedo escanear mi propia pantalla',
        a: 'Toca «Guardar QR» o haz una captura de pantalla. En la app de tu banco entra a pagar con QR y elige la imagen desde tu galería.',
    },
    {
        q: '¿Se cobra solo cada mes?',
        a: 'No. Cada plan es un solo pago. Nunca pedimos tarjeta y nada se cobra por su cuenta.',
    },
    {
        q: 'Ya tengo membresía. ¿Qué pasa si pago otro plan o uso un código?',
        a: 'Los días nuevos se agregan a los que te quedan. Si te quedaban 20 días y sumas 6 meses, conservas esos 20.',
    },
    {
        q: '¿Dónde encuentro mi código de acceso?',
        a: 'Viene en tu libro impreso o te lo entrega la administración. Toca «Tengo un código» y escríbelo: da igual si usas mayúsculas, minúsculas o guiones. Cada código sirve una sola vez.',
    },
    {
        q: 'Si mi membresía vence, ¿pierdo mis apuntes?',
        a: 'No. Tus apuntes y tu avance quedan guardados, y vuelven a abrirse cuando renuevas.',
    },
];

const open = ref(null);
</script>

<template>
    <section aria-labelledby="membership-faq-title" class="space-y-3">
        <h2 id="membership-faq-title" :class="sectionTitle">Preguntas frecuentes</h2>

        <ul class="divide-y divide-border rounded-brand border border-border bg-surface overflow-hidden">
            <li v-for="(item, index) in questions" :key="item.q">
                <h3>
                    <button
                        type="button"
                        class="w-full flex items-center justify-between gap-3 px-3.5 py-3.5 sm:px-4 text-left text-sm font-semibold text-foreground hover:bg-background/60 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40"
                        :aria-expanded="open === index"
                        :aria-controls="`membership-faq-${index}`"
                        @click="open = open === index ? null : index"
                    >
                        <span>{{ item.q }}</span>
                        <ChevronDown
                            class="h-4 w-4 shrink-0 text-muted transition-transform duration-200 motion-reduce:transition-none"
                            :class="{ 'rotate-180': open === index }"
                            aria-hidden="true"
                        />
                    </button>
                </h3>
                <p
                    v-if="open === index"
                    :id="`membership-faq-${index}`"
                    class="px-3.5 pb-4 sm:px-4 text-sm leading-relaxed text-muted max-w-[68ch]"
                >
                    {{ item.a }}
                </p>
            </li>
        </ul>
    </section>
</template>
