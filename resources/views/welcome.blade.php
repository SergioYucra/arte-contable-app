<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Prevención de FOUC de tema visual --}}
        <x-theme-script />

        <title>{{ config('brand.name', 'El Arte de la Contabilidad') }} — {{ config('brand.tagline') }}</title>

        {{-- Metadatos SEO de servidor (ralphjsmit/laravel-seo) --}}
        {!! seo() !!}

        <!-- Tipografía Inter oficial -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Estilos y Scripts compilados con Tailwind Dual Tokens -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans antialiased bg-background text-foreground flex flex-col justify-between selection:bg-primary/25 selection:text-foreground">

        {{-- ============ BARRA DE NAVEGACIÓN SUPERIOR ============ --}}
        <header class="w-full border-b border-border bg-surface/90 backdrop-blur sticky top-0 z-50 transition-colors">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                {{-- Isotipo y Nombre de Marca Oficial --}}
                <a href="/" class="flex items-center gap-3 group focus:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-brand" aria-label="Inicio - El Arte de la Contabilidad">
                    <span class="flex h-10 w-10 items-center justify-center rounded-brand bg-primary/10 text-primary border border-primary/20 shadow-xs transition-transform group-hover:scale-105">
                        <x-application-logo class="h-6 w-6 text-primary" />
                    </span>
                    <div class="flex flex-col">
                        <span class="text-base font-bold tracking-tight text-foreground leading-none">{{ config('brand.name') }}</span>
                        <span class="text-[11px] font-medium text-muted mt-0.5 hidden sm:block leading-none">{{ config('brand.tagline') }}</span>
                    </div>
                </a>

                {{-- Enlaces de Autenticación y Selector de Modo Dual --}}
                <nav class="flex items-center gap-3">
                    <x-theme-toggle />
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-brand text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            {{ __('Ir al Panel de Control') }} &rarr;
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="px-3.5 py-2 rounded-brand text-xs font-semibold text-muted hover:text-foreground transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            {{ __('Iniciar Sesión') }}
                        </a>

                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center px-4 py-2 rounded-brand text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                {{ __('Crear Cuenta') }}
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        {{-- ============ CONTENIDO PRINCIPAL / HERO SECTION ============ --}}
        <main class="flex-1 flex flex-col items-center justify-center px-4 sm:px-6 lg:px-8 py-12 lg:py-20 relative overflow-hidden">
            {{-- Halos de iluminación ambiental suave --}}
            <div class="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center overflow-hidden" aria-hidden="true">
                <div class="h-[28rem] w-[28rem] rounded-full bg-primary/10 dark:bg-primary/5 blur-3xl animate-float-slow"></div>
                <div class="h-[24rem] w-[24rem] rounded-full bg-accent/10 dark:bg-accent/5 blur-3xl translate-x-32 -translate-y-16 animate-float-slower"></div>
            </div>

            <div class="max-w-7xl mx-auto w-full grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
                {{-- Columna Izquierda: Propuesta de Valor --}}
                <div class="lg:col-span-7 space-y-6 text-center lg:text-start animate-fade-up">
                    {{-- Badge Institucional --}}
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-surface text-xs font-medium text-muted shadow-2xs">
                        <span class="h-2 w-2 rounded-full bg-primary animate-pulse"></span>
                        <span class="font-semibold text-foreground">{{ config('brand.tagline') }}</span>
                    </div>

                    {{-- Titular Principal --}}
                    <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-foreground leading-[1.12]">
                        Aprende, practica y domina la contabilidad <span class="text-primary underline decoration-primary/30 decoration-wavy underline-offset-8">desde cero</span>
                    </h1>

                    {{-- Descripción pedagógica --}}
                    <p class="text-base sm:text-lg text-muted max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                        {{ config('brand.description') }}
                    </p>

                    {{-- Botones de Acción (CTAs) --}}
                    <div class="pt-2 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3.5">
                        @auth
                            <a href="{{ route('dashboard') }}" class="w-full sm:w-auto px-6 py-3.5 rounded-brand text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm transition-all text-center">
                                {{ __('Ingresar a mi Espacio de Práctica') }} &rarr;
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="w-full sm:w-auto px-6 py-3.5 rounded-brand text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm transition-all text-center">
                                    {{ __('Comenzar Ahora Gratis') }}
                                </a>
                            @endif
                            <a href="{{ route('login') }}" class="w-full sm:w-auto px-6 py-3.5 rounded-brand text-sm font-semibold border border-border bg-surface text-foreground hover:bg-background transition-colors text-center shadow-2xs">
                                {{ __('Acceder a mi Cuenta') }}
                            </a>
                        @endauth
                    </div>

                    {{-- Ventajas clave resumidas --}}
                    <div class="pt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-muted">
                        <div class="flex items-center gap-2 justify-center lg:justify-start">
                            <svg class="h-4 w-4 text-primary flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                            </svg>
                            <span>100% Interactivo</span>
                        </div>
                        <div class="flex items-center gap-2 justify-center lg:justify-start">
                            <svg class="h-4 w-4 text-primary flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                            </svg>
                            <span>Ejercicios Paso a Paso</span>
                        </div>
                        <div class="flex items-center gap-2 justify-center lg:justify-start">
                            <svg class="h-4 w-4 text-primary flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                            </svg>
                            <span>Partida Doble Clara</span>
                        </div>
                    </div>
                </div>

                {{-- Columna Derecha: Portada del Libro "El Arte de Contabilizar desde 0" --}}
                <div class="lg:col-span-5 flex justify-center lg:justify-end animate-fade-up">
                    <div class="relative w-full max-w-sm">
                        {{-- Resplandor perimetral detrás del libro --}}
                        <div class="absolute -inset-2 bg-gradient-to-tr from-primary/30 to-accent/20 rounded-2xl blur-xl opacity-60"></div>

                        {{-- Estructura del Libro 3D estilizado --}}
                        <div class="relative rounded-2xl border-2 border-primary/30 bg-secondary text-secondary-foreground shadow-2xl overflow-hidden p-8 sm:p-9 flex flex-col justify-between min-h-[460px] book-spine-shadow">
                            {{-- Textura de fondo sutil --}}
                            <div class="pointer-events-none absolute inset-0 bg-brand-grid opacity-30"></div>

                            {{-- Cabecera de la Portada --}}
                            <div class="relative z-10 space-y-4">
                                <div class="flex items-center justify-between border-b border-white/10 pb-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest bg-white/10 text-white border border-white/15">
                                        Libro Guía Oficial
                                    </span>
                                    <span class="text-[11px] font-semibold text-secondary-foreground/60 tracking-wider">
                                        Edición 2026
                                    </span>
                                </div>

                                <div class="pt-2">
                                    <p class="text-xs font-semibold uppercase tracking-widest text-primary">
                                        Metodología Práctica
                                    </p>
                                    <h2 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-white leading-snug">
                                        El Arte de Contabilizar <span class="text-primary">desde 0</span>
                                    </h2>
                                    <p class="mt-2 text-xs text-secondary-foreground/75 leading-relaxed">
                                        Fundamentos sólidos, reglas de la partida doble y dinámica contable explicadas de forma visual y accesible.
                                    </p>
                                </div>
                            </div>

                            {{-- Emblema central de la portada --}}
                            <div class="relative z-10 py-6 my-auto flex items-center justify-center">
                                <div class="h-24 w-24 rounded-2xl bg-white/5 border border-white/15 flex items-center justify-center backdrop-blur shadow-inner">
                                    <x-application-logo class="h-14 w-14 text-primary" />
                                </div>
                            </div>

                            {{-- Pie de la Portada --}}
                            <div class="relative z-10 pt-4 border-t border-white/10 flex items-center justify-between text-[11px] text-secondary-foreground/80">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                    <span class="font-medium">Casos prácticos resueltos</span>
                                </div>
                                <span class="font-bold tracking-wider text-white uppercase text-[10px]">
                                    Aprende a tu ritmo
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ PILARES DE LA PLATAFORMA ============ --}}
            <section class="mt-20 sm:mt-28 max-w-7xl mx-auto w-full border-t border-border pt-12" aria-labelledby="features-heading">
                <div class="text-center max-w-2xl mx-auto mb-10">
                    <h2 id="features-heading" class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">
                        Diseñado para tu formación contable integral
                    </h2>
                    <p class="mt-2 text-xs sm:text-sm text-muted">
                        Una plataforma que une la teoría explicada en el libro con un entorno de práctica activo.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {{-- Pilar 1 --}}
                    <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/50">
                        <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-foreground">Acompañamiento Didáctico</h3>
                        <p class="text-xs text-muted leading-relaxed">
                            Contenido organizado por módulos temáticos siguiendo el plan de estudios del libro "El Arte de Contabilizar desde 0".
                        </p>
                    </div>

                    {{-- Pilar 2 --}}
                    <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/50">
                        <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-foreground">Práctica Contable Viva</h3>
                        <p class="text-xs text-muted leading-relaxed">
                            Registra transacciones, genera asientos de ajuste y comprueba el balance de comprobación con retroalimentación inmediata.
                        </p>
                    </div>

                    {{-- Pilar 3 --}}
                    <div class="p-6 rounded-brand border border-border bg-surface shadow-xs space-y-3 transition hover:border-primary/50">
                        <div class="h-10 w-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-foreground">Accesible y Seguro</h3>
                        <p class="text-xs text-muted leading-relaxed">
                            Diseñado bajo estándares WCAG 2.1 con soporte fluido para modo claro y oscuro, garantizando comodidad visual prolongada.
                        </p>
                    </div>
                </div>
            </section>
        </main>

        {{-- ============ PIE DE PÁGINA (FOOTER) ============ --}}
        <footer class="border-t border-border bg-surface py-6 text-center text-xs text-muted transition-colors">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <x-application-logo class="h-4 w-4 text-primary" />
                    <span class="font-medium text-foreground">&copy; 2026 {{ config('brand.name') }}.</span>
                    <span>{{ __('Todos los derechos reservados.') }}</span>
                </div>
                <div class="flex items-center gap-4 text-xs font-medium">
                    <a href="{{ route('login') }}" class="hover:text-foreground transition-colors">{{ __('Iniciar Sesión') }}</a>
                    @if (Route::has('register'))
                        <span class="text-border">&bull;</span>
                        <a href="{{ route('register') }}" class="hover:text-foreground transition-colors">{{ __('Crear Cuenta') }}</a>
                    @endif
                </div>
            </div>
        </footer>

    </body>
</html>
