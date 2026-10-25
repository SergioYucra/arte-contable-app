@props([
    'variant' => 'segmented', // 'segmented' (cápsula directa de 3 opciones) o 'dropdown' (botón único con menú)
    'align' => 'right',
])

<div
    x-data="{
        theme: localStorage.getItem('theme') || 'system',
        open: false,
        setTheme(val) {
            this.theme = val;
            localStorage.setItem('theme', val);
            this.applyTheme();
            this.open = false;
        },
        toggleQuick() {
            // Alterna rápidamente entre claro y oscuro si se usa botón simple
            const isCurrentlyDark = document.documentElement.classList.contains('dark');
            this.setTheme(isCurrentlyDark ? 'light' : 'dark');
        },
        applyTheme() {
            const isDark = this.theme === 'dark' || (this.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-theme', 'light');
            }
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: this.theme, isDark } }));
        },
        init() {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                if (this.theme === 'system') {
                    this.applyTheme();
                }
            });
        }
    }"
    class="relative inline-flex items-center"
>
    @if ($variant === 'segmented')
        {{-- ========================================================== --}}
        {{-- VARIANTE 1: Cápsula Segmentada Ultra-Moderna (1 Solo Clic) --}}
        {{-- ========================================================== --}}
        <div
            class="inline-flex items-center p-1 rounded-full bg-surface/90 border border-border/80 shadow-xs backdrop-blur-md transition-all duration-200"
            role="radiogroup"
            aria-label="Selector de esquema de color"
        >
            {{-- Opción: Modo Claro --}}
            <button
                type="button"
                @click="setTheme('light')"
                :aria-checked="theme === 'light'"
                role="radio"
                title="Modo Claro (Diurno)"
                class="group relative flex h-7 w-8 items-center justify-center rounded-full transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 active:scale-90"
                :class="theme === 'light' 
                    ? 'bg-amber-500/15 text-amber-500 dark:text-amber-400 shadow-2xs font-bold border border-amber-500/30' 
                    : 'text-muted hover:text-foreground hover:bg-background/80'"
            >
                <svg class="h-4 w-4 transition-transform duration-300 group-hover:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4" :fill="theme === 'light' ? 'currentColor' : 'none'" :fill-opacity="theme === 'light' ? '0.25' : '0'" />
                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                </svg>
                <span class="sr-only">Modo Claro</span>
            </button>

            {{-- Opción: Modo Oscuro --}}
            <button
                type="button"
                @click="setTheme('dark')"
                :aria-checked="theme === 'dark'"
                role="radio"
                title="Modo Oscuro (Nocturno)"
                class="group relative flex h-7 w-8 items-center justify-center rounded-full transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 active:scale-90"
                :class="theme === 'dark' 
                    ? 'bg-sky-500/15 text-sky-400 shadow-2xs font-bold border border-sky-500/30' 
                    : 'text-muted hover:text-foreground hover:bg-background/80'"
            >
                <svg class="h-4 w-4 transition-transform duration-300 group-hover:-rotate-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z" :fill="theme === 'dark' ? 'currentColor' : 'none'" :fill-opacity="theme === 'dark' ? '0.25' : '0'" />
                    <path d="M19 3v3M20.5 4.5h-3" stroke-width="1.75" />
                </svg>
                <span class="sr-only">Modo Oscuro</span>
            </button>

            {{-- Opción: Automático / Sistema --}}
            <button
                type="button"
                @click="setTheme('system')"
                :aria-checked="theme === 'system'"
                role="radio"
                title="Automático (Según el sistema)"
                class="group relative flex h-7 w-8 items-center justify-center rounded-full transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 active:scale-90"
                :class="theme === 'system' 
                    ? 'bg-primary/15 text-primary shadow-2xs font-bold border border-primary/30' 
                    : 'text-muted hover:text-foreground hover:bg-background/80'"
            >
                <svg class="h-4 w-4 transition-transform duration-300 group-hover:scale-110" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="2" y="3" width="20" height="14" rx="2" />
                    <path d="M8 21h8M12 17v4" />
                    <path d="M2 13h20" stroke-opacity="0.35" />
                </svg>
                <span class="sr-only">Automático</span>
            </button>
        </div>

    @else
        {{-- ========================================================== --}}
        {{-- VARIANTE 2: Botón Único con Menú Desplegable Glassmorphic   --}}
        {{-- ========================================================== --}}
        <div class="relative" @click.outside="open = false" @keydown.escape.window="open = false">
            <button
                type="button"
                @click="open = !open"
                :aria-expanded="open"
                aria-haspopup="true"
                aria-label="Seleccionar modo visual"
                class="group relative inline-flex h-9 items-center gap-2 px-3 rounded-full border border-border/80 bg-surface/90 hover:bg-background hover:border-primary/40 text-foreground transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 shadow-xs"
            >
                {{-- Icono animado según el estado actual --}}
                <span class="flex items-center justify-center">
                    <svg x-show="theme === 'light'" class="h-4 w-4 text-amber-500 transition-transform duration-500 group-hover:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4" fill="currentColor" fill-opacity="0.25" />
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                    </svg>
                    <svg x-show="theme === 'dark'" x-cloak class="h-4 w-4 text-sky-400 transition-transform duration-500 group-hover:-rotate-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z" fill="currentColor" fill-opacity="0.25" />
                        <path d="M19 3v3M20.5 4.5h-3" stroke-width="1.75" />
                    </svg>
                    <svg x-show="theme === 'system'" x-cloak class="h-4 w-4 text-muted transition-transform duration-500 group-hover:scale-110" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" />
                        <path d="M8 21h8M12 17v4" />
                    </svg>
                </span>

                {{-- Texto del modo actual --}}
                <span class="text-xs font-semibold tracking-tight hidden sm:inline" x-text="theme === 'light' ? 'Claro' : (theme === 'dark' ? 'Oscuro' : 'Auto')"></span>

                {{-- Chevron indicador --}}
                <svg class="h-3.5 w-3.5 text-muted transition-transform duration-200" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>

            {{-- Menú flotante desplegable --}}
            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                class="absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} mt-2 w-52 rounded-2xl border border-border/80 bg-surface/95 p-1.5 shadow-2xl backdrop-blur-xl z-50 focus:outline-none"
                role="menu"
            >
                <button
                    type="button"
                    @click="setTheme('light')"
                    class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2 text-xs font-medium text-foreground hover:bg-background/80 transition-colors"
                    :class="{ 'bg-primary/10 text-primary font-semibold': theme === 'light' }"
                    role="menuitem"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="4" fill="currentColor" fill-opacity="0.25" />
                                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                            </svg>
                        </span>
                        <span class="flex flex-col text-start">
                            <span class="font-semibold">Modo Claro</span>
                            <span class="text-[10px] text-muted font-normal">Fondo blanco radiante</span>
                        </span>
                    </span>
                    <span x-show="theme === 'light'" class="text-primary font-bold text-xs">✓</span>
                </button>

                <button
                    type="button"
                    @click="setTheme('dark')"
                    class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2 text-xs font-medium text-foreground hover:bg-background/80 transition-colors mt-0.5"
                    :class="{ 'bg-primary/10 text-primary font-semibold': theme === 'dark' }"
                    role="menuitem"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-500/10 text-sky-400">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z" fill="currentColor" fill-opacity="0.25" />
                                <path d="M19 3v3M20.5 4.5h-3" stroke-width="1.75" />
                            </svg>
                        </span>
                        <span class="flex flex-col text-start">
                            <span class="font-semibold">Modo Oscuro</span>
                            <span class="text-[10px] text-muted font-normal">Pizarra y cian descansado</span>
                        </span>
                    </span>
                    <span x-show="theme === 'dark'" class="text-primary font-bold text-xs">✓</span>
                </button>

                <button
                    type="button"
                    @click="setTheme('system')"
                    class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2 text-xs font-medium text-foreground hover:bg-background/80 transition-colors mt-0.5"
                    :class="{ 'bg-primary/10 text-primary font-semibold': theme === 'system' }"
                    role="menuitem"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-muted/15 text-muted">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="3" width="20" height="14" rx="2" />
                                <path d="M8 21h8M12 17v4" />
                            </svg>
                        </span>
                        <span class="flex flex-col text-start">
                            <span class="font-semibold">Automático</span>
                            <span class="text-[10px] text-muted font-normal">Sincronizado con el sistema</span>
                        </span>
                    </span>
                    <span x-show="theme === 'system'" class="text-primary font-bold text-xs">✓</span>
                </button>
            </div>
        </div>
    @endif
</div>
