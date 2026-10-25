<x-guest-layout>
    <x-slot:heading>
        {{ __('Recuperar contraseña') }}
    </x-slot:heading>

    <x-slot:subheading>
        {{ __('Ingresa tu correo y te enviaremos un enlace de restablecimiento seguro.') }}
    </x-slot:subheading>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Correo electrónico')" />
            <x-text-input id="email" class="block mt-1.5 w-full" type="email" name="email" :value="old('email')" required autofocus placeholder="usuario@ejemplo.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div class="pt-2">
            <x-primary-button class="w-full py-2.5">
                {{ __('Enviar enlace de restablecimiento') }}
            </x-primary-button>
        </div>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-sm text-primary hover:text-primary-hover transition-colors">
            &larr; {{ __('Volver a inicio de sesión') }}
        </a>
    </x-slot:footer>
</x-guest-layout>
