<x-guest-layout>
    <x-slot:heading>
        {{ __('Iniciar sesión') }}
    </x-slot:heading>

    <x-slot:subheading>
        {{ __('Ingresa tus credenciales para acceder al sistema') }}
    </x-slot:subheading>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Correo electrónico')" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="usuario@ejemplo.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <!-- Password con alternancia de visibilidad -->
        <div x-data="{ show: false }">
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Contraseña')" />
                @if (Route::has('password.request'))
                    <a class="text-xs font-medium text-primary hover:text-primary-hover transition-colors focus:outline-none focus:underline" href="{{ route('password.request') }}">
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </a>
                @endif
            </div>

            <div class="relative mt-1.5">
                <x-text-input id="password" class="block w-full pe-10 font-sans"
                                x-bind:type="show ? 'text' : 'password'"
                                name="password"
                                required autocomplete="current-password"
                                placeholder="••••••••" />
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

            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between pt-1">
            <label for="remember_me" class="inline-flex items-center cursor-pointer select-none">
                <input id="remember_me" type="checkbox" class="h-4 w-4 rounded border-border bg-surface text-primary shadow-xs focus:ring-2 focus:ring-primary/30 focus:ring-offset-0 transition-colors" name="remember">
                <span class="ms-2 text-xs font-medium text-muted hover:text-foreground transition-colors">{{ __('Recordar este dispositivo') }}</span>
            </label>
        </div>

        <div class="pt-2">
            <x-primary-button class="w-full py-2.5">
                {{ __('Ingresar a la plataforma') }}
            </x-primary-button>
        </div>
    </form>

    <x-slot:footer>
        @if (Route::has('register'))
            <span>¿Aún no tienes cuenta?</span>
            <a href="{{ route('register') }}" class="font-semibold text-primary hover:text-primary-hover ms-1 transition-colors">
                {{ __('Regístrate aquí') }}
            </a>
        @endif
    </x-slot:footer>
</x-guest-layout>
