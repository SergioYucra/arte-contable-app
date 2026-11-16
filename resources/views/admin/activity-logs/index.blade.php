<x-app-layout :title="__('Bitácora de actividad')">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
                <h1 class="font-semibold text-lg sm:text-xl text-foreground leading-tight truncate">
                    {{ __('Bitácora de actividad') }}
                </h1>
                <p class="hidden sm:block text-xs text-muted mt-1">
                    {{ __('Registro inmutable de auditoría del sistema. Acceso exclusivo para Superadministrador.') }}
                </p>
            </div>

            <div class="shrink-0 flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1 rounded-full text-[11px] sm:text-xs font-semibold bg-primary/10 text-primary border border-primary/20 tabular-nums">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    {{ number_format($initialData['meta']['total'] ?? 0, 0, ',', '.') }}
                    <span class="hidden sm:inline">{{ __('eventos registrados') }}</span>
                    <span class="sm:hidden">{{ __('eventos') }}</span>
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="w-full px-3 sm:px-6 lg:px-8">
            <div
                data-vue-island="ActivityLogViewer"
                data-props="{{ json_encode([
                    'initialData' => $initialData,
                    'logOptions' => $logOptions,
                    'eventOptions' => $eventOptions,
                    'listUrl' => route('admin.activity-logs.list'),
                ]) }}"
            ></div>
        </div>
    </div>
</x-app-layout>
