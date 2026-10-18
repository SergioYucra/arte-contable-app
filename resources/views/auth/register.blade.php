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
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Correo electrónico')" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="tu@empresa.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Contraseña')" />

            <x-text-input id="password" class="block mt-1.5 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password"
                            placeholder="Mínimo 8 caracteres" />

            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <!-- Confirm Password -->
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirmar contraseña')" />

            <x-text-input id="password_confirmation" class="block mt-1.5 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password"
                            placeholder="Repite tu contraseña" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
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
