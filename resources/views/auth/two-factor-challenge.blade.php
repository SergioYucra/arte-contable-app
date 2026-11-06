<x-guest-layout :title="__('Verificación en dos pasos')">
    <x-slot:heading>
        {{ __('Verificación en dos pasos') }}
    </x-slot:heading>

    <x-slot:subheading>
        {{ __('Escribe el código de 6 dígitos de tu app de autenticación') }}
    </x-slot:subheading>

    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }" class="space-y-4">
        <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-4">
            @csrf

            <div x-show="! recovery">
                <x-input-label for="code" :value="__('Código de la app')" />
                <x-text-input id="code" class="block mt-1.5 w-full font-mono tracking-widest" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus x-bind:disabled="recovery" />
                <x-input-error :messages="$errors->first('code')" class="mt-1.5" />
            </div>

            <div x-show="recovery" x-cloak>
                <x-input-label for="recovery_code" :value="__('Código de recuperación')" />
                <x-text-input id="recovery_code" class="block mt-1.5 w-full font-mono" type="text" name="recovery_code" autocomplete="off" maxlength="64" x-bind:disabled="! recovery" />
                <x-input-error :messages="$errors->first('recovery_code')" class="mt-1.5" />
            </div>

            <div class="pt-2">
                <x-primary-button class="w-full py-2.5">
                    {{ __('Verificar y entrar') }}
                </x-primary-button>
            </div>
        </form>

        <button type="button" class="text-xs font-medium text-primary hover:text-primary-hover" @click="recovery = ! recovery">
            <span x-show="! recovery">{{ __('¿Perdiste el teléfono? Usa un código de recuperación') }}</span>
            <span x-show="recovery" x-cloak>{{ __('Usar el código de la app') }}</span>
        </button>
    </div>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-sm text-primary hover:text-primary-hover transition-colors">
            &larr; {{ __('Volver a inicio de sesión') }}
        </a>
    </x-slot:footer>
</x-guest-layout>
