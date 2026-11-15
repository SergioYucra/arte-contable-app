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
        'nav-item group relative flex min-h-[42px] items-center gap-3 rounded-[10px] border px-3 text-[13px] transition-all duration-150',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[#14b8a6]/60',
        'border-white/10 bg-[#175c80]/40 text-white font-medium shadow-[inset_3px_0_0_#14b8a6]' => $active,
        'border-transparent text-[#c3d5de] hover:bg-white/[0.07] hover:text-white' => ! $active,
    ]) }}
>
    <span
        class="flex h-[18px] w-[18px] shrink-0 items-center justify-center transition-colors duration-150 {{ $active ? 'text-[#78dacc]' : 'text-[#9bb9c8] group-hover:text-[#c4e7f1]' }}"
        aria-hidden="true"
    >
        {{ $icon }}
    </span>
    <span class="sidebar-label truncate">{{ $label }}</span>
</a>
