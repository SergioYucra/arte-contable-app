<x-app-layout :title="$initialMembership['is_active'] ? 'Mi membresía' : 'Membresía y planes de acceso'">
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

