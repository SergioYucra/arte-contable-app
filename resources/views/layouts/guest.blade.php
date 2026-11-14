<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Prevención de FOUC de tema visual --}}
        <x-theme-script />

        {{-- SEO técnico: títulos en español y noindex por defecto en formularios de autenticación/verificación --}}
        @php
            $resolvedTitle = $title
                ?: (isset($heading) ? trim(strip_tags((string) $heading)) : null)
                ?: config('brand.name');
            $seoData = new \RalphJSmit\Laravel\SEO\Support\SEOData(
                title: $resolvedTitle,
                robots: $noindex ? 'noindex, nofollow' : null,
            );
        @endphp
        {!! seo($seoData) !!}

        {{-- Tipografías oficiales: Inter (Sans), Newsreader (Serif Editorial), JetBrains Mono (Mono) --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|newsreader:400,400i,500,600,700|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-background text-foreground selection:bg-accent/25 selection:text-foreground">
        <div class="min-h-screen flex">

            {{-- ============ Panel editorial de marca (izquierda, solo desktop) ============ --}}
            <aside class="relative hidden lg:flex lg:w-[46%] xl:w-[48%] flex-col justify-between overflow-hidden bg-secondary text-secondary-foreground border-r border-border/20 p-10 xl:p-14">
                {{-- Sutil textura editorial de líneas de cuaderno --}}
                <div class="pointer-events-none absolute inset-0 opacity-[0.06]" style="background-image: linear-gradient(to bottom, transparent 31px, rgba(255,255,255,0.6) 32px); background-size: 100% 32px;"></div>

                {{-- Marca + Volver al inicio --}}
                <div class="relative z-10 flex items-center justify-between gap-4">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-3 group">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 border border-white/15 overflow-hidden transition group-hover:bg-white/15">
                            <x-application-logo class="h-8 w-8 object-contain" />
                        </span>
                        <span class="flex flex-col leading-tight">
                            <span class="font-serif text-lg font-medium tracking-tight text-white">{{ config('brand.name') }}</span>
                            <span class="font-mono text-[10px] tracking-wider text-slate-300">ESPACIO DE ESTUDIO</span>
                        </span>
                    </a>

                    <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3.5 py-1.5 text-xs font-medium text-slate-200 transition hover:bg-white/10 hover:text-white">
                        <span aria-hidden="true">←</span>
                        <span>Inicio</span>
                    </a>
                </div>

                {{-- Mensaje editorial principal --}}
                <div class="relative z-10 max-w-lg animate-fade-up">
                    <div class="mb-6 flex items-center gap-3">
                        <div class="h-px w-10 bg-gold"></div>
                        <span class="font-mono text-[10px] tracking-[0.2em] text-gold">BIBLIOTECA DIGITAL</span>
                    </div>

                    <h2 class="font-serif text-4xl xl:text-[44px] font-normal leading-[1.08] tracking-tight text-white">
                        Aprender contabilidad <em class="italic text-[#e5c887]">con calma</em> y claridad.
                    </h2>

                    <p class="mt-4 text-sm leading-[1.8] text-slate-300">
                        {{ config('brand.description') }}
                    </p>

                    <ul class="mt-8 space-y-3.5">
                        @foreach (config('brand.features', []) as $feature)
                            <li class="flex items-start gap-3 text-xs sm:text-sm text-slate-200">
                                <span class="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full bg-gold/20 text-[#e5c887] border border-gold/40">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                                <span class="leading-relaxed">{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Nota editorial al pie del mensaje --}}
                    <div class="mt-9 inline-flex items-center gap-3.5 rounded-xl border border-white/10 bg-white/[0.05] px-4 py-3">
                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full border border-[#e5c887]/50 bg-white/10 p-1">
                            <img src="{{ asset('images/inicio/arte_contable.webp') }}" alt="Emblema {{ config('brand.name') }}" class="h-full w-full object-contain" />
                        </div>
                        <div class="text-xs leading-snug">
                            <p class="font-serif italic text-white">“Todo tiene que estar en equilibrio.”</p>
                            <span class="font-mono text-[10px] tracking-wider text-slate-300">ACTIVO = PASIVO + PATRIMONIO</span>
                        </div>
                    </div>
                </div>

                <div class="relative z-10 flex items-center justify-between text-xs text-slate-400">
                    <span>&copy; {{ date('Y') }} {{ config('brand.name') }}</span>
                    <span class="font-serif italic text-slate-300">Lee. Comprende. Practica.</span>
                </div>
            </aside>

            {{-- ============ Panel de formulario (derecha) ============ --}}
            <main class="relative flex flex-1 flex-col items-center justify-center px-5 py-12 sm:px-10">
                {{-- Controles superiores (Volver en mobile + Tema) --}}
                <div class="absolute top-5 inset-x-5 z-20 flex items-center justify-between lg:justify-end sm:top-6 sm:inset-x-8">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3.5 py-1.5 text-xs font-medium text-foreground transition hover:border-primary/40 lg:hidden">
                        <span aria-hidden="true">←</span>
                        <span>Inicio</span>
                    </a>
                    <x-theme-toggle />
                </div>

                <div class="relative w-full max-w-[460px] animate-fade-up pt-8 lg:pt-0">
                    {{-- Logo mobile --}}
                    <a href="{{ url('/') }}" class="mb-7 inline-flex items-center gap-3 lg:hidden">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-surface overflow-hidden">
                            <x-application-logo class="h-7 w-7 object-contain" />
                        </span>
                        <span class="font-serif text-lg font-medium tracking-tight text-foreground">{{ config('brand.name') }}</span>
                    </a>

                    @isset($heading)
                        <div class="mb-6">
                            <h1 class="font-serif text-3xl sm:text-[34px] font-normal leading-tight tracking-tight text-foreground">{{ $heading }}</h1>
                            @isset($subheading)
                                <p class="mt-2 text-sm text-muted">{{ $subheading }}</p>
                            @endisset
                        </div>
                    @endisset

                    <div class="rounded-2xl border border-border bg-surface p-6 sm:p-8 shadow-sm">
                        {{ $slot }}
                    </div>

                    @isset($footer)
                        <div class="mt-6 text-center text-sm text-muted">
                            {{ $footer }}
                        </div>
                    @endisset
                </div>
            </main>
        </div>
    </body>
</html>
