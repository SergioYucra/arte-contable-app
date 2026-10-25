<x-guest-layout>
    <x-slot:heading>
        {{ __('Crear una cuenta') }}
    </x-slot:heading>

    <x-slot:subheading>
        {{ __('Completa tus datos para comenzar en la plataforma') }}
    </x-slot:subheading>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Nombre completo')" />
            <x-text-input id="name" class="block mt-1.5 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Juan Pérez" />
            <x-input-error :messages="$errors->first('name')" class="mt-1.5" />
        </div>

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Correo electrónico')" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="tu@empresa.com" />
            <x-input-error :messages="$errors->first('email')" class="mt-1.5" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Contraseña')" />
            <div class="mt-1.5">
                <x-password-strength id="password" name="password" placeholder="Mínimo 8 caracteres">
                    <x-input-error :messages="$errors->first('password')" class="mt-1.5" />
                </x-password-strength>
            </div>
        </div>

        <!-- Confirm Password -->
        <div x-data="{ show: false, pwd: '', confirmation: '' }" @password-changed.window="pwd = $event.detail">
            <div class="flex items-center justify-between">
                <x-input-label for="password_confirmation" :value="__('Confirmar contraseña')" />
                <span x-show="confirmation.length > 0" x-cloak x-transition.opacity
                      class="text-xs font-semibold"
                      :class="confirmation === pwd ? 'text-emerald-600' : 'text-red-500'"
                      x-text="confirmation === pwd ? '✓ Las contraseñas coinciden' : 'Las contraseñas no coinciden'">
                </span>
            </div>

            <div class="relative mt-1.5">
                <x-text-input id="password_confirmation" class="block w-full pe-10 font-sans text-sm"
                                x-bind:type="show ? 'text' : 'password'"
                                name="password_confirmation"
                                x-model="confirmation"
                                required autocomplete="new-password"
                                placeholder="Repite tu contraseña" />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute inset-y-0 end-0 flex items-center px-3 text-muted hover:text-foreground focus:outline-none transition-colors"
                    :aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    tabindex="-1"
                >
                    <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 8.2 7.36 5 12 5s8.58 3.2 9.96 6.68a1 1 0 0 1 0 .64C20.58 15.8 16.64 19 12 19s-8.58-3.2-9.96-6.68Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.22A10.48 10.48 0 0 0 2.04 12c1.38 3.48 5.32 6.68 9.96 6.68 1.77 0 3.4-.46 4.82-1.2M6.23 6.23A10.45 10.45 0 0 1 12 5.32c4.64 0 8.58 3.2 9.96 6.68a10.5 10.5 0 0 1-4.29 5.07M6.23 6.23 3 3m3.23 3.23 3.65 3.65m7.89 7.89L21 21m-3.23-3.23-3.65-3.65m0 0a3 3 0 1 0-4.24-4.24m4.24 4.24L9.88 9.88" />
                    </svg>
                </button>
            </div>

            <x-input-error :messages="$errors->first('password_confirmation')" class="mt-1.5" />
        </div>

        <div class="pt-2">
            <x-primary-button class="w-full py-2.5">
                {{ __('Registrarme') }}
            </x-primary-button>
        </div>
    </form>

    <x-slot:footer>
        <span>¿Ya tienes una cuenta registrada?</span>
        <a href="{{ route('login') }}" class="font-semibold text-primary hover:text-primary-hover ms-1 transition-colors">
            {{ __('Inicia sesión') }}
        </a>
    </x-slot:footer>
</x-guest-layout>
