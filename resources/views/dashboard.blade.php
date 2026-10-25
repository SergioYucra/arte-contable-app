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
@endphp

<x-app-layout>
    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Bienvenida --}}
            <section class="relative overflow-hidden rounded-brand border border-border bg-surface p-6 sm:p-8 shadow-xs" aria-labelledby="welcome-heading">
                <div class="pointer-events-none absolute -top-16 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 id="welcome-heading" class="text-2xl font-bold tracking-tight text-foreground">
                            {{ __('¡Hola, :name!', ['name' => $firstName]) }}
                        </h2>
                        <p class="mt-1.5 text-sm text-muted max-w-xl leading-relaxed">
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

            {{-- Tarjetas de resumen --}}
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" aria-label="{{ __('Resumen de tu cuenta') }}">
                <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Mi cuenta') }}</p>
                            <p class="text-sm font-semibold text-foreground truncate" title="{{ $user->email }}">{{ $user->email }}</p>
                        </div>
                    </div>
                </article>

                <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4 12 14.01l-3-3" /></svg>
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Correo electrónico') }}</p>
                            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">{{ __('Verificado') }}</p>
                        </div>
                    </div>
                </article>

                <article class="rounded-brand border border-border bg-surface p-5 shadow-xs">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Miembro desde') }}</p>
                            <p class="text-sm font-semibold text-foreground">{{ $user->created_at?->translatedFormat('d \d\e F \d\e Y') }}</p>
                        </div>
                    </div>
                </article>
            </section>

            {{-- Accesos rápidos --}}
            <section aria-labelledby="quick-heading">
                <h2 id="quick-heading" class="text-sm font-semibold text-foreground mb-3">{{ __('Accesos rápidos') }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="{{ route('profile.edit') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Mi perfil') }}</p>
                        <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Actualiza tus datos y cambia tu contraseña.') }}</p>
                    </a>

                    @can('manage-users')
                        <a href="{{ route('admin.users.index') }}" class="group rounded-brand border border-border bg-surface p-5 shadow-xs hover:border-primary/40 hover:shadow-md transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <p class="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">{{ __('Usuarios y roles') }}</p>
                            <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Consulta los usuarios registrados y administra sus niveles de acceso.') }}</p>
                        </a>
                    @endcan

                    <div class="rounded-brand border border-dashed border-border bg-background/60 p-5">
                        <p class="text-sm font-semibold text-foreground">{{ __('Práctica contable') }}</p>
                        <p class="mt-1 text-xs text-muted leading-relaxed">{{ __('Ejercicios, asientos y balances del libro estarán disponibles próximamente.') }}</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
