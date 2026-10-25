<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- SEO técnico (ralphjsmit/laravel-seo): title, description, canonical, Open Graph. --}}
        {!! seo() !!}

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-background text-foreground">
        <div class="min-h-screen flex">

            {{-- ============ Panel de marca (izquierda, solo desktop) ============ --}}
            <aside class="relative hidden lg:flex lg:w-1/2 xl:w-[55%] flex-col justify-between overflow-hidden bg-secondary text-secondary-foreground p-12 xl:p-16">
                {{-- Fondo decorativo --}}
                <div class="pointer-events-none absolute inset-0 bg-brand-grid [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)]"></div>
                <div class="pointer-events-none absolute -top-32 -left-24 h-[28rem] w-[28rem] rounded-full bg-primary/40 blur-3xl animate-float-slow"></div>
                <div class="pointer-events-none absolute -bottom-40 right-[-6rem] h-[30rem] w-[30rem] rounded-full bg-accent/25 blur-3xl animate-float-slower"></div>

                {{-- Logo --}}
                <a href="/" class="relative z-10 inline-flex items-center gap-3 group">
                    <span class="flex h-11 w-11 items-center justify-center rounded-brand bg-white/10 ring-1 ring-white/20 backdrop-blur transition group-hover:bg-white/15">
                        @if (config('brand.logo'))
                            <img src="{{ asset(config('brand.logo')) }}" alt="{{ config('brand.name') }}" class="h-7 w-7 object-contain">
                        @else
                            <x-application-logo class="h-6 w-6 fill-current text-white" />
                        @endif
                    </span>
                    <span class="text-lg font-semibold tracking-tight">{{ config('brand.name') }}</span>
                </a>

                {{-- Mensaje principal --}}
                <div class="relative z-10 max-w-lg animate-fade-up">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium ring-1 ring-white/15 backdrop-blur">
                        <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
                        {{ config('brand.name') }}
                    </span>

                    <h2 class="mt-6 text-4xl xl:text-5xl font-semibold leading-tight tracking-tight">
                        {{ config('brand.tagline') }}
                    </h2>

                    <p class="mt-4 text-base leading-relaxed text-secondary-foreground/70">
                        {{ config('brand.description') }}
                    </p>

                    <ul class="mt-10 space-y-4">
                        @foreach (config('brand.features', []) as $feature)
                            <li class="flex items-center gap-3 text-sm text-secondary-foreground/90">
                                <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-primary/30 ring-1 ring-primary/50">
                                    <svg class="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                                {{ $feature }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative z-10 text-xs text-secondary-foreground/50">
                    &copy; {{ date('Y') }} {{ config('brand.name') }}. {{ __('Todos los derechos reservados.') }}
                </p>
            </aside>

            {{-- ============ Panel de formulario (derecha) ============ --}}
            <main class="relative flex flex-1 flex-col items-center justify-center px-6 py-12 sm:px-10">
                {{-- Glow sutil en mobile/tablet --}}
                <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-primary/10 to-transparent lg:hidden"></div>

                <div class="relative w-full max-w-md animate-fade-up">
                    {{-- Logo mobile --}}
                    <a href="/" class="mb-10 inline-flex items-center gap-3 lg:hidden">
                        <span class="flex h-11 w-11 items-center justify-center rounded-brand bg-primary text-primary-foreground shadow-lg shadow-primary/30">
                            <x-application-logo class="h-6 w-6 fill-current" />
                        </span>
                        <span class="text-lg font-semibold tracking-tight">{{ config('brand.name') }}</span>
                    </a>

                    @isset($heading)
                        <div class="mb-8">
                            <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight text-foreground">{{ $heading }}</h1>
                            @isset($subheading)
                                <p class="mt-2 text-sm text-muted">{{ $subheading }}</p>
                            @endisset
                        </div>
                    @endisset

                    <div class="rounded-brand border border-border bg-surface p-6 sm:p-8 shadow-xl shadow-secondary/5">
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
