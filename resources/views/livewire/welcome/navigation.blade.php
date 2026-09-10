<nav class="-mx-3 flex flex-1 justify-end">
    @auth
        <a
            href="{{ url('/dashboard') }}"
            class="rounded-md px-3 py-2 text-gray-900 ring-1 ring-transparent transition hover:text-gray-700 focus:outline-none focus-visible:ring-mulberry-600"
        >
            Dashboard
        </a>
    @else
        <a
            href="{{ route('login') }}"
            class="rounded-md px-3 py-2 text-gray-900 ring-1 ring-transparent transition hover:text-gray-700 focus:outline-none focus-visible:ring-mulberry-600"
        >
            Log in
        </a>

        @if (Route::has('register'))
            <a
                href="{{ route('register') }}"
                class="rounded-md px-3 py-2 text-gray-900 ring-1 ring-transparent transition hover:text-gray-700 focus:outline-none focus-visible:ring-mulberry-600"
            >
                Register
            </a>
        @endif
    @endauth
</nav>
