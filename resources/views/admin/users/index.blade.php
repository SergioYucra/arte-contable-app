<x-app-layout :title="__('Usuarios y niveles de acceso')">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="font-semibold text-xl text-foreground leading-tight">
                    {{ __('Usuarios y niveles de acceso') }}
                </h1>
                <p class="text-xs text-muted mt-1">
                    {{ __('Consulta quién tiene acceso a la plataforma y qué puede hacer.') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                    {{ count($users) }} {{ __('usuarios registrados') }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            {{-- Isla Vue 3 (Seguridad H6: montaje por nombre y data-props sin compilación en navegador) --}}
            <div
                data-vue-island="UserManagementTable"
                data-props="{{ json_encode([
                    'initialUsers' => $users,
                    'availableRoles' => $availableRoles,
                    'updateRoleUrlTemplate' => route('admin.users.update-role', ':id'),
                    'deleteUserUrlTemplate' => route('admin.users.destroy', ':id'),
                    'restoreUserUrlTemplate' => route('admin.users.restore', ':id'),
                    'resendVerificationUrlTemplate' => route('admin.users.resend-verification', ':id'),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
