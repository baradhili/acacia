import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // The dashboard card spans are composed at runtime — interpolated
    // into Blade from the PHP registry (CoreNav spans) and swapped by
    // widget-manager.js on width cycling — so no scanner ever sees
    // them as literals. Safelist keeps every override the layout
    // feature can emit in the stylesheet.
    safelist: [
        'md:col-span-1',
        'md:col-span-2',
        'lg:col-span-1',
        'lg:col-span-2',
        'lg:col-span-3',
        'lg:col-span-4',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
