@props([
    'id' => 'password',
    'name' => 'password',
    'placeholder' => '••••••••',
    'min' => 8,
])

<div
    x-data="{
        value: '',
        show: false,
        get hasLen() { return this.value.length >= {{ $min }}; },
        get hasCase() { return /[a-z]/.test(this.value) && /[A-Z]/.test(this.value); },
        get hasNum() { return /[0-9]/.test(this.value); },
        get hasSym() { return /[^a-zA-Z0-9]/.test(this.value); },
        get score() {
            let s = 0;
            if (this.hasLen) s++;
            if (this.hasCase) s++;
            if (this.hasNum) s++;
            if (this.hasSym) s++;
            return s;
        },
        get complete() { return this.score === 4; },
        get status() {
            if (this.score === 1) return { label: 'Débil', color: 'text-rose-500', bar: 'bg-rose-500' };
            if (this.score === 2) return { label: 'Regular', color: 'text-amber-500', bar: 'bg-amber-500' };
            if (this.score === 3) return { label: 'Buena', color: 'text-blue-500', bar: 'bg-blue-500' };
            if (this.score === 4) return { label: 'Segura', color: 'text-emerald-600 dark:text-emerald-400', bar: 'bg-emerald-500' };
            return { label: '', color: '', bar: 'bg-border' };
        },
        update() {
            this.$dispatch('password-changed', this.value);
        }
    }"
    class="space-y-2"
>
    {{-- Campo de entrada con toggle de visibilidad integrado --}}
    <div class="relative">
        <x-text-input
            :id="$id"
            :name="$name"
            x-model="value"
            x-bind:type="show ? 'text' : 'password'"
            @input="update()"
            required
            autocomplete="new-password"
            :placeholder="$placeholder"
            class="block w-full pe-10 font-sans text-sm"
        />

        <button
            type="button"
            @click="show = !show"
            class="absolute inset-y-0 end-0 flex items-center pe-3 text-muted hover:text-foreground focus:outline-none transition-colors"
            :aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'"
            tabindex="-1"
        >
            <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
    </div>

    {{-- Despliegue interactivo y elegante: solo aparece al empezar a escribir --}}
    <div
        x-show="value.length > 0"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1"
        class="rounded-lg border border-border/80 bg-surface p-3 space-y-2.5 shadow-xs"
    >
        {{-- Barra segmentada de fortaleza (4 segmentos precisos) --}}
        <div class="flex items-center justify-between gap-3">
            <div class="grid grid-cols-4 gap-1.5 flex-1">
                <div class="h-1 rounded-full transition-colors duration-200" :class="score >= 1 ? status.bar : 'bg-border'"></div>
                <div class="h-1 rounded-full transition-colors duration-200" :class="score >= 2 ? status.bar : 'bg-border'"></div>
                <div class="h-1 rounded-full transition-colors duration-200" :class="score >= 3 ? status.bar : 'bg-border'"></div>
                <div class="h-1 rounded-full transition-colors duration-200" :class="score >= 4 ? status.bar : 'bg-border'"></div>
            </div>
            <span
                class="text-[11px] font-semibold transition-colors duration-150 min-w-[3.5rem] text-end"
                :class="status.color"
                x-text="status.label"
            ></span>
        </div>

        {{-- Lista de 4 requisitos en cuadrícula limpia --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 pt-0.5">
            {{-- Regla 1: Mínimo 8 caracteres --}}
            <div
                class="flex items-center gap-1.5 text-[11px] transition-colors duration-150"
                :class="hasLen ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-muted'"
            >
                <svg x-show="hasLen" class="h-3 w-3 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                </svg>
                <span x-show="!hasLen" class="h-1 w-1 rounded-full bg-border mx-1 flex-shrink-0"></span>
                <span>Al menos {{ $min }} caracteres</span>
            </div>

            {{-- Regla 2: Mayúscula y minúscula --}}
            <div
                class="flex items-center gap-1.5 text-[11px] transition-colors duration-150"
                :class="hasCase ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-muted'"
            >
                <svg x-show="hasCase" class="h-3 w-3 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                </svg>
                <span x-show="!hasCase" class="h-1 w-1 rounded-full bg-border mx-1 flex-shrink-0"></span>
                <span>Mayúscula y minúscula</span>
            </div>

            {{-- Regla 3: Un número --}}
            <div
                class="flex items-center gap-1.5 text-[11px] transition-colors duration-150"
                :class="hasNum ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-muted'"
            >
                <svg x-show="hasNum" class="h-3 w-3 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                </svg>
                <span x-show="!hasNum" class="h-1 w-1 rounded-full bg-border mx-1 flex-shrink-0"></span>
                <span>Al menos un número</span>
            </div>

            {{-- Regla 4: Un símbolo especial --}}
            <div
                class="flex items-center gap-1.5 text-[11px] transition-colors duration-150"
                :class="hasSym ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-muted'"
            >
                <svg x-show="hasSym" class="h-3 w-3 flex-shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                </svg>
                <span x-show="!hasSym" class="h-1 w-1 rounded-full bg-border mx-1 flex-shrink-0"></span>
                <span>Un símbolo (!@#$%...)</span>
            </div>
        </div>

        {{-- Confirmación al completar todas las reglas --}}
        <div x-show="complete" x-cloak class="pt-1 border-t border-border/50 flex items-center gap-1.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
            </svg>
            <span>Contraseña segura y lista para guardar</span>
        </div>
    </div>

    {{ $slot }}
</div>
