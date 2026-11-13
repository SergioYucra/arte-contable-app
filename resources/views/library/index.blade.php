<x-app-layout title="Biblioteca de libros">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-4">
            <div>
                <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight">
                    Biblioteca y catálogo de estudio
                </h1>
                <p class="text-xs text-muted mt-0.5">
                    Explora las obras oficiales publicadas, consulta el índice temático y continúa tu lectura donde la dejaste.
                </p>
            </div>

            <div class="self-start sm:self-auto shrink-0 flex items-center gap-2">
                @if ($initialMembership['is_active'])
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Lectura 100% desbloqueada ({{ $initialMembership['days_remaining'] }} d)
                    </span>
                @else
                    <a
                        href="{{ $membershipUrl }}"
                        class="inline-flex items-center gap-1.5 rounded-full border border-primary/25 bg-primary/10 hover:bg-primary/20 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-primary transition-colors"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                        <span>Modo muestra libre · Desbloquear todo &rarr;</span>
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-3 sm:py-6">
        <div class="w-full px-2.5 sm:px-6 lg:px-8">
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

