<x-app-layout :title="__('Bitácora de auditoría')">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="font-semibold text-xl text-foreground leading-tight">
                    {{ __('Bitácora de auditoría') }}
                </h1>
                <p class="text-xs text-muted mt-1">
                    {{ __('Registro inmutable de las acciones de administración y de los eventos de seguridad. Solo lectura.') }}
                </p>
            </div>

            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                {{ $activities->total() }} {{ __('registros') }}
            </span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="w-full px-4 sm:px-6 lg:px-8 space-y-4">
            {{-- Filtros --}}
            <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="grid grid-cols-1 gap-3 rounded-brand border border-border bg-surface p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-6">
                <div>
                    <x-input-label for="log" :value="__('Tipo')" class="text-xs" />
                    <select id="log" name="log" class="mt-1 block w-full rounded-brand border-border bg-surface text-sm text-foreground focus:border-primary focus:ring-primary/30">
                        <option value="">{{ __('Todos') }}</option>
                        @foreach ($logNames as $logName)
                            <option value="{{ $logName }}" @selected(($filters['log'] ?? '') === $logName)>{{ $logLabels[$logName] ?? $logName }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <x-input-label for="q" :value="__('Descripción contiene')" class="text-xs" />
                    <x-text-input id="q" name="q" type="search" class="mt-1 block w-full text-sm" :value="$filters['q'] ?? ''" maxlength="100" />
                </div>

                <div>
                    <x-input-label for="causer" :value="__('Hecho por (nombre o correo)')" class="text-xs" />
                    <x-text-input id="causer" name="causer" type="search" class="mt-1 block w-full text-sm" :value="$filters['causer'] ?? ''" maxlength="255" />
                </div>

                <div>
                    <x-input-label for="from" :value="__('Desde')" class="text-xs" />
                    <x-text-input id="from" name="from" type="date" class="mt-1 block w-full text-sm" :value="$filters['from'] ?? ''" />
                </div>

                <div>
                    <x-input-label for="to" :value="__('Hasta')" class="text-xs" />
                    <x-text-input id="to" name="to" type="date" class="mt-1 block w-full text-sm" :value="$filters['to'] ?? ''" />
                </div>

                <div class="flex items-center gap-3 sm:col-span-2 lg:col-span-6">
                    <x-primary-button class="text-xs py-2 px-4">{{ __('Filtrar') }}</x-primary-button>
                    <a href="{{ route('admin.audit-logs.index') }}" class="text-xs font-medium text-muted hover:text-foreground">{{ __('Limpiar filtros') }}</a>
                </div>
            </form>

            {{-- Registros --}}
            <div class="overflow-hidden rounded-brand border border-border bg-surface shadow-xs">
                <ul class="divide-y divide-border">
                    @forelse ($activities as $activity)
                        <li class="p-4 sm:px-5">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-foreground break-words">{{ $activity->description }}</p>
                                    <p class="mt-1 text-xs text-muted">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider',
                                            'bg-rose-500/10 text-rose-700 dark:text-rose-300' => $activity->log_name === 'security',
                                            'bg-primary/10 text-primary' => $activity->log_name !== 'security',
                                        ])>{{ $logLabels[$activity->log_name] ?? $activity->log_name }}</span>
                                        <span class="ms-1">
                                            @if ($activity->causer)
                                                {{ __('Por') }} {{ $activity->causer->name }} ({{ $activity->causer->email }})
                                            @else
                                                {{ __('Sin usuario (sistema o visitante)') }}
                                            @endif
                                        </span>
                                    </p>
                                </div>
                                <time class="shrink-0 text-xs text-muted" datetime="{{ $activity->created_at?->toIso8601String() }}">
                                    {{ $activity->created_at?->format('d/m/Y H:i:s') }}
                                </time>
                            </div>

                            @if ($activity->properties && $activity->properties->isNotEmpty())
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-medium text-primary">{{ __('Detalles') }}</summary>
                                    <pre class="mt-2 overflow-x-auto rounded-brand bg-background p-3 text-[11px] leading-relaxed text-foreground">{{ json_encode($activity->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        </li>
                    @empty
                        <li class="p-8 text-center text-sm text-muted">{{ __('No hay registros con estos filtros.') }}</li>
                    @endforelse
                </ul>
            </div>

            {{ $activities->links() }}
        </div>
    </div>
</x-app-layout>
