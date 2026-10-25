<section>
    <header class="border-b border-border/60 pb-4">
        <h2 class="text-base font-semibold text-foreground tracking-tight">
            {{ __('Información del Perfil') }}
        </h2>

        <p class="mt-1 text-xs text-muted leading-relaxed">
            {{ __('Actualiza tu nombre visible y dirección de correo electrónico vinculada a la cuenta.') }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Nombre Completo')" class="text-xs font-medium text-foreground" />
            <x-text-input id="name" name="name" type="text" class="mt-1.5 block w-full text-sm font-sans" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-1.5" :messages="$errors->first('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Correo Electrónico')" class="text-xs font-medium text-foreground" />
            <x-text-input id="email" name="email" type="email" class="mt-1.5 block w-full text-sm font-sans" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-1.5" :messages="$errors->first('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-3 p-3 rounded-brand bg-amber-500/10 border border-amber-500/20 text-xs text-amber-800 dark:text-amber-300">
                    <p class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-amber-600 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ __('Tu correo electrónico no ha sido verificado aún.') }}</span>
                    </p>
                    <button form="send-verification" class="mt-2 text-xs font-medium text-primary hover:underline focus:outline-none">
                        {{ __('Reenviar correo de verificación') }} &rarr;
                    </button>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-emerald-600 flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
                            </svg>
                            {{ __('Se ha enviado un nuevo enlace de verificación a tu bandeja.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-primary-button class="text-xs py-2 px-4 shadow-xs">
                {{ __('Guardar Información') }}
            </x-primary-button>

            @if (session('status') === 'profile-updated')
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
                    {{ __('Perfil actualizado correctamente') }}
                </span>
            @endif
        </div>
    </form>
</section>
