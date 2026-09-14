<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The dashboard carries two risks nothing else in the suite covers.
 *
 * The first is a tenant leak hiding behind a 200. Every other tenant screen aborts
 * 403 for a super-admin, and TenantScreenAccessTest sweeps the lot to prove it.
 * This screen deliberately renders for them instead — it is where login lands and
 * the navigation offers them no other destination — so 'dashboard' sits on that
 * test's allowlist and the checking has to happen here, by hand.
 *
 * The second is arithmetic an owner will trust without checking. "£0 expected
 * today" when there are four bookings, or a midnight appointment counted on the
 * wrong day, is worse than a blank page: it looks like an answer.
 *
 * Time is frozen in mid-July on purpose. London is BST then (UTC+1), so local
 * 00:30 falls on the previous UTC date and the start of the local month falls in
 * the previous UTC month — the two conversions most likely to be got wrong. A
 * relative date would exercise them only for part of the year.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Business $gym;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant is static state and PHPUnit shares one process across tests.
        Tenant::forget();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00', 'Europe/London'));

        $this->salon = Business::factory()->create([
            'name' => 'Bright Hair Studio',
            'slug' => 'bright-hair',
            'timezone' => 'Europe/London',
        ]);

        $this->gym = Business::factory()->create([
            'name' => 'Iron Works Gym',
            'slug' => 'iron-works',
            'timezone' => 'Europe/London',
        ]);

        $this->owner = User::factory()->create(['business_id' => $this->salon->id]);

        // A service exists from the start, so the setup checklist turns on and off
        // with the customer count alone and each test controls one variable.
        // Named without an ampersand on purpose: assertDontSee() would then have to
        // match the HTML-escaped form and a passing test would prove nothing.
        Service::factory()->forBusiness($this->salon)->create([
            'name' => 'Signature Cut',
            'duration_minutes' => 30,
            'price' => 35.00,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Tenant::forget();

        parent::tearDown();
    }

    /* ================================================================
     | Helpers
     * ================================================================ */

    /**
     * A booking at a London wall-clock time — the way the salon would say it.
     *
     * Stored in UTC, as every date in this app is. Passing local times through here
     * rather than writing UTC in the tests keeps the fixtures readable and means
     * the conversion under test is the app's, not the test's.
     */
    protected function bookingAt(string $localDateTime, array $attributes = [], ?Business $business = null): Appointment
    {
        $starts = Carbon::parse($localDateTime, 'Europe/London');

        return Appointment::factory()
            ->forBusiness($business ?? $this->salon)
            ->create(array_merge([
                'starts_at' => $starts->clone()->utc(),
                'ends_at' => $starts->clone()->addMinutes(30)->utc(),
                'status' => Appointment::CONFIRMED,
                'price' => 30.00,
            ], $attributes));
    }

    /** The component, rendered as the salon's owner. */
    protected function dashboard()
    {
        $this->actingAs($this->owner);

        return Volt::test('dashboard');
    }

    /* ================================================================
     | It opens at all
     * ================================================================ */

    public function test_the_landing_page_opens_for_an_owner(): void
    {
        // Also proves the route is wired to the Volt component: four auth tests
        // redirect to route('dashboard') after login, so this must render.
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bright Hair Studio');
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /* ================================================================
     | Local dates — the arithmetic an owner will not double-check
     * ================================================================ */

    /**
     * The bug this whole timezone approach exists to prevent.
     *
     * 00:30 on 16 July in London is 23:30 on 15 July in UTC. A whereDate() against
     * the stored column puts that booking on today's list and leaves it off
     * tomorrow's — so the owner sees a booking they do not have, and tomorrow's
     * reminder is never counted, let alone sent.
     */
    public function test_a_booking_just_after_midnight_belongs_to_tomorrow(): void
    {
        $justAfterMidnight = $this->bookingAt('2026-07-16 00:30');

        // Same UTC date as the booking above, but genuinely today in London.
        $thisEvening = $this->bookingAt('2026-07-15 18:00');

        $component = $this->dashboard();

        $todayIds = $component->viewData('today')['all']->pluck('id')->all();
        $tomorrowIds = $component->viewData('tomorrow')['all']->pluck('id')->all();

        $this->assertContains($thisEvening->id, $todayIds);
        $this->assertNotContains($justAfterMidnight->id, $todayIds);

        $this->assertContains($justAfterMidnight->id, $tomorrowIds);
        $this->assertNotContains($thisEvening->id, $tomorrowIds);
    }

    /**
     * Same conversion, one month up.
     *
     * The local month starts at 00:00 on 1 July London, which is 23:00 on 30 June
     * UTC. Anything booked in that last local hour of June must stay in June, and
     * the first half-hour of July must land in July.
     */
    public function test_the_month_boundary_is_the_local_one(): void
    {
        // 22:00 on 30 June London is 21:00 UTC, still June either way.
        $this->bookingAt('2026-06-30 22:00', [
            'status' => Appointment::COMPLETED,
            'price' => 999.00,
        ]);

        // 00:30 on 1 July London is 23:30 on 30 JUNE in UTC. A month range built
        // from UTC month boundaries would drop this one.
        $this->bookingAt('2026-07-01 00:30', [
            'status' => Appointment::COMPLETED,
            'price' => 40.00,
        ]);

        $month = $this->dashboard()->viewData('month');

        $this->assertSame('July 2026', $month['label']);

        // The £999 is the tell: if it appears, June has leaked into July.
        $this->assertEqualsWithDelta(
            40.00,
            $month['earned'],
            0.001,
            'Month totals must use the local month, not the UTC one.'
        );

        $this->assertSame(1, $month['completed']);
    }

    /* ================================================================
     | Today
     * ================================================================ */

    public function test_todays_takings_ignore_cancellations_but_the_list_still_shows_them(): void
    {
        $this->bookingAt('2026-07-15 09:00', ['status' => Appointment::COMPLETED, 'price' => 40.00]);
        $this->bookingAt('2026-07-15 11:00', ['status' => Appointment::CONFIRMED, 'price' => 35.00]);
        $this->bookingAt('2026-07-15 13:00', ['status' => Appointment::CANCELLED, 'price' => 50.00]);
        $this->bookingAt('2026-07-15 15:00', ['status' => Appointment::NO_SHOW, 'price' => 20.00]);

        $today = $this->dashboard()->viewData('today');

        // All four are listed: an owner wants to see the gap in their day.
        $this->assertCount(4, $today['all']);

        // Only two are counted, and neither the cancellation nor the no-show adds
        // a penny to what the day is expected to bring in.
        $this->assertCount(2, $today['live']);
        $this->assertEqualsWithDelta(75.00, $today['expected'], 0.001);

        // Completed only, so this is money actually taken.
        $this->assertCount(1, $today['done']);
        $this->assertEqualsWithDelta(40.00, $today['takenSoFar'], 0.001);
    }

    /**
     * "Next up" has to mean the next thing the owner is waiting for, not simply the
     * next start time. Marking someone done early is normal, and a card still
     * pointing at them would be quietly useless all afternoon.
     */
    public function test_next_up_skips_bookings_already_dealt_with(): void
    {
        $this->bookingAt('2026-07-15 09:00', ['status' => Appointment::COMPLETED]);
        $eleven = $this->bookingAt('2026-07-15 11:00');
        $noon = $this->bookingAt('2026-07-15 12:00');

        $this->assertSame($eleven->id, $this->dashboard()->viewData('today')['next']->id);

        // Seen early and marked done: the card must move on.
        $eleven->changeStatus(Appointment::COMPLETED);

        $this->assertSame($noon->id, $this->dashboard()->viewData('today')['next']->id);
    }

    public function test_next_up_is_empty_once_the_day_is_over(): void
    {
        $this->bookingAt('2026-07-15 08:00', ['status' => Appointment::COMPLETED]);

        $this->assertNull($this->dashboard()->viewData('today')['next']);
    }

    /* ================================================================
     | Tomorrow's reminders — the number that must not be a promise
     * ================================================================ */

    /**
     * The distinction the old "do they have a phone number?" check got wrong.
     *
     * Telegram will not let a bot message someone who has not started a chat, so a
     * customer who prefers Telegram and has a perfectly good mobile is unreachable
     * until they link. Counting them as reachable promises a reminder that never
     * leaves, and the owner hears about it from the client.
     */
    public function test_tomorrows_count_only_includes_customers_we_can_actually_reach(): void
    {
        $linked = Customer::factory()->forBusiness($this->salon)->telegramLinked()->create([
            'name' => 'Reachable Rita',
        ]);

        $notLinked = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Unlinked Umar',
            'preferred_channel' => 'telegram',
            'phone' => '+447700900301',
            'telegram_chat_id' => null,
        ]);

        $optedOut = Customer::factory()->forBusiness($this->salon)->telegramLinked()->create([
            'name' => 'Opted-out Olu',
            'unsubscribed_at' => now()->subMonth(),
        ]);

        $this->bookingAt('2026-07-16 10:00', ['customer_id' => $linked->id]);
        $this->bookingAt('2026-07-16 11:00', ['customer_id' => $notLinked->id]);
        $this->bookingAt('2026-07-16 12:00', ['customer_id' => $optedOut->id]);

        $tomorrow = $this->dashboard()->viewData('tomorrow');

        $this->assertCount(3, $tomorrow['all']);
        $this->assertCount(1, $tomorrow['reachable']);
        $this->assertCount(2, $tomorrow['unreachable']);

        $this->assertSame(
            $linked->id,
            $tomorrow['reachable']->first()->customer_id,
            'The only customer who has actually started the bot should be the reachable one.'
        );
    }

    /** The owner needs the name and the reason, or the number is just bad news. */
    public function test_the_unreachable_are_named_with_what_to_fix(): void
    {
        $notLinked = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Unlinked Umar',
            'preferred_channel' => 'telegram',
            'phone' => '+447700900302',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 11:00', ['customer_id' => $notLinked->id]);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs a phone call')
            ->assertSee('Unlinked Umar')
            ->assertSee('Telegram not linked yet');
    }

    public function test_a_cancelled_booking_tomorrow_is_not_something_to_remind_about(): void
    {
        $this->bookingAt('2026-07-16 10:00');
        $this->bookingAt('2026-07-16 14:00', ['status' => Appointment::CANCELLED]);

        $this->assertCount(1, $this->dashboard()->viewData('tomorrow')['all']);
    }

    /* ================================================================
     | This month
     * ================================================================ */

    public function test_earnings_count_completed_only_and_the_no_show_cost_is_named(): void
    {
        $this->bookingAt('2026-07-02 10:00', ['status' => Appointment::COMPLETED, 'price' => 40.00]);
        $this->bookingAt('2026-07-10 10:00', ['status' => Appointment::COMPLETED, 'price' => 60.00]);
        $this->bookingAt('2026-07-05 10:00', ['status' => Appointment::NO_SHOW, 'price' => 30.00]);
        $this->bookingAt('2026-07-06 10:00', ['status' => Appointment::CANCELLED, 'price' => 25.00]);
        $this->bookingAt('2026-07-20 10:00', ['status' => Appointment::CONFIRMED, 'price' => 45.00]);

        $month = $this->dashboard()->viewData('month');

        // A confirmed booking is not money yet. Counting it as earned would turn
        // every later no-show into a phantom loss.
        $this->assertEqualsWithDelta(100.00, $month['earned'], 0.001);
        $this->assertSame(2, $month['completed']);

        $this->assertEqualsWithDelta(45.00, $month['booked'], 0.001);

        // The figure that pays for this entire product.
        $this->assertSame(1, $month['noShows']);
        $this->assertEqualsWithDelta(30.00, $month['noShowValue'], 0.001);

        $this->assertSame(1, $month['cancelled']);
    }

    /* ================================================================
     | Win-back
     * ================================================================ */

    /**
     * Three numbers rather than one, because collapsing them is how a business
     * ends up messaging people who never agreed to hear from them — which under
     * PECR is the ICO's business, not a support ticket.
     */
    public function test_lapsed_customers_are_split_by_whether_we_may_and_can_message_them(): void
    {
        // Consented and linked: a campaign the owner can run this afternoon.
        Customer::factory()->forBusiness($this->salon)->marketable()->telegramLinked()->lapsed()->create();

        // Consented but nowhere to send it — prefers Telegram, never linked.
        Customer::factory()->forBusiness($this->salon)->marketable()->lapsed()->create([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        // Never agreed to marketing. Must not be in either of the other two.
        Customer::factory()->forBusiness($this->salon)->telegramLinked()->lapsed()->create([
            'marketing_consent' => false,
        ]);

        // Seen last week, so not lapsed at all.
        Customer::factory()->forBusiness($this->salon)->marketable()->telegramLinked()->create([
            'last_visit_at' => now()->subWeek(),
        ]);

        $lapsed = $this->dashboard()->viewData('lapsed');

        $this->assertSame(3, $lapsed['total']);
        $this->assertSame(2, $lapsed['marketable']);
        $this->assertSame(1, $lapsed['contactable']);
    }

    /* ================================================================
     | Worth a look
     * ================================================================ */

    public function test_unconfirmed_bookings_in_the_next_week_are_surfaced(): void
    {
        $this->bookingAt('2026-07-17 10:00', ['status' => Appointment::PENDING]);

        $texts = array_column($this->dashboard()->viewData('attention'), 'text');

        $this->assertNotEmpty(array_filter(
            $texts,
            fn (string $text) => str_contains($text, 'still unconfirmed')
        ));
    }

    /**
     * The panel has to be able to stay quiet. A banner that is always there is a
     * banner an owner learns to read past, and then misses the one that mattered.
     */
    public function test_nothing_to_act_on_means_no_panel_at_all(): void
    {
        $linked = Customer::factory()->forBusiness($this->salon)->telegramLinked()->create();

        // Confirmed, not pending, with a customer we can reach — and the factory
        // gives the booking a staff member, so that item stays quiet too.
        $this->bookingAt('2026-07-15 14:00', ['customer_id' => $linked->id]);

        $this->assertSame([], $this->dashboard()->viewData('attention'));

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Worth a look');
    }

    /* ================================================================
     | The empty state
     * ================================================================ */

    public function test_a_business_with_no_customers_is_told_what_to_do_next(): void
    {
        $response = $this->actingAs($this->owner)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Add your first customer')
            // The service added in setUp is already ticked off. Matched on the
            // suffix alone because Blade puts a newline and an indent between the
            // count and the word, so '1 service added' is not in the markup.
            ->assertSee('service added')
            ->assertDontSee('Add what you offer')
            // And the figures are withheld rather than shown as a wall of zeros.
            ->assertDontSee('No-shows this month');

        $this->assertFalse($this->dashboard()->viewData('setup')['ready']);
    }

    /* ================================================================
     | Tenant isolation — the reason this file exists
     * ================================================================ */

    public function test_another_businesss_bookings_are_invisible(): void
    {
        $gymMember = Customer::factory()->forBusiness($this->gym)->create([
            'name' => 'GYM-ONLY Dave',
        ]);

        $this->bookingAt('2026-07-15 11:00', ['customer_id' => $gymMember->id], $this->gym);
        $this->bookingAt('2026-07-16 11:00', ['customer_id' => $gymMember->id], $this->gym);

        $mine = $this->bookingAt('2026-07-15 12:00');

        $component = $this->dashboard();

        // Assert on membership, never on totals: AppointmentFactory creates its own
        // customer, service and staff inside the target business, so incidental rows
        // appear and an exact-count assertion would cry wolf.
        $this->assertSame([$mine->id], $component->viewData('today')['all']->pluck('id')->all());
        $this->assertCount(0, $component->viewData('tomorrow')['all']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('GYM-ONLY Dave');
    }

    public function test_the_month_figures_do_not_include_another_business(): void
    {
        $this->bookingAt('2026-07-08 10:00', ['status' => Appointment::COMPLETED, 'price' => 500.00], $this->gym);
        $this->bookingAt('2026-07-09 10:00', ['status' => Appointment::COMPLETED, 'price' => 40.00]);

        $this->assertEqualsWithDelta(40.00, $this->dashboard()->viewData('month')['earned'], 0.001);
    }

    /* ================================================================
     | Super-admin: the branch that replaces the 403
     * ================================================================ */

    /**
     * Why this screen is allowed to render for a super-admin at all.
     *
     * It is where login lands, and navigation.blade.php shows the logo link and the
     * Dashboard link to everyone. A 403 here would leave a signed-in admin on a
     * page where every visible link also 403s — and DemoBusinessSeeder really does
     * create admin@example.com with business_id = null, so this is shipped, not
     * theoretical.
     */
    public function test_a_super_admin_is_not_locked_out_of_the_landing_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Platform');
    }

    /**
     * And the price of that exemption, paid here.
     *
     * TenantScreenAccessTest's 403 sweep skips 'dashboard', so this is the only
     * thing standing between a null business_id and every client's customer list
     * rendered as one page. In the UK that would be a reportable breach, not a bug.
     */
    public function test_a_super_admin_sees_business_names_and_no_client_records(): void
    {
        $customer = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Sarah Ahmed',
            'phone' => '+447700900303',
        ]);

        $this->bookingAt('2026-07-15 11:00', ['customer_id' => $customer->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $response = $this->get(route('dashboard'));

        $response->assertOk()
            // Our own client list is legitimate: these are the businesses paying us.
            ->assertSee('Bright Hair Studio')
            ->assertSee('Iron Works Gym')
            // What is inside them is not.
            ->assertDontSee('Sarah Ahmed')
            ->assertDontSee('+447700900303')
            ->assertDontSee('Signature Cut')
            ->assertDontSee('Next up');

        $component = Volt::test('dashboard');

        // The owner half of the component must not have run at all — business() is
        // findOrFail(Tenant::id()) and would throw for a null business_id.
        $this->assertNull($component->viewData('business'));
        $this->assertNull($component->viewData('today'));
        $this->assertNull($component->viewData('tomorrow'));
        $this->assertNull($component->viewData('month'));
        $this->assertNull($component->viewData('lapsed'));
        $this->assertNull($component->viewData('attention'));
    }

    /** Counts across businesses are a support figure, and they must be right. */
    public function test_a_super_admin_sees_how_big_each_business_is(): void
    {
        Customer::factory()->forBusiness($this->salon)->count(3)->create();
        Customer::factory()->forBusiness($this->gym)->count(1)->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $component = Volt::test('dashboard');

        $this->assertSame(2, $component->viewData('totals')['businesses']);
        $this->assertSame(4, $component->viewData('totals')['customers']);

        $counts = $component->viewData('businesses')->pluck('customers_count', 'name')->all();

        $this->assertSame(3, $counts['Bright Hair Studio']);
        $this->assertSame(1, $counts['Iron Works Gym']);
    }
}
