<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/London'));

        $this->business = Business::factory()->create([
            'name' => 'Prestige Hair & Spa',
            'slug' => 'prestige-salon',
            'timezone' => 'Europe/London',
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_switch_to_analytics_tab(): void
    {
        Service::factory()->for($this->business)->create(['name' => 'Haircut']);
        Customer::factory()->for($this->business)->create();

        $this->actingAs($this->owner);

        Volt::test('dashboard')
            ->assertSee('Performance Analytics')
            ->set('tab', 'analytics')
            ->assertSee('Weekly Revenue Velocity')
            ->assertSee('Top Performing Services')
            ->assertSee('Staff Earnings');
    }

    public function test_analytics_kpis_and_weekly_revenue_are_calculated_correctly(): void
    {
        $this->actingAs($this->owner);

        $customer1 = Customer::factory()->for($this->business)->create();
        $customer2 = Customer::factory()->for($this->business)->create();

        $serviceA = Service::factory()->for($this->business)->create([
            'name' => 'Luxury Cut',
            'price' => 50.00,
        ]);

        $serviceB = Service::factory()->for($this->business)->create([
            'name' => 'Hair Coloring',
            'price' => 100.00,
        ]);

        $staff = StaffMember::factory()->for($this->business)->create([
            'name' => 'David Beckham',
            'color' => '#3b82f6',
        ]);

        $today = Carbon::parse('2026-07-15 10:00:00', 'Europe/London')->utc();
        $yesterday = Carbon::parse('2026-07-14 14:00:00', 'Europe/London')->utc();

        // 1. Completed appointment today: £50
        Appointment::factory()->for($this->business)->create([
            'customer_id' => $customer1->id,
            'service_id' => $serviceA->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $today,
            'ends_at' => $today->copy()->addHour(),
            'status' => Appointment::COMPLETED,
            'price' => 50.00,
            'source' => 'online',
        ]);

        // 2. Completed appointment yesterday: £100
        Appointment::factory()->for($this->business)->create([
            'customer_id' => $customer1->id, // repeat customer!
            'service_id' => $serviceB->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $yesterday,
            'ends_at' => $yesterday->copy()->addHour(),
            'status' => Appointment::COMPLETED,
            'price' => 100.00,
            'source' => 'manual',
        ]);

        // 3. Cancelled appointment today (should NOT count towards revenue)
        Appointment::factory()->for($this->business)->create([
            'customer_id' => $customer2->id,
            'service_id' => $serviceA->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $today->copy()->addHours(2),
            'ends_at' => $today->copy()->addHours(3),
            'status' => Appointment::CANCELLED,
            'price' => 50.00,
        ]);

        Volt::test('dashboard')
            ->set('tab', 'analytics')
            ->assertSee('£150.00') // Total completed revenue (£50 + £100)
            ->assertSee('£75.00')  // Average ticket size (£150 / 2 visits)
            ->assertSee('Luxury Cut')
            ->assertSee('Hair Coloring')
            ->assertSee('David Beckham');
    }

    public function test_tenant_isolation_prevents_seeing_another_business_revenue(): void
    {
        $otherBusiness = Business::factory()->create(['name' => 'Other Salon']);
        $otherService = Service::factory()->for($otherBusiness)->create(['price' => 999.00]);

        // Completed £999 booking in another business
        Appointment::factory()->for($otherBusiness)->create([
            'service_id' => $otherService->id,
            'status' => Appointment::COMPLETED,
            'price' => 999.00,
        ]);

        $this->actingAs($this->owner);

        Volt::test('dashboard')
            ->set('tab', 'analytics')
            ->assertDontSee('£999.00');
    }
}
