<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Dashboard') }}
            </h2>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                {{ config('brand.name') }}
            </span>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-surface border border-border overflow-hidden shadow-xs rounded-brand">
                <div class="p-6 text-foreground flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">{{ __('¡Bienvenido de vuelta, :name!', ['name' => auth()->user()->name]) }}</h3>
                        <p class="text-sm text-muted mt-1">{{ __('La sesión actual está autenticada y protegida por roles y permisos de Spatie.') }}</p>
                    </div>
                    <div class="hidden sm:block">
                        <span class="inline-flex items-center px-3 py-1 rounded-brand text-xs font-medium bg-background text-muted border border-border">
                            Rol: {{ auth()->user()->getRoleNames()->first() ?? 'Sin rol' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Isla Vue 3: componente montado sobre Blade --}}
            <div id="app">
                <system-status-badge environment="{{ app()->environment() }}" label="Master Boilerplate Status"></system-status-badge>
            </div>
        </div>
    </div>
</x-app-layout>
