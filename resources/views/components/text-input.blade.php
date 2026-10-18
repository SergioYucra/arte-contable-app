@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-border bg-surface text-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 rounded-brand shadow-sm transition-colors duration-150 disabled:bg-background disabled:opacity-60']) }}>
