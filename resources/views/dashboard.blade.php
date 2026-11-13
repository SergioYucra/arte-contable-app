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
    <div class="py-6">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Bienvenida --}}
            <section class="relative overflow-hidden rounded-brand border border-border bg-surface p-6 sm:p-8 shadow-xs" aria-labelledby="welcome-heading">
                <div class="pointer-events-none absolute -top-16 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 id="welcome-heading" class="text-2xl font-bold tracking-tight text-foreground">
                            {{ __('¡Hola, :name!', ['name' => $firstName]) }}
                        </h2>
                        <p class="mt-1.5 text-sm text-muted max-w-2xl leading-relaxed">
                            {{ config('brand.tagline') }}. {{ __('Aquí encontrarás tu información de cuenta y los accesos a tus herramientas de estudio.') }}
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

            {{-- Tarjetas de resumen adaptadas al rol (N5) --}}
            @if ($user->hasRole('SuperAdmin') || $user->hasRole('Admin'))
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
            @else
                {{-- Tarjetas Informativas para Estudiante --}}
                <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-label="{{ __('Resumen de tu cuenta de estudio') }}">
                    <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Mi cuenta de estudiante') }}</p>
                                <p class="text-sm font-semibold text-foreground truncate">{{ $maskedEmail }}</p>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $user->hasVerifiedEmail() ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400' }}">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    @if ($user->hasVerifiedEmail())
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4 12 14.01l-3-3" />
                                    @else
                                        <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                                    @endif
                                </svg>
                            </span>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Estado de correo') }}</p>
                                <p class="text-sm font-semibold {{ $user->hasVerifiedEmail() ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                    {{ $user->hasVerifiedEmail() ? __('Verificado') : __('Pendiente de verificación') }}
                                </p>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $user->hasActiveSubscription() ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-primary/10 text-primary' }}">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Membresía de lectura') }}</p>
                                <p class="text-sm font-semibold {{ $user->hasActiveSubscription() ? 'text-emerald-700 dark:text-emerald-400' : 'text-foreground' }}">
                                    <a href="{{ route('membership.index') }}" class="hover:underline">
                                        {{ $user->hasActiveSubscription() ? __('Membresía activa') : __('Ver planes y canjear') }} &rarr;
                                    </a>
                                </p>
                            </div>
                        </div>
                    </article>
                </section>
            @endif

            {{-- Hero: Continuar Lectura integrado en el Dashboard --}}
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

            {{-- Accesos rápidos --}}
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

                    @can('manage-users')
                        <a href="{{ route('admin.users.index') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Usuarios y roles') }}</p>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Consulta los usuarios registrados y administra sus niveles de acceso.') }}</p>
                        </a>
                    @else
                        <a href="{{ route('membership.index') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Planes y membresía') }}</p>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Consulta el estado de tu suscripción, realiza pagos por QR o canjea códigos.') }}</p>
                        </a>
                    @endcan
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
