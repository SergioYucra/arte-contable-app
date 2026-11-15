<x-app-layout title="Suscripciones y pagos">
    <x-slot name="header">
        {{-- Lo pendiente se muestra en la cabecera del panel, que se actualiza al aprobar o rechazar. --}}
        <h1 class="font-serif text-xl sm:text-2xl text-foreground leading-tight truncate">
            Suscripciones y pagos
        </h1>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="SubscriptionManager"
                data-props="{{ json_encode([
                    'initialPlans' => $initialPlans,
                    'initialPendingRequests' => $initialPendingRequests,
                    'initialReviewedRequests' => $initialReviewedRequests,
                    'initialAccessCodes' => $initialAccessCodes,
                    'initialSummary' => $initialSummary,
                    'urls' => $urls,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
