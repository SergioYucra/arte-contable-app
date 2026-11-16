<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Prevención de FOUC de tema visual --}}
        <x-theme-script />

        {{-- SEO técnico automático en español y bloqueo de indexación en área privada --}}
        @php
            $seoData = new \RalphJSmit\Laravel\SEO\Support\SEOData(
                title: $title ?: __('Espacio de estudio'),
                robots: 'noindex, nofollow',
            );
        @endphp
        {!! seo($seoData) !!}

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|newsreader:400,500,600&display=swap" rel="stylesheet" />

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

            @php
                $topbarSection = match (true) {
                    request()->routeIs('dashboard') => null,
                    request()->routeIs('library.*') => __('Biblioteca de estudio'),
                    request()->routeIs('membership.*') => __('Membresía y planes'),
                    request()->routeIs('quick-links.*') => __('Accesos rápidos'),
                    request()->routeIs('profile.*') => __('Mi perfil'),
                    request()->routeIs('admin.books.*') => __('Libros y ediciones'),
                    request()->routeIs('admin.subscriptions.*') => __('Suscripciones y pagos'),
                    request()->routeIs('admin.quick-links.*') => __('Accesos rápidos'),
                    request()->routeIs('admin.users.*') => __('Usuarios y roles'),
                    request()->routeIs('admin.activity-logs.*') => __('Bitácora de actividad'),
                    default => $title ?: null,
                };
            @endphp

            <div class="app-shell flex min-h-screen flex-col">
                {{-- Barra superior --}}
                <div class="sticky top-0 z-20 flex h-[58px] sm:h-[66px] shrink-0 items-center justify-between gap-3 border-b border-border bg-surface/95 px-4 backdrop-blur-md sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        {{-- Abrir menú (móvil) --}}
                        <button type="button" @click="mobileOpen = true" class="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[9px] border border-border bg-surface text-muted transition-colors hover:border-primary hover:text-primary lg:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" aria-label="Abrir menú" aria-controls="app-sidebar" :aria-expanded="mobileOpen">
                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10" /></svg>
                        </button>

                        {{-- Contraer / expandir menú (escritorio) --}}
                        <button type="button" @click="toggleCollapse()" class="hidden h-[34px] w-[34px] shrink-0 place-items-center rounded-[9px] border border-border bg-surface text-muted transition-colors hover:border-primary hover:text-primary lg:grid focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" :aria-label="collapsed ? 'Expandir menú' : 'Contraer menú'" :title="collapsed ? 'Expandir menú' : 'Contraer menú'" aria-controls="app-sidebar" :aria-expanded="! collapsed">
                            <svg x-show="! collapsed" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M9 3v18M16 15l-3-3 3-3" /></svg>
                            <svg x-show="collapsed" x-cloak class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M9 3v18M14 9l3 3-3 3" /></svg>
                        </button>

                        {{-- Ruta de ubicación (Breadcrumb) --}}
                        <nav aria-label="Ubicación actual" class="flex min-w-0 items-center gap-2 text-[13px]">
                            @if ($topbarSection)
                                <a href="{{ route('dashboard') }}" class="hidden sm:inline shrink-0 font-medium text-muted transition-colors hover:text-primary">
                                    {{ __('Inicio') }}
                                </a>
                                <span class="hidden sm:inline text-muted/45 select-none" aria-hidden="true">/</span>
                                <span class="truncate font-semibold text-foreground">{{ $topbarSection }}</span>
                            @else
                                <span class="truncate font-semibold text-foreground">{{ __('Inicio') }}</span>
                            @endif
                        </nav>
                    </div>

                    @php
                        $topbarUser = auth()->user();
                        $topbarActiveSub = $topbarUser?->subscriptions()
                            ->where('status', \App\Models\Subscription::STATUS_ACTIVE)
                            ->where('expires_at', '>', now())
                            ->latest('expires_at')
                            ->first();
                        $topbarIsAdmin = $topbarUser?->can('manage-users') ?? false;
                        $topbarHasFullAccess = (bool) $topbarActiveSub || $topbarIsAdmin;
                        $topbarDaysRemaining = $topbarActiveSub
                            ? max(0, (int) ceil(now()->floatDiffInDays($topbarActiveSub->expires_at, false)))
                            : ($topbarIsAdmin ? 365 : 0);
                    @endphp

                    <div class="ms-auto flex items-center gap-2 sm:gap-2.5 shrink-0">
                        @if ($topbarHasFullAccess)
                            <a
                                href="{{ route('membership.index') }}"
                                title="Lectura 100% desbloqueada ({{ $topbarDaysRemaining }} días restantes)"
                                class="inline-flex min-h-[34px] items-center gap-1.5 rounded-[8px] border border-emerald-500/25 bg-emerald-500/10 px-2.5 text-[11px] font-semibold text-emerald-700 transition-colors hover:bg-emerald-500/15 dark:text-emerald-400"
                            >
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="hidden md:inline">Lectura 100% desbloqueada ({{ $topbarDaysRemaining }} d)</span>
                                <span class="md:hidden">Activo ({{ $topbarDaysRemaining }} d)</span>
                            </a>
                        @else
                            <a
                                href="{{ route('membership.index') }}"
                                class="inline-flex min-h-[34px] items-center gap-1.5 rounded-[8px] border border-[#d7e6e8] bg-[#eef6f6] px-2.5 text-[11px] font-semibold text-[#315f6a] transition-colors hover:border-primary/40 dark:border-border dark:bg-surface dark:text-primary"
                            >
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gold shadow-[0_0_0_2px_rgba(222,184,115,0.22)]"></span>
                                <span class="hidden md:inline">Modo muestra libre · Desbloquear todo &rarr;</span>
                                <span class="md:hidden">Desbloquear &rarr;</span>
                            </a>
                        @endif

                        <x-theme-toggle variant="compact" />
                    </div>
                </div>

                <!-- Page Heading -->
                @isset($header)
                    <header class="bg-surface border-b border-border shadow-xs">
                        <div class="w-full py-3 sm:py-4 px-3.5 sm:px-6 lg:px-8">
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
