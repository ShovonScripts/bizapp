<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\Payment\StripePaymentService;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StripeDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;
    private Service $service;
    private StaffMember $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();

        $this->business = Business::factory()->create([
            'name' => 'Luxe Glow Hair & Spa',
            'slug' => 'luxe-glow-deposit',
            'timezone' => 'Europe/London',
            'phone' => '07700900123',
            'currency' => 'GBP',
            'settings' => [
                'deposit' => [
                    'enabled' => true,
                    'type' => 'percentage',
                    'value' => 20.00, // 20%
                ],
                'stripe' => [
                    'test_mode' => true,
                    'secret_key' => 'sk_test_fake_key_for_http_fake',
                ],
            ],
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->service = Service::factory()->for($this->business)->create([
            'name' => 'Balayage & Treatment',
            'duration_minutes' => 90,
            'price' => 120.00,
            'active' => true,
        ]);

        $this->staff = StaffMember::factory()->for($this->business)->create([
            'name' => 'Sophia Vane',
            'active' => true,
            'color' => '#6366f1',
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }

    public function test_business_calculates_deposit_correctly(): void
    {
        // Percentage test: 20% of £120 = £24.00
        $this->assertTrue($this->business->depositEnabled());
        $this->assertEquals(24.00, $this->business->calculateDepositFor(120.00));

        // Fixed amount test: £15 flat
        $this->business->settings = array_merge($this->business->settings, [
            'deposit' => [
                'enabled' => true,
                'type' => 'fixed',
                'value' => 15.00,
            ],
        ]);
        $this->business->save();

        $this->assertEquals(15.00, $this->business->calculateDepositFor(120.00));

        // Full upfront test: 100% of £120 = £120.00
        $this->business->settings = array_merge($this->business->settings, [
            'deposit' => [
                'enabled' => true,
                'type' => 'full',
                'value' => 100.00,
            ],
        ]);
        $this->business->save();

        $this->assertEquals(120.00, $this->business->calculateDepositFor(120.00));

        // Disabled test
        $this->business->settings = array_merge($this->business->settings, [
            'deposit' => [
                'enabled' => false,
            ],
        ]);
        $this->business->save();

        $this->assertFalse($this->business->depositEnabled());
        $this->assertEquals(0.00, $this->business->calculateDepositFor(120.00));
    }

    public function test_public_booking_creates_pending_appointment_with_deposit_due(): void
    {
        $tomorrow = Carbon::parse('tomorrow 11:00:00', 'Europe/London');

        $component = Volt::test('booking.public', ['slug' => $this->business->slug])
            ->call('selectService', $this->service->id)
            ->call('selectStaff', $this->staff->id)
            ->call('selectDate', $tomorrow->toDateString())
            ->call('selectTime', '11:00')
            ->set('customer_name', 'Emma Watson')
            ->set('customer_phone', '07700900456')
            ->set('customer_email', 'emma@example.com')
            ->call('submitBooking');

        // Verify appointment in DB
        $appointment = Appointment::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->first();

        $this->assertNotNull($appointment);
        $this->assertTrue($appointment->deposit_required);
        $this->assertEquals(24.00, $appointment->deposit_amount);
        $this->assertEquals('unpaid', $appointment->deposit_status);
        $this->assertEquals(0.00, $appointment->paid_amount);
        $this->assertEquals(Appointment::PENDING, $appointment->status);
        $this->assertEquals(120.00, $appointment->balanceDue());
        $this->assertFalse($appointment->isFullyPaid());

        // Simulate deposit payment
        $appointment->update([
            'deposit_status' => 'paid',
            'paid_amount' => 24.00,
        ]);
        $this->assertEquals(96.00, $appointment->balanceDue());
        $this->assertTrue($appointment->hasPaidDeposit());
    }

    public function test_stripe_payment_service_generates_checkout_session(): void
    {
        $appointment = Appointment::factory()->create([
            'business_id' => $this->business->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'price' => 120.00,
            'deposit_required' => true,
            'deposit_amount' => 24.00,
            'deposit_status' => 'unpaid',
            'status' => Appointment::PENDING,
        ]);

        $service = app(StripePaymentService::class);
        $successUrl = route('stripe.payment.success', $appointment->id);
        $cancelUrl = route('stripe.payment.cancel', $appointment->id);

        $session = $service->createCheckoutSession($appointment, $successUrl, $cancelUrl);

        $this->assertArrayHasKey('id', $session);
        $this->assertArrayHasKey('url', $session);
        $this->assertNotNull($appointment->fresh()->stripe_session_id);
    }

    public function test_stripe_payment_controller_success_callback_confirms_appointment(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'email' => 'client@example.test',
        ]);

        $appointment = Appointment::factory()->create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'price' => 120.00,
            'deposit_required' => true,
            'deposit_amount' => 24.00,
            'deposit_status' => 'unpaid',
            'paid_amount' => 0.00,
            'status' => Appointment::PENDING,
            'stripe_session_id' => 'cs_test_mock123',
        ]);

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_mock123',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_mock_123',
                'amount_total' => 2400,
            ], 200),
        ]);

        $response = $this->get(route('stripe.payment.success', [
            'appointment' => $appointment->id,
            'session_id' => 'cs_test_mock123',
        ]));

        $response->assertRedirect();

        $appointment->refresh();
        $this->assertEquals('paid', $appointment->deposit_status);
        $this->assertEquals(24.00, $appointment->paid_amount);
        $this->assertEquals(Appointment::CONFIRMED, $appointment->status);
        $this->assertEquals(96.00, $appointment->balanceDue());
        $this->assertTrue($appointment->hasPaidDeposit());
    }

    public function test_appointment_screen_can_mark_remaining_balance_paid(): void
    {
        $this->actingAs($this->owner);

        $appointment = Appointment::factory()->create([
            'business_id' => $this->business->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'price' => 120.00,
            'deposit_required' => true,
            'deposit_amount' => 24.00,
            'deposit_status' => 'paid',
            'paid_amount' => 24.00,
            'status' => Appointment::CONFIRMED,
        ]);

        Volt::test('appointments.index')
            ->call('markPaid', $appointment->id);

        $appointment->refresh();
        $this->assertEquals(120.00, $appointment->paid_amount);
        $this->assertEquals(0.00, $appointment->balanceDue());
        $this->assertTrue($appointment->isFullyPaid());
    }

    public function test_settings_screen_saves_deposit_policy(): void
    {
        $this->actingAs($this->owner);

        Volt::test('settings.index')
            ->set('deposit_enabled', true)
            ->set('deposit_type', 'fixed')
            ->set('deposit_value', '25.00')
            ->set('stripe_test_mode', true)
            ->call('save');

        $this->business->refresh();
        $this->assertTrue($this->business->depositEnabled());
        $this->assertEquals('fixed', $this->business->depositType());
        $this->assertEquals(25.00, $this->business->depositValue());
    }
}
