<x-app-layout :title="__('Mi perfil')">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Perfil') }}
            </h2>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                {{ __('Configuración de cuenta') }}
            </span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="w-full px-4 sm:px-6 lg:px-8 grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
            <div class="p-6 sm:p-8 bg-surface border border-border rounded-brand shadow-xs">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="p-6 sm:p-8 bg-surface border border-border rounded-brand shadow-xs">
                @include('profile.partials.update-password-form')
            </div>

            {{-- P4: verificación en dos pasos --}}
            <div class="p-6 sm:p-8 bg-surface border border-border rounded-brand shadow-xs">
                <header class="border-b border-border/60 pb-4">
                    <h2 class="text-base font-semibold text-foreground tracking-tight">{{ __('Verificación en dos pasos') }}</h2>
                    <p class="mt-1 text-xs text-muted leading-relaxed">
                        {{ __('Un código de tu teléfono, además de la contraseña, para que nadie más pueda entrar a tu cuenta.') }}
                    </p>
                </header>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-foreground">
                        @if ($user->hasEnabledTwoFactorAuthentication())
                            {{ __('Estado: activa.') }}
                        @elseif ($user->requiresTwoFactorAuthentication())
                            {{ __('Estado: inactiva. Tu nivel de acceso la exige para usar el panel.') }}
                        @else
                            {{ __('Estado: inactiva (opcional para tu nivel de acceso).') }}
                        @endif
                    </p>
                    @if ($user->hasVerifiedEmail())
                        <a href="{{ route('two-factor.show') }}" class="text-xs font-semibold text-primary hover:text-primary-hover">
                            {{ $user->hasEnabledTwoFactorAuthentication() ? __('Administrar') : __('Activar') }} &rarr;
                        </a>
                    @else
                        <span class="text-xs text-muted">{{ __('Verifica tu correo para activarla.') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
