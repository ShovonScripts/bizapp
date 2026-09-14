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

                /*
                 * The marketing/guest page ground. It was a literal bg-[#f8f9fc]
                 * in five files (an arbitrary hex Tailwind can't reason about and
                 * nothing could change in one place). As a token, "the app's off-
                 * white" has a name.
                 */
                canvas: '#f8f9fc',
            },

            fontFamily: {
                sans: ['Instrument Sans', ...defaultTheme.fontFamily.sans],
                /* Several screens name a `font-display` heading font, but a second
                   family was never loaded. Mapped to the app face so the intent is
                   explicit and the class stops doing nothing; swap the stack here if
                   a display face is added later. */
                display: ['Instrument Sans', ...defaultTheme.fontFamily.sans],
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

            /*
             * Shape tokens — so a card is one radius and a control is another,
             * everywhere. Pages used to mix rounded-lg/xl/2xl/3xl at random; the
             * components (x-card, x-button, x-text-input) are the only things
             * allowed to name a radius now.
             */
            borderRadius: {
                card: '0.875rem', // 14px — the outer face of every card/panel
                /*
                 * The liquid-glass panels pair an outer shell with an inner face
                 * inset by the shell's 2px border. Those two radii were written as
                 * rounded-[32px]/rounded-[22px]/rounded-[30px] in three different
                 * files; as tokens they are named, counted, and reusable.
                 */
                glass: '1.5rem',        // outer shell (was rounded-3xl / rounded-[32px])
                'glass-inner': '1.375rem', // inner face (was rounded-[30px] / rounded-[22px])
            },
            boxShadow: {
                card: '0 1px 2px 0 rgba(16, 24, 40, 0.05)',
                pop: '0 8px 24px -8px rgba(16, 24, 40, 0.14)',
                /*
                 * Pages were written against Tailwind v4's scale (shadow-xs) but
                 * this project builds on v3, where the class does not exist —
                 * 48 uses rendered nothing at all. Defined here so the intent
                 * (a whisper of a shadow, below sm) actually paints.
                 */
                xs: '0 1px 2px 0 rgba(16, 24, 40, 0.04)',
            },
            fontSize: {
                /* Same story as shadow-xs: v4-only name used across the diary. */
                '2xs': ['0.625rem', { lineHeight: '0.875rem' }],
            },
        },
    },

    plugins: [forms],
};
