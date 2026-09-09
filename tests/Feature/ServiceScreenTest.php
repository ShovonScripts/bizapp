<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The services screen decides what the price list says and how long each booking
 * blocks the calendar, so the risks here are: a deleted service silently erasing
 * what was sold in past bookings (services have no soft deletes and
 * appointments.service_id is nullOnDelete), a duration of 0 producing appointments
 * with no end, and one business reading or editing another's price list.
 */
class ServiceScreenTest extends TestCase
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

    protected function service(array $attributes = []): Service
    {
        return Service::factory()->forBusiness($this->salon)->create($attributes);
    }

    /** Fill the add-service form with valid values, overriding what the test cares about. */
    protected function form(array $fields = [])
    {
        $component = Volt::test('services.index');

        $defaults = [
            'name' => 'Cut & Blow Dry',
            'duration_minutes' => '45',
            'price' => '38.00',
        ];

        foreach (array_merge($defaults, $fields) as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    /* ================================================================
     | Access
     * ================================================================ */

    public function test_a_guest_cannot_reach_the_services_screen(): void
    {
        $this->get('/services')->assertRedirect('/login');
    }

    public function test_a_super_admin_is_locked_out_rather_than_shown_every_price_list(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/services')->assertForbidden();
    }

    public function test_an_owner_sees_only_their_own_businesss_services(): void
    {
        $this->service(['name' => 'Salon Colour']);
        Service::factory()->forBusiness($this->gym)->create(['name' => 'GYM-ONLY Session']);

        $this->actingAs($this->owner);

        Volt::test('services.index')
            ->assertSee('Salon Colour')
            ->assertDontSee('GYM-ONLY Session');
    }

    /* ================================================================
     | Creating and editing
     * ================================================================ */

    public function test_creating_a_service_attaches_it_to_the_current_business(): void
    {
        $this->actingAs($this->owner);

        $this->form()->call('save')->assertHasNoErrors();

        $service = Service::firstOrFail();

        // business_id must never come from the form — the trait fills it.
        $this->assertSame($this->salon->id, $service->business_id);
        $this->assertSame('Cut & Blow Dry', $service->name);
        $this->assertSame(45, $service->duration_minutes);
        $this->assertSame('38.00', $service->price);
        $this->assertTrue($service->active);
    }

    public function test_a_service_needs_a_name(): void
    {
        $this->actingAs($this->owner);

        $this->form(['name' => ''])->call('save')->assertHasErrors(['name' => 'required']);

        $this->assertSame(0, Service::count());
    }

    /**
     * A zero-length service would make ends_at equal starts_at, so the booking
     * blocks nothing and the calendar shows an invisible appointment.
     */
    public function test_a_service_cannot_last_zero_minutes(): void
    {
        $this->actingAs($this->owner);

        $this->form(['duration_minutes' => '0'])->call('save')->assertHasErrors('duration_minutes');

        $this->assertSame(0, Service::count());
    }

    public function test_a_duration_that_is_not_a_number_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $this->form(['duration_minutes' => 'about an hour'])->call('save')->assertHasErrors('duration_minutes');
    }

    /**
     * The numeric inputs are string properties on purpose: a typed `public int`
     * throws a TypeError the moment the field is cleared, which is a 500 page
     * instead of a validation message.
     */
    public function test_clearing_the_price_gives_a_form_error_not_a_type_error(): void
    {
        $this->actingAs($this->owner);

        $this->form(['price' => ''])->call('save')->assertHasErrors('price');
    }

    public function test_a_free_service_is_allowed(): void
    {
        $this->actingAs($this->owner);

        // Consultations and patch tests are genuinely £0.
        $this->form(['name' => 'Patch Test', 'price' => '0'])->call('save')->assertHasNoErrors();

        $this->assertSame('0.00', Service::firstOrFail()->price);
    }

    public function test_editing_updates_the_existing_service_instead_of_adding_one(): void
    {
        $service = $this->service(['name' => 'Gents Cut', 'price' => 18.00]);

        $this->actingAs($this->owner);

        Volt::test('services.index')
            ->call('edit', $service->id)
            ->assertSet('name', 'Gents Cut')
            ->set('price', '20.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Service::count());
        $this->assertSame('20.00', $service->fresh()->price);
    }

    public function test_editing_another_businesss_service_is_impossible(): void
    {
        $theirs = Service::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        // findOrFail is the authorisation check: their id does not exist in our scope.
        $this->expectException(ModelNotFoundException::class);

        Volt::test('services.index')->call('edit', $theirs->id);
    }

    public function test_deleting_another_businesss_service_is_impossible(): void
    {
        $theirs = Service::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('services.index')->call('delete', $theirs->id);
    }

    /* ================================================================
     | Retiring vs deleting — the part that protects history
     * ================================================================ */

    public function test_hiding_a_service_keeps_it_but_takes_it_off_the_list(): void
    {
        $service = $this->service(['name' => 'Perm']);

        $this->actingAs($this->owner);

        Volt::test('services.index')
            ->call('toggleActive', $service->id)
            ->assertDontSee('Perm');

        $this->assertFalse($service->fresh()->active);
    }

    public function test_a_hidden_service_can_be_brought_back(): void
    {
        $service = $this->service(['name' => 'Perm', 'active' => false]);

        $this->actingAs($this->owner);

        Volt::test('services.index')
            ->call('toggleActive', $service->id)
            ->assertSee('Perm');

        $this->assertTrue($service->fresh()->active);
    }

    public function test_hidden_services_are_visible_when_asked_for(): void
    {
        $this->service(['name' => 'Retired Treatment', 'active' => false]);

        $this->actingAs($this->owner);

        Volt::test('services.index')
            ->assertDontSee('Retired Treatment')
            ->set('showInactive', true)
            ->assertSee('Retired Treatment');
    }

    public function test_a_service_nobody_ever_booked_is_deleted_outright(): void
    {
        $service = $this->service(['name' => 'Typo Service']);

        $this->actingAs($this->owner);

        Volt::test('services.index')->call('delete', $service->id);

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    /**
     * The important one. services has no soft deletes and appointments.service_id
     * is nullOnDelete, so a real delete here blanks out what was actually sold —
     * last quarter's figures would quietly change. Deactivating keeps the record.
     */
    public function test_a_service_with_past_bookings_is_hidden_rather_than_deleted(): void
    {
        $service = $this->service(['name' => 'Full Colour']);

        $appointment = Appointment::factory()
            ->forBusiness($this->salon)
            ->create(['service_id' => $service->id]);

        $this->actingAs($this->owner);

        Volt::test('services.index')->call('delete', $service->id);

        // Still there, just hidden. Checked through the model's boolean cast rather
        // than a raw column comparison, which differs between SQLite and MySQL.
        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertFalse($service->fresh()->active);

        // And the booking still knows what was sold.
        $this->assertSame($service->id, $appointment->fresh()->service_id);
    }

    /**
     * A cancelled-then-removed booking is still history attached to this service,
     * so the withTrashed() check has to see it.
     */
    public function test_a_service_used_only_by_a_deleted_booking_is_still_protected(): void
    {
        $service = $this->service(['name' => 'Highlights']);

        $appointment = Appointment::factory()
            ->forBusiness($this->salon)
            ->create(['service_id' => $service->id]);

        $appointment->delete();

        $this->actingAs($this->owner);

        Volt::test('services.index')->call('delete', $service->id);

        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertFalse($service->fresh()->active);
    }

    /**
     * The Delete button is only rendered when the count is zero, so the count has
     * to include removed bookings too — otherwise the UI offers a destructive
     * action the server will refuse to perform.
     */
    public function test_the_booked_count_includes_removed_bookings(): void
    {
        $service = $this->service();

        Appointment::factory()
            ->forBusiness($this->salon)
            ->create(['service_id' => $service->id])
            ->delete();

        $this->actingAs($this->owner);

        $listed = Volt::test('services.index')->viewData('services')->firstOrFail();

        $this->assertSame(1, (int) $listed->appointments_count);
    }

    /** A count that leaked across tenants would make the delete guard fire at random. */
    public function test_the_booked_count_ignores_other_businesses(): void
    {
        $service = $this->service();

        Appointment::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $listed = Volt::test('services.index')->viewData('services')->firstOrFail();

        $this->assertSame(0, (int) $listed->appointments_count);
    }

    /* ================================================================
     | Ordering
     * ================================================================ */

    public function test_services_appear_in_the_owners_chosen_order(): void
    {
        $this->service(['name' => 'Zzz Last', 'sort_order' => 1]);
        $this->service(['name' => 'Aaa Second', 'sort_order' => 2]);
        $this->service(['name' => 'Mmm First', 'sort_order' => 0]);

        $this->actingAs($this->owner);

        $names = Volt::test('services.index')
            ->viewData('services')
            ->pluck('name')
            ->all();

        $this->assertSame(['Mmm First', 'Zzz Last', 'Aaa Second'], $names);
    }
}
