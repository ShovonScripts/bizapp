<?php

namespace Tests\Feature;

use App\Messaging\Drivers\WhatsAppCloudApiDriver;
use App\Messaging\MessagingManager;
use App\Messaging\SendResult;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Customer;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDriverTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $customer;

    protected MessagingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();

        $this->salon = Business::factory()->create([
            'name' => 'Luxe Hair & Spa',
            'slug' => 'luxe-hair',
        ]);

        $this->customer = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Emma Watson',
            'phone' => '+447700900123',
            'whatsapp_number' => '+447700900123',
            'preferred_channel' => 'whatsapp',
        ]);

        $this->manager = app(MessagingManager::class);
    }

    public function test_whatsapp_channel_is_registered_and_supported(): void
    {
        $this->assertTrue($this->manager->supports('whatsapp'));
        $this->assertContains('whatsapp', $this->manager->supportedChannels());

        config(['messaging.driver' => null]);
        $driver = $this->manager->driver($this->salon, 'whatsapp');

        $this->assertInstanceOf(WhatsAppCloudApiDriver::class, $driver);
        $this->assertSame('whatsapp', $driver->channel());
    }

    public function test_driver_reports_unconfigured_when_credentials_missing(): void
    {
        config([
            'messaging.whatsapp.access_token' => null,
            'messaging.whatsapp.phone_number_id' => null,
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);

        $this->assertFalse($driver->isConfigured());
    }

    public function test_driver_reports_configured_when_credentials_present(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'EAAG...test-token',
            'messaging.whatsapp.phone_number_id' => '10987654321',
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);

        $this->assertTrue($driver->isConfigured());
    }

    public function test_driver_uses_tenant_channel_connection_override(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'platform-token',
            'messaging.whatsapp.phone_number_id' => 'platform-phone-id',
        ]);

        $connection = ChannelConnection::create([
            'business_id' => $this->salon->id,
            'channel' => 'whatsapp',
            'credentials' => [
                'access_token' => 'custom-tenant-token',
                'phone_number_id' => 'custom-phone-id',
            ],
            'status' => 'connected',
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon, $connection);

        $this->assertTrue($driver->isConfigured());

        Http::fake([
            'https://graph.facebook.com/v19.0/custom-phone-id/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '447700900123', 'wa_id' => '447700900123']],
                'messages' => [['id' => 'wamid.HBgLCustomTenant123']],
            ], 200),
        ]);

        $result = $driver->send($this->customer, 'Reminder from custom salon!');

        $this->assertTrue($result->sent);
        $this->assertSame('wamid.HBgLCustomTenant123', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/custom-phone-id/messages'
                && $request->hasHeader('Authorization', 'Bearer custom-tenant-token');
        });
    }

    public function test_sends_whatsapp_message_successfully(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'EAAG_valid_test_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
            'messaging.whatsapp.api_url' => 'https://graph.facebook.com/v19.0',
        ]);

        Http::fake([
            'https://graph.facebook.com/v19.0/100099887766554/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [
                    ['input' => '447700900123', 'wa_id' => '447700900123'],
                ],
                'messages' => [
                    ['id' => 'wamid.HBgLNDQ3NzAwOTAwMTIzFQIAERgSQ0E4MzQzN0IyREJGNzFFMjhEAA=='],
                ],
            ], 200),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Hi Emma, your appointment is tomorrow at 2:00 PM.');

        $this->assertTrue($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertSame('wamid.HBgLNDQ3NzAwOTAwMTIzFQIAERgSQ0E4MzQzN0IyREJGNzFFMjhEAA==', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v19.0/100099887766554/messages'
                && $request->hasHeader('Authorization', 'Bearer EAAG_valid_test_token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['recipient_type'] === 'individual'
                && $request['to'] === '447700900123' // E.164 digits without '+'
                && $request['text']['body'] === 'Hi Emma, your appointment is tomorrow at 2:00 PM.'
                && $request['text']['preview_url'] === false;
        });
    }

    public function test_fails_permanently_if_driver_not_configured(): void
    {
        config([
            'messaging.whatsapp.access_token' => null,
            'messaging.whatsapp.phone_number_id' => null,
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('not configured', $result->error);
    }

    public function test_fails_permanently_if_customer_has_no_whatsapp_target(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'EAAG_valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        $customerWithoutPhone = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'No Phone Customer',
            'phone' => null,
            'whatsapp_number' => null,
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($customerWithoutPhone, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('no WhatsApp number or mobile phone', $result->error);
    }

    public function test_classifies_token_expired_as_permanent_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'expired_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'fbtrace_id' => 'A1B2C3D4',
                ],
            ], 401),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('access token is invalid or expired', $result->error);
    }

    public function test_classifies_rate_limit_as_retryable_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Rate limit hit',
                    'code' => 130429,
                ],
            ], 429),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertTrue($result->retryable);
        $this->assertStringContainsString('rate limit reached', $result->error);
    }

    public function test_classifies_server_error_as_retryable_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Internal server error occurred',
                    'code' => 1,
                ],
            ], 500),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertTrue($result->retryable);
        $this->assertStringContainsString('Meta WhatsApp server error', $result->error);
    }

    public function test_classifies_network_timeout_as_retryable_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
        });

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertTrue($result->retryable);
        $this->assertStringContainsString('Could not reach WhatsApp Cloud API', $result->error);
    }

    public function test_classifies_unregistered_number_as_permanent_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Recipient phone number not in allowed list or not on WhatsApp',
                    'code' => 131026,
                ],
            ], 400),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('not registered on WhatsApp', $result->error);
    }

    public function test_classifies_24h_service_window_expired_as_permanent_failure(): void
    {
        config([
            'messaging.whatsapp.access_token' => 'valid_token',
            'messaging.whatsapp.phone_number_id' => '100099887766554',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.',
                    'code' => 131047,
                ],
            ], 400),
        ]);

        $driver = new WhatsAppCloudApiDriver($this->salon);
        $result = $driver->send($this->customer, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('More than 24 hours have passed', $result->error);
    }
}
