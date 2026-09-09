<?php

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\BusinessSnapshot;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * The landing page after login, and the screen the boss will demo.
 *
 * ─── Why this is the ONE screen without guardTenant() ────────────────────────
 * Every other tenant screen aborts 403 for a super-admin, because business_id =
 * null means "unscoped" to the global scope and they would otherwise see every
 * client's records merged into one list. This is where login lands, though, and
 * navigation.blade.php puts the logo link and the Today link OUTSIDE its
 * `$tenantScreens` guard — so a 403 here would leave a
 * super-admin on a page whose every visible link 403s too. DemoBusinessSeeder
 * really does create admin@example.com with a null business_id, so this is a
 * state we ship, not a hypothetical.
 *
 * So this component branches, and the branch IS the security boundary:
 *   owner (business_id set) → their own figures, via the global scope
 *   super-admin (null)      → platform counts only: which businesses exist and
 *                             how big they are, never a row from inside one
 *
 * Consequences, both deliberate:
 *   1. business() must never be called outside the owner branch — it is
 *      findOrFail(Tenant::id()), so for a super-admin it would throw.
 *   2. 'dashboard' stays on TenantScreenAccessTest::NON_TENANT_ROUTES, so that
 *      403 sweep skips it. DashboardTest covers this screen specifically instead,
 *      including that no end-customer name reaches a super-admin.
 * Do NOT copy this file as the template for a new screen — copy appointments.
 *
 * ─── Read-only on purpose ───────────────────────────────────────────────────
 * Changing a booking's status has to go through Appointment::changeStatus() so
 * the customer's visit rollup stays honest, and the diary already does that
 * properly. A second place to get it wrong is worse than an extra click, so every
 * card here links to the screen that owns the action.
 */
