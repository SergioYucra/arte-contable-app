<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('brand.name', config('app.name', 'Arte Contable')) }} — {{ config('brand.tagline') }}</title>

        {{-- SEO técnico --}}
        {!! seo() !!}

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans antialiased bg-background text-foreground flex flex-col justify-between selection:bg-primary/20">

        {{-- ============ NAVBAR ============ --}}
        <header class="w-full border-b border-border bg-surface/80 backdrop-blur sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                {{-- Logo y Nombre --}}
                <a href="/" class="flex items-center gap-3 group">
                    <span class="flex h-10 w-10 items-center justify-center rounded-brand bg-primary text-primary-foreground shadow-sm shadow-primary/30 transition group-hover:scale-105">
                        @if (config('brand.logo'))
                            <img src="{{ asset(config('brand.logo')) }}" alt="{{ config('brand.name') }}" class="h-6 w-6 object-contain">
                        @else
                            <x-application-logo class="h-5 w-5 fill-current" />
                        @endif
                    </span>
                    <span class="text-base font-bold tracking-tight text-foreground">{{ config('brand.name') }}</span>
                </a>

                {{-- Navegación Auth --}}
                <nav class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-brand text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs transition-colors">
                            {{ __('Ir al Panel') }} &rarr;
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="px-3.5 py-2 rounded-brand text-xs font-semibold text-muted hover:text-foreground transition-colors">
                            {{ __('Iniciar Sesión') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-brand text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs transition-colors">
                                {{ __('Crear Cuenta') }}
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        {{-- ============ HERO SECTION ============ --}}
        <main class="flex-1 flex flex-col items-center justify-center px-4 sm:px-6 lg:px-8 py-16 sm:py-24 relative overflow-hidden">
            {{-- Fondo decorativo sutil --}}
            <div class="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center overflow-hidden">
                <div class="h-[30rem] w-[30rem] rounded-full bg-primary/5 blur-3xl"></div>
                <div class="h-[25rem] w-[25rem] rounded-full bg-accent/5 blur-3xl -translate-y-12 translate-x-24"></div>
            </div>

            <div class="max-w-3xl text-center space-y-6 animate-fade-up">
                {{-- Badge de bienvenida --}}
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-surface text-xs font-medium text-muted shadow-2xs">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                    <span>{{ config('brand.tagline') }}</span>
                </div>

                {{-- Título Principal --}}
                <h1 class="text-3xl sm:text-5xl font-bold tracking-tight text-foreground leading-[1.15]">
                    Gestión contable profesional, segura y diseñada para crecer
                </h1>

                {{-- Subtítulo --}}
                <p class="text-base sm:text-lg text-muted max-w-2xl mx-auto leading-relaxed">
                    {{ config('brand.description') }}
                </p>

                {{-- Acciones principales (CTAs) --}}
                <div class="pt-4 flex flex-col sm:flex-row items-center justify-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="w-full sm:w-auto px-6 py-3 rounded-brand text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm transition-all text-center">
                            {{ __('Acceder a mi Panel de Control') }} &rarr;
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="w-full sm:w-auto px-6 py-3 rounded-brand text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm transition-all text-center">
                            {{ __('Iniciar Sesión') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="w-full sm:w-auto px-6 py-3 rounded-brand text-sm font-semibold border border-border bg-surface text-foreground hover:bg-background transition-colors text-center">
                                {{ __('Registrarme') }}
                            </a>
                        @endif
                    @endauth
                </div>
            </div>

            {{-- ============ CARACTERÍSTICAS / HIGHLIGHTS ============ --}}
            <div class="mt-20 max-w-5xl w-full grid grid-cols-1 md:grid-cols-3 gap-6">
                {{-- Característica 1 --}}
                <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/40">
                    <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                    </div>
                    <h2 class="text-sm font-semibold text-foreground">Acceso y Roles Granulares</h2>
                    <p class="text-xs text-muted leading-relaxed">
                        Control de accesos robusto que garantiza que cada usuario solo acceda a los módulos y datos contables autorizados.
                    </p>
                </div>

                {{-- Característica 2 --}}
                <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/40">
                    <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                    </div>
                    <h2 class="text-sm font-semibold text-foreground">Trazabilidad y Auditoría</h2>
                    <p class="text-xs text-muted leading-relaxed">
                        Bitácora inmutable de operaciones para cumplir con estándares contables y regulatorios sin alteraciones.
                    </p>
                </div>

                {{-- Característica 3 --}}
                <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/40">
                    <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </div>
                    <h2 class="text-sm font-semibold text-foreground">Experiencia Moderna</h2>
                    <p class="text-xs text-muted leading-relaxed">
                        Diseñado para ofrecer rapidez y claridad en cada gestión, sin fricciones ni sobrecargas visuales.
                    </p>
                </div>
            </div>
        </main>

        {{-- ============ FOOTER ============ --}}
        <footer class="border-t border-border bg-surface py-6 text-center text-xs text-muted">
            <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
                <span>&copy; {{ date('Y') }} {{ config('brand.name') }}. {{ __('Todos los derechos reservados.') }}</span>
                <div class="flex items-center gap-4">
                    <a href="{{ route('login') }}" class="hover:text-foreground transition-colors">{{ __('Iniciar Sesión') }}</a>
                    @if (Route::has('register'))
                        <span>&bull;</span>
                        <a href="{{ route('register') }}" class="hover:text-foreground transition-colors">{{ __('Crear Cuenta') }}</a>
                    @endif
                </div>
            </div>
        </footer>

    </body>
</html>
