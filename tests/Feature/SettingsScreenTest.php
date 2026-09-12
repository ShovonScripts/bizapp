<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();

        $this->business = Business::factory()->create([
            'name' => 'Royal Hair Studio',
            'slug' => 'royal-hair',
            'phone' => '+447700900111',
            'niche' => 'Hair Salon',
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('settings.index'));

        $response->assertRedirect('/login');
    }

    public function test_super_admin_is_forbidden(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get(route('settings.index'));

        $response->assertForbidden();
    }

    public function test_business_owner_can_render_settings_screen(): void
    {
        $response = $this->actingAs($this->owner)->get(route('settings.index'));

        $response->assertOk()
            ->assertSee('Business Settings')
            ->assertSee('Royal Hair Studio')
            ->assertSee('royal-hair');
    }

    public function test_business_owner_can_update_profile_and_hours(): void
    {
        $this->actingAs($this->owner);

        Volt::test('settings.index')
            ->set('name', 'Royal Crown Barbers')
            ->set('slug', 'royal-crown-barbers')
            ->set('niche', 'Barbershop')
            ->set('phone', '07700 900222')
            ->set('opening_hours_from', '08:30')
            ->set('opening_hours_to', '19:30')
            ->set('slot_interval_minutes', 15)
            ->set('quiet_hours_from', '22:00')
            ->set('quiet_hours_to', '07:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->business->refresh();

        $this->assertEquals('Royal Crown Barbers', $this->business->name);
        $this->assertEquals('royal-crown-barbers', $this->business->slug);
        $this->assertEquals('Barbershop', $this->business->niche);
        $this->assertEquals('+447700900222', $this->business->phone);

        // Check settings JSON bag
        $this->assertEquals('08:30', $this->business->setting('opening_hours.from'));
        $this->assertEquals('19:30', $this->business->setting('opening_hours.to'));
        $this->assertEquals(15, $this->business->setting('slot_interval_minutes'));
        $this->assertEquals('22:00', $this->business->quietHours()['from']);
        $this->assertEquals('07:30', $this->business->quietHours()['to']);
    }

    public function test_cannot_take_another_business_slug(): void
    {
        Business::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($this->owner);

        Volt::test('settings.index')
            ->set('slug', 'taken-slug')
            ->call('save')
            ->assertHasErrors(['slug']);
    }

    public function test_owner_can_keep_their_own_slug_when_saving(): void
    {
        $this->actingAs($this->owner);

        Volt::test('settings.index')
            ->set('name', 'Royal Hair Studio Updated')
            ->set('slug', 'royal-hair')
            ->call('save')
            ->assertHasNoErrors(['slug']);

        $this->business->refresh();
        $this->assertEquals('royal-hair', $this->business->slug);
    }
}
