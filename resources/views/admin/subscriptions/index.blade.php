<x-app-layout title="Membresías y pagos">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-serif text-xl sm:text-2xl text-foreground leading-tight truncate">
                Membresías y pagos
            </h1>

            @php($pending = $initialSummary['pending_payments'])
            <span @class([
                'shrink-0 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold',
                'border-gold/40 bg-gold/15 text-foreground' => $pending > 0,
                'border-border bg-surface text-muted' => $pending === 0,
            ])>
                @if ($pending > 0)
                    <span class="h-1.5 w-1.5 rounded-full bg-gold" aria-hidden="true"></span>
                    {{ $pending }} {{ $pending === 1 ? 'pago por revisar' : 'pagos por revisar' }}
                @else
                    Sin pagos por revisar
                @endif
            </span>
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
