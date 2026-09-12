<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SiteSettingsAndSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_save_site_settings(): void
    {
        $admin = User::factory()->create([
            'role' => 'superadmin',
            'business_id' => null,
        ]);

        $this->actingAs($admin);

        Volt::test('dashboard')
            ->set('platformTab', 'settings')
            ->set('siteName', 'Apex Studio Platform')
            ->set('siteTagline', 'Next-Gen Booking Engine')
            ->set('supportEmail', 'contact@apexplatform.com')
            ->set('currencySymbol', '$')
            ->call('saveSiteSettings')
            ->assertDispatched('toast');

        $this->assertEquals('Apex Studio Platform', SiteSettings::get('general', 'site_name'));
        $this->assertEquals('Next-Gen Booking Engine', SiteSettings::get('general', 'site_tagline'));
        $this->assertEquals('contact@apexplatform.com', SiteSettings::get('general', 'support_email'));
        $this->assertEquals('$', SiteSettings::get('billing', 'currency_symbol'));
    }

    public function test_superadmin_can_save_seo_settings_and_public_page_reflects_them(): void
    {
        $admin = User::factory()->create([
            'role' => 'superadmin',
            'business_id' => null,
        ]);

        $this->actingAs($admin);

        Volt::test('dashboard')
            ->set('platformTab', 'seo')
            ->set('seoPage', 'home')
            ->set('seoTitle', 'Revolutionary Salon Appointment Diary')
            ->set('seoDescription', 'Experience cutting-edge booking with instant SMS & Telegram reminders.')
            ->set('seoKeywords', 'salon booking, liquid glass, appointments')
            ->call('saveSeoSettings')
            ->assertDispatched('toast');

        // Check helper directly
        $seo = SiteSettings::seoForPage('home');
        $this->assertEquals('Revolutionary Salon Appointment Diary', $seo['raw_title']);
        $this->assertStringContainsString('Revolutionary Salon Appointment Diary', $seo['title']);
        $this->assertEquals('Experience cutting-edge booking with instant SMS & Telegram reminders.', $seo['description']);

        // Check public welcome page renders the new meta
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Revolutionary Salon Appointment Diary', false);
        $response->assertSee(e('Experience cutting-edge booking with instant SMS & Telegram reminders.'), false);
    }

    public function test_regular_owner_cannot_save_site_settings_or_seo(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create([
            'role' => 'owner',
            'business_id' => $business->id,
        ]);

        $this->actingAs($owner);

        Volt::test('dashboard')
            ->set('siteName', 'Hacked Name')
            ->call('saveSiteSettings')
            ->assertForbidden();

        Volt::test('dashboard')
            ->set('seoTitle', 'Hacked Title')
            ->call('saveSeoSettings')
            ->assertForbidden();
    }
}
