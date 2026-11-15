<x-app-layout title="Suscripciones y pagos">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                Suscripciones y pagos
            </h1>

            @if (($initialSummary['pending_payments'] ?? 0) > 0)
                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-amber-600 dark:text-amber-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    {{ $initialSummary['pending_payments'] }} {{ $initialSummary['pending_payments'] === 1 ? 'pago por revisar' : 'pagos por revisar' }}
                </span>
            @else
                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    {{ $initialSummary['active_members'] ?? 0 }} {{ ($initialSummary['active_members'] ?? 0) === 1 ? 'acceso activo' : 'accesos activos' }}
                </span>
            @endif
        </div>
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
