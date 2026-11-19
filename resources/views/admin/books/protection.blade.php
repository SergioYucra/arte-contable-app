<x-app-layout title="Protección de libros">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                Protección de libros
            </h1>

            @if (($initialData['stats']['active_holds'] ?? 0) > 0)
                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-amber-600 dark:text-amber-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    {{ $initialData['stats']['active_holds'] }} {{ $initialData['stats']['active_holds'] === 1 ? 'lectura pausada' : 'lecturas pausadas' }}
                </span>
            @else
                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 sm:px-3 py-1 text-[11px] sm:text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Todo en orden
                </span>
            @endif
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="BookProtectionPanel"
                data-props="{{ json_encode([
                    'initialData' => $initialData,
                    'urls' => $urls,
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
