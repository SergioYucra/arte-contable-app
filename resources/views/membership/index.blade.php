<x-app-layout :title="$initialMembership['is_active'] ? 'Mi membresía' : 'Membresía y planes de acceso'">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8">
            {{-- Ni el nombre ni el correo viajan en el HTML: la página no los usa. --}}
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
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
