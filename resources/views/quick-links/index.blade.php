<x-app-layout title="Accesos rápidos">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-6">
            <div
                data-vue-island="StudentQuickLinksCatalog"
                data-props="{{ json_encode([
                    'initialCategories' => $initialCategories,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
