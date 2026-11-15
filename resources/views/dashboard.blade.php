@php
    $user = auth()->user();
    $roleName = $user->getRoleNames()->first();
    $roleLabels = [
        'SuperAdmin' => 'Superadministrador',
        'Admin' => 'Administrador',
        'Manager' => 'Coordinador',
        'User' => 'Estudiante',
    ];
    $roleLabel = $roleLabels[$roleName] ?? 'Sin rol asignado';
    $firstName = \Illuminate\Support\Str::of($user->name)->explode(' ')->first();

    // Enmascaramiento seguro de correo (cumplimiento PII Masking)
    $emailParts = explode('@', (string) $user->email);
    $maskedEmail = count($emailParts) === 2
        ? \Illuminate\Support\Str::mask($emailParts[0], '*', min(2, strlen($emailParts[0])), max(1, strlen($emailParts[0]) - 2)) . '@' . $emailParts[1]
        : $user->email;

    // Estado de lectura activo más reciente para integración con el Dashboard
    $latestReadingState = \App\Models\ReadingState::with(['bookEdition.book'])
        ->where('user_id', $user->id)
        ->where('last_page', '>', 0)
        ->whereHas('bookEdition', function ($q) {
            $q->where('status', \App\Models\BookEdition::STATUS_READY)
              ->whereHas('book', fn ($bq) => $bq->where('is_published', true));
        })
        ->latest('updated_at')
        ->first();

    $activeReadingHero = null;
    if ($latestReadingState && $latestReadingState->bookEdition && $latestReadingState->bookEdition->book) {
        $ed = $latestReadingState->bookEdition;
        $bk = $ed->book;
        $totalPgs = max(1, (int) $ed->total_pages);
        $currPg = (int) $latestReadingState->last_page;
        $pct = min(100, (int) round(($currPg / $totalPgs) * 100));
        $coverUrl = ! empty($bk->cover_path) ? \Illuminate\Support\Facades\Storage::disk('public')->url($bk->cover_path) : null;

        $activeReadingHero = [
            'book_title' => $bk->title,
            'edition_id' => $ed->id,
            'edition_name' => $ed->edition_name,
            'current_page' => $currPg,
            'total_pages' => $totalPgs,
            'percentage' => $pct,
            'cover_url' => $coverUrl,
            'updated_at_human' => $latestReadingState->updated_at?->diffForHumans() ?? 'Reciente',
        ];
    }

    $publishedBooksCount = \App\Models\Book::where('is_published', true)->count();
@endphp

