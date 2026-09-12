<?php

namespace Tests\Feature;

use App\Messaging\Telegram\UpdateHandler;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use App\Models\Service;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InteractiveMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $customer;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00', 'Europe/London'));

        $this->salon = Business::factory()->create([
            'name' => 'Glow Beauty Lounge',
            'slug' => 'glow-beauty',
            'phone' => '+441614960123',
            'timezone' => 'Europe/London',
        ]);

        \App\Models\ChannelConnection::create([
            'business_id' => $this->salon->id,
            'channel' => 'whatsapp',
            'status' => \App\Models\ChannelConnection::ACTIVE,
            'meta' => [
                'phone_number_id' => '10987654321',
            ],
        ]);

        $this->service = Service::factory()->forBusiness($this->salon)->create([
            'name' => 'Balayage & Cut',
            'duration_minutes' => 60,
            'price' => 85.00,
        ]);

        $this->customer = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Chloe Bennett',
            'phone' => '+447700900555',
            'whatsapp_number' => '+447700900555',
            'telegram_chat_id' => '998877',
            'preferred_channel' => 'telegram',
        ]);
    }

    protected function createAppointment(string $status = Appointment::PENDING): Appointment
    {
        $startsAt = Carbon::parse('2026-07-16 14:00', 'Europe/London');

        return Appointment::factory()->create([
            'business_id' => $this->salon->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'starts_at' => $startsAt->clone()->utc(),
            'ends_at' => $startsAt->clone()->addHour()->utc(),
            'status' => $status,
            'price' => 85.00,
        ]);
    }

    /* -------------------------- Telegram Two-Way -------------------------- */

    public function test_telegram_reply_yes_confirms_pending_appointment(): void
    {
        $appointment = $this->createAppointment(Appointment::PENDING);

        $handler = app(UpdateHandler::class);
        $reply = $handler->handle([
            'message' => [
                'chat' => ['id' => 998877],
                'text' => 'YES',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertSame(998877, $reply['chat_id']);
        $this->assertStringContainsString('now confirmed', $reply['text']);
        $this->assertStringContainsString('Balayage & Cut', $reply['text']);
        $this->assertStringContainsString('Glow Beauty Lounge', $reply['text']);

        $this->assertSame(Appointment::CONFIRMED, $appointment->fresh()->status);
    }

    public function test_telegram_reply_cancel_cancels_appointment_and_voids_reminders(): void
    {
        $appointment = $this->createAppointment(Appointment::CONFIRMED);

        $reminder = ScheduledMessage::create([
            'business_id' => $this->salon->id,
            'customer_id' => $this->customer->id,
            'channel' => 'telegram',
            'template_key' => 'appointment_reminder_24h',
            'payload' => ['body' => 'Reminder text'],
            'send_at' => now()->addHours(2),
            'status' => ScheduledMessage::PENDING,
            'related_type' => $appointment->getMorphClass(),
            'related_id' => $appointment->id,
        ]);

        $handler = app(UpdateHandler::class);
        $reply = $handler->handle([
            'message' => [
                'chat' => ['id' => 998877],
                'text' => 'CANCEL',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('has been cancelled', $reply['text']);
        $this->assertStringContainsString('+441614960123', $reply['text']);

        $this->assertSame(Appointment::CANCELLED, $appointment->fresh()->status);
        $this->assertSame(ScheduledMessage::CANCELLED, $reminder->fresh()->status);
    }

    public function test_telegram_unknown_reply_returns_interactive_help(): void
    {
        $this->createAppointment(Appointment::PENDING);

        $handler = app(UpdateHandler::class);
        $reply = $handler->handle([
            'message' => [
                'chat' => ['id' => 998877],
                'text' => 'What time do you close?',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Reply YES to confirm', $reply['text']);
        $this->assertStringContainsString('CANCEL', $reply['text']);
        $this->assertStringContainsString('/stop', $reply['text']);
    }

    /* ------------------------- WhatsApp Two-Way -------------------------- */

    public function test_whatsapp_webhook_verification_returns_challenge(): void
    {
        config(['messaging.whatsapp.webhook_verify_token' => 'meta_test_verify_token_123']);

        $response = $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=meta_test_verify_token_123&hub_challenge=88776655');

        $response->assertOk();
        $this->assertSame('88776655', $response->getContent());
    }

    public function test_whatsapp_webhook_verification_fails_with_invalid_token(): void
    {
        config(['messaging.whatsapp.webhook_verify_token' => 'correct_secret']);

        $response = $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong_secret&hub_challenge=88776655');

        $response->assertForbidden();
    }

    public function test_whatsapp_incoming_yes_confirms_appointment_and_dispatches_reply(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'EAAG_whatsapp_token',
            'messaging.whatsapp.phone_number_id' => '10987654321',
            'messaging.whatsapp.api_url' => 'https://graph.facebook.com/v19.0',
            'messaging.whatsapp.app_secret' => 'test-app-secret',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.Reply123']],
            ], 200),
        ]);

        $this->customer->update(['preferred_channel' => 'whatsapp']);

        $appointment = $this->createAppointment(Appointment::PENDING);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_123456',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'phone_number_id' => '10987654321',
                                'messages' => [
                                    [
                                        'from' => '447700900555',
                                        'id' => 'wamid.Inbound123',
                                        'timestamp' => '1720000000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'YES',
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
        $this->assertSame('EVENT_RECEIVED', $response->getContent());

        $this->assertSame(Appointment::CONFIRMED, $appointment->fresh()->status);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/10987654321/messages'
                && $request['to'] === '447700900555'
                && str_contains($request['text']['body'], 'now confirmed');
        });
    }

    public function test_whatsapp_incoming_cancel_cancels_appointment_and_dispatches_reply(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'EAAG_whatsapp_token',
            'messaging.whatsapp.phone_number_id' => '10987654321',
            'messaging.whatsapp.api_url' => 'https://graph.facebook.com/v19.0',
            'messaging.whatsapp.app_secret' => 'test-app-secret',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.ReplyCancel123']],
            ], 200),
        ]);

        $appointment = $this->createAppointment(Appointment::CONFIRMED);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_123456',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'phone_number_id' => '10987654321',
                                'messages' => [
                                    [
                                        'from' => '447700900555',
                                        'id' => 'wamid.InboundCancel',
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
        $this->assertSame(Appointment::CANCELLED, $appointment->fresh()->status);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/10987654321/messages'
                && $request['to'] === '447700900555'
                && str_contains($request['text']['body'], 'has been cancelled');
        });
    }

    public function test_customer_with_no_upcoming_appointment_gets_courteous_fallback(): void
    {
        $handler = app(UpdateHandler::class);
        $reply = $handler->handle([
            'message' => [
                'chat' => ['id' => 998877],
                'text' => 'YES',
            ],
        ]);

        $this->assertNotNull($reply);
        $this->assertStringContainsString("couldn't find an upcoming appointment", $reply['text']);
    }
}
