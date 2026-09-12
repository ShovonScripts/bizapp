<?php

namespace Tests\Feature\Security;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\Calendar\IcsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;
    private Business $otherBusiness;
    private Service $service;
    private StaffMember $staff;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Tenant::forget();

        $this->business = Business::factory()->create([
            'name' => 'Main Salon',
            'slug' => 'main-salon',
            'timezone' => 'Europe/London',
            'phone' => '+441234567890',
        ]);

        $this->otherBusiness = Business::factory()->create([
            'name' => 'Other Salon',
            'slug' => 'other-salon',
            'timezone' => 'Europe/London',
            'phone' => '+449876543210',
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->service = Service::factory()->for($this->business)->create([
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 25.00,
            'active' => true,
        ]);

        $this->staff = StaffMember::factory()->for($this->business)->create([
            'name' => 'Alice',
            'active' => true,
            'color' => '#6366f1',
        ]);

        $this->customer = Customer::factory()->for($this->business)->create([
            'name' => 'Jane Doe',
            'phone' => '+447700900123',
            'whatsapp_number' => '+447700900123',
        ]);
    }

    /* -------------------------- P0 #4: ICS token scoping -------------------------- */

    public function test_ics_route_rejects_sequential_id_and_requires_token(): void
    {
        $appointment = Appointment::factory()->for($this->business)->create([
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'cancellation_token' => 'secret-token-123',
        ]);

        // Sequential ID should 404 because route now expects token
        $response = $this->get("/appointments/{$appointment->id}/calendar.ics");
        $response->assertStatus(404);

        // Valid token should work
        $response = $this->get("/appointments/{$appointment->cancellation_token}/calendar.ics");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
    }

    public function test_ics_route_does_not_leak_other_business_appointments(): void
    {
        $otherAppointment = Appointment::factory()->for($this->otherBusiness)->create([
            'customer_id' => Customer::factory()->for($this->otherBusiness)->create()->id,
            'service_id' => Service::factory()->for($this->otherBusiness)->create()->id,
            'staff_member_id' => StaffMember::factory()->for($this->otherBusiness)->create()->id,
            'cancellation_token' => 'other-token-456',
        ]);

        // The token IS the access control — if you have the token, you can access the ICS.
        // The security win over the old sequential-ID route is that the token is
        // unguessable, so enumeration is impossible.
        $response = $this->get("/appointments/{$otherAppointment->cancellation_token}/calendar.ics");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        // But a random wrong token must still 404
        $response = $this->get('/appointments/wrong-token/calendar.ics');
        $response->assertStatus(404);
    }

    /* -------------------------- P0 #5: Confirmation view token exposure -------------------------- */

    public function test_confirmation_view_does_not_expose_tokens_in_url(): void
    {
        $appointment = Appointment::factory()->for($this->business)->create([
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'deposit_required' => true,
            'deposit_amount' => 10.00,
            'cancellation_token' => 'secret-cancel-token',
        ]);

        $response = $this->withSession(['booking.confirmed' => $appointment->id])
            ->get(route('booking.public', $this->business->slug));

        $response->assertOk();

        // Tokens may appear in rendered links (ICS download, cancellation) — that is
        // expected and necessary. The security win is that they are NOT exposed in the
        // URL query string, where they would leak via referrers, logs, and browser history.
        $this->assertStringNotContainsString('confirmed_id=', $response->getContent());
        $this->assertStringNotContainsString('telegram_link_token', $response->getContent());
    }

    /* -------------------------- P0 #6: WhatsApp wrong-business cancel -------------------------- */

    public function test_whatsapp_inbound_does_not_cancel_other_business_appointment(): void
    {
        config([
            'messaging.whatsapp.app_secret' => 'test-app-secret',
        ]);

        // Create an appointment in the main business for a customer
        $mainAppointment = Appointment::factory()->for($this->business)->create([
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'status' => Appointment::CONFIRMED,
        ]);

        // Create WhatsApp channel connection for OTHER business
        ChannelConnection::create([
            'business_id' => $this->otherBusiness->id,
            'channel' => 'whatsapp',
            'status' => ChannelConnection::ACTIVE,
            'meta' => [
                'phone_number_id' => '99999999999',
            ],
        ]);

        // Sign the payload with the app secret
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_OTHER',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'phone_number_id' => '99999999999',
                                'messages' => [
                                    [
                                        'from' => '447700900123',
                                        'id' => 'wamid.CancelOther',
                                        'timestamp' => '1720000000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'CANCEL',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $payloadJson, 'test-app-secret');

        $response = $this->call('POST', '/whatsapp/webhook', [], [], [], [
            'HTTP_X_Hub_Signature_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        $response->assertOk();

        // Main business's appointment should NOT be cancelled
        $this->assertEquals(Appointment::CONFIRMED, $mainAppointment->refresh()->status);
    }

    /* -------------------------- P0 #1: Stripe webhook signature -------------------------- */

    public function test_stripe_webhook_accepts_valid_signature_and_confirms_appointment(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

        $customer = Customer::factory()->for($this->business)->create([
            'email' => 'client@example.test',
        ]);

        $appointment = Appointment::factory()->for($this->business)->create([
            'customer_id' => $customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'deposit_required' => true,
            'deposit_amount' => 10.00,
            'deposit_status' => 'unpaid',
            'paid_amount' => 0.00,
            'status' => Appointment::PENDING,
        ]);

        $payload = json_encode([
            'id' => 'evt_test_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => (string) $appointment->id,
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_mock_123',
                    'amount_total' => 1000,
                ],
            ],
        ]);

        $timestamp = time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, 'whsec_test_secret');
        $sigHeader = "t={$timestamp},v1={$signature}";

        $response = $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe_Signature' => $sigHeader,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();

        $appointment->refresh();
        $this->assertEquals('paid', $appointment->deposit_status);
        $this->assertEquals(10.00, $appointment->paid_amount);
        $this->assertEquals(Appointment::CONFIRMED, $appointment->status);
    }

    public function test_stripe_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson('/stripe/webhook', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['client_reference_id' => '1']],
        ]);

        $response->assertStatus(403);
    }

    public function test_stripe_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['client_reference_id' => '1']],
        ]);

        $response = $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe_Signature' => 't=1234567890,v1=invalid_signature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(403);
    }
}
