<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The staff screen feeds the calendar and the double-booking check, so the risks
 * are: a departed stylist being deleted and taking the record of who did the work
 * with them (no soft deletes, appointments.staff_member_id is nullOnDelete), a
 * phone number saved in a format that will never send, and cross-tenant access.
 */
class StaffScreenTest extends TestCase
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

        $this->salon = Business::factory()->create(['name' => 'Salon', 'slug' => 'salon']);
        $this->gym = Business::factory()->create(['name' => 'Gym', 'slug' => 'gym']);

        $this->owner = User::factory()->create(['business_id' => $this->salon->id]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    protected function staff(array $attributes = []): StaffMember
    {
        return StaffMember::factory()->forBusiness($this->salon)->create($attributes);
    }

    /** Fill the add-staff form with valid values, overriding what the test cares about. */
    protected function form(array $fields = [])
    {
        $component = Volt::test('staff.index');

        // 07700 900xxx is Ofcom's reserved drama range — a misdirected send
        // from a fixture can never reach a real person.
        $defaults = ['name' => 'Jade Thompson', 'phone' => '07700 900456'];

        foreach (array_merge($defaults, $fields) as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    /* ================================================================
     | Access
     * ================================================================ */

    public function test_a_guest_cannot_reach_the_staff_screen(): void
    {
        $this->get('/staff')->assertRedirect('/login');
    }

    public function test_a_super_admin_is_locked_out_rather_than_shown_every_teams_staff(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/staff')->assertForbidden();
    }

    public function test_an_owner_sees_only_their_own_businesss_staff(): void
    {
        $this->staff(['name' => 'Salon Stylist']);
        StaffMember::factory()->forBusiness($this->gym)->create(['name' => 'GYM-ONLY Trainer']);

        $this->actingAs($this->owner);

        Volt::test('staff.index')
            ->assertSee('Salon Stylist')
            ->assertDontSee('GYM-ONLY Trainer');
    }

    /* ================================================================
     | Creating and editing
     * ================================================================ */

    public function test_creating_a_staff_member_attaches_them_to_the_current_business(): void
    {
        $this->actingAs($this->owner);

        $this->form()->call('save')->assertHasNoErrors();

        $member = StaffMember::firstOrFail();

        // business_id must never come from the form — the trait fills it.
        $this->assertSame($this->salon->id, $member->business_id);
        $this->assertSame('Jade Thompson', $member->name);
        $this->assertTrue($member->active);
    }

    public function test_a_staff_member_needs_a_name(): void
    {
        $this->actingAs($this->owner);

        $this->form(['name' => ''])->call('save')->assertHasErrors(['name' => 'required']);

        $this->assertSame(0, StaffMember::count());
    }

    /** The column stores E.164 — anything else fails to send when we message staff. */
    public function test_a_number_typed_the_normal_way_is_stored_as_e164(): void
    {
        $this->actingAs($this->owner);

        $this->form(['phone' => '07700 900456'])->call('save')->assertHasNoErrors();

        $this->assertSame('+447700900456', StaffMember::firstOrFail()->phone);
    }

    /**
     * The model's phone mutator returns null for anything it cannot parse, so
     * without the shape rule this text would be saved as an empty phone and nobody
     * would know it went missing.
     */
    public function test_text_that_is_not_a_phone_number_is_rejected_not_discarded(): void
    {
        $this->actingAs($this->owner);

        $this->form(['phone' => 'ask reception'])
            ->call('save')
            ->assertHasErrors('phone')
            // Handed back unchanged, so the error points at what was typed.
            ->assertSet('phone', 'ask reception');

        $this->assertSame(0, StaffMember::count());
    }

    public function test_staff_without_a_phone_number_are_fine(): void
    {
        $this->actingAs($this->owner);

        $this->form(['phone' => ''])->call('save')->assertHasNoErrors();

        $this->assertNull(StaffMember::firstOrFail()->phone);
    }

    /** No unique index on staff phone: a shared salon landline is legitimate. */
    public function test_two_staff_can_share_a_phone_number(): void
    {
        $this->actingAs($this->owner);

        $this->form(['name' => 'Jade', 'phone' => '07700 900456'])->call('save')->assertHasNoErrors();
        $this->form(['name' => 'Kelly', 'phone' => '07700 900456'])->call('save')->assertHasNoErrors();

        $this->assertSame(2, StaffMember::count());
    }

    public function test_a_colour_is_chosen_from_the_palette(): void
    {
        $this->actingAs($this->owner);

        $this->form()->set('color', '#0ea5e9')->call('save')->assertHasNoErrors();

        $this->assertSame('#0ea5e9', StaffMember::firstOrFail()->color);
    }

    /**
     * The column is char(7). Anything longer would be truncated by MySQL into a
     * colour nobody picked, so it has to be a validation error instead.
     */
    public function test_a_colour_that_is_not_a_hex_code_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $this->form()->set('color', 'sky blue please')->call('save')->assertHasErrors('color');

        $this->assertSame(0, StaffMember::count());
    }

    public function test_editing_updates_the_existing_staff_member_instead_of_adding_one(): void
    {
        $member = $this->staff(['name' => 'Jade Thompson']);

        $this->actingAs($this->owner);

        Volt::test('staff.index')
            ->call('edit', $member->id)
            ->assertSet('name', 'Jade Thompson')
            ->set('name', 'Jade Thompson-Clarke')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, StaffMember::count());
        $this->assertSame('Jade Thompson-Clarke', $member->fresh()->name);
    }

    /** Saving an unchanged record must not trip the phone rules on its own stored value. */
    public function test_editing_without_touching_the_phone_number_is_allowed(): void
    {
        $member = $this->staff(['phone' => '+447700900456']);

        $this->actingAs($this->owner);

        Volt::test('staff.index')
            ->call('edit', $member->id)
            ->assertSet('phone', '+447700900456')
            ->set('sort_order', '3')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('+447700900456', $member->fresh()->phone);
        $this->assertSame(3, $member->fresh()->sort_order);
    }

    public function test_editing_another_businesss_staff_member_is_impossible(): void
    {
        $theirs = StaffMember::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        // findOrFail is the authorisation check: their id does not exist in our scope.
        $this->expectException(ModelNotFoundException::class);

        Volt::test('staff.index')->call('edit', $theirs->id);
    }

    public function test_deleting_another_businesss_staff_member_is_impossible(): void
    {
        $theirs = StaffMember::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('staff.index')->call('delete', $theirs->id);
    }

    /* ================================================================
     | Someone leaving — the part that protects history
     * ================================================================ */

    public function test_hiding_a_staff_member_takes_them_off_the_list_but_keeps_the_record(): void
    {
        $member = $this->staff(['name' => 'Departing Stylist']);

        $this->actingAs($this->owner);

        $names = Volt::test('staff.index')
            ->call('toggleActive', $member->id)
            ->viewData('staff')
            ->pluck('name')
            ->all();

        $this->assertNotContains('Departing Stylist', $names);
        $this->assertFalse($member->fresh()->active);
    }

    public function test_a_hidden_staff_member_can_be_brought_back(): void
    {
        $member = $this->staff(['name' => 'Returning Stylist', 'active' => false]);

        $this->actingAs($this->owner);

        Volt::test('staff.index')
            ->call('toggleActive', $member->id)
            ->assertSee('Returning Stylist');

        $this->assertTrue($member->fresh()->active);
    }

    public function test_hidden_staff_are_visible_when_asked_for(): void
    {
        $this->staff(['name' => 'Left Last Year', 'active' => false]);

        $this->actingAs($this->owner);

        Volt::test('staff.index')
            ->assertDontSee('Left Last Year')
            ->set('showInactive', true)
            ->assertSee('Left Last Year');
    }

    public function test_a_staff_member_who_never_took_a_booking_is_deleted_outright(): void
    {
        $member = $this->staff(['name' => 'Added By Mistake']);

        $this->actingAs($this->owner);

        Volt::test('staff.index')->call('delete', $member->id);

        $this->assertDatabaseMissing('staff_members', ['id' => $member->id]);
    }

    /**
     * The important one. staff_members has no soft deletes and
     * appointments.staff_member_id is nullOnDelete, so a real delete here erases
     * who actually did the work — and any per-stylist takings figure with it.
     */
    public function test_a_staff_member_with_past_bookings_is_hidden_rather_than_deleted(): void
    {
        $member = $this->staff(['name' => 'Long Serving Stylist']);

        $appointment = Appointment::factory()
            ->forBusiness($this->salon)
            ->create(['staff_member_id' => $member->id]);

        $this->actingAs($this->owner);

        Volt::test('staff.index')->call('delete', $member->id);

        // Still there, just hidden. Checked through the model's boolean cast rather
        // than a raw column comparison, which differs between SQLite and MySQL.
        $this->assertDatabaseHas('staff_members', ['id' => $member->id]);
        $this->assertFalse($member->fresh()->active);

        // And the booking still knows who did it.
        $this->assertSame($member->id, $appointment->fresh()->staff_member_id);
    }

    public function test_a_staff_member_with_only_a_removed_booking_is_still_protected(): void
    {
        $member = $this->staff();

        Appointment::factory()
            ->forBusiness($this->salon)
            ->create(['staff_member_id' => $member->id])
            ->delete();

        $this->actingAs($this->owner);

        Volt::test('staff.index')->call('delete', $member->id);

        $this->assertDatabaseHas('staff_members', ['id' => $member->id]);
        $this->assertFalse($member->fresh()->active);
    }

    /* ================================================================
     | Counts shown on the list
     * ================================================================ */

    public function test_the_upcoming_count_ignores_cancellations(): void
    {
        $member = $this->staff();

        Appointment::factory()->forBusiness($this->salon)->create([
            'staff_member_id' => $member->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(30),
            'status' => Appointment::CONFIRMED,
        ]);

        Appointment::factory()->forBusiness($this->salon)->create([
            'staff_member_id' => $member->id,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'status' => Appointment::CANCELLED,
        ]);

        $this->actingAs($this->owner);

        $listed = Volt::test('staff.index')
            ->viewData('staff')
            ->firstWhere('id', $member->id);

        $this->assertSame(1, (int) $listed->upcoming_count);
    }

    public function test_the_upcoming_count_ignores_past_bookings(): void
    {
        $member = $this->staff();

        Appointment::factory()->forBusiness($this->salon)->create([
            'staff_member_id' => $member->id,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addMinutes(30),
            'status' => Appointment::COMPLETED,
        ]);

        $this->actingAs($this->owner);

        $listed = Volt::test('staff.index')
            ->viewData('staff')
            ->firstWhere('id', $member->id);

        $this->assertSame(0, (int) $listed->upcoming_count);
        $this->assertSame(1, (int) $listed->appointments_count);
    }

    /** A count that leaked across tenants would make the delete guard fire at random. */
    public function test_the_booking_counts_ignore_other_businesses(): void
    {
        $member = $this->staff();

        Appointment::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $listed = Volt::test('staff.index')
            ->viewData('staff')
            ->firstWhere('id', $member->id);

        $this->assertSame(0, (int) $listed->appointments_count);
    }

    /* ================================================================
     | Ordering
     * ================================================================ */

    public function test_staff_appear_in_the_owners_chosen_order(): void
    {
        $this->staff(['name' => 'Zoe', 'sort_order' => 1]);
        $this->staff(['name' => 'Amber', 'sort_order' => 2]);
        $this->staff(['name' => 'Molly', 'sort_order' => 0]);

        $this->actingAs($this->owner);

        $names = Volt::test('staff.index')
            ->viewData('staff')
            ->pluck('name')
            ->all();

        $this->assertSame(['Molly', 'Zoe', 'Amber'], $names);
    }
}
