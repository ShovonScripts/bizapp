<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();
        Carbon::setTestNow('2026-09-15 10:00:00'); // Tuesday 10:00 BST/UTC
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Tenant::forget();
        parent::tearDown();
    }

    public function test_public_booking_portal_can_be_rendered_with_valid_business_slug(): void
    {
        $business = Business::factory()->create([
            'name' => 'Glow Hair Studio',
            'slug' => 'glow-hair-studio',
        ]);

        Service::factory()->for($business)->create([
            'name' => 'Full Cut & Style',
            'price' => 45.00,
            'duration_minutes' => 45,
            'active' => true,
        ]);

        $response = $this->get(route('booking.public', 'glow-hair-studio'));

        $response->assertOk()
            ->assertSee('Glow Hair Studio')
            ->assertSee('Full Cut & Style')
            ->assertSee('45.00');
    }

    public function test_invalid_business_slug_returns_404(): void
    {
        $response = $this->get(route('booking.public', 'non-existent-salon'));

        $response->assertNotFound();
    }

    public function test_inactive_services_are_excluded_from_public_booking(): void
    {
        $business = Business::factory()->create(['slug' => 'test-salon']);

        Service::factory()->for($business)->create([
            'name' => 'Active Service',
            'active' => true,
        ]);

        Service::factory()->for($business)->create([
            'name' => 'Deprecated Service',
            'active' => false,
        ]);

        $response = $this->get(route('booking.public', 'test-salon'));

        $response->assertOk()
            ->assertSee('Active Service')
            ->assertDontSee('Deprecated Service');
    }

    public function test_customer_can_step_through_booking_wizard_and_complete_an_appointment(): void
    {
        config(['messaging.telegram.bot_username' => 'auto_jis_bot']);

        $business = Business::factory()->create([
            'name' => 'Luxe Nails & Hair',
            'slug' => 'luxe-salon',
            'timezone' => 'Europe/London',
            'phone' => '07700900123',
        ]);

        $service = Service::factory()->for($business)->create([
            'name' => 'Gel Polish Manicure',
            'price' => 35.00,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $staff = StaffMember::factory()->for($business)->create([
            'name' => 'Emma Watson',
            'active' => true,
        ]);

        $tomorrow = Carbon::now()->addDay()->toDateString();

        Volt::test('booking.public', ['slug' => 'luxe-salon'])
            ->assertSee('Gel Polish Manicure')
            // Step 1: select service
            ->call('selectService', $service->id)
            ->assertSet('step', 2)
            ->assertSee('Emma Watson')
            // Step 2: select staff
            ->call('selectStaff', $staff->id)
            ->assertSet('step', 3)
            // Step 3: pick date & time
            ->call('selectDate', $tomorrow)
            ->call('selectTime', '14:00')
            ->assertSet('step', 4)
            // Step 4: fill contact details
            ->set('customer_name', 'Sophie Ellis')
            ->set('customer_phone', '07700 900555')
            ->set('customer_email', 'sophie@example.com')
            ->set('notes', 'Glitter accent on ring fingers please')
            ->call('submitBooking')
            // Step 5: confirmed
            ->assertSet('step', 5)
            ->assertSee('All Set, Sophie')
            ->assertSee('Gel Polish Manicure')
            ->assertSee('https://t.me/auto_jis_bot?start=');

        // Verify DB
        Tenant::for($business->id, function () use ($service, $staff) {
            $customer = Customer::where('phone', '+447700900555')->first();
            $this->assertNotNull($customer);
            $this->assertEquals('Sophie Ellis', $customer->name);
            $this->assertNotEmpty($customer->telegram_link_token);

            $appointment = Appointment::where('customer_id', $customer->id)->first();
            $this->assertNotNull($appointment);
            $this->assertEquals($service->id, $appointment->service_id);
            $this->assertEquals($staff->id, $appointment->staff_member_id);
            $this->assertEquals('online', $appointment->source);
            $this->assertEquals(Appointment::CONFIRMED, $appointment->status);
            $this->assertEquals(35.00, $appointment->price);
        });
    }

    public function test_existing_customer_with_same_phone_number_is_reused_instead_of_duplicated(): void
    {
        $business = Business::factory()->create(['slug' => 'oasis-spa']);

        Customer::factory()->for($business)->create([
            'name' => 'Clara Oswald',
            'phone' => '+447700900888',
        ]);

        $service = Service::factory()->for($business)->create([
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $staff = StaffMember::factory()->for($business)->create(['active' => true]);
        $tomorrow = Carbon::now()->addDay()->toDateString();

        Volt::test('booking.public', ['slug' => 'oasis-spa'])
            ->call('selectService', $service->id)
            ->call('selectStaff', $staff->id)
            ->call('selectDate', $tomorrow)
            ->call('selectTime', '11:00')
            ->set('customer_name', 'Clara Oswald')
            ->set('customer_phone', '07700 900888')
            ->call('submitBooking')
            ->assertSet('step', 5);

        Tenant::for($business->id, function () {
            $this->assertEquals(1, Customer::where('phone', '+447700900888')->count());
        });
    }

    public function test_double_booking_conflict_blocks_unavailable_time_slot(): void
    {
        $business = Business::factory()->create(['slug' => 'barber-shop', 'timezone' => 'Europe/London']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 30, 'active' => true]);
        $staff = StaffMember::factory()->for($business)->create(['active' => true]);

        $tomorrow = Carbon::now()->addDay()->toDateString();
        $slotStart = Carbon::parse($tomorrow.' 14:00:00', 'Europe/London')->utc();
        $slotEnd = $slotStart->clone()->addMinutes(30);

        // Existing appointment from 14:00 to 14:30
        Appointment::factory()->for($business)->create([
            'staff_member_id' => $staff->id,
            'starts_at' => $slotStart,
            'ends_at' => $slotEnd,
            'status' => Appointment::CONFIRMED,
        ]);

        $component = Volt::test('booking.public', ['slug' => 'barber-shop'])
            ->call('selectService', $service->id)
            ->call('selectStaff', $staff->id)
            ->call('selectDate', $tomorrow);

        $slots = $component->get('availableSlots');
        $times = array_column($slots, 'time');

        $this->assertNotContains('14:00', $times);
        $this->assertContains('14:30', $times);
    }

    public function test_tenant_isolation_prevents_seeing_or_booking_another_business_services(): void
    {
        $businessA = Business::factory()->create(['name' => 'Salon Alpha', 'slug' => 'salon-alpha']);
        $businessB = Business::factory()->create(['name' => 'Spa Beta', 'slug' => 'spa-beta']);

        $serviceA = Service::factory()->for($businessA)->create(['name' => 'Alpha Cut', 'active' => true]);
        $serviceB = Service::factory()->for($businessB)->create(['name' => 'Beta Massage', 'active' => true]);

        // Salon Alpha's page must only see Alpha Cut, never Beta Massage
        $response = $this->get(route('booking.public', 'salon-alpha'));
        $response->assertOk()
            ->assertSee('Alpha Cut')
            ->assertDontSee('Beta Massage');

        // Submitting with service from Business B on Business A's portal should fail
        $tomorrow = Carbon::now()->addDay()->toDateString();
        $this->expectException(ModelNotFoundException::class);

        Volt::test('booking.public', ['slug' => 'salon-alpha'])
            ->set('selectedServiceId', $serviceB->id)
            ->set('selectedDate', $tomorrow)
            ->set('selectedTime', '10:00')
            ->set('customer_name', 'Hacker John')
            ->set('customer_phone', '07700 900999')
            ->call('submitBooking');
    }

    public function test_public_booking_logs_marketing_consent_when_customer_opt_in(): void
    {
        $business = Business::factory()->create([
            'name' => 'Consent Salon',
            'slug' => 'consent-salon',
            'timezone' => 'Europe/London',
        ]);

        $service = Service::factory()->for($business)->create([
            'name' => 'Test Service',
            'price' => 30.00,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $staff = StaffMember::factory()->for($business)->create(['active' => true]);
        $tomorrow = Carbon::now()->addDay()->toDateString();

        Volt::test('booking.public', ['slug' => 'consent-salon'])
            ->call('selectService', $service->id)
            ->call('selectStaff', $staff->id)
            ->call('selectDate', $tomorrow)
            ->call('selectTime', '10:00')
            ->set('customer_name', 'Consent Tester')
            ->set('customer_phone', '07700 900999')
            ->set('marketing_consent', true)
            ->call('submitBooking')
            ->assertSet('step', 5);

        Tenant::for($business->id, function () {
            $customer = Customer::where('phone', '+447700900999')->first();
            $this->assertNotNull($customer);
            $this->assertTrue($customer->marketing_consent);
            $this->assertNotNull($customer->consent_at);
            $this->assertEquals('booking_form', $customer->consent_source);
        });
    }

    public function test_public_booking_does_not_record_consent_when_box_is_left_unticked(): void
    {
        $business = Business::factory()->create([
            'name' => 'No Consent Salon',
            'slug' => 'no-consent-salon',
            'timezone' => 'Europe/London',
        ]);

        $service = Service::factory()->for($business)->create([
            'name' => 'Test Service',
            'price' => 30.00,
            'duration_minutes' => 30,
            'active' => true,
        ]);

        $staff = StaffMember::factory()->for($business)->create(['active' => true]);
        $tomorrow = Carbon::now()->addDay()->toDateString();

        // marketing_consent is deliberately never set - this asserts the default.
        Volt::test('booking.public', ['slug' => 'no-consent-salon'])
            ->call('selectService', $service->id)
            ->call('selectStaff', $staff->id)
            ->call('selectDate', $tomorrow)
            ->call('selectTime', '10:00')
            ->set('customer_name', 'Silent Tester')
            ->set('customer_phone', '07700 900998')
            ->call('submitBooking')
            ->assertSet('step', 5);

        Tenant::for($business->id, function () {
            $customer = Customer::where('phone', '+447700900998')->first();
            $this->assertNotNull($customer, 'Booking must still succeed without marketing consent.');
            $this->assertFalse((bool) $customer->marketing_consent);
            $this->assertNull($customer->consent_at);
            $this->assertNull($customer->consent_source);
        });
    }
}
