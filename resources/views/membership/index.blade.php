<x-app-layout :title="$initialMembership['is_active'] ? 'Mi membresía' : 'Membresía y planes de acceso'">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8">
            {{-- Solo el primer nombre, igual que el saludo del inicio. El correo no se envía: la página no lo usa. --}}
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
                    'statusUrl' => $statusUrl,
                    'libraryUrl' => route('library.index'),
                    'userName' => \Illuminate\Support\Str::of(auth()->user()->name)->trim()->before(' ')->value(),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
