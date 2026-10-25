<x-guest-layout>
    <div
        x-data="{ editing: {{ $errors->has('email') ? 'true' : 'false' }} }"
        class="space-y-6"
    >
        {{-- Cabecera: icono + título --}}
        <div class="text-center">
            <div class="relative mx-auto flex h-16 w-16 items-center justify-center">
                <span class="absolute inset-0 rounded-full bg-primary/10 animate-ping [animation-duration:2.5s]"></span>
                <span class="relative flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 ring-1 ring-primary/20">
                    <svg class="h-7 w-7 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </span>
            </div>

            <h1 class="mt-5 text-xl font-semibold tracking-tight text-foreground">
                {{ __('Revisa tu bandeja de entrada') }}
            </h1>
            <p class="mt-2 text-sm text-muted leading-relaxed">
                {{ __('Te enviamos un enlace de verificación para activar tu cuenta.') }}
            </p>
        </div>

        {{-- Avisos de estado --}}
        @if (session('status') === 'verification-link-sent' || session('status') === 'email-updated')
            <div
                x-data="{ show: true }"
                x-show="show"
                x-transition.opacity
                role="status"
                class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-xs text-emerald-800"
            >
                <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                </svg>
                <span class="flex-1 font-medium leading-relaxed">
                    @if (session('status') === 'email-updated')
                        {{ __('Correo actualizado. Enviamos un nuevo enlace a tu nueva dirección.') }}
                    @else
                        {{ __('Enviamos un nuevo enlace de verificación a tu correo.') }}
                    @endif
                </span>
                <button type="button" @click="show = false" class="text-emerald-600 hover:text-emerald-800 transition-colors" aria-label="{{ __('Cerrar aviso') }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                </button>
            </div>
        @endif

        {{-- Correo destino --}}
        <div class="rounded-xl border border-border bg-background/60 p-4">
            <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Enviado a') }}</p>
                <button
                    type="button"
                    @click="editing = !editing"
                    class="text-xs font-semibold text-primary hover:text-primary-hover transition-colors focus:outline-none focus-visible:underline"
                >
                    <span x-show="!editing">{{ __('Cambiar correo') }}</span>
                    <span x-show="editing" x-cloak>{{ __('Cancelar') }}</span>
                </button>
            </div>
            <p class="mt-1.5 truncate text-sm font-medium text-foreground" title="{{ auth()->user()->email }}">
                {{ auth()->user()->email }}
            </p>

            {{-- Formulario de corrección --}}
            <div
                x-show="editing"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="mt-4 border-t border-border pt-4"
            >
                <form method="POST" action="{{ route('verification.update') }}" class="space-y-3">
                    @csrf
                    @method('put')

                    <div>
                        <x-input-label for="new_email" :value="__('Correo correcto')" class="text-xs font-medium text-foreground" />
                        <x-text-input
                            id="new_email"
                            type="email"
                            name="email"
                            class="mt-1.5 block w-full text-sm"
                            :value="old('email', auth()->user()->email)"
                            required
                            placeholder="tu@empresa.com"
                            x-effect="if (editing) $nextTick(() => $el.focus())"
                        />
                        <x-input-error :messages="$errors->first('email')" class="mt-1.5" />
                    </div>

                    <x-primary-button class="w-full justify-center py-2.5">
                        {{ __('Guardar y reenviar enlace') }}
                    </x-primary-button>
                </form>
            </div>
        </div>

        {{-- Acciones --}}
        <div x-show="!editing" class="space-y-4">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-primary-button class="w-full justify-center py-2.5">
                    {{ __('Reenviar correo de verificación') }}
                </x-primary-button>
            </form>

            <p class="text-center text-xs text-muted leading-relaxed">
                {{ __('¿No lo ves? Revisa la carpeta de spam o correo no deseado.') }}
            </p>
        </div>
    </div>

    <x-slot:footer>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-medium text-muted hover:text-foreground transition-colors">
                &larr; {{ __('Cerrar sesión') }}
            </button>
        </form>
    </x-slot:footer>
</x-guest-layout>
