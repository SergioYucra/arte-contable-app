<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Prevención de FOUC de tema visual --}}
        <x-theme-script />

        {{-- SEO técnico automático (ralphjsmit/laravel-seo) --}}
        {!! seo() !!}

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans antialiased bg-background text-foreground">
        <div
            x-data="{
                mobileOpen: false,
                collapsed: document.documentElement.dataset.sidebar === 'collapsed',
                toggleCollapse() {
                    this.collapsed = ! this.collapsed;
                    document.documentElement.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';
                    try { localStorage.setItem('sidebar', this.collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
                },
            }"
            class="min-h-screen bg-background"
        >
            @include('layouts.sidebar')

            <div class="app-shell flex min-h-screen flex-col">
                {{-- Barra superior --}}
                <div class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-border bg-surface/85 px-4 backdrop-blur-md sm:px-6 lg:px-8">
                    {{-- Abrir menú (móvil) --}}
                    <button type="button" @click="mobileOpen = true" class="rounded-lg p-2 text-muted hover:bg-background hover:text-foreground lg:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" aria-label="Abrir menú" aria-controls="app-sidebar" :aria-expanded="mobileOpen">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10" /></svg>
                    </button>

                    {{-- Contraer / expandir menú (escritorio) --}}
                    <button type="button" @click="toggleCollapse()" class="hidden rounded-lg p-2 text-muted hover:bg-background hover:text-foreground lg:inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" :aria-label="collapsed ? 'Expandir menú' : 'Contraer menú'" :title="collapsed ? 'Expandir menú' : 'Contraer menú'" aria-controls="app-sidebar" :aria-expanded="! collapsed">
                        <svg class="h-5 w-5 transition-transform duration-200" :class="collapsed ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M9 3v18M16 9l-3 3 3 3" /></svg>
                    </button>

                    <span class="truncate text-sm font-semibold text-foreground lg:hidden">{{ config('brand.name') }}</span>

                    <div class="ms-auto flex items-center gap-3">
                        <x-theme-toggle />
                    </div>
                </div>

                <!-- Page Heading -->
                @isset($header)
                    <header class="bg-surface border-b border-border shadow-xs">
                        <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
