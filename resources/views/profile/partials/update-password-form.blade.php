<section>
    <header class="border-b border-border/60 pb-4">
        <h2 class="text-base font-semibold text-foreground tracking-tight">
            {{ __('Seguridad de la Cuenta') }}
        </h2>

        <p class="mt-1 text-xs text-muted leading-relaxed">
            {{ __('Asegúrate de que tu cuenta utilice una contraseña segura de al menos 8 caracteres para mantenerse protegida.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('put')

        <!-- Contraseña Actual -->
        <div x-data="{ show: false }">
            <x-input-label for="update_password_current_password" :value="__('Contraseña Actual')" class="text-xs font-medium text-foreground" />
            <div class="relative mt-1.5">
                <x-text-input
                    id="update_password_current_password"
                    name="current_password"
                    x-bind:type="show ? 'text' : 'password'"
                    class="block w-full pe-10 font-sans text-sm"
                    autocomplete="current-password"
                    placeholder="Tu contraseña actual"
                />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute inset-y-0 end-0 flex items-center pe-3 text-muted hover:text-foreground focus:outline-none transition-colors"
                    :aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    tabindex="-1"
                >
                    <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>
            <x-input-error :messages="$errors->updatePassword->first('current_password')" class="mt-1.5" />
        </div>

        <!-- Nueva Contraseña -->
        <div>
            <x-input-label for="update_password_password" :value="__('Nueva Contraseña')" class="text-xs font-medium text-foreground" />
            <div class="mt-1.5">
                <x-password-strength id="update_password_password" name="password" placeholder="Mínimo 8 caracteres" />
            </div>
            <x-input-error :messages="$errors->updatePassword->first('password')" class="mt-1.5" />
        </div>

        <!-- Confirmar Contraseña -->
        <div x-data="{ show: false, pwd: '', confirmation: '' }" @password-changed.window="pwd = $event.detail">
            <div class="flex items-center justify-between">
                <x-input-label for="update_password_password_confirmation" :value="__('Confirmar Nueva Contraseña')" class="text-xs font-medium text-foreground" />
                <span x-show="confirmation.length > 0" x-cloak x-transition.opacity
                      class="text-[11px] font-medium"
                      :class="confirmation === pwd ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500 dark:text-rose-400'"
                      x-text="confirmation === pwd ? '✓ Coinciden' : 'No coinciden'">
                </span>
            </div>
            <div class="relative mt-1.5">
                <x-text-input
                    id="update_password_password_confirmation"
                    name="password_confirmation"
                    x-bind:type="show ? 'text' : 'password'"
                    class="block w-full pe-10 font-sans text-sm"
                    autocomplete="new-password"
                    x-model="confirmation"
                    placeholder="Repite la nueva contraseña"
                />
                <button
                    type="button"
                    @click="show = !show"
                    class="absolute inset-y-0 end-0 flex items-center pe-3 text-muted hover:text-foreground focus:outline-none transition-colors"
                    :aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                    tabindex="-1"
                >
                    <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 0 1 6 0z" />
                    </svg>
                    <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>
            <x-input-error :messages="$errors->updatePassword->first('password_confirmation')" class="mt-1.5" />
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-primary-button class="text-xs py-2 px-4 shadow-xs">
                {{ __('Guardar Contraseña') }}
            </x-primary-button>

            @if (session('status') === 'password-updated')
                <span
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 3000)"
                    class="text-xs text-emerald-600 dark:text-emerald-400 font-medium inline-flex items-center gap-1.5"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                    </svg>
                    {{ __('Guardado correctamente') }}
                </span>
            @endif
        </div>
    </form>
</section>
