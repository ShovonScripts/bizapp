<?php

namespace App\Http\Controllers;

use App\Messaging\Drivers\WhatsAppCloudApiDriver;
use App\Messaging\Interactive\AppointmentResponseHandler;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming webhooks from Meta's WhatsApp Cloud API.
 *
 * Supports both:
 * 1. GET: Webhook verification challenge (hub.challenge / hub.verify_token)
 * 2. POST: Inbound customer messages & interactive replies ("YES" / "CANCEL")
 */
class WhatsAppWebhookController
{
    public function __invoke(Request $request, AppointmentResponseHandler $handler): Response
    {
        if ($request->isMethod('GET')) {
            return $this->verifyWebhook($request);
        }

        return $this->handleIncomingMessage($request, $handler);
    }

    /**
     * Responds to Meta's webhook verification request.
     */
    protected function verifyWebhook(Request $request): Response
    {
        $expectedToken = (string) config('messaging.whatsapp.webhook_verify_token');

        if ($expectedToken === '') {
            return response('WhatsApp webhook verification token is not configured.', 403);
        }

        // In PHP, query params with dots like hub.mode and hub.verify_token may appear with underscores
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode'));
        $verifyToken = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'));
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge'));

        if ($mode === 'subscribe' && hash_equals($expectedToken, $verifyToken)) {
            return response($challenge, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        Log::warning('[whatsapp] Webhook verification failed with invalid token.', [
            'ip' => $request->ip(),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Processes inbound message payloads and dispatches interactive replies.
     */
    protected function handleIncomingMessage(Request $request, AppointmentResponseHandler $handler): Response
    {
        if ($request->isMethod('POST')) {
            $appSecret = config('messaging.whatsapp.app_secret');
            $signature = $request->header('X-Hub-Signature-256');

            if (blank($appSecret)) {
                Log::warning('[whatsapp] Rejected: app secret is not configured.');
                return response('Forbidden', 403);
            }

            if (blank($signature)) {
                Log::warning('[whatsapp] Rejected: missing signature header.');
                return response('Forbidden', 403);
            }

            if (!str_starts_with($signature, 'sha256=')) {
                Log::warning('[whatsapp] Rejected: malformed signature header.');
                return response('Forbidden', 403);
            }

            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
            if (!hash_equals($expected, $signature)) {
                Log::warning('[whatsapp] Rejected: signature mismatch.');
                return response('Invalid signature', 403);
            }
        }

        $entries = (array) $request->input('entry', []);

        foreach ($entries as $entry) {
            $changes = (array) data_get($entry, 'changes', []);

            foreach ($changes as $change) {
                $messages = (array) data_get($change, 'value.messages', []);

                foreach ($messages as $message) {
                    $this->processSingleMessage($message, $change, $handler);
                }
            }
        }

        return response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    protected function processSingleMessage(array $message, array $change, AppointmentResponseHandler $handler): void
    {
        $from = (string) data_get($message, 'from');

        if (blank($from)) {
            return;
        }

        $text = match ((string) data_get($message, 'type')) {
            'text' => (string) data_get($message, 'text.body', ''),
            'button' => (string) data_get($message, 'button.text', ''),
            'interactive' => (string) (
                data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title', '')
            ),
            default => '',
        };

        $text = trim($text);

        if ($text === '') {
            return;
        }

        $digits = preg_replace('/\D+/', '', $from);

        $phoneNumberId = (string) data_get($change, 'value.metadata.phone_number_id', data_get($change, 'value.phone_number_id', ''));
        $business = null;

        if ($phoneNumberId !== '') {
            $business = \App\Models\Business::whereHas('channelConnections', function ($q) use ($phoneNumberId) {
                $q->where('channel', 'whatsapp')
                  ->where('meta->phone_number_id', $phoneNumberId);
            })->first();
        }

        if (! $business) {
            Log::info('[whatsapp] Inbound message received from number with no matching business phone_number_id', ['from' => $digits, 'phone_number_id' => $phoneNumberId]);
            return;
        }

        \App\Support\Tenant::set($business->id);

        $customer = Customer::where(function ($q) use ($digits) {
            $q->where('whatsapp_number', '+'.$digits)
                ->orWhere('whatsapp_number', $digits)
                ->orWhere('phone', '+'.$digits)
                ->orWhere('phone', $digits);
        })->first();

        if (! $customer || ! $customer->business) {
            Log::info('[whatsapp] Inbound message received from unlinked number', ['from' => $digits, 'business_id' => $business->id]);
            return;
        }

        if ((int) $customer->business_id !== (int) $business->id) {
            Log::info('[whatsapp] Inbound message from number belonging to another business', ['from' => $digits, 'business_id' => $business->id, 'customer_business_id' => $customer->business_id]);
            return;
        }

        $reply = $handler->handle($customer, $text);

        $driver = new WhatsAppCloudApiDriver($business);

        if ($driver->isConfigured()) {
            $driver->send($customer, $reply);
        }
    }
}
