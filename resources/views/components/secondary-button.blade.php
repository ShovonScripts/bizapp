{{--
    The way out of a form. Deliberately quieter than the primary button, but the
    same height — "Cancel" sits beside "Book it" and a mismatched pair reads as a
    mistake.
--}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-x-2 min-h-touch px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 shadow-sm hover:bg-gray-50 active:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-mulberry-600 focus-visible:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
