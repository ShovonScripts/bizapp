<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    /**
     * business_name was added to the register component (see the SECURITY note
     * there — stock Breeze would create super-admins), so it must be set here or
     * validation fails and this test breaks for the wrong reason.
     */
    public function test_new_users_can_register(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('business_name', 'Test Salon')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_business_name_is_required(): void
    {
        Volt::test('pages.auth.register')
            ->set('business_name', '')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['business_name' => 'required']);

        $this->assertGuest();
    }
}
