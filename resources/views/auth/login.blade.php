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
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="tu@empresa.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <!-- Password -->
        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Contraseña')" />
                @if (Route::has('password.request'))
                    <a class="text-xs font-medium text-primary hover:text-primary-hover transition-colors" href="{{ route('password.request') }}">
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </a>
                @endif
            </div>

            <x-text-input id="password" class="block mt-1.5 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password"
                            placeholder="••••••••" />

            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between pt-1">
            <label for="remember_me" class="inline-flex items-center cursor-pointer select-none">
                <input id="remember_me" type="checkbox" class="rounded border-border text-primary shadow-sm focus:ring-primary focus:ring-offset-0" name="remember">
                <span class="ms-2 text-sm text-muted">{{ __('Recordar dispositivo') }}</span>
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
