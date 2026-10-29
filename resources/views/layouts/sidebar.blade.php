@php
    $user = auth()->user();
    $roleLabels = [
        'SuperAdmin' => 'Superadministrador',
        'Admin' => 'Administrador',
        'Manager' => 'Coordinador',
        'User' => 'Estudiante',
    ];
    $roleLabel = $roleLabels[$user->getRoleNames()->first()] ?? 'Sin rol asignado';
    $initial = mb_strtoupper(mb_substr($user->name, 0, 1));
@endphp

{{-- Fondo oscurecido para el menú móvil --}}
<div
    x-show="mobileOpen"
    x-cloak
    x-transition.opacity
    @click="mobileOpen = false"
    class="fixed inset-0 z-30 bg-black/60 backdrop-blur-xs lg:hidden"
    aria-hidden="true"
></div>

<aside
    id="app-sidebar"
    class="app-sidebar fixed inset-y-0 start-0 z-40 flex flex-col border-e border-border bg-surface"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    aria-label="Menú principal"
    @keydown.escape.window="mobileOpen = false"
>
    {{-- Marca --}}
    <div class="sidebar-brand flex h-16 shrink-0 items-center gap-3 border-b border-border px-4">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" title="{{ config('brand.name') }}">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-primary/10 border border-primary/20">
                <x-application-logo class="h-7 w-7 object-contain text-primary" />
            </span>
            <span class="sidebar-label min-w-0 leading-tight">
                <span class="block truncate text-sm font-bold tracking-tight text-foreground">{{ config('brand.name') }}</span>
                <span class="block truncate text-[11px] text-muted">{{ __('Espacio de estudio') }}</span>
            </span>
        </a>

        {{-- Cerrar menú (solo móvil) --}}
        <button type="button" @click="mobileOpen = false" class="ms-auto rounded-lg p-1.5 text-muted hover:bg-background hover:text-foreground lg:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50" aria-label="Cerrar menú">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
    </div>

    {{-- Navegación --}}
    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5" aria-label="Secciones">
        <div class="space-y-1">
            <p class="sidebar-section-title px-3 pb-1 text-[10px] font-semibold uppercase tracking-widest text-muted/80">{{ __('Principal') }}</p>

            <x-sidebar-link :href="route('dashboard')" :label="__('Inicio')" :active="request()->routeIs('dashboard')">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" /></svg>
                </x-slot:icon>
            </x-sidebar-link>
        </div>

        @can('manage-users')
            <div class="space-y-1">
                <p class="sidebar-section-title px-3 pb-1 text-[10px] font-semibold uppercase tracking-widest text-muted/80">{{ __('Administración') }}</p>

                <x-sidebar-link :href="route('admin.users.index')" :label="__('Usuarios y roles')" :active="request()->routeIs('admin.users.*')">
                    <x-slot:icon>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>
            </div>
        @endcan
    </nav>

    {{-- Cuenta --}}
    <div class="shrink-0 space-y-1 border-t border-border p-3">
        <x-sidebar-link :href="route('profile.edit')" :label="__('Mi perfil')" :active="request()->routeIs('profile.*')">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><circle cx="12" cy="10" r="3" /><path d="M6.17 18.5a7 7 0 0 1 11.66 0" /></svg>
            </x-slot:icon>
        </x-sidebar-link>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                title="{{ __('Cerrar sesión') }}"
                class="nav-item group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-muted transition-colors duration-150 hover:bg-rose-500/10 hover:text-rose-600 dark:hover:text-rose-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500/50"
            >
                <span class="flex h-5 w-5 shrink-0 items-center justify-center" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /></svg>
                </span>
                <span class="sidebar-label truncate">{{ __('Cerrar sesión') }}</span>
            </button>
        </form>

        {{-- Tarjeta de usuario --}}
        <div class="sidebar-user mt-2 flex items-center gap-3 rounded-xl border border-border bg-background/60 p-2.5" title="{{ $user->name }} · {{ $roleLabel }}">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary border border-primary/20" aria-hidden="true">{{ $initial }}</span>
            <span class="sidebar-label min-w-0 leading-tight">
                <span class="block truncate text-sm font-semibold text-foreground">{{ $user->name }}</span>
                <span class="block truncate text-[11px] text-muted">{{ $roleLabel }}</span>
            </span>
        </div>
    </div>
</aside>
