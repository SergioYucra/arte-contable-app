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
    class="fixed inset-0 z-30 bg-[#0b1d16]/65 backdrop-blur-xs lg:hidden"
    aria-hidden="true"
></div>

<aside
    id="app-sidebar"
    class="app-sidebar fixed inset-y-0 start-0 z-40 flex flex-col border-e border-white/10 px-4 pt-6 pb-3.5"
    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    aria-label="Menú principal"
    @keydown.escape.window="mobileOpen = false"
>
    {{-- Marca --}}
    <div class="sidebar-brand flex min-h-[48px] shrink-0 items-center justify-between gap-2 px-1 mb-8">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-[#14b8a6]/60" title="{{ config('brand.name') }}">
            <span class="flex h-[38px] w-[38px] shrink-0 items-center justify-center overflow-hidden rounded-xl border border-[#a4cede]/25 bg-[#1b5674]/35 text-[#c4e7f1]">
                <x-application-logo class="h-6 w-6 object-contain" />
            </span>
            <span class="sidebar-label min-w-0 leading-tight">
                <span class="block truncate font-serif text-[16px] font-medium leading-[1.08] tracking-[-0.25px] text-[#f4f7f4]">{{ config('brand.name') }}</span>
                <span class="mt-1 block truncate text-[10.5px] text-[#9bb9c8]">{{ __('Espacio de estudio') }}</span>
            </span>
        </a>

        {{-- Cerrar menú (solo móvil) --}}
        <button type="button" @click="mobileOpen = false" class="ms-auto grid h-8 w-8 shrink-0 place-items-center rounded-lg border border-white/15 text-white/80 hover:bg-white/10 hover:text-white lg:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-[#14b8a6]/60" aria-label="Cerrar menú">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </button>
    </div>

    {{-- Navegación --}}
    <nav class="flex-1 space-y-6 overflow-y-auto no-scrollbar" aria-label="Secciones">
        <div class="space-y-1">
            <p class="sidebar-section-title px-3 pb-1.5 font-mono text-[9.5px] font-medium uppercase tracking-[1.2px] text-[#8daab9]">{{ __('Principal') }}</p>

            <x-sidebar-link :href="route('dashboard')" :label="__('Inicio')" :active="request()->routeIs('dashboard')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            <x-sidebar-link :href="route('library.index')" :label="__('Biblioteca de estudio')" :active="request()->routeIs('library.*')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20" /><path d="M8 7h6" /><path d="M8 11h8" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            <x-sidebar-link :href="route('membership.index')" :label="__('Membresía y planes')" :active="request()->routeIs('membership.*')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            <x-sidebar-link :href="route('quick-links.index')" :label="__('Accesos rápidos')" :active="request()->routeIs('quick-links.*')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            <x-sidebar-link :href="route('calculators.index')" :label="__('Calculadoras')" :active="request()->routeIs('calculators.*')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" /><line x1="8" x2="16" y1="6" y2="6" /><line x1="16" x2="16" y1="14" y2="18" /><path d="M16 10h.01" /><path d="M12 10h.01" /><path d="M8 10h.01" /><path d="M12 14h.01" /><path d="M8 14h.01" /><path d="M12 18h.01" /><path d="M8 18h.01" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            <x-sidebar-link :href="route('tools.index')" :label="__('Herramientas')" :active="request()->routeIs('tools.*')">
                <x-slot:icon>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" /></svg>
                </x-slot:icon>
            </x-sidebar-link>

            {{-- Módulo TaxRelief: mismos roles que sus rutas (app/Modules/TaxRelief/Routes/web.php) --}}
            @if (config('tax_relief.enabled') && $user->hasAnyRole(['User', 'Manager', 'Admin', 'SuperAdmin']))
                <x-sidebar-link :href="route('tax-relief.index')" :label="__('Calculadora tributaria')" :active="request()->routeIs('tax-relief.*')">
                    <x-slot:icon>
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" /><line x1="8" x2="16" y1="6" y2="6" /><line x1="16" x2="16" y1="14" y2="18" /><path d="M16 10h.01M12 10h.01M8 10h.01M12 14h.01M8 14h.01M12 18h.01M8 18h.01" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>
            @endif
        </div>

        @can('manage-users')
            <div class="space-y-1">
                <p class="sidebar-section-title px-3 pb-1.5 font-mono text-[9.5px] font-medium uppercase tracking-[1.2px] text-[#8daab9]">{{ __('Administración') }}</p>

                <x-sidebar-link :href="route('admin.books.index')" :label="__('Libros y ediciones')" :active="request()->routeIs('admin.books.*')">
                    <x-slot:icon>
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>

                <x-sidebar-link :href="route('admin.subscriptions.index')" :label="__('Suscripciones y pagos')" :active="request()->routeIs('admin.subscriptions.*')">
                    <x-slot:icon>
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>

                <x-sidebar-link :href="route('admin.quick-links.index')" :label="__('Accesos rápidos')" :active="request()->routeIs('admin.quick-links.*')">
                    <x-slot:icon>
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="M2 12h20" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>

                <x-sidebar-link :href="route('admin.users.index')" :label="__('Usuarios y roles')" :active="request()->routeIs('admin.users.*')">
                    <x-slot:icon>
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>
                    </x-slot:icon>
                </x-sidebar-link>

                @role('SuperAdmin')
                    <x-sidebar-link :href="route('admin.activity-logs.index')" :label="__('Bitácora de actividad')" :active="request()->routeIs('admin.activity-logs.*')">
                        <x-slot:icon>
                            <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3" /><circle cx="12" cy="12" r="9" /></svg>
                        </x-slot:icon>
                    </x-sidebar-link>
                @endrole
            </div>
        @endcan
    </nav>

    {{-- Cuenta --}}
    <div class="mt-4 shrink-0 space-y-1 pt-3">
        <p class="sidebar-section-title px-3 pb-1.5 font-mono text-[9.5px] font-medium uppercase tracking-[1.2px] text-[#8daab9]">{{ __('Cuenta') }}</p>

        <x-sidebar-link :href="route('profile.edit')" :label="__('Mi perfil')" :active="request()->routeIs('profile.*')">
            <x-slot:icon>
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><circle cx="12" cy="10" r="3" /><path d="M6.17 18.5a7 7 0 0 1 11.66 0" /></svg>
            </x-slot:icon>
        </x-sidebar-link>

        <form method="POST" action="{{ route('logout') }}" class="pb-3 border-b border-white/10">
            @csrf
            <button
                type="submit"
                title="{{ __('Cerrar sesión') }}"
                class="nav-item group flex min-h-[42px] w-full items-center gap-3 rounded-[10px] border border-transparent px-3 text-[13px] text-[#c3d5de] transition-all duration-150 hover:bg-rose-500/15 hover:text-rose-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400/50"
            >
                <span class="flex h-[18px] w-[18px] shrink-0 items-center justify-center text-[#9bb9c8] group-hover:text-rose-300" aria-hidden="true">
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /></svg>
                </span>
                <span class="sidebar-label truncate">{{ __('Cerrar sesión') }}</span>
            </button>
        </form>

        {{-- Tarjeta de usuario --}}
        <div class="sidebar-user flex items-center gap-2.5 px-1.5 pt-3.5 pb-2.5" title="{{ $user->name }} · {{ $roleLabel }}">
            <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full border border-[#80bdcf] bg-[#dceff3] font-serif text-[13px] font-semibold text-[#164d68]" aria-hidden="true">{{ $initial }}</span>
            <span class="sidebar-label min-w-0 leading-tight">
                <span class="block truncate text-[12px] font-medium text-[#f0f4f0]">{{ $user->name }}</span>
                <span class="block truncate text-[10.5px] text-[#9bb9c8]">{{ $roleLabel }}</span>
            </span>
            <span class="sidebar-user-status ms-auto h-[7px] w-[7px] shrink-0 rounded-full bg-[#14b8a6] shadow-[0_0_0_3px_rgba(20,184,166,0.2)]" aria-label="Cuenta activa"></span>
        </div>

        {{-- Contraer / expandir menú (escritorio) --}}
        <button
            type="button"
            @click="toggleCollapse()"
            class="sidebar-collapse-btn hidden min-h-[36px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-[11.5px] text-[#9bb9c8] transition-colors duration-150 hover:bg-white/[0.07] hover:text-white lg:flex focus:outline-none focus-visible:ring-2 focus-visible:ring-[#14b8a6]/60"
            :aria-label="collapsed ? 'Expandir menú' : 'Contraer menú'"
            :title="collapsed ? 'Expandir menú' : 'Contraer menú'"
            aria-controls="app-sidebar"
            :aria-expanded="! collapsed"
        >
            <svg x-show="! collapsed" class="h-[17px] w-[17px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" /><path d="M9 3v18" /><path d="m16 15-3-3 3-3" /></svg>
            <svg x-show="collapsed" x-cloak class="h-[17px] w-[17px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" /><path d="M9 3v18" /><path d="m14 9 3 3-3 3" /></svg>
            <span class="sidebar-label truncate">{{ __('Contraer menú') }}</span>
        </button>
    </div>
</aside>
