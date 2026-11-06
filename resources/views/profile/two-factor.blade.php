<x-app-layout :title="__('Verificación en dos pasos')">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Verificación en dos pasos') }}
            </h2>
            @if ($enabled)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20">{{ __('Activa') }}</span>
            @else
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">{{ __('Inactiva') }}</span>
            @endif
        </div>
    </x-slot>

    <div class="py-6">
        <div class="w-full max-w-3xl px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('two-factor-required'))
                <div role="alert" class="rounded-brand border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-900 dark:text-amber-200">
                    {{ session('two-factor-required') }}
                </div>
            @endif

            @php
                $statusMessages = [
                    'two-factor-enabled' => __('Listo: la verificación en dos pasos quedó activa.'),
                    'two-factor-disabled' => __('La verificación en dos pasos quedó desactivada.'),
                    'recovery-codes-regenerated' => __('Generamos códigos de recuperación nuevos. Los anteriores ya no sirven.'),
                    'recovery-code-used' => __('Entraste con un código de recuperación, que ya no se puede volver a usar. Si te quedan pocos, genera códigos nuevos.'),
                ];
            @endphp
            @if (isset($statusMessages[session('status')]))
                <div role="status" class="rounded-brand border border-emerald-500/25 bg-emerald-500/10 p-4 text-sm text-emerald-900 dark:text-emerald-200">
                    {{ $statusMessages[session('status')] }}
                </div>
            @endif

            <div class="p-6 sm:p-8 bg-surface border border-border rounded-brand shadow-xs space-y-4">
                <p class="text-sm text-foreground leading-relaxed">
                    {{ __('Además de tu contraseña, al entrar te pediremos un código de 6 dígitos que genera una app en tu teléfono (Google Authenticator, Microsoft Authenticator, Authy u otra). Así, aunque alguien consiga tu contraseña, no puede entrar sin tu teléfono.') }}
                </p>

                @if ($required)
                    <p class="text-xs text-muted leading-relaxed">
                        {{ __('Tu nivel de acceso la exige: sin ella no puedes usar el panel.') }}
                    </p>
                @endif

                {{-- Códigos de recuperación recién generados: se muestran una sola vez --}}
                @if ($recoveryCodes)
                    <div class="rounded-brand border border-primary/30 bg-primary/5 p-4">
                        <h3 class="text-sm font-semibold text-foreground">{{ __('Guarda tus códigos de recuperación') }}</h3>
                        <p class="mt-1 text-xs text-muted leading-relaxed">
                            {{ __('Si pierdes el teléfono, cada código te deja entrar una vez. Guárdalos en un lugar seguro: no los volveremos a mostrar.') }}
                        </p>
                        <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-foreground">
                            @foreach ($recoveryCodes as $code)
                                <li class="rounded bg-background px-2 py-1">{{ $code }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($enabled)
                    <p class="text-xs text-muted">
                        {{ trans_choice('{0} No te quedan códigos de recuperación: genera códigos nuevos.|{1} Te queda :count código de recuperación.|[2,*] Te quedan :count códigos de recuperación.', $remainingRecoveryCodes, ['count' => $remainingRecoveryCodes]) }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <form method="POST" action="{{ route('two-factor.recovery-codes') }}">
                            @csrf
                            <x-secondary-button type="submit" class="text-xs">{{ __('Generar códigos de recuperación nuevos') }}</x-secondary-button>
                        </form>

                        <form method="POST" action="{{ route('two-factor.disable') }}"
                              x-data
                              @submit="if (! confirm(@js($required ? __('Tu nivel de acceso exige la verificación en dos pasos: tendrás que activarla de nuevo para entrar al panel. ¿Desactivarla?') : __('¿Desactivar la verificación en dos pasos?')))) $event.preventDefault()">
                            @csrf
                            @method('DELETE')
                            <x-danger-button type="submit" class="text-xs">{{ __('Desactivar') }}</x-danger-button>
                        </form>
                    </div>
                @elseif ($pendingSecret)
                    <div class="grid gap-6 sm:grid-cols-[auto,1fr] items-start">
                        <div class="rounded-brand bg-white p-3 shadow-xs w-fit" aria-label="{{ __('Código QR para la app de autenticación') }}">
                            {!! $qrCodeSvg !!}
                        </div>
                        <div class="space-y-3">
                            <ol class="list-decimal ps-5 text-sm text-foreground space-y-1">
                                <li>{{ __('Abre la app de autenticación y escanea el código QR.') }}</li>
                                <li>{{ __('Escribe el código de 6 dígitos que te muestra la app.') }}</li>
                            </ol>
                            <p class="text-xs text-muted">
                                {{ __('¿No puedes escanear? Escribe esta clave en la app:') }}
                                <span class="mt-1 block break-all font-mono text-foreground select-all">{{ $pendingSecret }}</span>
                            </p>

                            <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-3">
                                @csrf
                                <div>
                                    <x-input-label for="code" :value="__('Código de la app')" class="text-xs" />
                                    <x-text-input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 block w-40 font-mono tracking-widest" required autofocus />
                                    <x-input-error :messages="$errors->first('code')" class="mt-1.5" />
                                </div>
                                <x-primary-button class="text-xs">{{ __('Confirmar y activar') }}</x-primary-button>
                            </form>
                        </div>
                    </div>
                @else
                    <form method="POST" action="{{ route('two-factor.enable') }}">
                        @csrf
                        <x-primary-button class="text-xs">{{ __('Activar verificación en dos pasos') }}</x-primary-button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
