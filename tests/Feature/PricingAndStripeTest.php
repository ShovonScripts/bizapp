<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PricingAndStripeTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_save_pricing_and_stripe_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => 'superadmin',
            'business_id' => null,
        ]);

        $this->actingAs($admin);

        Volt::test('dashboard')
            ->set('platformTab', 'pricing')
            ->set('planStarterPrice', '25')
            ->set('planGrowthPrice', '49')
            ->set('planBusinessPrice', '89')
            ->set('planBillingPeriod', 'month')
            ->set('planAnnualDiscount', '25')
            ->set('stripePublishableKey', 'pk_test_mock_superadmin_pub')
            ->set('stripeTestMode', true)
            ->set('stripeCurrency', 'gbp')
            ->call('savePricingAndStripe')
            ->assertDispatched('toast');

        $this->assertEquals('25', SiteSettings::get('plan_starter_price'));
        $this->assertEquals('49', SiteSettings::get('plan_growth_price'));
        $this->assertEquals('89', SiteSettings::get('plan_business_price'));
        $this->assertEquals('pk_test_mock_superadmin_pub', SiteSettings::get('stripe_publishable_key'));
        $this->assertTrue(SiteSettings::get('stripe_test_mode'));
    }

    public function test_welcome_page_renders_pricing_section_and_stripe_box(): void
    {
        SiteSettings::set('plan_growth_price', '45');
        SiteSettings::clearCache();

        $response = $this->get('/');
        $response->assertStatus(200);

        // Pricing section anchor and header
        $response->assertSee('id="pricing"', false);
        $response->assertSee('Simple, transparent pricing for growing teams', false);
        $response->assertSee('£45', false);

        // Stripe Credential & Trust Box
        $response->assertSee('Secured with Stripe Payment Infrastructure', false);
        $response->assertSee('PCI-DSS Level 1', false);
        $response->assertSee('Zero card numbers touch our server', false);
    }

    public function test_pricing_page_renders_dynamic_plans_and_stripe_box(): void
    {
        SiteSettings::set('plan_starter_price', '29');
        SiteSettings::clearCache();

        $response = $this->get('/pricing');
        $response->assertStatus(200);

        $response->assertSee('£29', false);
        $response->assertSee('Secured with Stripe Payment Infrastructure', false);
        $response->assertSee('PCI-DSS Level 1', false);
    }

    public function test_regular_owner_cannot_save_pricing_and_stripe(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create([
            'role' => 'owner',
            'business_id' => $business->id,
        ]);

        $this->actingAs($owner);

        Volt::test('dashboard')
            ->set('planStarterPrice', '1')
            ->call('savePricingAndStripe')
            ->assertForbidden();
    }
}
