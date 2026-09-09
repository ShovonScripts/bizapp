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
            colors: {
                /*
                 * Mulberry — the one brand colour.
                 *
                 * Chosen to sit a long way from every status colour already in use
                 * (amber = needs attention, sky = confirmed, emerald = done, red =
                 * cancelled). A blue or teal brand would have muddled with two of
                 * them, which is the practical reason this is not the usual SaaS
                 * indigo. 700 is the working shade — buttons, links, active nav.
                 * 50 is the only tint used for filled bands.
                 */
                mulberry: {
                    50: '#fbf3f6',
                    100: '#f6e5ec',
                    200: '#eccbd9',
                    300: '#dea5bd',
                    400: '#cb769b',
                    500: '#b4547d',
                    600: '#993c64',
                    700: '#7d2f51',
                    800: '#692945',
                    900: '#5a253c',
                    950: '#330f20',
                },
            },

            fontFamily: {
                sans: ['Instrument Sans', ...defaultTheme.fontFamily.sans],
            },

            minHeight: {
                /*
                 * The floor for anything tappable. This app is used one-handed, on a
                 * phone, between clients, often with wet hands — 44px is Apple's
                 * minimum and it is not negotiable on the diary screen, where six
                 * actions used to sit 12px apart with "Done" beside "No-show".
                 */
                touch: '2.75rem',
            },
            minWidth: {
                touch: '2.75rem',
            },
        },
    },

    plugins: [forms],
};
