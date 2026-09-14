<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The diary is the screen the whole product hangs off, so the risks are worth
 * naming: a booking landing on the wrong calendar day because it was grouped in
 * UTC rather than the salon's local time, a crafted Livewire payload attaching
 * another business's customer to a booking, a double-booking slipping through,
 * a price changing retroactively when the service's price is put up, and the
 * customer's spend figures drifting out of step with what actually happened.
 *
 * Dates here are hard-coded rather than relative to now(). July is BST (UTC+1)
 * and January is GMT (UTC+0), which is exactly the difference these tests exist
 * to catch — a relative date would test one or the other depending on the month
 * the suite happens to run in.
 */
class AppointmentScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Business $gym;

    protected User $owner;

    protected Customer $customer;

    protected Service $service;

    protected StaffMember $stylist;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant is static state and PHPUnit shares one process across tests.
        Tenant::forget();

        $this->salon = Business::factory()->create([
            'name' => 'Salon',
            'slug' => 'salon',
            'timezone' => 'Europe/London',
        ]);

        $this->gym = Business::factory()->create([
            'name' => 'Gym',
            'slug' => 'gym',
            'timezone' => 'Europe/London',
        ]);

        $this->owner = User::factory()->create(['business_id' => $this->salon->id]);

        $this->customer = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Sarah Ahmed',
            // 07700 900xxx is Ofcom's reserved drama range, so a misdirected send
            // from a fixture can never reach a real person.
            'phone' => '+447700900111',
        ]);

        $this->service = Service::factory()->forBusiness($this->salon)->create([
            'name' => 'Cut & Finish',
            'duration_minutes' => 45,
            'price' => 35.00,
        ]);

        $this->stylist = StaffMember::factory()->forBusiness($this->salon)->create([
            'name' => 'Jade Thompson',
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    /* ================================================================
     | Helpers
     * ================================================================ */

    /** A UTC Carbon from a London wall-clock time — how the salon would say it. */
    protected function london(string $localDateTime): Carbon
    {
        return Carbon::parse($localDateTime, 'Europe/London')->utc();
    }

    protected function booking(array $attributes = [], ?Business $business = null): Appointment
    {
        return Appointment::factory()
            ->forBusiness($business ?? $this->salon)
            ->create($attributes);
    }

    /**
     * The booking form, filled with values that save cleanly.
     *
     * Order matters: service_id is set before duration and price, because setting
     * it fires updatedServiceId() and prefills both — so the explicit values below
     * must come afterwards to win. Overrides passed in are applied last.
     */
    protected function bookingForm(array $fields = [])
    {
        $component = Volt::test('appointments.index');

        $defaults = [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->stylist->id,
            'form_date' => '2026-07-15',
            'form_time' => '10:00',
            'duration_minutes' => '30',
            'price' => '25.00',
            'status' => Appointment::CONFIRMED,
        ];

        foreach (array_merge($defaults, $fields) as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    /* ================================================================
     | Access
     * ================================================================ */

    public function test_a_guest_cannot_reach_the_diary(): void
    {
        $this->get('/appointments')->assertRedirect('/login');
    }

    public function test_a_super_admin_is_locked_out_rather_than_shown_every_salons_diary(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/appointments')->assertForbidden();
    }

    public function test_an_owner_sees_only_their_own_businesss_bookings(): void
    {
        $ours = $this->booking(['starts_at' => $this->london('2026-07-15 10:00'), 'ends_at' => $this->london('2026-07-15 10:45')]);

        $theirs = $this->booking([
            'starts_at' => $this->london('2026-07-15 11:00'),
            'ends_at' => $this->london('2026-07-15 11:45'),
        ], $this->gym);

        $this->actingAs($this->owner);

        $ids = Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->viewData('appointments')
            ->pluck('id')
            ->all();

        $this->assertContains($ours->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /* ================================================================
     | The day boundary — where UTC quietly ruins everything
     * ================================================================ */

    /**
     * The most valuable test in this file.
     *
     * 00:30 on 15 July in London is 23:30 on 14 July in UTC. A whereDate() on the
     * raw column would file this booking under the 14th, so the owner opening the
     * 15th would not see their first client of the day.
     */
    public function test_an_after_midnight_booking_appears_on_the_local_day_not_the_utc_one(): void
    {
        $booking = $this->booking([
            'starts_at' => $this->london('2026-07-15 00:30'),
            'ends_at' => $this->london('2026-07-15 01:15'),
        ]);

        // Proof the fixture really does straddle the boundary, otherwise this test
        // could pass while testing nothing.
        $this->assertSame('2026-07-14 23:30:00', $booking->starts_at->toDateTimeString());

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index');

        $this->assertContains(
            $booking->id,
            $component->set('date', '2026-07-15')->viewData('appointments')->pluck('id')->all(),
            'A 00:30 booking is missing from the day it actually happens on.'
        );

        $this->assertNotContains(
            $booking->id,
            $component->set('date', '2026-07-14')->viewData('appointments')->pluck('id')->all(),
            'A 00:30 booking is showing on the previous day, which means it was grouped in UTC.'
        );
    }

    /** The same thing in reverse: what the form takes in must be stored as UTC. */
    public function test_a_time_typed_in_summer_is_stored_an_hour_behind_in_utc(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm(['form_date' => '2026-07-15', 'form_time' => '00:30'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-07-14 23:30:00', Appointment::firstOrFail()->starts_at->toDateTimeString());
    }

    /** And in winter there is no offset at all, so the same code must not shift it. */
    public function test_a_time_typed_in_winter_is_stored_unchanged(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm(['form_date' => '2026-01-15', 'form_time' => '09:00'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-01-15 09:00:00', Appointment::firstOrFail()->starts_at->toDateTimeString());
    }

    /* ================================================================
     | What the day list shows
     * ================================================================ */

    public function test_cancellations_are_hidden_until_asked_for(): void
    {
        $cancelled = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'status' => Appointment::CANCELLED,
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index')->set('date', '2026-07-15');

        $this->assertNotContains($cancelled->id, $component->viewData('appointments')->pluck('id')->all());

        $component->set('showCancelled', true);

        $this->assertContains($cancelled->id, $component->viewData('appointments')->pluck('id')->all());
    }

    public function test_the_day_can_be_narrowed_to_one_staff_member(): void
    {
        $mine = $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
        ]);

        $other = StaffMember::factory()->forBusiness($this->salon)->create(['name' => 'Kelly']);

        $theirs = $this->booking([
            'staff_member_id' => $other->id,
            'starts_at' => $this->london('2026-07-15 12:00'),
            'ends_at' => $this->london('2026-07-15 12:45'),
        ]);

        $this->actingAs($this->owner);

        $ids = Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->set('staffFilter', $this->stylist->id)
            ->viewData('appointments')
            ->pluck('id')
            ->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /** Clearing the date input must not leave the page disagreeing with itself. */
    public function test_an_emptied_date_falls_back_to_today_rather_than_breaking(): void
    {
        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->set('date', '')
            ->assertSet('date', $this->salon->now()->toDateString());
    }

    public function test_a_nonsense_date_in_the_url_does_not_error(): void
    {
        $this->actingAs($this->owner);

        $this->get('/appointments?day=not-a-date')->assertOk();
    }

    /* ================================================================
     | Booking
     * ================================================================ */

    public function test_a_booking_is_attached_to_the_current_business_and_marked_manual(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm()->call('save')->assertHasNoErrors();

        $appointment = Appointment::firstOrFail();

        // business_id must never come from the form — the trait fills it.
        $this->assertSame($this->salon->id, $appointment->business_id);
        $this->assertSame($this->customer->id, $appointment->customer_id);
        $this->assertSame('manual', $appointment->source);
        $this->assertSame(Appointment::CONFIRMED, $appointment->status);
    }

    public function test_the_end_time_is_worked_out_from_the_duration(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm(['duration_minutes' => '90'])->call('save')->assertHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertSame(90, $appointment->durationMinutes());
        $this->assertSame('2026-07-15 10:30:00', $appointment->ends_at->toDateTimeString());
    }

    public function test_choosing_a_service_fills_in_its_duration_and_price(): void
    {
        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('create')
            ->set('service_id', $this->service->id)
            ->assertSet('duration_minutes', '45')
            ->assertSet('price', '35.00');
    }

    public function test_a_booking_needs_a_customer(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm(['customer_id' => null])
            ->call('save')
            ->assertHasErrors('customer_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_booking_can_be_made_without_a_service_or_a_staff_member(): void
    {
        $this->actingAs($this->owner);

        // A quick blocked-out slot with nothing else known yet is legitimate.
        $this->bookingForm(['service_id' => null, 'staff_member_id' => null])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Appointment::count());
    }

    /* ================================================================
     | Cross-tenant payloads — the ids are the leak surface here
     * ================================================================ */

    /**
     * Rule::exists builds a plain query builder, so the BelongsToBusiness global
     * scope does NOT apply to it. Without the hand-written business_id clause,
     * these three tests would pass validation and attach another business's row.
     */
    public function test_another_businesss_customer_cannot_be_attached_to_a_booking(): void
    {
        $theirs = Customer::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->bookingForm(['customer_id' => $theirs->id])
            ->call('save')
            ->assertHasErrors('customer_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_another_businesss_service_cannot_be_attached_to_a_booking(): void
    {
        $theirs = Service::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->bookingForm(['service_id' => $theirs->id])
            ->call('save')
            ->assertHasErrors('service_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_another_businesss_staff_member_cannot_be_attached_to_a_booking(): void
    {
        $theirs = StaffMember::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->bookingForm(['staff_member_id' => $theirs->id])
            ->call('save')
            ->assertHasErrors('staff_member_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_soft_deleted_customer_cannot_be_booked(): void
    {
        $this->customer->delete();

        $this->actingAs($this->owner);

        $this->bookingForm()->call('save')->assertHasErrors('customer_id');
    }

    public function test_editing_another_businesss_booking_is_impossible(): void
    {
        $theirs = $this->booking([], $this->gym);

        $this->actingAs($this->owner);

        // findOrFail is the authorisation check: their id does not exist in our scope.
        $this->expectException(ModelNotFoundException::class);

        Volt::test('appointments.index')->call('edit', $theirs->id);
    }

    public function test_changing_the_status_of_another_businesss_booking_is_impossible(): void
    {
        $theirs = $this->booking([], $this->gym);

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('appointments.index')->call('setStatus', $theirs->id, Appointment::COMPLETED);
    }

    /* ================================================================
     | Double booking — warned about, not forbidden
     * ================================================================ */

    public function test_a_clashing_booking_is_warned_about_and_not_saved(): void
    {
        $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['form_time' => '10:30'])->call('save');

        // No validation error — this is a judgement call put to the owner, not a
        // malformed form. So the proof is the warning plus nothing being written.
        $component->assertHasNoErrors();

        $this->assertStringContainsString('Jade Thompson', $component->get('conflict'));
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_owner_can_overrule_the_clash_warning(): void
    {
        $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $this->bookingForm(['form_time' => '10:30'])
            ->call('save')
            ->set('allowOverlap', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Appointment::count());
    }

    /** Colour developing at 10:00–11:00 then the next client at 11:00 is not a clash. */
    public function test_back_to_back_bookings_do_not_count_as_a_clash(): void
    {
        $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['form_time' => '11:00'])->call('save');

        $this->assertNull($component->get('conflict'));
        $this->assertSame(2, Appointment::count());
    }

    public function test_a_cancelled_booking_no_longer_holds_the_slot(): void
    {
        $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CANCELLED,
        ]);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['form_time' => '10:30'])->call('save');

        $this->assertNull($component->get('conflict'));
        $this->assertSame(2, Appointment::count());
    }

    /** Two different stylists at the same time is the normal case, not a clash. */
    public function test_two_staff_can_be_busy_at_the_same_time(): void
    {
        $other = StaffMember::factory()->forBusiness($this->salon)->create(['name' => 'Kelly']);

        $this->booking([
            'staff_member_id' => $other->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['form_time' => '10:00'])->call('save');

        $this->assertNull($component->get('conflict'));
        $this->assertSame(2, Appointment::count());
    }

    /** A clash is a clash within one salon only — another business's diary is not ours. */
    public function test_another_businesss_booking_never_causes_a_clash(): void
    {
        $theirStaff = StaffMember::factory()->forBusiness($this->gym)->create();

        $this->booking([
            'staff_member_id' => $theirStaff->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
        ], $this->gym);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['form_time' => '10:30'])->call('save');

        $this->assertNull($component->get('conflict'));
    }

    public function test_editing_a_booking_does_not_report_it_clashing_with_itself(): void
    {
        $appointment = $this->booking([
            'staff_member_id' => $this->stylist->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index')
            ->call('edit', $appointment->id)
            ->set('notes', 'Wants a fringe trim too')
            ->call('save');

        $this->assertNull($component->get('conflict'));
        $this->assertSame(1, Appointment::count());
        $this->assertSame('Wants a fringe trim too', $appointment->fresh()->notes);
    }

    /**
     * Deliberate: with nobody assigned there is no diary to clash with, so the
     * check cannot honestly say anything. Recorded as a test so it stays a
     * decision rather than becoming a bug report.
     */
    public function test_bookings_with_nobody_assigned_never_clash(): void
    {
        $this->booking([
            'staff_member_id' => null,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 11:00'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $component = $this->bookingForm(['staff_member_id' => null, 'form_time' => '10:30'])->call('save');

        $this->assertNull($component->get('conflict'));
        $this->assertSame(2, Appointment::count());
    }

    /* ================================================================
     | The price is frozen at the moment of booking
     * ================================================================ */

    public function test_putting_a_service_price_up_does_not_change_bookings_already_taken(): void
    {
        $appointment = $this->booking([
            'service_id' => $this->service->id,
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'price' => 35.00,
        ]);

        $this->service->update(['price' => 60.00]);

        $this->actingAs($this->owner);

        // Opening an existing booking must not re-read the service's price. edit()
        // assigns service_id server-side, and Livewire does not fire updated hooks
        // for that — which is precisely what keeps the old price intact.
        Volt::test('appointments.index')
            ->call('edit', $appointment->id)
            ->assertSet('price', '35.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('35.00', $appointment->fresh()->price);
    }

    /* ================================================================
     | Adding a customer without leaving the form
     * ================================================================ */

    /**
     * Covers the markup, not the behaviour — deliberately.
     *
     * Every other test in this section reaches the inline panel by setting
     * `addingCustomer` directly, which leaves `showForm` false, so the whole
     * `@if ($showForm)` modal is never rendered and a fatal in that branch (a
     * class not aliased in the markup half, say) would pass CI and fail in front
     * of a client. This walks the two buttons an owner actually presses.
     */
    public function test_the_inline_new_customer_panel_renders(): void
    {
        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('create')
            ->call('startAddingCustomer')
            ->assertSee('Without a phone number this customer cannot be sent reminders.')
            ->assertSee('Pick an existing customer instead')
            ->assertHasNoErrors();
    }

    public function test_a_new_customer_can_be_created_while_booking(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => 'Priya Patel',
            'new_customer_phone' => '07700 900222',
        ])->call('save')->assertHasNoErrors();

        $created = Customer::where('name', 'Priya Patel')->firstOrFail();

        $this->assertSame($this->salon->id, $created->business_id);
        // Normalised on the way in, or it would never send.
        $this->assertSame('+447700900222', $created->phone);
        $this->assertSame($created->id, Appointment::firstOrFail()->customer_id);
    }

    /**
     * An already-known number is the same person, not an error. Inserting instead
     * would hit the (business_id, phone) unique index and produce a raw database
     * error the owner cannot act on, mid-phone-call.
     */
    public function test_a_phone_number_already_on_file_books_that_customer_instead_of_duplicating_them(): void
    {
        $this->actingAs($this->owner);

        $before = Customer::count();

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => 'Sara A',
            'new_customer_phone' => '07700 900111', // already Sarah Ahmed
        ])->call('save')->assertHasNoErrors();

        $this->assertSame($before, Customer::count());
        $this->assertSame($this->customer->id, Appointment::firstOrFail()->customer_id);
    }

    /** A returning customer is restored, along with their whole history. */
    public function test_a_removed_customer_coming_back_is_restored_rather_than_blocked(): void
    {
        $this->customer->delete();

        $this->actingAs($this->owner);

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => 'Sarah Ahmed',
            'new_customer_phone' => '07700 900111',
        ])->call('save')->assertHasNoErrors();

        $this->assertFalse($this->customer->fresh()->trashed());
        $this->assertSame($this->customer->id, Appointment::firstOrFail()->customer_id);
    }

    /**
     * The reason customer creation happens inside save()'s transaction and after a
     * single validate() covering everything: creating them first would leave a
     * customer behind every time the rest of the form turned out to be wrong.
     */
    public function test_a_failed_booking_does_not_leave_a_half_entered_customer_behind(): void
    {
        $this->actingAs($this->owner);

        $before = Customer::count();

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => 'Never Saved',
            'new_customer_phone' => '07700 900333',
            'duration_minutes' => '', // the thing that fails
        ])->call('save')->assertHasErrors('duration_minutes');

        $this->assertSame($before, Customer::count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_new_customer_needs_a_name(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => '',
            'new_customer_phone' => '07700 900333',
        ])->call('save')->assertHasErrors('new_customer_name');
    }

    /** The model's mutator nulls anything it cannot parse, so this must be rejected. */
    public function test_text_that_is_not_a_phone_number_is_rejected_not_quietly_dropped(): void
    {
        $this->actingAs($this->owner);

        $this->bookingForm([
            'customer_id' => null,
            'addingCustomer' => true,
            'new_customer_name' => 'Priya Patel',
            'new_customer_phone' => 'ask reception',
        ])->call('save')->assertHasErrors('new_customer_phone');

        $this->assertSame(0, Appointment::count());
    }

    /* ================================================================
     | Status changes and the customer's figures
     * ================================================================ */

    public function test_marking_a_booking_done_updates_the_customers_last_visit_and_spend(): void
    {
        $appointment = $this->booking([
            'customer_id' => $this->customer->id,
            'starts_at' => $this->london('2026-01-15 10:00'),
            'ends_at' => $this->london('2026-01-15 10:45'),
            'status' => Appointment::CONFIRMED,
            'price' => 35.00,
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')->call('setStatus', $appointment->id, Appointment::COMPLETED);

        $customer = $this->customer->fresh();

        $this->assertSame('35.00', $customer->total_spend);
        $this->assertSame('2026-01-15 10:00:00', $customer->last_visit_at->toDateTimeString());
    }

    /**
     * Reversal matters as much as completion. An increment/decrement rollup drifts
     * the first time this happens, and once total_spend is wrong it stays wrong —
     * quietly changing who counts as a lapsed customer.
     */
    public function test_undoing_a_completed_booking_takes_the_money_back_off(): void
    {
        $appointment = $this->booking([
            'customer_id' => $this->customer->id,
            'starts_at' => $this->london('2026-01-15 10:00'),
            'ends_at' => $this->london('2026-01-15 10:45'),
            'status' => Appointment::COMPLETED,
            'price' => 35.00,
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index');

        $component->call('setStatus', $appointment->id, Appointment::COMPLETED);
        $this->assertSame('35.00', $this->customer->fresh()->total_spend);

        $component->call('setStatus', $appointment->id, Appointment::CANCELLED);

        $customer = $this->customer->fresh();

        $this->assertSame('0.00', $customer->total_spend);
        $this->assertNull($customer->last_visit_at);
    }

    public function test_a_no_show_is_not_counted_as_a_visit(): void
    {
        $appointment = $this->booking([
            'customer_id' => $this->customer->id,
            'starts_at' => $this->london('2026-01-15 10:00'),
            'ends_at' => $this->london('2026-01-15 10:45'),
            'status' => Appointment::CONFIRMED,
            'price' => 35.00,
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')->call('setStatus', $appointment->id, Appointment::NO_SHOW);

        $customer = $this->customer->fresh();

        $this->assertSame('0.00', $customer->total_spend);
        $this->assertNull($customer->last_visit_at);
        $this->assertSame(Appointment::NO_SHOW, $appointment->fresh()->status);
    }

    /**
     * The status comes from the browser, so it cannot be trusted: a crafted payload
     * must not be able to write an arbitrary string into the column. A status the
     * code never checks for would drop the booking out of the diary, the reminder
     * queue and the day's takings at once, silently.
     *
     * Asserted on the response rather than with expectException(): Livewire's test
     * harness calls withoutExceptionHandling([HttpException::class, ...]), which
     * leaves HttpException *handled* — abort() becomes a real 422 response instead
     * of an exception thrown at the test. (ModelNotFoundException is not in that
     * list, which is why the cross-tenant tests above can still expect a throw.)
     */
    public function test_an_unknown_status_is_refused(): void
    {
        $appointment = $this->booking(['status' => Appointment::CONFIRMED]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('setStatus', $appointment->id, 'sort_of_maybe')
            ->assertStatus(422);

        $this->assertSame(Appointment::CONFIRMED, $appointment->fresh()->status);
    }

    /** Moving a booking to a different person has to correct both of their figures. */
    public function test_moving_a_booking_to_another_customer_corrects_both_of_their_figures(): void
    {
        $other = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Leah Brown',
            'phone' => '+447700900444',
        ]);

        $appointment = $this->booking([
            'customer_id' => $this->customer->id,
            'starts_at' => $this->london('2026-01-15 10:00'),
            'ends_at' => $this->london('2026-01-15 10:45'),
            'status' => Appointment::COMPLETED,
            'price' => 35.00,
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index');
        $component->call('setStatus', $appointment->id, Appointment::COMPLETED);

        $this->assertSame('35.00', $this->customer->fresh()->total_spend);

        $component->call('edit', $appointment->id)
            ->set('customer_id', $other->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.00', $this->customer->fresh()->total_spend);
        $this->assertSame('35.00', $other->fresh()->total_spend);
    }

    /* ================================================================
     | Removing a booking
     * ================================================================ */

    public function test_removing_a_booking_keeps_the_record_and_corrects_the_spend(): void
    {
        $appointment = $this->booking([
            'customer_id' => $this->customer->id,
            'starts_at' => $this->london('2026-01-15 10:00'),
            'ends_at' => $this->london('2026-01-15 10:45'),
            'status' => Appointment::COMPLETED,
            'price' => 35.00,
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index');
        $component->call('setStatus', $appointment->id, Appointment::COMPLETED);
        $component->call('delete', $appointment->id);

        // Soft deleted: appointments are the record of what happened.
        $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
        $this->assertSame('0.00', $this->customer->fresh()->total_spend);
    }

    public function test_removing_another_businesss_booking_is_impossible(): void
    {
        $theirs = $this->booking([], $this->gym);

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('appointments.index')->call('delete', $theirs->id);
    }

    /* ================================================================
     | Editing rather than adding
     * ================================================================ */

    public function test_editing_updates_the_existing_booking_instead_of_adding_one(): void
    {
        $appointment = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('edit', $appointment->id)
            ->assertSet('form_date', '2026-07-15')
            ->assertSet('form_time', '10:00')
            ->set('form_time', '14:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Appointment::count());
        $this->assertSame('2026-07-15 13:00:00', $appointment->fresh()->starts_at->toDateTimeString());
    }

    /** Moving a booking to another day should take the owner with it. */
    public function test_the_diary_follows_a_booking_moved_to_a_different_day(): void
    {
        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->call('create')
            ->set('customer_id', $this->customer->id)
            ->set('form_date', '2026-07-20')
            ->set('form_time', '10:00')
            ->set('duration_minutes', '30')
            ->set('price', '25.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('date', '2026-07-20');
    }

    /* ================================================================
     | The week strip
     * ================================================================ */

    public function test_the_week_strip_runs_monday_to_sunday(): void
    {
        $this->actingAs($this->owner);

        // 15 July 2026 is a Wednesday.
        $strip = Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->viewData('weekStrip');

        $this->assertCount(7, $strip);
        // A UK diary starts on Monday. Carbon's 'en' locale can default to Sunday,
        // which would put the whole strip a day out.
        $this->assertSame('2026-07-13', $strip[0]['date']);
        $this->assertSame('Mon', $strip[0]['weekday']);
        $this->assertSame('2026-07-19', $strip[6]['date']);
    }

    public function test_the_week_strip_counts_bookings_on_their_local_day(): void
    {
        // 23:30 UTC on the 14th is 00:30 on the 15th in London.
        $this->booking([
            'starts_at' => $this->london('2026-07-15 00:30'),
            'ends_at' => $this->london('2026-07-15 01:15'),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->actingAs($this->owner);

        $strip = collect(
            Volt::test('appointments.index')
                ->set('date', '2026-07-15')
                ->viewData('weekStrip')
        )->keyBy('date');

        $this->assertSame(1, $strip['2026-07-15']['count']);
        $this->assertSame(0, $strip['2026-07-14']['count']);
    }

    public function test_the_week_strip_ignores_cancellations_and_other_businesses(): void
    {
        $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'status' => Appointment::CANCELLED,
        ]);

        $this->booking([
            'starts_at' => $this->london('2026-07-15 11:00'),
            'ends_at' => $this->london('2026-07-15 11:45'),
            'status' => Appointment::CONFIRMED,
        ], $this->gym);

        $this->actingAs($this->owner);

        $strip = collect(
            Volt::test('appointments.index')
                ->set('date', '2026-07-15')
                ->viewData('weekStrip')
        )->keyBy('date');

        $this->assertSame(0, $strip['2026-07-15']['count']);
    }

    /* ================================================================
     | The day's takings line
     * ================================================================ */

    public function test_the_expected_takings_ignore_cancellations(): void
    {
        $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'status' => Appointment::CONFIRMED,
            'price' => 35.00,
        ]);

        $this->booking([
            'starts_at' => $this->london('2026-07-15 12:00'),
            'ends_at' => $this->london('2026-07-15 12:45'),
            'status' => Appointment::CANCELLED,
            'price' => 90.00,
        ]);

        $this->actingAs($this->owner);

        $takings = Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->set('showCancelled', true)
            ->viewData('expectedTakings');

        $this->assertSame(35.0, (float) $takings);
    }

    /* ================================================================
     | Calendar view mode & Quick Reschedule
     * ================================================================ */

    public function test_view_mode_can_be_switched_to_columns(): void
    {
        $this->actingAs($this->owner);

        $component = Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->call('setViewMode', 'columns')
            ->assertSet('viewMode', 'columns')
            ->assertSee($this->stylist->name);
    }

    public function test_create_for_staff_pre_fills_staff_member_id(): void
    {
        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->call('createForStaff', $this->stylist->id)
            ->assertSet('showForm', true)
            ->assertSet('staff_member_id', $this->stylist->id);
    }

    public function test_quick_reschedule_modal_can_be_opened(): void
    {
        $booking = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'staff_member_id' => $this->stylist->id,
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->set('date', '2026-07-15')
            ->call('openReschedule', $booking->id)
            ->assertSet('showReschedule', true)
            ->assertSet('reschedulingId', $booking->id)
            ->assertSet('reschedule_date', '2026-07-15')
            ->assertSet('reschedule_time', '10:00')
            ->assertSet('reschedule_staff_id', $this->stylist->id);
    }

    public function test_quick_reschedule_presets_shift_date_and_time(): void
    {
        $booking = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'staff_member_id' => $this->stylist->id,
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('openReschedule', $booking->id)
            ->call('applyReschedulePreset', 'tomorrow')
            ->assertSet('reschedule_date', '2026-07-16')
            ->call('applyReschedulePreset', 'plus_15m')
            ->assertSet('reschedule_time', '10:15');
    }

    public function test_save_reschedule_updates_slot_and_closes_modal(): void
    {
        $booking = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'staff_member_id' => $this->stylist->id,
        ]);

        $this->actingAs($this->owner);

        Volt::test('appointments.index')
            ->call('openReschedule', $booking->id)
            ->set('reschedule_date', '2026-07-16')
            ->set('reschedule_time', '11:00')
            ->call('saveReschedule')
            ->assertSet('showReschedule', false)
            ->assertSet('date', '2026-07-16');

        $booking->refresh();

        $this->assertSame('2026-07-16 10:00:00', $booking->starts_at->toDateTimeString()); // UTC in summer is -1h
        $this->assertSame('2026-07-16 10:45:00', $booking->ends_at->toDateTimeString());
    }

    public function test_quick_reschedule_warns_on_clash_and_respects_override(): void
    {
        // Existing booking on 2026-07-15 from 14:00 to 14:45
        $this->booking([
            'starts_at' => $this->london('2026-07-15 14:00'),
            'ends_at' => $this->london('2026-07-15 14:45'),
            'staff_member_id' => $this->stylist->id,
        ]);

        // Booking to move
        $booking = $this->booking([
            'starts_at' => $this->london('2026-07-15 10:00'),
            'ends_at' => $this->london('2026-07-15 10:45'),
            'staff_member_id' => $this->stylist->id,
        ]);

        $this->actingAs($this->owner);

        // Attempting to move into 14:15 clashes
        $component = Volt::test('appointments.index')
            ->call('openReschedule', $booking->id)
            ->set('reschedule_date', '2026-07-15')
            ->set('reschedule_time', '14:15')
            ->call('saveReschedule')
            ->assertSet('showReschedule', true);

        $this->assertNotNull($component->get('reschedule_conflict'));

        $component->set('reschedule_allow_overlap', true)
            ->call('saveReschedule')
            ->assertSet('showReschedule', false);

        $booking->refresh();
        $this->assertSame('2026-07-15 13:15:00', $booking->starts_at->toDateTimeString());
    }
}
