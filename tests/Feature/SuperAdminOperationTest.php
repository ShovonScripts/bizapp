<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuperAdminOperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }

    public function test_super_admin_can_enter_operating_mode_and_access_tenant_screens(): void
    {
        $salon = Business::factory()->create(['name' => 'Elite Salon', 'slug' => 'elite-salon']);
        $admin = User::factory()->superAdmin()->create();

        // 1. Without operating mode, tenant screens abort 403
        $this->actingAs($admin)->get(route('appointments.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('customers.index'))->assertForbidden();

        // 2. Superadmin enters operating mode via dashboard component action
        Volt::actingAs($admin)
            ->test('dashboard')
            ->call('operate', $salon->id)
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Tenant::isOperating());
        $this->assertSame($salon->id, Tenant::id());

        // 3. With operating mode active in session, superadmin can access all tenant screens
        $this->actingAs($admin)->get(route('appointments.index'))->assertOk();
        $this->actingAs($admin)->get(route('customers.index'))->assertOk();
        $this->actingAs($admin)->get(route('services.index'))->assertOk();
        $this->actingAs($admin)->get(route('staff.index'))->assertOk();
        $this->actingAs($admin)->get(route('settings.index'))->assertOk();

        // 4. Superadmin exits operating mode and returns to platform view
        Volt::actingAs($admin)
            ->test('dashboard')
            ->call('exitOperate')
            ->assertRedirect(route('dashboard'));

        $this->assertFalse(Tenant::isOperating());
        $this->assertNull(Tenant::id());
    }

    public function test_super_admin_can_provision_new_business_from_dashboard(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Volt::actingAs($admin)
            ->test('dashboard')
            ->set('newBusinessName', 'Royal Barber')
            ->set('newBusinessNiche', 'Barbershop')
            ->set('newBusinessTimezone', 'Europe/London')
            ->set('newOwnerName', 'Arthur Royal')
            ->set('newOwnerEmail', 'arthur@royalbarber.test')
            ->call('createBusiness')
            ->assertDispatched('toast');

        $this->assertDatabaseHas('businesses', [
            'name' => 'Royal Barber',
            'slug' => 'royal-barber',
            'niche' => 'Barbershop',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Arthur Royal',
            'email' => 'arthur@royalbarber.test',
            'role' => 'owner',
        ]);
    }
}
