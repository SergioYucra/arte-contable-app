<x-app-layout title="Biblioteca de libros">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-6">
            <div
                data-vue-island="StudentLibraryCatalog"
                data-props="{{ json_encode([
                    'initialBooks' => $initialBooks,
                    'initialContinueReading' => $initialContinueReading,
                    'initialMembership' => $initialMembership,
                    'initialOpenReader' => $initialOpenReader ?? null,
                    'membershipUrl' => $membershipUrl,
                    'userName' => auth()->user()->name,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>

