@props([
    'href',
    'label',
    'active' => false,
])

<a
    href="{{ $href }}"
    title="{{ $label }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'nav-item group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors duration-150',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50',
        'bg-primary/10 text-primary font-semibold' => $active,
        'text-muted hover:bg-background hover:text-foreground' => ! $active,
    ]) }}
>
    {{-- Indicador lateral del elemento activo --}}
    @if ($active)
        <span class="absolute inset-y-2 start-0 w-1 rounded-e-full bg-primary" aria-hidden="true"></span>
    @endif

    <span class="flex h-5 w-5 shrink-0 items-center justify-center" aria-hidden="true">
        {{ $icon }}
    </span>
    <span class="sidebar-label truncate">{{ $label }}</span>
</a>
