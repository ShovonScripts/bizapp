{{--
    Destructive actions only. Red is reserved for this and for the cancelled status
    badge — it never appears as decoration.
--}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-x-2 min-h-touch px-4 py-2 bg-red-600 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-red-700 active:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
