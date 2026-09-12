<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the dashboard says about one business, in one place.
 *
 * Two rules shape this class:
 *
 * 1. **It never reads the current tenant.** Every query hangs off
 *    `$this->business->appointments()` / `->customers()` rather than
 *    `Appointment::query()`, so the numbers are correct even with no tenant set.
 *    That matters because the scheduled reminder command will eventually loop over
 *    every business to build a "here's your day" message, and a class that only
 *    works inside a web request would have to be rewritten for it.
 *
 * 2. **Nothing is cached.** An owner who marks a booking "Done" and then sees
 *    today's takings unchanged will conclude the app is broken — and be right to.
 *    A handful of indexed aggregates against one business's rows is cheap; a stale
 *    figure is not.
 *
 * All the queries are still tenant-safe: the relation pins `business_id`, and the
 * global scope on top of it either repeats that same clause or (with no tenant)
 * adds nothing. If they ever disagree the result is an empty set, never another
 * business's data.
 */
class BusinessSnapshot
{
    /** No visit in this many days and we call the customer lapsed. */
    public const LAPSED_AFTER_DAYS = 90;

    /**
     * One instant, captured once, used for every metric below.
     *
     * Not a convenience. Calling $business->now() per metric means a request that
     * lands at 23:59:59.9 can compute "today" from one date and "tomorrow" from
     * another — leaving a dashboard that shows tomorrow twice, or skips a day.
     * Rare, unreproducible, and exactly the kind of bug nobody ever finds.
     */
    protected Carbon $now;

    public function __construct(public readonly Business $business)
    {
        $this->now = $business->now();
    }

    public static function for(Business $business): static
    {
        return new static($business);
    }

    /** The business's own local time, for "as at 14:32" style captions. */
    public function asAt(): Carbon
    {
        return $this->now->copy();
    }

    /* ----------------------------------------------------------------------
     | Today
     * -------------------------------------------------------------------- */

