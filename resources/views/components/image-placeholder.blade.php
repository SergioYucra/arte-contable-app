@props([
    'size' => '100 × 100 px',
    'label' => 'Imagen',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center border border-dashed border-border bg-background/70 text-center text-muted select-none']) }}>
    <span class="font-sans text-[10px] font-medium leading-tight text-foreground/70">{{ $label }}</span>
    <span class="mt-0.5 font-mono text-[9px] leading-tight text-muted">{{ $size }}</span>
</div>

