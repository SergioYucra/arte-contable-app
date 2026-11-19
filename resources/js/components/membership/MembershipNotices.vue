<script setup>
import { Eye, Undo2 } from 'lucide-vue-next';
import BaseAlert from '../ui/BaseAlert.vue';
import { btn } from '../subscriptions/shared';

/**
 * Avisos sobre los pagos de la persona: uno en revisión (con ver y retirar) o el último
 * que no se aprobó (con el motivo). Van arriba de todo: es lo que vino a mirar.
 */
defineProps({
    pendingRequest: { type: Object, default: null },
    rejectedRequest: { type: Object, default: null },
    isStaff: { type: Boolean, default: false },
});

defineEmits(['withdraw', 'send-another', 'dismiss-rejected']);
</script>

<template>
    <div v-if="pendingRequest || rejectedRequest || isStaff" class="space-y-3">
        <BaseAlert v-if="isStaff" type="info" title="Tu cuenta ya puede leer todo">
            Administras la plataforma, así que no necesitas un plan. Esta es la página que ven los estudiantes.
        </BaseAlert>

        <BaseAlert v-if="pendingRequest" type="warning" title="Pago en revisión">
            <div class="space-y-3">
                <p>
                    Enviaste tu comprobante del
                    <strong class="font-semibold text-foreground">{{ pendingRequest.plan_name }}</strong>
                    (<span class="tabular-nums">{{ pendingRequest.amount_formatted }}</span>) el {{ pendingRequest.created_at }}.
                    La administración revisa cada pago a mano; cuando termine, esta página te avisa.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <a :href="pendingRequest.proof_url" target="_blank" rel="noopener" :class="btn.small">
                        <Eye class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                        <span>Ver mi comprobante</span>
                        <span class="sr-only"> (se abre en otra pestaña)</span>
                    </a>
                    <button v-if="pendingRequest.cancel_url" type="button" :class="btn.small" @click="$emit('withdraw')">
                        <Undo2 class="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                        <span>Me equivoqué, retirar envío</span>
                    </button>
                </div>
            </div>
        </BaseAlert>

        <BaseAlert
            v-if="rejectedRequest"
            type="error"
            title="Tu último pago no fue aprobado"
            dismissible
            @dismiss="$emit('dismiss-rejected')"
        >
            <div class="space-y-3">
                <p>
                    {{ rejectedRequest.plan_name }},
                    <span class="tabular-nums">{{ rejectedRequest.amount_formatted }}</span>, enviado el {{ rejectedRequest.created_at }}.
                    <template v-if="rejectedRequest.admin_notes">
                        Motivo: <strong class="font-semibold text-foreground">{{ rejectedRequest.admin_notes }}</strong>
                    </template>
                </p>
                <button type="button" :class="btn.small" @click="$emit('send-another')">
                    Enviar otro comprobante
                </button>
            </div>
        </BaseAlert>
    </div>
</template>
