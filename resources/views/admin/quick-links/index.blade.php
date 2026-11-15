<x-app-layout title="Accesos rápidos">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
                <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                    Accesos rápidos y portales oficiales
                </h1>
                <p class="hidden sm:block text-xs text-muted mt-0.5">
                    Administra las categorías y enlaces directos a instituciones tributarias, laborales y comerciales para los estudiantes.
                </p>
            </div>

            <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-primary">
                <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                {{ count($initialLinks) }} {{ count($initialLinks) === 1 ? 'enlace' : 'enlaces' }}
            </span>
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="QuickLinkAdminManager"
                data-props="{{ json_encode([
                    'initialCategories' => $initialCategories,
                    'initialLinks' => $initialLinks,
                    'storeCategoryUrl' => route('admin.quick-links.categories.store'),
                    'storeLinkUrl' => route('admin.quick-links.store'),
                    'studentPreviewUrl' => route('quick-links.index'),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
