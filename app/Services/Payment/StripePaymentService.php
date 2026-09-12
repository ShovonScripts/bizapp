<?php

namespace App\Services\Payment;

use App\Models\Appointment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripePaymentService
{
    /**
     * Create a Stripe Checkout Session for an appointment deposit.
     * Returns an array with ['id' => $sessionId, 'url' => $checkoutUrl].
     */
    public function createCheckoutSession(Appointment $appointment, string $successUrl, string $cancelUrl): array
    {
        $business = $appointment->business;
        $service = $appointment->service;
        $customer = $appointment->customer;
        $config = $business->stripeConfig();

        $secretKey = $config['secret_key'];
        $currency = strtolower($business->currency ?: 'gbp');
        $amountPence = (int) round(((float) $appointment->deposit_amount) * 100);

        // Fallback/Simulated test checkout if Stripe secret is not configured or in sandbox test mode
        if (blank($secretKey) || str_starts_with($secretKey, 'mock_') || $config['test_mode'] && blank(config('services.stripe.secret'))) {
            $mockSessionId = 'cs_test_' . uniqid() . '_' . $appointment->id;
            // Provide a direct callback URL that automatically hits the success route
            $simulatedUrl = $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'session_id=' . $mockSessionId . '&mock=1';

            $appointment->update([
                'stripe_session_id' => $mockSessionId,
            ]);

            return [
                'id' => $mockSessionId,
                'url' => $simulatedUrl,
                'is_mock' => true,
            ];
        }

        $sessionData = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $customer?->email,
            'client_reference_id' => (string) $appointment->id,
            'success_url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $amountPence,
                        'product_data' => [
                            'name' => "Booking Deposit: {$service->name}",
                            'description' => "Secured appointment deposit for {$business->name}",
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'appointment_id' => (string) $appointment->id,
                'business_id' => (string) $business->id,
                'service_id' => (string) $service->id,
            ],
        ];

        try {
            $response = Http::withToken($secretKey)
                ->asForm()
                ->post('https://api.stripe.com/v1/checkout/sessions', $sessionData);

            if ($response->successful()) {
                $session = $response->json();
                $appointment->update([
                    'stripe_session_id' => $session['id'],
                ]);

                return [
                    'id' => $session['id'],
                    'url' => $session['url'],
                    'is_mock' => false,
                ];
            }

            Log::error('[Stripe] Failed to create checkout session', ['error' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('[Stripe] Exception creating checkout session', ['message' => $e->getMessage()]);
        }

        throw new \RuntimeException('Payment could not be started. Your slot is held for 15 minutes — please try again or contact the business directly.');
    }

    /**
     * Verify payment for a session and return session details.
     */
    public function verifySession(Appointment $appointment, string $sessionId): array
    {
        $business = $appointment->business;
        $config = $business->stripeConfig();
        $secretKey = $config['secret_key'];

        if (blank($secretKey)) {
            return [
                'success' => false,
                'payment_intent_id' => null,
                'amount_paid' => 0.0,
            ];
        }

        try {
            $response = Http::withToken($secretKey)
                ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

            if ($response->successful()) {
                $session = $response->json();
                $isPaid = ($session['payment_status'] ?? '') === 'paid';
                $amountPaid = isset($session['amount_total']) ? ((float) $session['amount_total']) / 100 : (float) $appointment->deposit_amount;

                return [
                    'success' => $isPaid,
                    'payment_intent_id' => $session['payment_intent'] ?? null,
                    'amount_paid' => $amountPaid,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[Stripe] Error verifying checkout session', ['error' => $e->getMessage()]);
        }

        return ['success' => false, 'payment_intent_id' => null, 'amount_paid' => 0.0];
    }
}
