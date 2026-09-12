<?php

namespace App\Messaging\Drivers;

use App\Messaging\Contracts\MessageDriver;
use App\Messaging\SendResult;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Customer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Delivers automated reminders via Meta's WhatsApp Cloud API (Graph API).
 *
 * ─── Credentials Resolution ─────────────────────────────────────────────────
 * Like Telegram, almost every business uses the platform credentials in .env.
 * If an enterprise salon brings its own WhatsApp Business Account (WABA),
 * its encrypted row in channel_connections takes precedence.
 *
 * ─── Phone Number Normalisation ─────────────────────────────────────────────
 * WhatsApp Cloud API expects recipient numbers in E.164 digits without a leading
 * plus (e.g. "447700900123"). Customer::whatsappTarget() gives E.164 (+44...),
 * and this driver cleans the leading plus before dispatching.
 */
class WhatsAppCloudApiDriver implements MessageDriver
{
    public function __construct(
        protected Business $business,
        protected ?ChannelConnection $connection = null,
    ) {}

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return filled($this->token()) && filled($this->phoneNumberId());
    }

    protected function token(): ?string
    {
        $own = data_get($this->connection?->credentials, 'access_token');

        return filled($own) ? $own : config('messaging.whatsapp.access_token');
    }

    protected function phoneNumberId(): ?string
    {
        $own = data_get($this->connection?->credentials, 'phone_number_id');

        return filled($own) ? $own : config('messaging.whatsapp.phone_number_id');
    }

    protected function apiUrl(): string
    {
        return config('messaging.whatsapp.api_url', 'https://graph.facebook.com/v19.0');
    }

    public function send(Customer $customer, string $body): SendResult
    {
        if (! $this->isConfigured()) {
            return SendResult::permanentFailure(
                'WhatsApp Cloud API credentials are not configured. Add WHATSAPP_ACCESS_TOKEN and WHATSAPP_PHONE_NUMBER_ID to .env.'
            );
        }

        $target = $customer->whatsappTarget();

        if (blank($target)) {
            return SendResult::permanentFailure(
                'This customer has no WhatsApp number or mobile phone on file.'
            );
        }

        // WhatsApp Cloud API requires country code + subscriber number with digits only (no leading +)
        $cleanPhone = preg_replace('/\D+/', '', $target);

        $endpoint = rtrim($this->apiUrl(), '/').'/'.$this->phoneNumberId().'/messages';

        try {
            $response = Http::withToken($this->token())
                ->timeout(config('messaging.whatsapp.timeout', 10))
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $cleanPhone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $body,
                    ],
                ]);
        } catch (ConnectionException $e) {
            return SendResult::retryableFailure('Could not reach WhatsApp Cloud API: '.$e->getMessage());
        }

        $payload = $response->json() ?? [];

        if ($response->successful() && ! empty(data_get($payload, 'messages.0.id'))) {
            return SendResult::sent((string) data_get($payload, 'messages.0.id'));
        }

        return $this->classify($response->status(), $payload);
    }

    /**
     * Map Meta Graph API error codes into retryable vs permanent failures.
     */
    protected function classify(int $status, array $payload): SendResult
    {
        $errorCode = (int) data_get($payload, 'error.code');
        $errorSubcode = (int) data_get($payload, 'error.error_subcode');
        $description = (string) data_get($payload, 'error.message', 'HTTP '.$status);

        // Rate limited by Meta
        if ($status === 429 || $errorCode === 130429 || $errorCode === 4) {
            return SendResult::retryableFailure('WhatsApp rate limit reached: '.$description);
        }

        // Meta internal server issues
        if ($status >= 500 || $errorCode === 1 || $errorCode === 2) {
            return SendResult::retryableFailure('Meta WhatsApp server error: '.$description);
        }

        // Token expired or revoked
        if ($status === 401 || $errorCode === 190) {
            return SendResult::permanentFailure(
                'WhatsApp access token is invalid or expired. Check WHATSAPP_ACCESS_TOKEN in .env.'
            );
        }

        // Number not registered on WhatsApp
        if ($errorCode === 131026) {
            return SendResult::permanentFailure(
                'This recipient phone number is not registered on WhatsApp.'
            );
        }

        // Sandbox limitation: recipient not in Meta tester list
        if ($errorCode === 131030) {
            return SendResult::permanentFailure(
                'Recipient number is not in the Meta developer allowed list.'
            );
        }

        // 24-hour service window closed (template message needed)
        if ($errorCode === 131047) {
            return SendResult::permanentFailure(
                'More than 24 hours have passed since the customer last messaged the business.'
            );
        }

        return SendResult::permanentFailure('WhatsApp rejected message: '.$description);
    }
}
