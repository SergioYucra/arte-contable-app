<x-app-layout title="Herramientas">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-6">
            <div
                data-vue-island="StudentToolsCatalog"
                data-props="{{ json_encode([
                    'initialItems' => $initialItems,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