new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component
{
    public function with(): array
    {
        return Tenant::check() ? $this->ownerData() : $this->platformData();
    }

    /**
     * One business's day, from BusinessSnapshot.
     *
     * The figures live in that class rather than here because the reminder engine
     * needs the same ones from a console command, where there is no component and
     * no logged-in user to read a tenant from.
     */
    protected function ownerData(): array
    {
        $business = Business::findOrFail(Tenant::id());
        $snapshot = BusinessSnapshot::for($business);

        /* Every key is returned by BOTH branches, even as null. Blade turns an
           undefined variable into an ErrorException, so a shape that differs
           between branches is a 500 waiting for the first super-admin to log in. */
        return [
            'business' => $business,
            'businesses' => null,
            'totals' => null,

            'greeting' => $this->greeting($snapshot->asAt()->hour),
            'setup' => $snapshot->setup(),
            'today' => $snapshot->today(),
            'tomorrow' => $snapshot->tomorrow(),
            'month' => $snapshot->month(),
            'lapsed' => $snapshot->lapsed(),
            'attention' => $snapshot->attention(),
        ];
    }

    /**
     * Platform figures for a super-admin: counts, never contents.
     *
     * "Bright Hair Studio has 412 customers" is a support figure and legitimate.
     * Reading those 412 customers is not, and would need an audited impersonation
     * flow rather than a quietly unscoped list on the landing page.
     */
    protected function platformData(): array
    {
        return [
            'business' => null,

            /* acrossAllBusinesses()/withoutGlobalScope('business') is redundant
               today — Tenant::id() is null for a super-admin, so the scope no-ops —
               but saying it out loud keeps these counts right if that ever changes,
               and makes it obvious that crossing businesses here is intended. */
            'businesses' => Business::query()
                ->withCount([
                    'customers' => fn ($query) => $query->withoutGlobalScope('business'),
                    'appointments' => fn ($query) => $query->withoutGlobalScope('business'),
                ])
                ->orderBy('name')
                ->get(),

            'totals' => [
                'businesses' => Business::query()->count(),
                'users' => User::query()->count(),
                'customers' => Customer::query()->acrossAllBusinesses()->count(),
                'appointments' => Appointment::query()->acrossAllBusinesses()->count(),
            ],

            'greeting' => 'Platform',
            'setup' => null,
            'today' => null,
            'tomorrow' => null,
            'month' => null,
            'lapsed' => null,
            'attention' => null,
        ];
    }

    /** Business-local hour, not server hour — the whole point of Business::now(). */
    protected function greeting(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}; ?>

<div>
    <div class="py-8 max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if ($business === null)

            {{-- ------------------------------------------------------------------
                 Super-admin: the platform, not a salon. Counts only, no client rows.
                 ------------------------------------------------------------------ --}}

            <div class="px-4 sm:px-0">
                <h1 class="text-xl font-semibold text-gray-900">Platform</h1>
                <p class="text-sm text-gray-500">
                    Your account is not attached to a business, so there is no diary to show.
                    The client screens are closed to this account on purpose.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach ($totals as $label => $count)
                    <div wire:key="total-{{ $label }}" class="bg-white p-4 shadow-sm sm:rounded-lg">
                        <div class="text-xs uppercase tracking-wider text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $count }}</div>
                    </div>
                @endforeach
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="border-b border-gray-100 p-4 text-sm font-medium text-gray-900">Businesses</div>

                <ul class="divide-y divide-gray-100">
                    @forelse ($businesses as $client)
                        <li wire:key="business-{{ $client->id }}" class="flex items-center justify-between gap-3 p-4">
                            <div>
                                <div class="font-medium text-gray-900">{{ $client->name }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ $client->niche ?: 'no niche set' }} &middot; {{ $client->timezone }}
                                    &middot; joined {{ $client->created_at->format('j M Y') }}
                                </div>
                            </div>

                            <div class="shrink-0 text-sm tabular-nums text-gray-500">
                                {{ $client->customers_count }} customers &middot;
                                {{ $client->appointments_count }} bookings
                            </div>
                        </li>
                    @empty
                        <li class="p-4 text-sm text-gray-500">No businesses yet.</li>
                    @endforelse
                </ul>
            </div>

            <p class="px-4 text-xs text-gray-500 sm:px-0">
                Client records stay inside each business. To look at one, sign in with an account
                belonging to it &mdash; there is no cross-business view by design.
            </p>

        @else

            @php
                /* Local closures rather than component methods: Blade can only call
                   PUBLIC methods on $this, and neither of these should be reachable
                   from the browser. */
                $money = fn ($amount) => '£'.number_format((float) $amount, 2);
                $at = fn ($utc) => $business->toLocal($utc)->format('H:i');
            @endphp

            <div class="flex items-end justify-between gap-3 px-4 sm:px-0">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">{{ $greeting }}</h1>
                    <p class="text-sm text-gray-500">
                        {{ $business->name }} &middot; {{ $today['date']->format('l j F') }}
                    </p>
                </div>

                <a href="{{ route('appointments.index') }}" wire:navigate
                   class="shrink-0 text-sm font-medium text-mulberry-700 hover:text-mulberry-900">
                    Open the diary &rarr;
                </a>
            </div>

            @if (! $setup['ready'])

                {{-- A grid of zeros is not a dashboard, it is a shrug. While the
                     essentials are missing, say what to do instead of showing them. --}}

                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div>
                        <h2 class="font-semibold text-gray-900">Two things and you can take a booking</h2>
                        <p class="text-sm text-gray-500">
                            Once these are in, this page shows your day, your takings and who needs reminding.
                        </p>
                    </div>

                    <ul class="space-y-3 text-sm">
                        <li class="flex flex-wrap items-center gap-2">
                            @if ($setup['services'] > 0)
                                <span class="font-semibold text-green-600">&#10003;</span>
                                <span class="text-gray-500">
                                    {{ $setup['services'] }}
                                    {{ $setup['services'] === 1 ? 'service' : 'services' }} added
                                </span>
                            @else
                                <span class="font-semibold text-gray-300">&bull;</span>
                                <a href="{{ route('services.index') }}" wire:navigate
                                   class="font-medium text-mulberry-700 hover:text-mulberry-900">
                                    Add what you offer
                                </a>
                                <span class="text-gray-500">&mdash; name, how long, how much</span>
                            @endif
                        </li>

                        <li class="flex flex-wrap items-center gap-2">
                            @if ($setup['customers'] > 0)
                                <span class="font-semibold text-green-600">&#10003;</span>
                                <span class="text-gray-500">
                                    {{ $setup['customers'] }}
                                    {{ $setup['customers'] === 1 ? 'customer' : 'customers' }} on file
                                </span>
                            @else
                                <span class="font-semibold text-gray-300">&bull;</span>
                                <a href="{{ route('customers.index') }}" wire:navigate
                                   class="font-medium text-mulberry-700 hover:text-mulberry-900">
                                    Add your first customer
                                </a>
                                <span class="text-gray-500">&mdash; a mobile number is enough</span>
                            @endif
                        </li>
                    </ul>

                    @if ($setup['staff'] === 0)
                        <p class="text-xs text-gray-500">
                            Working on your own? You can skip staff entirely and add them later.
                        </p>
                    @endif
                </div>

            @else

                {{-- ------------------------- The four numbers ------------------------- --}}

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                    <div class="bg-white shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs uppercase tracking-wider text-gray-500">Today</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
                            {{ $today['live']->count() }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ $today['live']->count() === 1 ? 'booking' : 'bookings' }},
                            {{ $money($today['expected']) }} expected
                        </div>
                        @if ($today['done']->isNotEmpty())
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $today['done']->count() }} done &middot; {{ $money($today['takenSoFar']) }} taken
                            </div>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs uppercase tracking-wider text-gray-500">Tomorrow</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
                            {{ $tomorrow['all']->count() }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ $tomorrow['all']->count() === 1 ? 'booking' : 'bookings' }} to remind
                        </div>
                        @if ($tomorrow['unreachable']->isNotEmpty())
                            <div class="mt-1 text-xs font-medium text-amber-700">
                                {{ $tomorrow['unreachable']->count() }} cannot be reached
                            </div>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs uppercase tracking-wider text-gray-500">{{ $month['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
                            {{ $money($month['earned']) }}
                        </div>
                        <div class="text-sm text-gray-500">
                            from {{ $month['completed'] }} completed
                        </div>
                        @if ($month['booked'] > 0)
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $money($month['booked']) }} still in the diary
                            </div>
                        @endif
                    </div>

                    <div class="bg-white shadow-sm sm:rounded-lg p-4">
                        <div class="text-xs uppercase tracking-wider text-gray-500">No-shows this month</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums {{ $month['noShows'] > 0 ? 'text-amber-700' : 'text-gray-900' }}">
                            {{ $month['noShows'] }}
                        </div>
                        <div class="text-sm text-gray-500">
                            {{ $money($month['noShowValue']) }} of empty chair
                        </div>
                        @if ($month['cancelled'] > 0)
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $month['cancelled'] }} cancelled in advance
                            </div>
                        @endif
                    </div>

                </div>

                {{-- Rendered only when there is something to act on, so this page
                     never becomes a banner an owner learns to skip past. --}}
                @if (count($attention) > 0)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-medium text-amber-900">Worth a look</p>

                        <ul class="mt-2 space-y-1.5">
                            @foreach ($attention as $item)
                                <li wire:key="attention-{{ $loop->index }}"
                                    class="flex flex-wrap items-center gap-2 text-sm text-amber-800">
                                    <span>{{ $item['text'] }}</span>
                                    <a href="{{ route($item['route'], $item['params']) }}" wire:navigate
                                       class="font-medium text-amber-900 underline hover:no-underline">
                                        {{ $item['action'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- --------------------------- Today and tomorrow --------------------------- --}}

                <div class="grid gap-4 lg:grid-cols-3">

                    <div class="bg-white shadow-sm sm:rounded-lg lg:col-span-2">
                        <div class="flex items-center justify-between border-b border-gray-100 p-4">
                            <h2 class="font-semibold text-gray-900">Your day</h2>
                            <a href="{{ route('appointments.index', ['day' => $today['date']->toDateString()]) }}"
                               wire:navigate class="text-sm font-medium text-mulberry-700 hover:text-mulberry-900">
                                Diary
                            </a>
                        </div>

                        @if ($today['next'])
                            <div class="border-b border-gray-100 bg-mulberry-50 p-4">
                                <div class="text-xs uppercase tracking-wider text-mulberry-800">Next up</div>
                                <div class="mt-1 font-medium text-gray-900">
                                    {{ $at($today['next']->starts_at) }}
                                    &middot; {{ $today['next']->customer?->name ?? 'Customer removed' }}
                                </div>
                                <div class="text-sm text-gray-600">
                                    {{ $today['next']->service?->name ?? 'No service set' }}
                                    @if ($today['next']->staffMember)
                                        with {{ $today['next']->staffMember->name }}
                                    @endif
                                    &middot; {{ $today['next']->starts_at->diffForHumans() }}
                                </div>
                            </div>
                        @endif

                        <ul class="divide-y divide-gray-100 text-sm">
                            @forelse ($today['all'] as $appointment)
                                <li wire:key="today-{{ $appointment->id }}"
                                    class="flex items-center justify-between gap-3 p-4 {{ $appointment->isCancelled() ? 'opacity-60' : '' }}">

                                    <div class="flex min-w-0 items-baseline gap-3">
                                        <span class="w-12 shrink-0 font-semibold tabular-nums text-gray-900">
                                            {{ $at($appointment->starts_at) }}
                                        </span>

                                        <span class="min-w-0">
                                            <span class="font-medium text-gray-900">
                                                {{ $appointment->customer?->name ?? 'Customer removed' }}
                                            </span>
                                            <span class="text-gray-500">
                                                &middot; {{ $appointment->service?->name ?? 'No service set' }}
                                            </span>
                                        </span>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-3">
                                        <span class="text-gray-500">{{ $appointment->statusLabel() }}</span>
                                        <span class="tabular-nums text-gray-700">
                                            {{ $money($appointment->price) }}
                                        </span>
                                    </div>
                                </li>
                            @empty
                                <li class="p-4 text-gray-500">
                                    Nothing booked today.
                                    <a href="{{ route('appointments.index') }}" wire:navigate
                                       class="font-medium text-mulberry-700 hover:text-mulberry-900">Add a booking</a>
                                </li>
                            @endforelse
                        </ul>
                    </div>

                    <div class="space-y-4">

                        {{-- Named for what it can prove today: who is contactable. It becomes
                             "reminders sent" once messages:dispatch exists — until then this
                             page must not claim a message went out. --}}
                        <div class="bg-white shadow-sm sm:rounded-lg">
                            <div class="border-b border-gray-100 p-4">
                                <h2 class="font-semibold text-gray-900">Tomorrow's reminders</h2>
                                <p class="text-xs text-gray-500">{{ $tomorrow['date']->format('l j F') }}</p>
                            </div>

                            <div class="space-y-3 p-4 text-sm">
                                @if ($tomorrow['all']->isEmpty())
                                    <p class="text-gray-500">Nothing booked for tomorrow yet.</p>
                                @else
                                    <p class="text-gray-700">
                                        <span class="font-semibold">{{ $tomorrow['reachable']->count() }}</span>
                                        of {{ $tomorrow['all']->count() }} can be reached.
                                    </p>

                                    @if ($tomorrow['unreachable']->isNotEmpty())
                                        <div class="rounded-md bg-amber-50 p-3">
                                            <p class="font-medium text-amber-800">Needs a phone call</p>

                                            <ul class="mt-1 space-y-1 text-amber-900">
                                                @foreach ($tomorrow['unreachable'] as $appointment)
                                                    <li wire:key="unreachable-{{ $appointment->id }}">
                                                        {{ $at($appointment->starts_at) }}
                                                        &middot;
                                                        {{ $appointment->customer?->name ?? 'Customer removed' }}
                                                        <span class="text-xs">
                                                            ({{ $appointment->customer?->unreachableReason() ?? 'customer removed' }})
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>

                                            <a href="{{ route('customers.index') }}" wire:navigate
                                               class="mt-2 inline-block font-medium text-amber-900 underline hover:no-underline">
                                                Fix their details
                                            </a>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- Win-back. Two numbers, because they lead somewhere different:
                             one is a campaign you can run today, the other is a reason to
                             start asking for consent at the till. --}}
                        <div class="bg-white shadow-sm sm:rounded-lg">
                            <div class="border-b border-gray-100 p-4">
                                <h2 class="font-semibold text-gray-900">Not seen in 90 days</h2>
                            </div>

                            <div class="space-y-2 p-4 text-sm">
                                <div class="text-2xl font-semibold tabular-nums text-gray-900">
                                    {{ $lapsed['total'] }}
                                </div>

                                @if ($lapsed['total'] === 0)
                                    <p class="text-gray-500">Everyone has been in recently.</p>
                                @else
                                    <p class="text-gray-500">
                                        {{ $lapsed['contactable'] }} you may message and can reach.
                                    </p>

                                    @if ($lapsed['marketable'] > $lapsed['contactable'])
                                        <p class="text-xs text-gray-500">
                                            {{ $lapsed['marketable'] - $lapsed['contactable'] }}
                                            agreed to marketing but have no working contact details.
                                        </p>
                                    @endif

                                    @if ($lapsed['total'] > $lapsed['marketable'])
                                        <p class="text-xs text-gray-500">
                                            {{ $lapsed['total'] - $lapsed['marketable'] }}
                                            have not agreed to marketing, so they must not be included.
                                        </p>
                                    @endif

                                    <a href="{{ route('customers.index', ['filter' => 'lapsed']) }}" wire:navigate
                                       class="inline-block font-medium text-mulberry-700 hover:text-mulberry-900">
                                        See who
                                    </a>
                                @endif
                            </div>
                        </div>

                    </div>
                </div>

            @endif

        @endif

    </div>
</div>
