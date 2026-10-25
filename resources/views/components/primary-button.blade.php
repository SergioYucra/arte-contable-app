<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-4 py-2.5 bg-primary hover:bg-primary-hover focus:bg-primary-hover active:bg-primary-hover text-primary-foreground border border-transparent rounded-brand font-medium text-sm tracking-wide shadow-sm hover:shadow focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:focus:ring-offset-surface transition-all duration-150 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer']) }}>
    {{ $slot }}
</button>
