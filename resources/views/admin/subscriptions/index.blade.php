<x-app-layout title="Suscripciones y pagos">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                Suscripciones y pagos
            </h1>

            <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-primary">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                {{ count($initialPlans) }} {{ count($initialPlans) === 1 ? 'plan activo' : 'planes registrados' }}
            </span>
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="SubscriptionManager"
                data-props="{{ json_encode([
                    'initialPlans' => $initialPlans,
                    'initialRequests' => $initialRequests,
                    'initialAccessCodes' => $initialAccessCodes,
                    'storePlanUrl' => route('admin.subscriptions.plans.store'),
                    'storeCodeUrl' => route('admin.subscriptions.codes.store'),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>

