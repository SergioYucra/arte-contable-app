import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Convierte una variable CSS con canales RGB en un color Tailwind
 * que soporta modificadores de opacidad (bg-primary/50).
 */
const themeColor = (variable) => `rgb(var(${variable}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['var(--font-sans)', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: {
                    DEFAULT: themeColor('--color-primary'),
                    hover: themeColor('--color-primary-hover'),
                    foreground: themeColor('--color-primary-foreground'),
                },
                secondary: {
                    DEFAULT: themeColor('--color-secondary'),
                    foreground: themeColor('--color-secondary-foreground'),
                },
                accent: themeColor('--color-accent'),
                background: themeColor('--color-background'),
                surface: themeColor('--color-surface'),
                foreground: themeColor('--color-foreground'),
                'text-main': themeColor('--color-text-main'),
                muted: themeColor('--color-muted'),
                border: themeColor('--color-border'),
            },
            borderRadius: {
                brand: 'var(--radius)',
            },
        },
    },

    plugins: [forms],
};
