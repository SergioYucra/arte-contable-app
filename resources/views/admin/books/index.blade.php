<x-app-layout title="Libros y ediciones">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                Libros y ediciones
            </h1>

            <div class="shrink-0 flex items-center gap-2">
                <a
                    href="{{ route('admin.library-protection.index') }}"
                    class="inline-flex items-center gap-1.5 rounded-brand border border-border bg-background px-2.5 sm:px-3 py-1.5 text-[11px] sm:text-xs font-semibold text-foreground hover:border-primary/40 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
                >
                    <svg class="h-3.5 w-3.5 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                    <span>Protección<span class="hidden sm:inline"> de libros</span></span>
                </a>

                <span class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    {{ count($books) }} {{ count($books) === 1 ? 'obra' : 'obras' }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="BookCatalogManager"
                data-props="{{ json_encode([
                    'initialBooks' => $books,
                    'storeBookUrl' => route('admin.books.store'),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>

