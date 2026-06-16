import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Barlow', ...defaultTheme.fontFamily.sans],
                condensed: ['"Barlow Condensed"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                onit: {
                    DEFAULT: '#FF7000',
                    hover: '#e56300',
                    ink: '#011926',
                    surface: '#071f2e',
                    'surface-hover': '#0a2a3f',
                    border: '#0f3048',
                    muted: '#0a2536',
                },
            },
            maxWidth: {
                portal: '64rem',
            },
        },
    },
    plugins: [forms],
};
