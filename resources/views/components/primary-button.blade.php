{{--
    The main action on every screen.

    Was 12px ALL-CAPS grey, which made the most important control on the page the
    least legible text on it. Now sentence case at 14px in the brand colour, with a
    44px floor so it can be hit one-handed.
--}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-x-2 min-h-touch px-4 py-2 bg-mulberry-700 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-mulberry-800 active:bg-mulberry-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 focus-visible:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
