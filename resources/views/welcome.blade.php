<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth scroll-pt-24">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Prevención de FOUC de tema visual --}}
        <x-theme-script />

        {{-- Metadatos SEO de servidor alimentados desde config/brand.php y Schema JSON-LD --}}
        @php
            $brandName = config('brand.name');
            $brandTagline = config('brand.tagline');
            $brandDescription = config('brand.description');
            $homeUrl = url('/');

            $schema = new \RalphJSmit\Laravel\SEO\SchemaCollection([
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $brandName,
                    'description' => $brandDescription,
                    'url' => $homeUrl,
                    'inLanguage' => str_replace('_', '-', app()->getLocale()),
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'EducationalOrganization',
                    'name' => $brandName,
                    'url' => $homeUrl,
                    'email' => 'elartedelacontabilidad1@gmail.com',
                    'founder' => [
                        '@type' => 'Person',
                        'name' => 'Max Apaza Luna',
                    ],
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Book',
                    'name' => config('brand.book_title'),
                    'author' => [
                        '@type' => 'Person',
                        'name' => 'Max Apaza Luna',
                    ],
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $brandName,
                    ],
                    'inLanguage' => 'es',
                    'bookEdition' => 'Primera edición 2026',
                ],
            ]);

            $seoData = new \RalphJSmit\Laravel\SEO\Support\SEOData(
                title: $brandName.' — '.$brandTagline,
                description: $brandDescription,
                author: 'Max Apaza Luna',
                image: 'images/seo/portada.png',
                url: $homeUrl,
                enableTitleSuffix: false,
                schema: $schema,
                type: 'website',
                site_name: $brandName,
                canonical_url: $homeUrl,
                openGraphTitle: $brandName.' — '.$brandTagline,
            );

            $registerOrLoginUrl = Route::has('register') ? route('register') : route('login');
            $catalogUrl = auth()->check() ? route('library.index') : $registerOrLoginUrl;
            $toolsUrl = auth()->check() ? route('dashboard') : $registerOrLoginUrl;
        @endphp
        {!! seo($seoData) !!}

        {{-- Tipografías oficiales: Inter (Sans), Newsreader (Serif Editorial), JetBrains Mono (Mono) --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|newsreader:400,400i,500,600,700|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" />

        {{-- Estilos y Scripts compilados con Tailwind Dual Tokens --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full overflow-x-hidden bg-background font-sans text-foreground antialiased selection:bg-accent/25 selection:text-foreground">

        {{-- ============ BARRA DE NAVEGACIÓN SUPERIOR ============ --}}
        <header
            x-data="{ mobileOpen: false }"
            @keydown.escape.window="mobileOpen = false"
            @click.outside="mobileOpen = false"
            class="sticky top-0 z-50 border-b border-border/80 bg-background/90 backdrop-blur-lg transition-colors"
            data-purpose="site-navigation"
        >
            <div class="mx-auto flex h-[76px] max-w-[1320px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                {{-- Logo y Marca --}}
                <a href="{{ url('/') }}" class="group flex min-w-0 shrink-0 items-center gap-3 rounded-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ $brandName }}, inicio">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] border border-border bg-surface p-1.5 text-primary shadow-xs transition-transform group-hover:scale-105">
                        <x-application-logo class="h-full w-full text-primary" />
                    </span>
                    <div class="flex flex-col truncate">
                        <span class="truncate font-serif text-[16px] font-medium leading-tight tracking-tight text-foreground sm:text-[17px]">
                            {{ $brandName }}
                        </span>
                        <span class="hidden truncate text-[10px] tracking-wide text-muted sm:block">
                            {{ $brandTagline }}
                        </span>
                    </div>
                </a>

                {{-- Enlaces Desktop --}}
                <nav class="ml-auto hidden items-center gap-7 text-xs font-medium text-muted md:flex lg:gap-8" aria-label="Navegación principal">
                    <a href="#inicio" class="transition-colors hover:text-primary">Inicio</a>
                    <a href="#libros" class="transition-colors hover:text-primary">Libros y materiales</a>
                    <a href="#herramientas" class="transition-colors hover:text-primary">Herramientas</a>
                    <a href="{{ auth()->check() ? route('membership.index') : '#suscripcion' }}" class="transition-colors hover:text-primary">Suscripción</a>
                </nav>

                {{-- Acciones Derecha --}}
                <div class="flex shrink-0 items-center gap-2.5 sm:gap-4">
                    <x-theme-toggle />

                    @auth
                        <a
                            href="{{ route('library.index') }}"
                            class="hidden min-h-[38px] items-center gap-1.5 rounded-full bg-primary px-4 text-xs font-semibold text-primary-foreground shadow-xs transition hover:-translate-y-0.5 hover:bg-primary-hover sm:inline-flex"
                        >
                            <span>Mi Biblioteca</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="hidden text-xs font-medium text-muted transition-colors hover:text-primary md:inline-flex">
                            Iniciar sesión
                        </a>
                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                class="hidden min-h-[38px] items-center gap-1.5 rounded-full bg-primary px-4 text-xs font-semibold text-primary-foreground shadow-xs transition hover:-translate-y-0.5 hover:bg-primary-hover sm:inline-flex"
                            >
                                <span>Crear cuenta</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @endif
                    @endauth

                    {{-- Botón Menú Móvil --}}
                    <button
                        type="button"
                        @click="mobileOpen = !mobileOpen"
                        :aria-expanded="mobileOpen"
                        aria-controls="mobile-drawer"
                        aria-label="Abrir menú"
                        class="rounded-md p-2 text-foreground transition-colors hover:bg-surface md:hidden"
                    >
                        <svg x-show="!mobileOpen" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <svg x-show="mobileOpen" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Menú Desplegable Móvil --}}
            <div
                id="mobile-drawer"
                x-show="mobileOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="border-t border-border bg-background px-4 pb-5 pt-3 shadow-lg md:hidden"
            >
                <nav class="flex flex-col divide-y divide-border text-sm font-medium text-foreground">
                    <a @click="mobileOpen = false" href="#inicio" class="py-3 transition-colors hover:text-primary">Inicio</a>
                    <a @click="mobileOpen = false" href="#libros" class="py-3 transition-colors hover:text-primary">Libros y materiales</a>
                    <a @click="mobileOpen = false" href="#herramientas" class="py-3 transition-colors hover:text-primary">Herramientas</a>
                    <a @click="mobileOpen = false" href="{{ auth()->check() ? route('membership.index') : '#suscripcion' }}" class="py-3 transition-colors hover:text-primary">Suscripción</a>
                </nav>
                <div class="mt-3 grid grid-cols-2 gap-2.5">
                    @auth
                        <a href="{{ route('library.index') }}" class="col-span-2 inline-flex min-h-[44px] items-center justify-center gap-2 rounded-full bg-primary px-4 text-xs font-semibold text-primary-foreground">
                            <span>Mi Biblioteca</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex min-h-[44px] items-center justify-center rounded-full border border-border bg-surface px-4 text-xs font-semibold text-foreground">
                            Iniciar sesión
                        </a>
                        <a href="{{ $registerOrLoginUrl }}" class="inline-flex min-h-[44px] items-center justify-center rounded-full bg-primary px-4 text-xs font-semibold text-primary-foreground">
                            Crear cuenta
                        </a>
                    @endauth
                </div>
            </div>
        </header>

        <main>
            {{-- ============ 1. HERO CENTRADO EDITORIAL ============ --}}
            <section id="inicio" class="mx-auto flex max-w-[1180px] flex-col items-center px-4 pb-16 pt-16 text-center sm:px-8 sm:pt-20 lg:pt-24" data-purpose="hero-landing">
                <div class="mx-auto flex max-w-[780px] flex-col items-center animate-fade-up">
                    <h1 class="font-serif text-[44px] font-normal leading-[0.99] tracking-[-0.035em] text-foreground sm:text-6xl lg:text-[78px]">
                        Un espacio para aprender contabilidad <em class="font-normal italic text-primary">desde cero.</em>
                    </h1>

                    <p class="mt-5 max-w-[540px] text-sm leading-[1.75] text-muted sm:text-[15px]">
                        Lee, toma apuntes y practica en un mismo lugar. Paso a paso, con ejemplos claros y a tu propio ritmo.
                    </p>

                    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                        @auth
                            <a
                                href="{{ route('dashboard') }}"
                                class="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-full bg-primary px-6 text-[13px] font-semibold text-primary-foreground shadow-sm transition hover:-translate-y-0.5 hover:bg-primary-hover"
                            >
                                <span>Ir a mi espacio de estudio</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @else
                            <a
                                href="{{ $registerOrLoginUrl }}"
                                class="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-full bg-primary px-6 text-[13px] font-semibold text-primary-foreground shadow-sm transition hover:-translate-y-0.5 hover:bg-primary-hover"
                            >
                                <span>Empieza gratis</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @endauth

                        <a
                            href="#libros"
                            class="inline-flex min-h-[48px] items-center justify-center gap-2 rounded-full px-5 text-[13px] font-semibold text-foreground transition hover:bg-surface"
                        >
                            <span>Libros y materiales</span>
                            <span aria-hidden="true">↓</span>
                        </a>
                    </div>

                    <div class="mt-5 flex items-center justify-center gap-2 text-xs text-muted">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">✓</span>
                        <span>Crea tu cuenta gratis. Avanza a tu ritmo.</span>
                    </div>
                </div>

                {{-- Composición Visual Editorial Centrada --}}
                <div class="relative mx-auto mt-8 h-[420px] w-full max-w-[650px] sm:mt-10 sm:h-[465px]" aria-label="Un libro, un cuaderno y una práctica contable para estudiar">
                    {{-- Halo radial suave --}}
                    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[340px] w-[340px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-accent/10 blur-3xl sm:h-[440px] sm:w-[440px]" aria-hidden="true"></div>

                    {{-- Órbitas elípticas sutiles --}}
                    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[340px] w-[340px] -translate-x-1/2 -translate-y-1/2 -rotate-[26deg] scale-y-[0.63] rounded-full border border-primary/15 sm:h-[410px] sm:w-[410px]" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[390px] w-[390px] -translate-x-1/2 -translate-y-1/2 rotate-[24deg] scale-y-[0.62] rounded-full border border-primary/15 sm:h-[470px] sm:w-[470px]" aria-hidden="true"></div>

                    {{-- Eslogan vertical --}}
                    <div class="vertical-rl absolute right-1 top-6 hidden font-mono text-[9px] tracking-[0.28em] text-muted sm:block" aria-hidden="true">
                        LEE <span class="py-1.5 text-gold">·</span> COMPRENDE <span class="py-1.5 text-gold">·</span> PRACTICA
                    </div>

                    {{-- 1. Libro Principal (Portada azul editorial) --}}
                    <div class="absolute left-6 top-8 z-20 -rotate-[5deg] transition-transform duration-300 hover:-rotate-2 sm:left-[22%] sm:top-10" style="filter: drop-shadow(15px 19px 18px rgb(15 35 53 / 0.22));">
                        <div class="relative flex h-[248px] w-[176px] flex-col items-center overflow-hidden rounded-r-sm bg-gradient-to-br from-[#246e8a] via-[#124568] to-[#0d3049] px-4 py-6 text-white shadow-[inset_7px_0_9px_rgba(8,31,41,0.25),inset_8px_0_0_rgba(255,255,255,0.08)] sm:h-[288px] sm:w-[206px] sm:px-5">
                            <span class="font-mono text-[8px] tracking-[0.2em] text-white/90">EL ARTE DE LA</span>
                            <div class="my-3 h-px w-10 bg-gold"></div>
                            <div class="text-center font-serif text-[25px] font-medium leading-[0.95] tracking-tight sm:text-[30px]">
                                Contabilidad<br /><em class="font-normal italic text-[#e5c887]">desde 0</em>
                            </div>
                            <div class="mt-4">
                                <div class="h-12 w-12 overflow-hidden rounded-full border border-[#e5c887]/60 bg-white/10 p-1 sm:h-14 sm:w-14">
                                    <img
                                        src="{{ asset('images/inicio/arte_contable.webp') }}"
                                        alt="Emblema de El Arte de la Contabilidad desde 0"
                                        class="h-full w-full object-contain"
                                    />
                                </div>
                            </div>
                            <span class="mt-auto font-mono text-[8px] tracking-[0.14em] text-white/85">LIC. MAX APAZA LUNA</span>
                            <span class="absolute bottom-2 right-2.5 font-mono text-[7px] text-white/60">EDICIÓN 2026</span>
                        </div>
                    </div>

                    {{-- 2. Tarjeta "MI CUADERNO" --}}
                    <div class="sheet-shadow absolute right-3 top-20 z-30 h-[142px] w-[168px] rotate-[5deg] rounded-sm border border-gold/30 bg-paper p-4 text-left text-foreground sm:right-[6%] sm:top-24 sm:h-[155px] sm:w-[190px]">
                        <div class="flex items-center gap-1.5 font-mono text-[9px] tracking-wider text-primary">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                            </svg>
                            <span>MI CUADERNO</span>
                        </div>
                        <p class="my-3 font-serif text-[17px] italic leading-[1.15] sm:text-[19px]">
                            Entender el <mark class="rounded bg-gold/45 px-1 text-foreground dark:bg-gold/30">porqué</mark> de cada registro.
                        </p>
                        <span class="block h-px w-full bg-border"></span>
                        <span class="mt-2 block h-px w-2/3 bg-border"></span>
                    </div>

                    {{-- 3. Hoja de Cálculo Flotante --}}
                    <div class="sheet-shadow absolute bottom-6 right-4 z-40 w-[212px] rotate-[2deg] rounded-lg border border-border bg-surface p-3.5 text-left text-foreground sm:bottom-10 sm:right-[9%] sm:w-[238px]">
                        <div class="flex items-center justify-between font-mono text-[8px] tracking-wider text-muted">
                            <span>MI PRIMERA PRÁCTICA</span>
                            <span class="tracking-[2px]">•••</span>
                        </div>
                        <div class="my-2.5 rounded bg-emerald-500/10 px-2 py-1 font-mono text-[10px] text-emerald-700 dark:text-emerald-300">
                            =SUMA(C3:C5)
                        </div>
                        <strong class="mb-2 block font-mono text-[9px] tracking-wider text-foreground">MI ARQUEO DE CAJA</strong>
                        <div class="flex justify-between border-t border-border py-1 font-mono text-[9px] text-muted">
                            <span>Billete (Bs)</span>
                            <span>Importe</span>
                        </div>
                        <div class="flex justify-between border-t border-border py-1 font-mono text-[9px] text-muted">
                            <span>100 × 5</span>
                            <span>500,00</span>
                        </div>
                        <div class="flex justify-between border-t border-border py-1 font-mono text-[9px] text-muted">
                            <span>50 × 8</span>
                            <span>400,00</span>
                        </div>
                        <div class="mt-0.5 flex justify-between border-t border-border pt-1.5 font-mono text-[10px] font-semibold text-foreground">
                            <span>Total</span>
                            <strong class="text-emerald-600 dark:text-emerald-400">900,00</strong>
                        </div>
                    </div>

                    {{-- 4. Sello Circular "DESDE 0 CON CALMA" --}}
                    <div class="absolute bottom-14 left-3 z-10 flex h-[68px] w-[68px] -rotate-[14deg] flex-col items-center justify-center rounded-full border border-gold/70 font-mono text-[8px] tracking-widest text-muted sm:bottom-16 sm:left-[9%] sm:h-[74px] sm:w-[74px]" aria-hidden="true">
                        <span>DESDE</span>
                        <strong class="font-serif text-[28px] leading-none text-foreground">0</strong>
                        <span>CON CALMA</span>
                    </div>
                </div>
            </section>

            {{-- ============ 2. FRANJA DE 3 PILARES ============ --}}
            <section class="bg-secondary text-secondary-foreground dark:border-y dark:border-border dark:bg-surface dark:text-foreground" data-purpose="core-value-props">
                <div class="mx-auto grid max-w-[1180px] grid-cols-1 divide-y divide-white/10 px-4 py-3 dark:divide-border sm:px-8 md:min-h-[135px] md:grid-cols-3 md:items-center md:divide-x md:divide-y-0 md:py-0">
                    <article class="flex items-center justify-start gap-4 py-4 md:justify-center md:px-6 md:py-2">
                        <span class="shrink-0 text-gold">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="font-serif text-[17px] font-medium text-white dark:text-foreground">Tu biblioteca, contigo</h3>
                            <p class="mt-0.5 text-xs text-slate-300 dark:text-muted">Libros y recursos en un solo lugar.</p>
                        </div>
                    </article>

                    <article class="flex items-center justify-start gap-4 py-4 md:justify-center md:px-6 md:py-2">
                        <span class="shrink-0 text-gold">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="font-serif text-[17px] font-medium text-white dark:text-foreground">Dale espacio a tus ideas</h3>
                            <p class="mt-0.5 text-xs text-slate-300 dark:text-muted">Apuntes que viven junto a la lectura.</p>
                        </div>
                    </article>

                    <article class="flex items-center justify-start gap-4 py-4 md:justify-center md:px-6 md:py-2">
                        <span class="shrink-0 text-gold">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="font-serif text-[17px] font-medium text-white dark:text-foreground">Siempre algo por aprender</h3>
                            <p class="mt-0.5 text-xs text-slate-300 dark:text-muted">Una biblioteca que seguirá creciendo.</p>
                        </div>
                    </article>
                </div>
            </section>

            {{-- ============ 3. CATÁLOGO DE LIBROS ============ --}}
            <section id="libros" class="mx-auto max-w-[1180px] px-4 py-20 sm:px-8 lg:py-24" data-purpose="editorial-catalog">
                <div class="mb-10 flex flex-col items-center gap-3 text-center">
                    <h2 class="max-w-[620px] font-serif text-3xl font-normal leading-[1.08] tracking-tight text-foreground sm:text-[44px]">
                        Libros y materiales para aprender de manera práctica.
                    </h2>
                    <a href="{{ $catalogUrl }}" class="inline-flex min-h-[44px] items-center gap-2 text-xs font-semibold text-primary transition-all hover:gap-3">
                        <span>{{ auth()->check() ? 'Ir a mi biblioteca' : 'Explorar con cuenta gratis' }}</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                    {{-- Libro 1: Disponible --}}
                    <article class="group flex flex-col rounded-2xl border border-border bg-surface p-3.5 transition duration-200 hover:-translate-y-1 hover:border-primary/40">
                        <div class="relative flex min-h-[220px] items-center justify-center rounded-xl bg-primary/[0.06] p-4 dark:bg-background/60">
                            <span class="absolute left-3 top-3 font-mono text-[9px] tracking-wider text-muted">01 / BIBLIOTECA</span>
                            <div class="relative flex h-[168px] w-[122px] flex-col items-center overflow-hidden rounded-r-sm bg-gradient-to-br from-[#246e8a] via-[#124568] to-[#0d3049] px-2.5 py-4 text-white shadow-lg">
                                <span class="font-mono text-[6.5px] tracking-[0.18em] text-white/90">EL ARTE DE LA</span>
                                <div class="my-2 h-px w-7 bg-gold"></div>
                                <div class="text-center font-serif text-[20px] font-medium leading-[0.95] tracking-tight">
                                    Contabilidad<br /><em class="font-normal italic text-[#e5c887]">desde 0</em>
                                </div>
                                <div class="mt-2.5">
                                    <div class="h-9 w-9 overflow-hidden rounded-full border border-[#e5c887]/60 bg-white/10 p-0.5">
                                        <img
                                            src="{{ asset('images/inicio/arte_contable.webp') }}"
                                            alt="Emblema de El Arte de la Contabilidad desde 0"
                                            class="h-full w-full object-contain"
                                            loading="lazy"
                                        />
                                    </div>
                                </div>
                                <span class="mt-auto font-mono text-[6px] tracking-wider text-white/85">LIC. MAX APAZA LUNA</span>
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col justify-between px-1.5 pb-1.5 pt-4">
                            <div>
                                <span class="font-mono text-[10px] tracking-wider text-muted">Primera edición · 2026</span>
                                <h3 class="mt-1.5 font-serif text-[21px] font-medium leading-tight text-foreground">
                                    El Arte de la Contabilidad desde 0
                                </h3>
                                <span class="mt-1.5 block font-mono text-[10px] tracking-widest text-muted">MAX APAZA LUNA</span>
                            </div>
                            <a href="{{ $catalogUrl }}" class="mt-4 inline-flex min-h-[40px] items-center gap-1.5 text-xs font-semibold text-primary transition-all hover:gap-2.5">
                                <span>{{ auth()->check() ? 'Abrir en mi biblioteca' : 'Crear cuenta gratis para leer' }}</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </article>

                    {{-- Libro 2: En preparación --}}
                    <article class="flex flex-col rounded-2xl border border-border bg-surface p-3.5 opacity-90">
                        <div class="relative flex min-h-[220px] items-center justify-center rounded-xl bg-emerald-500/[0.06] p-4 dark:bg-emerald-950/25">
                            <span class="absolute left-3 top-3 font-mono text-[9px] tracking-wider text-muted">02 / BIBLIOTECA</span>
                            <div class="relative flex h-[168px] w-[122px] flex-col items-center overflow-hidden rounded-r-sm bg-gradient-to-br from-[#466f5b] via-[#244f3f] to-[#1b3c30] px-2.5 py-4 text-white shadow-lg">
                                <span class="font-mono text-[6.5px] tracking-[0.18em] text-white/90">EL ARTE DE LA</span>
                                <div class="my-2 h-px w-7 bg-gold"></div>
                                <div class="text-center font-serif text-[19px] font-medium leading-[1.02] tracking-tight">
                                    Contabilidad<br /><em class="font-normal italic text-[#e5c887]">Intermedia</em>
                                </div>
                                <span class="mt-auto font-mono text-[6px] tracking-wider text-white/85">LIC. MAX APAZA LUNA</span>
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col justify-between px-1.5 pb-1.5 pt-4">
                            <div>
                                <span class="font-mono text-[10px] tracking-wider text-muted">En preparación</span>
                                <h3 class="mt-1.5 font-serif text-[21px] font-medium leading-tight text-foreground">
                                    Contabilidad Intermedia
                                </h3>
                                <span class="mt-1.5 block font-mono text-[10px] tracking-widest text-muted">PRÓXIMAMENTE</span>
                            </div>
                            <span class="mt-4 inline-flex items-center gap-2 text-[11px] font-medium text-muted">
                                <span class="h-1.5 w-1.5 rounded-full bg-gold"></span>
                                Próximamente en la biblioteca
                            </span>
                        </div>
                    </article>

                    {{-- Libro 3: En preparación --}}
                    <article class="flex flex-col rounded-2xl border border-border bg-surface p-3.5 opacity-90">
                        <div class="relative flex min-h-[220px] items-center justify-center rounded-xl bg-gold/10 p-4 dark:bg-amber-950/20">
                            <span class="absolute left-3 top-3 font-mono text-[9px] tracking-wider text-muted">03 / BIBLIOTECA</span>
                            <div class="relative flex h-[168px] w-[122px] flex-col items-center overflow-hidden rounded-r-sm bg-gradient-to-br from-[#b38b54] via-[#805e34] to-[#634528] px-2.5 py-4 text-white shadow-lg">
                                <span class="font-mono text-[6.5px] tracking-[0.18em] text-white/90">EL ARTE DE LA</span>
                                <div class="my-2 h-px w-7 bg-gold"></div>
                                <div class="mt-2 text-center font-serif text-[22px] font-medium leading-tight tracking-tight">
                                    Tributos
                                </div>
                                <span class="mt-auto font-mono text-[6px] tracking-wider text-white/85">LIC. MAX APAZA LUNA</span>
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col justify-between px-1.5 pb-1.5 pt-4">
                            <div>
                                <span class="font-mono text-[10px] tracking-wider text-muted">En preparación</span>
                                <h3 class="mt-1.5 font-serif text-[21px] font-medium leading-tight text-foreground">
                                    Tributos
                                </h3>
                                <span class="mt-1.5 block font-mono text-[10px] tracking-widest text-muted">PRÓXIMAMENTE</span>
                            </div>
                            <span class="mt-4 inline-flex items-center gap-2 text-[11px] font-medium text-muted">
                                <span class="h-1.5 w-1.5 rounded-full bg-gold"></span>
                                Próximamente en la biblioteca
                            </span>
                        </div>
                    </article>
                </div>
            </section>

            {{-- ============ 4. ESPACIO DE ESTUDIO DIVIDIDO ============ --}}
            <section id="espacio-estudio" class="mx-auto max-w-[1060px] px-4 sm:px-8" data-purpose="study-space-feature">
                <div class="rounded-[20px] border border-border bg-primary/[0.04] p-6 dark:bg-surface sm:p-10 lg:p-12">
                    <div class="mx-auto max-w-[680px] text-center">
                        <h2 class="font-serif text-3xl font-normal leading-[1.08] tracking-tight text-foreground sm:text-[40px]">
                            Tu lectura, tus notas y tu hoja de cálculo.
                        </h2>
                        <p class="mx-auto mt-3.5 max-w-[440px] text-xs leading-[1.8] text-muted sm:text-[13px]">
                            Todo lo que necesitas para aprender haciendo, en un espacio que guarda tu progreso.
                        </p>

                        <ul class="mx-auto my-6 grid max-w-md grid-cols-1 gap-2.5 text-left text-xs text-foreground sm:grid-cols-2 sm:gap-3">
                            <li class="flex items-center gap-2.5">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">✓</span>
                                <span>Notas vinculadas a cada lectura</span>
                            </li>
                            <li class="flex items-center gap-2.5">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">✓</span>
                                <span>Marcadores y progreso por página</span>
                            </li>
                            <li class="flex items-center gap-2.5">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">✓</span>
                                <span>Plantillas y ejercicios prácticos</span>
                            </li>
                            <li class="flex items-center gap-2.5">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">✓</span>
                                <span>Tu avance se guarda automáticamente</span>
                            </li>
                        </ul>

                        <a href="{{ $catalogUrl }}" class="inline-flex min-h-[44px] items-center justify-center gap-2 text-xs font-semibold text-primary transition-all hover:gap-3">
                            <span>{{ auth()->check() ? 'Ir a mi espacio de estudio' : 'Crear cuenta gratis para estudiar' }}</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>

                    {{-- Ventana Mockup del Espacio de Estudio --}}
                    <div class="sheet-shadow mx-auto mt-8 max-w-[850px] overflow-hidden rounded-xl border border-border bg-surface text-left">
                        {{-- Barra superior de ventana --}}
                        <div class="flex h-10 items-center justify-between border-b border-border bg-background/60 px-4 font-mono text-[10px] tracking-widest text-muted">
                            <div class="flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-[#d6b39c]"></span>
                                <span class="h-2 w-2 rounded-full bg-[#dfca91]"></span>
                                <span class="h-2 w-2 rounded-full bg-[#a8c7ab]"></span>
                            </div>
                            <span>MI ESPACIO DE ESTUDIO</span>
                            <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>

                        {{-- Dos paneles: Lector + Cuaderno --}}
                        <div class="grid grid-cols-1 divide-y divide-border sm:grid-cols-[1.1fr_0.9fr] sm:divide-x sm:divide-y-0">
                            <div class="flex flex-col justify-between p-5 sm:p-6">
                                <div>
                                    <div class="flex items-center justify-between font-mono text-[10px] tracking-wider text-primary">
                                        <span>CAPÍTULO 01</span>
                                        <span class="text-muted">LECTURA · 6 MIN</span>
                                    </div>
                                    <h3 class="mt-4 font-serif text-2xl font-medium leading-[1.08] text-foreground">
                                        Comprender antes<br />de registrar.
                                    </h3>
                                    <p class="mt-3 text-xs leading-[1.75] text-muted">
                                        La contabilidad es un lenguaje que nos ayuda a contar la historia de un negocio. Antes de registrar, primero observamos, comprendemos y encontramos el sentido de cada movimiento.
                                    </p>
                                    <div class="my-4 rounded border border-border bg-paper/70 px-3 py-2.5 text-center font-serif text-base italic text-foreground">
                                        Activo <span class="px-1 text-gold">=</span> Pasivo <span class="px-1 text-gold">+</span> Patrimonio
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 font-mono text-[10px] text-primary">
                                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                                    </svg>
                                    <span>Mi primer marcador</span>
                                    <span class="ml-auto text-muted">pág. 1</span>
                                </div>
                            </div>

                            <div class="flex flex-col justify-between bg-paper/55 p-5 sm:p-6">
                                <div>
                                    <div class="flex items-center gap-2 font-mono text-[10px] tracking-wider text-primary">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                        </svg>
                                        <span>MI CUADERNO</span>
                                        <span class="ml-auto tracking-[2px] text-muted">•••</span>
                                    </div>
                                    <span class="mt-5 block font-mono text-[9px] tracking-wider text-muted">VINCULADO A LA PÁGINA 1</span>
                                    <h4 class="mt-2 font-serif text-lg font-medium text-foreground">La ecuación contable</h4>
                                    <p class="mt-1.5 text-xs leading-[1.7] text-muted">
                                        Lo que la empresa tiene (activo) se financia con lo que debe (pasivo) y con los aportes de sus dueños (patrimonio).
                                    </p>
                                    <div class="mt-4 border-l-2 border-gold bg-gold/15 px-3 py-2 font-serif text-xs italic text-foreground">
                                        Todo tiene que estar en equilibrio.
                                    </div>
                                </div>
                                <span class="mt-5 block font-mono text-[9px] tracking-wider text-muted">APUNTE DE EJEMPLO</span>
                            </div>
                        </div>

                        {{-- Barra inferior de ventana --}}
                        <div class="flex items-center justify-between border-t border-border bg-background/60 px-4 py-2.5 font-mono text-[10px] text-muted">
                            <span class="flex items-center gap-2">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Guardado automáticamente
                            </span>
                            <span>01 / 12</span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============ 5. RECURSOS GRATUITOS ============ --}}
            <section id="herramientas" class="mx-auto max-w-[1180px] px-4 py-20 sm:px-8" data-purpose="free-resources">
                <div class="mb-9 flex flex-col items-center gap-3 text-center">
                    <h2 class="font-serif text-3xl font-normal tracking-tight text-foreground sm:text-[40px]">
                        Tu primera visita ya cuenta.
                    </h2>
                    <p class="text-xs text-muted sm:text-sm">
                        {{ auth()->check() ? 'Accede a tus guías y herramientas desde tu espacio de estudio.' : 'Un primer paso amable para empezar a comprender con tu cuenta gratuita.' }}
                    </p>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 font-mono text-[10px] text-emerald-700 dark:text-emerald-300">
                        <span>✓</span>
                        <span>Incluido con cuenta gratis</span>
                    </span>
                </div>

                <div class="mx-auto grid max-w-[940px] grid-cols-1 gap-4 md:grid-cols-2">
                    <a href="{{ $toolsUrl }}" class="group flex min-h-[120px] items-center gap-4 rounded-2xl border border-border bg-surface p-5 transition duration-200 hover:-translate-y-0.5 hover:border-primary/40">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div class="flex-1">
                            <span class="block font-mono text-[10px] tracking-wider text-muted">MINI GUÍA · CUENTA GRATUITA</span>
                            <h3 class="mt-1 font-serif text-xl font-medium text-foreground group-hover:text-primary">Tu primera sesión de estudio</h3>
                            <p class="mt-0.5 text-xs text-muted">Una ruta sencilla para comenzar, sin apuro.</p>
                        </div>
                        <span class="text-primary transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                    </a>

                    <a href="{{ $toolsUrl }}" class="group flex min-h-[120px] items-center gap-4 rounded-2xl border border-border bg-surface p-5 transition duration-200 hover:-translate-y-0.5 hover:border-primary/40">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gold/15 text-amber-700 dark:text-gold">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V13.5zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V18zm2.498-6.75h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V13.5zm0 2.25h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V18zm2.504-6.75h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V13.5zm0 2.25h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V18m2.498-6.75h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V13.5zM8.25 6h7.5v2.25h-7.5V6zM12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25z" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div class="flex-1">
                            <span class="block font-mono text-[10px] tracking-wider text-muted">HERRAMIENTA · CUENTA GRATUITA</span>
                            <h3 class="mt-1 font-serif text-xl font-medium text-foreground group-hover:text-primary">Depreciación lineal</h3>
                            <p class="mt-0.5 text-xs text-muted">Descubre cómo calcularla con un ejemplo claro.</p>
                        </div>
                        <span class="text-primary transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                    </a>
                </div>
            </section>

            {{-- ============ 6. COMUNIDAD / METAS ============ --}}
            <section class="mx-auto max-w-[1000px] px-4 pb-20 pt-4 sm:px-8" data-purpose="community-goals">
                <div class="mb-7 flex flex-col items-center gap-2 text-center">
                    <span class="inline-flex items-center gap-2 font-mono text-[10px] tracking-wider text-muted">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        UNA META EN CAMINO
                    </span>
                    <h2 class="font-serif text-3xl font-normal tracking-tight text-foreground sm:text-[42px]">
                        Todos empezamos con un sueño.
                    </h2>
                </div>

                <article class="relative flex flex-col items-center justify-center gap-5 overflow-hidden rounded-[18px] border border-gold/60 bg-[#1a3b37] p-8 text-center text-white dark:bg-[#102925] sm:p-10">
                    <span class="pointer-events-none absolute left-6 top-0 font-serif text-[88px] leading-none text-gold/35" aria-hidden="true">“</span>

                    <div class="relative z-10 flex flex-col items-center">
                        <span class="inline-flex items-center gap-2 font-mono text-[10px] uppercase tracking-wider text-amber-200/90">
                            <span class="h-1.5 w-1.5 rounded-full bg-gold"></span>
                            En progreso
                        </span>

                        <blockquote class="my-4 max-w-[720px] font-serif text-2xl italic leading-[1.2] tracking-tight text-white sm:text-[32px]">
                            Quiero entender la contabilidad para ayudar a crecer el negocio de mi familia.
                        </blockquote>

                        <div class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full border border-gold/80 font-serif text-base text-gold">A</span>
                            <div class="text-left">
                                <strong class="block text-xs font-semibold text-white">Ana</strong>
                                <small class="block text-[11px] text-emerald-100/75">Estudiante de contabilidad <span class="text-gold">·</span> Meta compartida</small>
                            </div>
                        </div>
                    </div>

                    <a
                        href="{{ auth()->check() ? route('membership.index') : $registerOrLoginUrl }}"
                        class="relative z-10 inline-flex min-h-[44px] items-center justify-center gap-2 rounded-full bg-white px-5 text-xs font-semibold text-[#1a3b37] transition hover:-translate-y-0.5 hover:bg-paper"
                    >
                        <span>{{ auth()->check() ? 'Ir a membresía' : 'Únete con tu cuenta gratis' }}</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </article>
            </section>

            {{-- ============ 7. FUNDADOR ============ --}}
            <section class="border-y border-border bg-primary/[0.03] py-20 dark:bg-surface/50" data-purpose="author-profile">
                <div class="mx-auto grid max-w-[1060px] grid-cols-1 items-center gap-10 px-4 sm:px-8 md:grid-cols-[0.7fr_1.3fr] lg:gap-16">
                    {{-- Foto del fundador --}}
                    <div class="mx-auto w-full max-w-[350px]">
                        <div class="aspect-square w-full overflow-hidden rounded-[18px] border border-border bg-surface">
                            <img
                                src="{{ asset('images/inicio/max_apaza.webp') }}"
                                alt="Max Apaza Luna, fundador de {{ $brandName }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                        </div>
                        <div class="flex justify-between pt-3 font-mono text-[10px] tracking-wider text-muted">
                            <span>CONTABILIDAD QUE SE COMPRENDE</span>
                            <span>01 — 26</span>
                        </div>
                    </div>

                    {{-- Biografía --}}
                    <div class="space-y-4 text-left">
                        <h2 class="font-serif text-4xl font-normal tracking-tight text-foreground sm:text-5xl">
                            Hola, soy Max.
                        </h2>
                        <p class="max-w-[530px] font-serif text-lg italic leading-[1.35] text-primary sm:text-[21px]">
                            Creo que aprender contabilidad no debería sentirse como descifrar un idioma desconocido.
                        </p>
                        <p class="max-w-[550px] text-xs leading-[1.9] text-muted sm:text-[13px]">
                            Escribí <em class="font-serif italic text-foreground">El Arte de la Contabilidad desde 0</em> para explicar cada concepto con claridad y mostrar el porqué detrás de cada registro. Este espacio nace para acompañar esa lectura: con apuntes, ejercicios y nuevos capítulos que iremos construyendo juntos.
                        </p>

                        <div class="pt-2">
                            <p class="font-serif text-[22px] italic text-foreground">Max Apaza Luna</p>
                            <span class="mt-1 block font-mono text-[10px] tracking-wider text-muted">Fundador · {{ $brandName }}</span>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-border pt-4 text-xs text-muted">
                            <span>Redes oficiales disponibles próximamente</span>
                            <a href="mailto:elartedelacontabilidad1@gmail.com" class="inline-flex min-h-[40px] items-center gap-1.5 font-semibold text-primary transition-all hover:gap-2.5">
                                <span>Escribir al autor</span>
                                <span aria-hidden="true">↗</span>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============ 8. ACCESO, OTROS PROYECTOS Y CTA FINAL ============ --}}
            <section id="suscripcion" class="mx-auto max-w-[1180px] px-4 py-20 sm:px-8" data-purpose="ecosystem-and-call-to-action">
                <div class="mb-8 text-center">
                    <h2 class="font-serif text-3xl font-normal tracking-tight text-foreground sm:text-[42px]">
                        Tu espacio de estudio te espera.
                    </h2>
                    <p class="mt-2 text-xs text-muted sm:text-sm">
                        Empieza con lo esencial y descubre más a medida que avanzas.
                    </p>
                </div>

                {{-- 2 Tarjetas: Cuenta gratuita vs. Membresía --}}
                <div class="mx-auto grid max-w-[880px] grid-cols-1 gap-4 md:grid-cols-2">
                    <article class="flex flex-col justify-between rounded-[15px] border border-border bg-surface p-7">
                        <div>
                            <span class="font-mono text-[10px] tracking-wider text-muted">01 / EMPIEZA AQUÍ</span>
                            <div class="mt-4 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <h3 class="mt-3 font-serif text-[27px] font-medium text-foreground">Cuenta gratuita</h3>
                            <p class="mt-1 text-xs font-semibold text-foreground">
                                Sin costo <span class="font-normal text-muted">· para empezar hoy</span>
                            </p>
                            <div class="my-5 h-px bg-border"></div>
                            <ul class="space-y-2.5 text-xs text-muted">
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Tu panel de estudio personal</li>
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Guías y recursos gratuitos</li>
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Opción de canje o membresía</li>
                            </ul>
                        </div>
                        <a
                            href="{{ auth()->check() ? route('dashboard') : $registerOrLoginUrl }}"
                            class="mt-6 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-full border border-primary bg-transparent px-4 text-xs font-semibold text-primary transition hover:bg-primary/10"
                        >
                            <span>{{ auth()->check() ? 'Ir a mi panel' : 'Crear cuenta gratuita' }}</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    </article>

                    <article class="flex flex-col justify-between rounded-[15px] border-2 border-primary bg-surface p-7 shadow-lg shadow-primary/5">
                        <div>
                            <span class="font-mono text-[10px] tracking-wider text-primary">02 / CUANDO QUIERAS</span>
                            <div class="mt-4 flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <h3 class="mt-3 font-serif text-[27px] font-medium text-foreground">Membresía</h3>
                            <p class="mt-1 text-xs font-semibold text-foreground">
                                Acceso completo <span class="font-normal text-muted">· a tu ritmo</span>
                            </p>
                            <div class="my-5 h-px bg-border"></div>
                            <ul class="space-y-2.5 text-xs text-muted">
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Lector protegido completo</li>
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Progreso guardado por página</li>
                                <li class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400">✓</span> Próximos módulos y capítulos</li>
                            </ul>
                        </div>
                        <a
                            href="{{ auth()->check() ? route('membership.index') : $registerOrLoginUrl }}"
                            class="mt-6 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-full bg-primary px-4 text-xs font-semibold text-primary-foreground shadow-sm transition hover:bg-primary-hover"
                        >
                            <span>{{ auth()->check() ? 'Ver planes y membresía' : 'Empezar gratis y activar luego' }}</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </div>

                {{-- Fila compacta de Proyectos Asociados --}}
                <div class="mx-auto mt-12 grid max-w-[1040px] grid-cols-1 items-center gap-4 md:grid-cols-2 lg:grid-cols-[auto_1fr_1fr]">
                    <div class="md:col-span-2 lg:col-span-1 lg:pr-4">
                        <h3 class="font-serif text-[24px] font-medium leading-tight text-foreground lg:whitespace-nowrap">
                            Proyectos asociados.
                        </h3>
                    </div>

                    <a
                        href="https://dexidi.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group flex h-full items-center gap-3.5 rounded-xl border border-border bg-surface p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm"
                    >
                        <div class="h-12 w-12 shrink-0 overflow-hidden rounded-[10px] border border-border bg-white p-1 dark:bg-surface-2">
                            <img
                                src="{{ asset('images/inicio/dexidi.webp') }}"
                                alt="Logo de Dexidi"
                                class="h-full w-full object-contain"
                                loading="lazy"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h4 class="font-serif text-lg font-medium leading-none text-foreground transition-colors group-hover:text-primary">Dexidi</h4>
                                <span class="inline-flex items-center gap-1 font-mono text-[10px] tracking-wider text-emerald-700 dark:text-emerald-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    EN PRODUCCIÓN
                                </span>
                            </div>
                            <p class="mt-1 text-[11px] leading-snug text-muted">Cursos gratuitos y de pago para seguir aprendiendo.</p>
                        </div>
                        <span class="shrink-0 text-xs text-muted transition-transform group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true">↗</span>
                    </a>

                    <a
                        href="https://conta-max.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group flex h-full items-center gap-3.5 rounded-xl border border-border bg-surface p-4 shadow-xs transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm"
                    >
                        <div class="h-12 w-12 shrink-0 overflow-hidden rounded-[10px] border border-border bg-white p-1 dark:bg-surface-2">
                            <img
                                src="{{ asset('images/inicio/contamax.webp') }}"
                                alt="Logo de ContaMax"
                                class="h-full w-full object-contain"
                                loading="lazy"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h4 class="font-serif text-lg font-medium leading-none text-foreground transition-colors group-hover:text-primary">ContaMax</h4>
                                <span class="inline-flex items-center gap-1 font-mono text-[10px] tracking-wider text-emerald-700 dark:text-emerald-400">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    EN PRODUCCIÓN
                                </span>
                            </div>
                            <p class="mt-1 text-[11px] leading-snug text-muted">Un sistema para acompañar la gestión contable, tus clientes y sus obligaciones.</p>
                        </div>
                        <span class="shrink-0 text-xs text-muted transition-transform group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden="true">↗</span>
                    </a>
                </div>

                {{-- Banner Final CTA con ∑ --}}
                <div class="relative mx-auto mt-14 flex min-h-[165px] max-w-[1080px] flex-col items-start justify-between gap-6 overflow-hidden rounded-[18px] bg-secondary p-8 text-secondary-foreground dark:border dark:border-border dark:bg-surface dark:text-foreground sm:flex-row sm:items-center sm:px-11 sm:py-9">
                    <div class="relative z-10">
                        <h2 class="font-serif text-3xl font-normal tracking-tight text-white dark:text-foreground sm:text-[35px]">
                            Hoy es un buen día para estudiar.
                        </h2>
                        <p class="mt-1.5 text-xs text-slate-300 dark:text-muted sm:text-sm">
                            Empieza gratis. Descubre la biblioteca. Elige cómo continuar.
                        </p>
                    </div>

                    <a
                        href="{{ auth()->check() ? route('library.index') : $registerOrLoginUrl }}"
                        class="relative z-10 inline-flex min-h-[46px] w-full shrink-0 items-center justify-center gap-2 rounded-full bg-gold px-6 text-xs font-semibold text-gold-foreground shadow-xs transition hover:-translate-y-0.5 hover:brightness-95 sm:w-auto sm:text-sm"
                    >
                        <span>{{ auth()->check() ? 'Ir a mi biblioteca' : 'Crear mi cuenta gratuita' }}</span>
                        <span aria-hidden="true">→</span>
                    </a>

                    <span class="pointer-events-none absolute -bottom-16 right-[32%] font-serif text-[210px] leading-none text-white/[0.04] dark:text-foreground/[0.04]" aria-hidden="true">∑</span>
                </div>
            </section>
        </main>

        {{-- ============ PIE DE PÁGINA ============ --}}
        <footer class="border-t border-border bg-surface transition-colors" data-purpose="site-footer">
            <div class="mx-auto flex min-h-[110px] max-w-[1320px] flex-col items-center justify-between gap-4 px-4 py-6 text-center sm:flex-row sm:px-6 sm:text-left lg:px-8">
                <a href="#inicio" class="flex items-center gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-background p-1 text-primary">
                        <x-application-logo class="h-full w-full text-primary" />
                    </span>
                    <div class="flex flex-col text-left">
                        <strong class="font-serif text-sm font-medium text-foreground">{{ $brandName }}</strong>
                        <small class="text-[10px] text-muted">{{ $brandTagline }}</small>
                    </div>
                </a>

                <div class="font-serif text-lg italic text-muted">
                    Lee. Comprende. Practica.
                </div>

                <div class="flex flex-col items-center gap-1 text-[11px] text-muted sm:items-end">
                    <a href="{{ auth()->check() ? route('membership.index') : '#suscripcion' }}" class="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline">
                        <span>Formas de acceso</span>
                        <span aria-hidden="true">→</span>
                    </a>
                    <span>&copy; {{ date('Y') }} &middot; Max Apaza Luna</span>
                </div>
            </div>
        </footer>
    </body>
</html>