<x-app-layout :title="__('Inicio')">
    <div class="py-6 sm:py-8">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($user->hasRole('SuperAdmin') || $user->hasRole('Admin'))
                {{-- Bienvenida Administrativa --}}
                <section class="relative overflow-hidden rounded-brand border border-border bg-surface p-6 sm:p-8 shadow-xs" aria-labelledby="welcome-heading">
                    <div class="pointer-events-none absolute -top-16 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" aria-hidden="true"></div>
                    <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h2 id="welcome-heading" class="text-2xl font-bold tracking-tight text-foreground">
                                {{ __('¡Hola, :name!', ['name' => $firstName]) }}
                            </h2>
                            <p class="mt-1.5 text-sm text-muted max-w-2xl leading-relaxed">
                                {{ config('brand.tagline') }}. {{ __('Aquí encontrarás tu información de cuenta y los accesos a tus herramientas de gestión.') }}
                            </p>
                        </div>
                        <span class="inline-flex w-fit items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold bg-background text-foreground border border-border">
                            <svg class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z" />
                            </svg>
                            {{ $roleLabel }}
                        </span>
                    </div>
                </section>

                {{-- Tarjetas Informativas para Administrador / SuperAdmin --}}
                <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-label="{{ __('Panel de control administrativo') }}">
                    <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Nivel de acceso') }}</p>
                                <p class="text-sm font-semibold text-foreground truncate">{{ $roleLabel }}</p>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Gestión de usuarios') }}</p>
                                <p class="text-sm font-semibold text-foreground">
                                    <a href="{{ route('admin.users.index') }}" class="hover:text-primary transition-colors underline decoration-dotted">
                                        {{ __('Administrar accesos') }} &rarr;
                                    </a>
                                </p>
                            </div>
                        </div>
                    </article>

                    @can('view-audit-logs')
                        <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="m9 12 2 2 4-4" /></svg>
                                </span>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Seguridad y auditoría') }}</p>
                                    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">{{ __('Bitácora inmutable activa') }}</p>
                                </div>
                            </div>
                        </article>
                    @else
                        <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4 12 14.01l-3-3" /></svg>
                                </span>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Estado de cuenta') }}</p>
                                    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">{{ __('Correo verificado y activo') }}</p>
                                </div>
                            </div>
                        </article>
                    @endcan
                </section>

                @if ($activeReadingHero)
                    <section aria-labelledby="dashboard-continue-reading" class="relative overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/95 text-slate-100 p-5 sm:p-6 shadow-lg">
                        <div class="pointer-events-none absolute -right-16 -top-16 h-52 w-52 rounded-full bg-sky-500/10 blur-3xl" aria-hidden="true"></div>
                        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-4 min-w-0 flex-1">
                                <div class="h-24 w-18 sm:h-28 sm:w-20 shrink-0 rounded-xl overflow-hidden border border-slate-700 bg-slate-800 shadow-md">
                                    @if ($activeReadingHero['cover_url'])
                                        <img src="{{ $activeReadingHero['cover_url'] }}" alt="Portada de {{ $activeReadingHero['book_title'] }}" class="h-full w-full object-cover" />
                                    @else
                                        <div class="h-full w-full flex flex-col items-center justify-center p-2 text-center bg-slate-800 text-sky-400">
                                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                        </div>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1 space-y-2.5">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-500/15 border border-sky-400/30 px-2.5 py-0.5 font-semibold text-sky-300">
                                            {{ __('En progreso') }}
                                        </span>
                                        <span class="rounded-md bg-slate-800 border border-slate-700 px-2 py-0.5 text-slate-300 font-medium">
                                            {{ $activeReadingHero['edition_name'] }}
                                        </span>
                                        <span class="text-slate-400">
                                            {{ $activeReadingHero['updated_at_human'] }}
                                        </span>
                                    </div>

                                    <h2 id="dashboard-continue-reading" class="text-base sm:text-lg font-bold text-white truncate">
                                        {{ $activeReadingHero['book_title'] }}
                                    </h2>

                                    <div class="max-w-lg space-y-1.5">
                                        <div class="flex items-center justify-between text-xs text-slate-300">
                                            <span>
                                                Pág. <strong class="text-white">{{ $activeReadingHero['current_page'] }}</strong> de {{ $activeReadingHero['total_pages'] }}
                                                <span class="text-sky-400 font-semibold ml-1">({{ $activeReadingHero['percentage'] }}%)</span>
                                            </span>
                                        </div>
                                        <div class="h-2 w-full rounded-full bg-slate-800 border border-slate-700 overflow-hidden">
                                            <div class="h-full rounded-full bg-sky-400" style="width: {{ max(3, $activeReadingHero['percentage']) }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 shrink-0">
                                <a
                                    href="{{ route('library.reader', ['edition' => $activeReadingHero['edition_id'], 'page' => $activeReadingHero['current_page']]) }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-400 hover:bg-sky-300 text-slate-950 font-semibold text-xs sm:text-sm px-5 py-2.5 shadow-sm transition-colors"
                                >
                                    <span>{{ __('Continuar lectura') }} &rarr;</span>
                                </a>
                                <a
                                    href="{{ route('library.index') }}"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-700 bg-slate-800/80 hover:bg-slate-800 text-slate-200 font-medium text-xs sm:text-sm px-4 py-2.5 transition-colors"
                                >
                                    {{ __('Ver catálogo') }}
                                </a>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Accesos rápidos (Admin) --}}
                <section aria-labelledby="quick-heading">
                    <h2 id="quick-heading" class="text-sm font-semibold text-foreground mb-3">{{ __('Accesos rápidos') }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <a href="{{ route('library.index') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Biblioteca y catálogo') }}</p>
                                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold text-primary">
                                    {{ $publishedBooksCount }} {{ $publishedBooksCount === 1 ? __('libro') : __('libros') }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Explora los libros publicados, revisa el índice temático y continúa tu lectura.') }}</p>
                        </a>

                        <a href="{{ route('profile.edit') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Mi perfil') }}</p>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Actualiza tus datos y cambia tu contraseña.') }}</p>
                        </a>

                        <a href="{{ route('admin.users.index') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Usuarios y roles') }}</p>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Consulta los usuarios registrados y administra sus niveles de acceso.') }}</p>
                        </a>
                    </div>
                </section>

            @else
                {{-- Inicio del Estudiante (Editorial) --}}
                <section aria-labelledby="welcome-title" class="pt-1">
                    <div class="inline-flex items-center gap-2 font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-[#1e5949] dark:text-[#87c9a6]">
                        <span class="h-1.5 w-1.5 rounded-full bg-[#82b893]" aria-hidden="true"></span>
                        {{ __('Bienvenida a tu espacio de estudio') }}
                    </div>
                    <h2 id="welcome-title" class="mt-2 font-serif text-[30px] sm:text-[36px] font-medium leading-[1.06] tracking-[-0.02em] text-foreground">
                        {{ __('Hola, :name.', ['name' => $firstName]) }}
                    </h2>
                    <p class="mt-1.5 max-w-[610px] text-sm text-muted leading-relaxed">
                        {{ __('Qué bueno tenerte aquí. Lee con calma, guarda tus ideas y practica a tu propio ritmo.') }}
                    </p>
                </section>

                {{-- Hero Editorial: Continuar lectura o Lectura recomendada + Ecuación Contable --}}
                <section
                    aria-labelledby="welcome-hero-title"
                    class="relative overflow-hidden rounded-2xl border border-[#174b58] p-6 sm:p-8 lg:p-9 text-[#f2f8f5] shadow-[0_13px_30px_rgba(18,59,77,0.12)]"
                    style="background: radial-gradient(circle at 96% 2%, rgba(61, 153, 145, 0.26), transparent 36%), linear-gradient(118deg, #102f43 0%, #155767 68%, #176e6c 100%);"
                >
                    <div class="pointer-events-none absolute -top-24 right-[26%] h-64 w-64 rounded-full border border-white/10" aria-hidden="true"></div>

                    <div class="relative z-10 grid grid-cols-1 lg:grid-cols-[minmax(0,1.15fr)_minmax(270px,0.85fr)] items-center gap-6 lg:gap-12">
                        {{-- Columna izquierda --}}
                        <div class="max-w-[590px]">
                            @if ($activeReadingHero)
                                <span class="inline-flex items-center gap-2 font-mono text-[10px] font-medium tracking-[0.1em] uppercase text-[#a7d8c8]">
                                    <span class="h-[7px] w-[7px] rounded-full bg-[#8be0b9] shadow-[0_0_0_4px_rgba(139,224,185,0.12)]" aria-hidden="true"></span>
                                    {{ __('Continuar lectura') }} &middot; {{ \Illuminate\Support\Str::upper($activeReadingHero['edition_name']) }}
                                </span>

                                <h2 id="welcome-hero-title" class="mt-3.5 max-w-[510px] font-serif text-[27px] sm:text-[34px] font-medium leading-[1.08] tracking-[-0.015em] text-[#f5fbf8]">
                                    {{ $activeReadingHero['book_title'] }}
                                </h2>

                                <p class="mt-2.5 max-w-[480px] text-[13px] leading-relaxed text-[#c6dedf]">
                                    {{ __('Retoma exactamente donde lo dejaste. Llevas :pct% completado en esta edición.', ['pct' => $activeReadingHero['percentage']]) }}
                                </p>

                                <div class="mt-4 max-w-md space-y-2">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[#c7dddc]">
                                        <span class="font-semibold text-[#edf5f1]">
                                            {{ __('Pág. :curr de :total', ['curr' => $activeReadingHero['current_page'], 'total' => $activeReadingHero['total_pages']]) }}
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg class="h-3.5 w-3.5 text-[#9fdac5]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
                                            {{ $activeReadingHero['updated_at_human'] }}
                                        </span>
                                    </div>
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/15">
                                        <div class="h-full rounded-full bg-[#80d6b0]" style="width: {{ max(4, $activeReadingHero['percentage']) }}%"></div>
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-wrap items-center gap-3">
                                    <a
                                        href="{{ route('library.reader', ['edition' => $activeReadingHero['edition_id'], 'page' => $activeReadingHero['current_page']]) }}"
                                        class="inline-flex min-h-[38px] items-center justify-center gap-2 rounded-[7px] border border-[#a5e3c9] bg-[#a9e5cd] px-3.5 text-xs font-bold text-[#173f46] transition-all hover:-translate-y-0.5 hover:bg-[#c2f0dd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#83bd9f]"
                                    >
                                        {{ __('Continuar lectura') }}
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                                    </a>
                                    <a
                                        href="{{ route('library.index') }}"
                                        class="inline-flex min-h-[38px] items-center justify-center rounded-[7px] border border-white/25 bg-white/10 px-3.5 text-xs font-semibold text-[#edf5f1] transition-colors hover:bg-white/15"
                                    >
                                        {{ __('Ver biblioteca') }}
                                    </a>
                                </div>
                            @else
                                <span class="inline-flex items-center gap-2 font-mono text-[10px] font-medium tracking-[0.1em] uppercase text-[#a7d8c8]">
                                    <span class="h-[7px] w-[7px] rounded-full bg-[#8be0b9] shadow-[0_0_0_4px_rgba(139,224,185,0.12)]" aria-hidden="true"></span>
                                    {{ __('Lectura recomendada') }} &middot; {{ __('Capítulo 01') }}
                                </span>

                                <h2 id="welcome-hero-title" class="mt-3.5 max-w-[510px] font-serif text-[27px] sm:text-[36px] font-medium leading-[1.08] tracking-[-0.015em] text-[#f5fbf8]">
                                    {{ __('Comprende antes de registrar.') }}
                                </h2>

                                <p class="mt-2.5 max-w-[480px] text-[13px] leading-relaxed text-[#c6dedf]">
                                    {{ __('El primer paso para aprender es entender la historia detrás de cada movimiento contable.') }}
                                </p>

                                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-[#c7dddc]">
                                    <span class="font-semibold text-[#edf5f1]">{{ config('brand.name', 'El Arte de Contabilizar desde 0') }}</span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <svg class="h-3.5 w-3.5 text-[#9fdac5]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
                                        {{ __('6 min de lectura') }}
                                    </span>
                                </div>

                                <a
                                    href="{{ route('library.index') }}"
                                    class="mt-5 inline-flex min-h-[38px] items-center justify-center gap-2 rounded-[7px] border border-[#a5e3c9] bg-[#a9e5cd] px-3.5 text-xs font-bold text-[#173f46] transition-all hover:-translate-y-0.5 hover:bg-[#c2f0dd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#83bd9f]"
                                >
                                    {{ __('Abrir primera lectura') }}
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                                </a>
                            @endif
                        </div>

                        {{-- Columna derecha: Tarjeta Ecuación Contable --}}
                        <div
                            class="w-full lg:max-w-[390px] lg:justify-self-end rounded-xl border border-white/20 p-5 shadow-[0_12px_24px_rgba(7,27,39,0.16)] backdrop-blur-md"
                            style="background: linear-gradient(145deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.04));"
                            aria-label="{{ __('Ecuación contable: activo igual pasivo más patrimonio') }}"
                        >
                            <div class="flex items-center justify-between font-mono text-[10px] font-medium tracking-[0.08em] text-[#b7d9d5]">
                                <span>{{ __('FUNDAMENTO 01') }}</span>
                                <span class="grid h-6 w-6 place-items-center rounded-[7px] border border-[#b2e5ce]/35 bg-[#8bdab5]/15 text-[#a6e5c8]">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12" /></svg>
                                </span>
                            </div>

                            <div class="mt-4 font-serif text-[20px] font-medium text-[#f4f9f7]">
                                {{ __('La ecuación contable') }}
                            </div>

                            <div class="mt-3.5 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/15 bg-[#092d3a]/35 px-3 py-3 text-xs font-semibold text-[#e6f4ee]">
                                <span>{{ __('Activo') }}</span>
                                <b class="font-mono text-sm font-medium text-[#88d2b4]">=</b>
                                <span>{{ __('Pasivo') }}</span>
                                <b class="font-mono text-sm font-medium text-[#88d2b4]">+</b>
                                <span>{{ __('Patrimonio') }}</span>
                            </div>

                            <div class="mt-2.5 text-[11px] text-[#b7d5d4]">
                                {{ __('Todo registro parte de una relación.') }}
                            </div>

                            <div class="mt-3.5 h-[3px] w-full overflow-hidden rounded bg-white/15">
                                <span class="block h-full w-[68%] rounded bg-[#80d6b0]"></span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ¿Qué te gustaría hacer? (Accesos principales) --}}
                <section aria-labelledby="welcome-shortcuts-title" class="pt-1">
                    <div class="flex flex-col md:flex-row md:items-end justify-between gap-2 mb-3.5">
                        <div>
                            <span class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-[#1e5949] dark:text-[#87c9a6]">
                                {{ __('Tu espacio') }}
                            </span>
                            <h2 id="welcome-shortcuts-title" class="mt-1 font-serif text-[23px] font-medium leading-tight tracking-[-0.01em] text-foreground">
                                {{ __('¿Qué te gustaría hacer?') }}
                            </h2>
                        </div>
                        <p class="max-w-[320px] text-xs text-muted leading-relaxed md:text-right">
                            {{ __('Elige un camino y empieza por lo que necesitas hoy.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {{-- 01 Biblioteca de estudio --}}
                        <a
                            href="{{ route('library.index') }}"
                            class="group flex min-h-[157px] flex-col rounded-[11px] border border-border bg-surface p-4 text-foreground transition-all hover:-translate-y-0.5 hover:border-[#8ab7a0] hover:shadow-[0_8px_20px_rgba(20,61,41,0.07)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#83bd9f]"
                        >
                            <div class="flex items-center justify-between gap-2 font-mono text-[10px] font-medium tracking-[0.06em] text-muted">
                                <span>01 / {{ __('ACCESO') }}</span>
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#eaf3ed] text-[#1e5949] dark:bg-[#27392f] dark:text-[#87c9a6]">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 6 4 14" /><path d="M12 6v14" /><path d="M8 8v12" /><path d="M4 4v16" /></svg>
                                </span>
                            </div>
                            <h3 class="mt-3 font-serif text-[18px] font-medium text-foreground">
                                {{ __('Biblioteca de estudio') }}
                            </h3>
                            <p class="mt-1 flex-1 text-xs text-muted leading-relaxed">
                                {{ __('Explora libros y materiales para avanzar a tu ritmo.') }}
                            </p>
                            <span class="mt-3 inline-flex items-center gap-1 text-[11px] font-bold text-[#1e5949] dark:text-[#87c9a6]">
                                {{ __('Ir a la biblioteca') }}
                                <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                            </span>
                        </a>

                        {{-- 02 Cuaderno y práctica --}}
                        <a
                            href="{{ $activeReadingHero ? route('library.reader', ['edition' => $activeReadingHero['edition_id'], 'page' => $activeReadingHero['current_page']]) : route('library.index') }}"
                            class="group flex min-h-[157px] flex-col rounded-[11px] border border-border bg-surface p-4 text-foreground transition-all hover:-translate-y-0.5 hover:border-[#8ab7a0] hover:shadow-[0_8px_20px_rgba(20,61,41,0.07)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#83bd9f]"
                        >
                            <div class="flex items-center justify-between gap-2 font-mono text-[10px] font-medium tracking-[0.06em] text-muted">
                                <span>02 / {{ __('ACCESO') }}</span>
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#eaf3ed] text-[#1e5949] dark:bg-[#27392f] dark:text-[#87c9a6]">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z" /><path d="m15 5 3 3" /></svg>
                                </span>
                            </div>
                            <h3 class="mt-3 font-serif text-[18px] font-medium text-foreground">
                                {{ __('Cuaderno y práctica') }}
                            </h3>
                            <p class="mt-1 flex-1 text-xs text-muted leading-relaxed">
                                {{ __('Relaciona tus apuntes y ejercicios con cada lectura.') }}
                            </p>
                            <span class="mt-3 inline-flex items-center gap-1 text-[11px] font-bold text-[#1e5949] dark:text-[#87c9a6]">
                                {{ __('Ver herramientas') }}
                                <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                            </span>
                        </a>

                        {{-- 03 Membresía --}}
                        <a
                            href="{{ route('membership.index') }}"
                            class="group flex min-h-[157px] flex-col rounded-[11px] border border-border bg-surface p-4 text-foreground transition-all hover:-translate-y-0.5 hover:border-[#8ab7a0] hover:shadow-[0_8px_20px_rgba(20,61,41,0.07)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#83bd9f]"
                        >
                            <div class="flex items-center justify-between gap-2 font-mono text-[10px] font-medium tracking-[0.06em] text-muted">
                                <span>03 / {{ __('ACCESO') }}</span>
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#eaf3ed] text-[#1e5949] dark:bg-[#27392f] dark:text-[#87c9a6]">
                                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" /></svg>
                                </span>
                            </div>
                            <h3 class="mt-3 font-serif text-[18px] font-medium text-foreground">
                                {{ __('Membresía') }}
                            </h3>
                            <p class="mt-1 flex-1 text-xs text-muted leading-relaxed">
                                {{ __('Conoce las opciones para acceder a toda la biblioteca.') }}
                            </p>
                            <span class="mt-3 inline-flex items-center gap-1 text-[11px] font-bold text-[#1e5949] dark:text-[#87c9a6]">
                                {{ $user->hasActiveSubscription() ? __('Ver mi membresía') : __('Explorar planes') }}
                                <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                            </span>
                        </a>
                    </div>
                </section>

                {{-- Un método sencillo: Leer. Comprender. Practicar. --}}
                <section
                    aria-labelledby="welcome-method-title"
                    class="grid grid-cols-1 lg:grid-cols-[minmax(230px,0.82fr)_minmax(0,1.6fr)] items-center gap-5 rounded-[11px] border border-border bg-surface/85 p-4 sm:p-5"
                >
                    <div class="flex items-start gap-3">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-border bg-surface text-[#1e5949] dark:text-[#87c9a6]">
                            <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
                        </span>
                        <div>
                            <span class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-[#1e5949] dark:text-[#87c9a6]">
                                {{ __('Un método sencillo') }}
                            </span>
                            <h2 id="welcome-method-title" class="mt-0.5 font-serif text-[18px] font-medium text-foreground">
                                {{ __('Leer. Comprender. Practicar.') }}
                            </h2>
                            <p class="mt-1 text-xs text-muted leading-relaxed">
                                {{ __('Avanza paso a paso; no necesitas saberlo todo para empezar.') }}
                            </p>
                        </div>
                    </div>

                    <ol class="grid grid-cols-1 md:grid-cols-3 gap-3.5">
                        <li class="flex items-start gap-2.5 min-w-0">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-[7px] border border-border bg-surface text-[#1e5949] dark:text-[#87c9a6]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
                            </span>
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-mono text-[9px] font-medium tracking-[0.06em] text-muted">{{ __('PASO 01') }}</span>
                                <strong class="text-xs font-semibold text-foreground">{{ __('Lee') }}</strong>
                                <small class="text-[11px] text-muted leading-snug">{{ __('Empieza por una idea, sin apuro.') }}</small>
                            </div>
                        </li>

                        <li class="flex items-start gap-2.5 min-w-0">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-[7px] border border-border bg-surface text-[#1e5949] dark:text-[#87c9a6]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z" /><path d="M6 12v5c3 3 9 3 12 0v-5" /></svg>
                            </span>
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-mono text-[9px] font-medium tracking-[0.06em] text-muted">{{ __('PASO 02') }}</span>
                                <strong class="text-xs font-semibold text-foreground">{{ __('Comprende') }}</strong>
                                <small class="text-[11px] text-muted leading-snug">{{ __('Busca el porqué detrás del registro.') }}</small>
                            </div>
                        </li>

                        <li class="flex items-start gap-2.5 min-w-0">
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-[7px] border border-border bg-surface text-[#1e5949] dark:text-[#87c9a6]">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="16" height="20" x="4" y="2" rx="2" /><line x1="8" x2="16" y1="6" y2="6" /><line x1="16" x2="16" y1="14" y2="18" /><path d="M16 10h.01" /><path d="M12 10h.01" /><path d="M8 10h.01" /><path d="M12 14h.01" /><path d="M8 14h.01" /><path d="M12 18h.01" /><path d="M8 18h.01" /></svg>
                            </span>
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-mono text-[9px] font-medium tracking-[0.06em] text-muted">{{ __('PASO 03') }}</span>
                                <strong class="text-xs font-semibold text-foreground">{{ __('Practica') }}</strong>
                                <small class="text-[11px] text-muted leading-snug">{{ __('Lleva lo aprendido a un ejercicio.') }}</small>
                            </div>
                        </li>
                    </ol>
                </section>

                {{-- Pie editorial --}}
                <footer class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-3 border-t border-border text-[11px] text-muted">
                    <span>{{ __('El Arte de la Contabilidad · Aprender desde cero, paso a paso.') }}</span>
                    <a href="{{ route('membership.index') }}" class="inline-flex items-center gap-1.5 font-semibold text-[#1e5949] dark:text-[#87c9a6] hover:underline">
                        {{ __('Conoce la membresía') }}
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></svg>
                    </a>
                </footer>
            @endif
        </div>
    </div>
</x-app-layout>
