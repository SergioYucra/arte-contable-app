<x-app-layout :title="$initialMembership['is_active'] ? 'Mi membresía' : 'Membresía y planes de acceso'">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-4">
            <div>
                <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight">
                    {{ $initialMembership['is_active'] ? 'Mi membresía' : 'Membresía y planes de acceso' }}
                </h1>
                <p class="text-xs text-muted mt-0.5">
                    {{ $initialMembership['is_active']
                        ? 'Gestiona tu suscripción activa, consulta tu vigencia o extiende tus días de lectura.'
                        : 'Adquiere tu plan con QR o canjea tu código de acceso para desbloquear la lectura completa.' }}
                </p>
            </div>

            <div class="self-start sm:self-auto shrink-0 flex items-center gap-2">
                @if ($initialMembership['is_active'])
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Membresía activa ({{ $initialMembership['days_remaining'] }} d restantes)
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-muted">
                        <span class="h-1.5 w-1.5 rounded-full bg-muted"></span>
                        Acceso libre limitado
                    </span>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-3 sm:py-6">
        <div class="w-full px-2.5 sm:px-6 lg:px-8">
            <div
                data-vue-island="StudentMembershipManager"
                data-props="{{ json_encode([
                    'initialPlans' => $initialPlans,
                    'initialMembership' => $initialMembership,
                    'initialPendingRequest' => $initialPendingRequest,
                    'initialRecentRequests' => $initialRecentRequests,
                    'initialRecentCodes' => $initialRecentCodes,
                    'storePaymentUrl' => $storePaymentUrl,
                    'redeemCodeUrl' => $redeemCodeUrl,
                    'libraryUrl' => route('library.index'),
                    'userName' => auth()->user()->name,
                    'userEmail' => auth()->user()->email,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>

