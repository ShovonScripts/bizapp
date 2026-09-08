<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for development. NOT for production.
 *
 * Two businesses on purpose: the salon is the one you demo, the gym exists only
 * so you can log in as the salon owner and confirm you cannot see the gym's data.
 * A tenant-scoping bug is invisible with a single tenant in the database.
 *
 * Run:  php artisan db:seed --class=DemoBusinessSeeder
 *   or: php artisan migrate:fresh --seed
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Super-admin: business_id stays null, so no tenant scope applies.
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'business_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        $salon = $this->salon();
        $this->gym();

        $this->command->info('Seeded demo data. Password for every account: password');
        $this->command->newLine();
        $this->command->table(
            ['Email', 'Role'],
            [
                ['admin@example.com', 'super-admin (sees everything)'],
                ['owner@demosalon.test', 'Demo Hair Studio owner'],
                ['owner@demogym.test', 'Demo Fitness owner'],
            ]
        );
        $this->command->info(sprintf(
            'Salon: %d services, %d staff, %d customers, %d appointments.',
            $salon->services()->count(),
            $salon->staffMembers()->count(),
            $salon->customers()->count(),
            $salon->appointments()->count(),
        ));
    }

    /* ===================================================================== */

    protected function salon(): Business
    {
        $salon = Business::updateOrCreate(
            ['slug' => 'demo-salon'],
            [
                'name' => 'Demo Hair Studio',
                'niche' => 'salon',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'phone' => '+441611234567',
                'email' => 'hello@demosalon.test',
                'address' => "12 High Street\nManchester\nM1 1AA",
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
                'settings' => [
                    'quiet_hours' => ['from' => '21:00', 'to' => '08:00'],
                    'default_channel' => 'telegram',
                    'invoice_prefix' => 'DHS',
                ],
            ]
        );

        User::updateOrCreate(
            ['email' => 'owner@demosalon.test'],
            [
                'business_id' => $salon->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ]
        );

        // ---------------- Services (real UK salon prices, roughly) ----------------
        $services = collect([
            ['Gents Cut',            25, 18.00],
            ['Cut & Blow Dry',       45, 38.00],
            ['Beard Trim',           15, 12.00],
            ['Wash & Style',         30, 24.00],
            ['Root Touch-Up',        60, 45.00],
            ['Full Colour',          90, 75.00],
            ['Highlights',          120, 95.00],
            ['Kids Cut (under 12)',  20, 14.00],
        ])->values()->map(function (array $row, int $i) use ($salon) {
            [$name, $minutes, $price] = $row;

            return Service::updateOrCreate(
                ['business_id' => $salon->id, 'name' => $name],
                [
                    'duration_minutes' => $minutes,
                    'price' => $price,
                    'active' => true,
                    'sort_order' => $i,
                ]
            );
        });

        // ---------------------------- Staff ----------------------------
        $staff = collect([
            ['Aisha', '#ec4899'],
            ['Marcus', '#3b82f6'],
            ['Chloe', '#10b981'],
        ])->values()->map(fn (array $row, int $i) => StaffMember::updateOrCreate(
            ['business_id' => $salon->id, 'name' => $row[0]],
            ['color' => $row[1], 'active' => true, 'sort_order' => $i]
        ));

        // --------------------------- Customers ---------------------------
        // Phones are Ofcom's reserved 07700 900xxx drama range — guaranteed never
        // to belong to a real person, so a misconfigured send cannot text a stranger.
        $customerRows = [
            ['Sarah Whitfield',  '+447700900001', 'sarah.w@example.com',   true],
            ['James Okonkwo',    '+447700900002', 'j.okonkwo@example.com', true],
            ['Priya Nair',       '+447700900003', 'priya.n@example.com',   true],
            ['Tom Bradshaw',     '+447700900004', null,                    false],
            ['Elena Petrova',    '+447700900005', 'elena.p@example.com',   true],
            ['Daniel Osei',      '+447700900006', 'd.osei@example.com',    false],
            ['Megan Lloyd',      '+447700900007', 'm.lloyd@example.com',   true],
            ['Hassan Ali',       '+447700900008', null,                    false],
            ['Rebecca Stone',    '+447700900009', 'r.stone@example.com',   true],
            ['Kwame Mensah',     '+447700900010', 'k.mensah@example.com',  true],
            ['Lucy Fairweather', '+447700900011', 'lucy.f@example.com',    false],
            ['Arjun Kapoor',     '+447700900012', 'arjun.k@example.com',   true],
        ];

        $customers = collect($customerRows)->map(function (array $row, int $i) use ($salon) {
            [$name, $phone, $email, $consented] = $row;

            return Customer::updateOrCreate(
                ['business_id' => $salon->id, 'phone' => $phone],
                [
                    'name' => $name,
                    'email' => $email,
                    'preferred_channel' => $i % 3 === 0 ? 'whatsapp' : 'telegram',
                    'marketing_consent' => $consented,
                    'consent_at' => $consented ? now()->subMonths(3) : null,
                    'consent_source' => $consented ? 'booking_form' : null,
                    // Two customers have already started the Telegram bot, so you
                    // have someone to test a real send against later.
                    'telegram_chat_id' => $i < 2 ? (string) (500000000 + $i) : null,
                ]
            );
        });

        // Two lapsed customers so the win-back rule has something to find,
        // and one who unsubscribed so you can prove we skip them.
        // NOTE: these two are held OUT of the booking pool below and their stats
        // are set AFTER refreshCustomerStats() — that method recomputes
        // last_visit_at from completed appointments and would otherwise wipe them.
        $bookable = $customers->take(10);

        // -------------------------- Appointments --------------------------
        // Idempotent: wipe and rebuild, otherwise re-running the seeder piles up
        // duplicate bookings and every dashboard number drifts.
        Appointment::where('business_id', $salon->id)->forceDelete();

        $tz = $salon->timezone;
        $rows = [];

        /* PAST — 10 weeks of history, so the dashboard has revenue to show and
           the no-show rate is not zero (which is the whole sales pitch). */
        for ($week = 10; $week >= 1; $week--) {
            for ($n = 0; $n < 4; $n++) {
                $service = $services->random();
                $customer = $bookable->random();

                $local = Carbon::now($tz)
                    ->subWeeks($week)
                    ->startOfWeek()
                    ->addDays(random_int(0, 5))
                    ->setTime(random_int(9, 17), [0, 15, 30, 45][random_int(0, 3)]);

                // Roughly 1 in 8 does not turn up. That is the number the pitch
                // is built on, so the demo should not look suspiciously perfect.
                $roll = random_int(1, 8);
                $status = match (true) {
                    $roll === 1 => Appointment::NO_SHOW,
                    $roll === 2 => Appointment::CANCELLED,
                    default => Appointment::COMPLETED,
                };

                $rows[] = $this->row($salon, $customer, $service, $staff->random(), $local, $status);
            }
        }

        /* TOMORROW — this is what the 24-hour reminder planner will pick up.
           Keep at least a few, or Phase 5 has nothing to test against. */
        foreach ([9, 11, 14, 16, 17] as $i => $hour) {
            $service = $services->random();
            $local = Carbon::now($tz)->addDay()->setTime($hour, [0, 30][$i % 2]);

            $rows[] = $this->row(
                $salon,
                $customers[$i],
                $service,
                $staff[$i % $staff->count()],
                $local,
                Appointment::CONFIRMED
            );
        }

        /* NEXT 3 WEEKS — a filling calendar. */
        for ($day = 2; $day <= 21; $day++) {
            $local = Carbon::now($tz)->addDays($day);

            if ($local->isSunday()) {
                continue;   // closed
            }

            foreach (range(1, random_int(1, 3)) as $ignored) {
                $service = $services->random();

                $rows[] = $this->row(
                    $salon,
                    $bookable->random(),
                    $service,
                    $staff->random(),
                    $local->clone()->setTime(random_int(9, 17), [0, 15, 30, 45][random_int(0, 3)]),
                    random_int(1, 6) === 1 ? Appointment::PENDING : Appointment::CONFIRMED
                );
            }
        }

        // Chunked insert: ~100 rows, and one INSERT beats 100 round trips.
        // Note this bypasses model events — fine here, nothing is listening yet.
        foreach (array_chunk($rows, 50) as $chunk) {
            Appointment::insert($chunk);
        }

        $this->refreshCustomerStats($salon);

        /* AFTER the stats refresh, or it would overwrite these.
           #10 = a lapsed customer WITH marketing consent, so the win-back rule
                 has a legitimate target (no consent = no marketing, so a lapsed
                 customer without consent would prove nothing).
           #11 = unsubscribed, so you can prove we correctly skip them. */
        $customers[10]->forceFill([
            'marketing_consent' => true,
            'consent_at' => now()->subMonths(8),
            'consent_source' => 'booking_form',
            'last_visit_at' => now()->subDays(140),
            'total_spend' => 186.00,
        ])->save();

        $customers[11]->forceFill([
            'marketing_consent' => false,
            'unsubscribed_at' => now()->subWeeks(3),
            'last_visit_at' => now()->subDays(160),
            'total_spend' => 92.00,
        ])->save();

        return $salon;
    }

    /* ===================================================================== */

    protected function gym(): Business
    {
        $gym = Business::updateOrCreate(
            ['slug' => 'demo-gym'],
            [
                'name' => 'Demo Fitness',
                'niche' => 'gym',
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]
        );

        User::updateOrCreate(
            ['email' => 'owner@demogym.test'],
            [
                'business_id' => $gym->id,
                'name' => 'Gym Owner',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ]
        );

        // Deliberately minimal — this tenant exists to be *invisible* to the salon.
        // Distinctive names so a leak is obvious the moment you see one.
        Service::updateOrCreate(
            ['business_id' => $gym->id, 'name' => 'GYM-ONLY Personal Training Hour'],
            ['duration_minutes' => 60, 'price' => 40.00, 'active' => true]
        );

        Customer::updateOrCreate(
            ['business_id' => $gym->id, 'phone' => '+447700900901'],
            ['name' => 'GYM-ONLY Test Member', 'preferred_channel' => 'email']
        );

        return $gym;
    }

    /* ===================================================================== */

    /** One raw appointment row, ready for a bulk insert. Local time in, UTC out. */
    protected function row(
        Business $business,
        Customer $customer,
        Service $service,
        StaffMember $staff,
        Carbon $localStart,
        string $status
    ): array {
        $startUtc = $localStart->clone()->utc();

        return [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $startUtc,
            'ends_at' => $startUtc->clone()->addMinutes($service->duration_minutes),
            'status' => $status,
            'price' => $service->price,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Recompute last_visit_at and total_spend from completed appointments.
     *
     * These columns are denormalised so the dashboard and the win-back rule do not
     * aggregate on every page load. Phase 5 will keep them fresh with an observer
     * plus a nightly command; for now the seeder does it once.
     */
    protected function refreshCustomerStats(Business $business): void
    {
        Customer::where('business_id', $business->id)
            ->get()
            ->each(function (Customer $customer) {
                $completed = Appointment::where('customer_id', $customer->id)
                    ->where('status', Appointment::COMPLETED);

                $customer->forceFill([
                    'last_visit_at' => $completed->clone()->max('starts_at'),
                    'total_spend' => $completed->clone()->sum('price'),
                ])->save();
            });
    }
}
