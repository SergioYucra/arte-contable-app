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
        </div>
    </div>
</x-app-layout>
