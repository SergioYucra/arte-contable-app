<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-foreground leading-tight">
                    {{ __('Administración de Usuarios y Roles') }}
                </h2>
                <p class="text-xs text-muted mt-1">
                    {{ __('Control de accesos y niveles de seguridad del sistema.') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                    {{ count($users) }} {{ __('Usuarios activos') }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            {{-- Isla Vue 3: Montaje reactivo del módulo interactivo de usuarios --}}
            <div data-vue-island>
                <user-management-table
                    :initial-users="@js($users)"
                    :available-roles="@js($availableRoles)"
                    update-role-url-template="{{ route('admin.users.update-role', ':id') }}"
                ></user-management-table>
            </div>
        </div>
    </div>
</x-app-layout>