    /**
     * Today's diary, loaded once and then sliced in PHP.
     *
     * A day of bookings is a handful of rows, so one query plus three eager loads
     * beats six aggregates — and it means the count, the takings and the "next up"
     * card can never disagree with each other, which they can if each runs its own
     * query either side of a status change.
     */
    public function todaysAppointments(): Collection
    {
        return $this->business->appointments()
            ->onLocalDate($this->business, $this->now)
            ->with(['customer', 'service', 'staffMember'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array{date: Carbon, all: Collection, live: Collection, done: Collection,
     *               expected: float, takenSoFar: float, next: ?Appointment}
     */
    public function today(): array
    {
        $all = $this->todaysAppointments();

        // Cancellations and no-shows stay in `all` — an owner wants to see the gap in
        // their day — but they are out of `live`, so they are never counted or charged.
        $live = $all->reject(fn (Appointment $a) => $a->isCancelled());
        $done = $live->where('status', Appointment::COMPLETED);

        return [
            'date' => $this->now->copy()->startOfDay(),
            'all' => $all,
            'live' => $live,
            'done' => $done,
            'expected' => (float) $live->sum(fn (Appointment $a) => (float) $a->price),
            'takenSoFar' => (float) $done->sum(fn (Appointment $a) => (float) $a->price),
            'next' => $this->nextUp($all),
        ];
    }

    /**
     * The next appointment still to come today.
     *
     * Filtered to REMINDABLE, not just "in the future": a booking already marked
     * completed or cancelled is not something the owner is waiting for, even if
     * its start time has not passed yet (marking someone done early is normal).
     */
    protected function nextUp(Collection $todays): ?Appointment
    {
        return $todays
            ->filter(fn (Appointment $a) => in_array($a->status, Appointment::REMINDABLE, true))
            ->first(fn (Appointment $a) => $a->starts_at->gte($this->now));
    }

    /* ----------------------------------------------------------------------
     | Tomorrow — the reminder-engine preview
     * -------------------------------------------------------------------- */

    /**
     * What tomorrow's reminder run will actually manage to send.
     *
     * The honest number is the point of this panel. "7 reminders tomorrow" is a
     * promise, and if two of those customers have no phone number and one has
     * unsubscribed, only four messages leave the building. The owner needs to know
     * which three so they can pick up the phone — a silent shortfall is how a
     * no-show gets blamed on us.
     *
     * Decided in PHP off loaded rows, deliberately: this is the same
     * hasContactRoute() the sender itself will call, so the figure cannot promise
     * something the dispatcher then refuses to do.
     *
     * @return array{date: Carbon, all: Collection, reachable: Collection, unreachable: Collection}
     */
    public function tomorrow(): array
    {
        $tomorrow = $this->now->copy()->addDay();

        $all = $this->business->appointments()
            ->onLocalDate($this->business, $tomorrow)
            ->remindable()
            ->with(['customer', 'service'])
            ->orderBy('starts_at')
            ->get();

        // No customer at all should be impossible (customer_id is required), but a
        // soft-deleted one resolves to null, and a dashboard is a bad place to
        // discover that. Unreachable is the safe bucket for it.
        //
        // Split on unreachableReason() rather than re-deriving the condition here:
        // the view prints that same reason next to each name, and a bucket decided by
        // one rule while the label comes from another is a bug that only shows up as
        // a customer sitting in the "needs a phone call" list with no reason given.
        [$reachable, $unreachable] = $all->partition(
            fn (Appointment $a) => $a->customer && $a->customer->unreachableReason() === null
        );

        return [
            'date' => $tomorrow->copy()->startOfDay(),
            'all' => $all,
            'reachable' => $reachable->values(),
            'unreachable' => $unreachable->values(),
        ];
    }

    /* ----------------------------------------------------------------------
     | This month
     * -------------------------------------------------------------------- */

    /**
     * Month-to-date money, plus what the diary still holds.
     *
     * Month boundaries are the business's local ones, converted to UTC for the
     * query. That conversion is safe here in a way that grouping by local date is
     * not: a month is one contiguous range, so it maps to a single pair of UTC
     * bounds without asking the database to know anything about timezones.
     *
     * @return array{label: string, earned: float, booked: float, completed: int,
     *               noShows: int, noShowValue: float, cancelled: int}
     */
    public function month(): array
    {
        $from = $this->now->copy()->startOfMonth()->utc();
        $to = $this->now->copy()->endOfMonth()->utc();

        $inMonth = fn () => $this->business->appointments()->whereBetween('starts_at', [$from, $to]);

        return [
            'label' => $this->now->format('F Y'),

            // Earned = completed only. A confirmed booking is not money yet, and
            // showing it as though it were turns a no-show into a phantom loss.
            'earned' => (float) $inMonth()->where('status', Appointment::COMPLETED)->sum('price'),
            'completed' => $inMonth()->where('status', Appointment::COMPLETED)->count(),

            // Still in the diary: what the rest of the month is worth if it holds.
            'booked' => (float) $inMonth()->remindable()->where('starts_at', '>=', $this->now)->sum('price'),

            // The number that pays for this whole product. Value included, because
            // "4 no-shows" lands very differently as "4 no-shows, £186".
            'noShows' => $inMonth()->where('status', Appointment::NO_SHOW)->count(),
            'noShowValue' => (float) $inMonth()->where('status', Appointment::NO_SHOW)->sum('price'),
            'cancelled' => $inMonth()->where('status', Appointment::CANCELLED)->count(),
        ];
    }

    /* ----------------------------------------------------------------------
     | Customers
     * -------------------------------------------------------------------- */

    /**
     * Win-back candidates: nobody seen for LAPSED_AFTER_DAYS.
     *
     * Split by whether we may legally market to them, because the two numbers lead
     * somewhere different. Lapsed-and-marketable is a campaign you can run this
     * afternoon; lapsed-without-consent is a reason to start asking for consent at
     * the till. Presenting only the total would invite exactly the send that earns
     * an ICO complaint.
     *
     * @return array{total: int, marketable: int, contactable: int}
     */
    public function lapsed(): array
    {
        $lapsed = fn () => $this->business->customers()->lapsed(self::LAPSED_AFTER_DAYS);

        return [
            'total' => $lapsed()->count(),
            'marketable' => $lapsed()->marketable()->count(),
            'contactable' => $lapsed()->marketable()->withContactRoute()->count(),
        ];
    }

    /**
     * Is this business actually set up? Drives the empty state.
     *
     * A brand-new account has nothing to show and a grid of zeros is not a
     * dashboard, it is a shrug. Knowing which piece is missing lets the screen say
     * "add a service" and link straight there.
     *
     * @return array{services: int, staff: int, customers: int, appointments: int, ready: bool}
     */
    public function setup(): array
    {
        $services = $this->business->services()->count();
        $staff = $this->business->staffMembers()->count();
        $customers = $this->business->customers()->count();
        $appointments = $this->business->appointments()->count();

        return [
            'services' => $services,
            'staff' => $staff,
            'customers' => $customers,
            'appointments' => $appointments,
            // Staff deliberately not required: a sole trader is a real business and
            // the diary lets a booking have no staff member.
            'ready' => $services > 0 && $customers > 0,
        ];
    }

    /* ----------------------------------------------------------------------
     | Things worth acting on
     * -------------------------------------------------------------------- */

    /**
     * Only the items that currently apply, each with the screen that fixes it.
     *
     * Returns an empty list when there is nothing wrong, so a salon in good order
     * sees no panel at all rather than a row of reassuring zeros — which is the
     * difference between a page an owner reads and one they learn to ignore.
     *
     * Route *names* rather than URLs on purpose: this class has to stay callable
     * from a console command building a "here's your morning" message, where
     * generating links to a web UI would make no sense.
     *
     * @return list<array{text: string, action: string, route: string, params: array}>
     */
    public function attention(): array
    {
        $items = [];

        // Unconfirmed and imminent. A pending booking is one nobody has said yes to.
        $unconfirmed = $this->business->appointments()
            ->where('status', Appointment::PENDING)
            ->whereBetween('starts_at', [
                $this->now->copy()->utc(),
                $this->now->copy()->addDays(7)->endOfDay()->utc(),
            ])
            ->count();

        if ($unconfirmed > 0) {
            $items[] = [
                'text' => $unconfirmed.' '.($unconfirmed === 1 ? 'booking' : 'bookings')
                    .' in the next week '.($unconfirmed === 1 ? 'is' : 'are').' still unconfirmed',
                'action' => 'Open the diary',
                'route' => 'appointments.index',
                'params' => [],
            ];
        }

        /* Customers who want messages but cannot receive them.
           Counted as a difference of two counts rather than a negated scope: the
           second query is a strict subset of the first, so the number cannot come
           out nonsensical, and there is no "NOT (a OR b OR c)" to get wrong. */
        $wantsMessages = fn () => $this->business->customers()
            ->where('preferred_channel', '!=', 'none')
            ->whereNull('unsubscribed_at');

        $missingRoute = $wantsMessages()->count() - $wantsMessages()->withContactRoute()->count();

        if ($missingRoute > 0) {
            $items[] = [
                'text' => $missingRoute.' '.($missingRoute === 1 ? 'customer is' : 'customers are')
                    .' set to receive reminders but have no working contact details',
                'action' => 'Fix in Customers',
                'route' => 'customers.index',
                'params' => [],
            ];
        }

        // Only worth raising once they are actually booking people in — a sole
        // trader with three appointments does not need a staff list, but a diary
        // with nobody assigned to anything cannot spot a double-booking.
        $setup = $this->setup();

        if ($setup['staff'] === 0 && $setup['appointments'] > 0) {
            $items[] = [
                'text' => 'Nobody on the staff list, so clashing bookings cannot be spotted',
                'action' => 'Add staff',
                'route' => 'staff.index',
                'params' => [],
            ];
        }

        return $items;
    }

    /* ----------------------------------------------------------------------
     | Revenue & Performance Analytics
     * -------------------------------------------------------------------- */

    public function analytics(): array
    {
        return [
            'weekly' => $this->weeklyRevenue(),
            'topServices' => $this->topServices(),
            'staffLeaderboard' => $this->staffLeaderboard(),
            'kpis' => $this->performanceMetrics(),
        ];
    }

    public function weeklyRevenue(): array
    {
        $days = [];
        $maxRevenue = 0.0;

        // Last 7 local days ending today
        for ($i = 6; $i >= 0; $i--) {
            $localDay = $this->now->copy()->subDays($i);
            $startUtc = $localDay->copy()->startOfDay()->utc();
            $endUtc = $localDay->copy()->endOfDay()->utc();

            $completed = $this->business->appointments()
                ->where('status', Appointment::COMPLETED)
                ->whereBetween('starts_at', [$startUtc, $endUtc]);

            $revenue = (float) $completed->sum('price');
            $count = (int) $completed->count();

            if ($revenue > $maxRevenue) {
                $maxRevenue = $revenue;
            }

            $days[] = [
                'day' => $localDay->format('D'),
                'date' => $localDay->format('j M'),
                'isToday' => $i === 0,
                'revenue' => $revenue,
                'count' => $count,
                'heightPct' => 0,
            ];
        }

        $effectiveMax = $maxRevenue > 0 ? $maxRevenue : 1.0;
        foreach ($days as &$d) {
            $d['heightPct'] = $maxRevenue > 0
                ? max(10, (int) round(($d['revenue'] / $effectiveMax) * 100))
                : 10;
        }
        unset($d);

        $totalWeekRevenue = array_sum(array_column($days, 'revenue'));
        $totalWeekCount = array_sum(array_column($days, 'count'));

        return [
            'days' => $days,
            'totalRevenue' => $totalWeekRevenue,
            'totalBookings' => $totalWeekCount,
            'maxRevenue' => $maxRevenue,
        ];
    }

    public function topServices(int $limit = 5): Collection
    {
        $completedAppointments = $this->business->appointments()
            ->where('status', Appointment::COMPLETED)
            ->with('service')
            ->get();

        $totalRevenue = (float) $completedAppointments->sum('price');

        return $completedAppointments
            ->groupBy('service_id')
            ->map(function (Collection $group) use ($totalRevenue) {
                $service = $group->first()->service;
                $revenue = (float) $group->sum('price');
                $count = $group->count();
                $share = $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0;

                return [
                    'service_id' => $service?->id,
                    'name' => $service?->name ?? 'Standard Service',
                    'count' => $count,
                    'revenue' => $revenue,
                    'share' => $share,
                ];
            })
            ->sortByDesc('revenue')
            ->take($limit)
            ->values();
    }

    public function staffLeaderboard(): Collection
    {
        $completedAppointments = $this->business->appointments()
            ->where('status', Appointment::COMPLETED)
            ->get();

        $allStaff = $this->business->staffMembers()->get();

        return $allStaff->map(function ($staff) use ($completedAppointments) {
            $staffAppointments = $completedAppointments->where('staff_member_id', $staff->id);
            $revenue = (float) $staffAppointments->sum('price');
            $count = $staffAppointments->count();

            return [
                'id' => $staff->id,
                'name' => $staff->name,
                'color' => $staff->color ?: '#e11d48',
                'count' => $count,
                'revenue' => $revenue,
            ];
        })
        ->sortByDesc('revenue')
        ->values();
    }

    public function performanceMetrics(): array
    {
        $completed = $this->business->appointments()
            ->where('status', Appointment::COMPLETED);

        $totalRevenue = (float) $completed->sum('price');
        $totalCompleted = (int) $completed->count();

        $aov = $totalCompleted > 0 ? round($totalRevenue / $totalCompleted, 2) : 0.0;

        $customerCount = $this->business->customers()->count();
        $repeatCustomerCount = $this->business->customers()
            ->has('appointments', '>=', 2)
            ->count();

        $retentionRate = $customerCount > 0
            ? round(($repeatCustomerCount / $customerCount) * 100, 1)
            : 0.0;

        $totalAllBookings = $this->business->appointments()->count();
        $onlineBookings = $this->business->appointments()->where('source', 'online')->count();
        $onlineShare = $totalAllBookings > 0
            ? round(($onlineBookings / $totalAllBookings) * 100, 1)
            : 0.0;

        return [
            'totalRevenue' => $totalRevenue,
            'totalCompleted' => $totalCompleted,
            'aov' => $aov,
            'retentionRate' => $retentionRate,
            'onlineShare' => $onlineShare,
            'onlineBookings' => $onlineBookings,
            'repeatCustomers' => $repeatCustomerCount,
        ];
    }
}
