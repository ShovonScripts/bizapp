<?php

namespace App\Http\Controllers;

use App\Mail\AppointmentNotificationMail;
use App\Models\Appointment;
use App\Services\Payment\StripePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StripePaymentController extends Controller
{
    public function success(Request $request, string $id, StripePaymentService $stripeService): RedirectResponse
    {
        $appointment = Appointment::withoutGlobalScopes()
            ->with(['business', 'customer', 'service', 'staffMember'])
            ->findOrFail($id);

        $sessionId = $request->query('session_id', $appointment->stripe_session_id);

        if ($sessionId) {
            $verification = $stripeService->verifySession($appointment, $sessionId);

            if ($verification['success']) {
                $appointment->update([
                    'deposit_status' => 'paid',
                    'paid_amount' => $verification['amount_paid'],
                    'stripe_payment_intent_id' => $verification['payment_intent_id'],
                    'status' => Appointment::CONFIRMED,
                ]);

                // Dispatch confirmation email if customer has email
                if ($appointment->customer?->email) {
                    try {
                        Mail::to($appointment->customer->email)->send(
                            new AppointmentNotificationMail(
                                $appointment,
                                "Deposit Received & Confirmed: {$appointment->service->name}",
                                "Thank you! We've received your deposit of £".number_format($verification['amount_paid'], 2).'. Your appointment has been officially confirmed.',
                                true
                            )
                        );
                    } catch (\Throwable $e) {
                        Log::warning('[StripeController] Failed sending confirmation email', [
                            'exception' => $e::class,
                        ]);
                    }
                }
            }
        }

        // Redirect back to booking portal confirmation view
        $request->session()->put('booking.confirmed', $appointment->id);

        return redirect()->route('booking.public', [
            'slug' => $appointment->business->slug,
            'deposit_paid' => 1,
        ]);
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $appointment = Appointment::withoutGlobalScopes()
            ->with('business')
            ->findOrFail($id);

        return redirect()->route('booking.public', [
            'slug' => $appointment->business->slug,
            'cancelled_payment' => 1,
        ])->with('error', 'Deposit payment was not completed. Please try again or select another time.');
    }

    public function webhook(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (blank($webhookSecret) || blank($sigHeader)) {
            Log::warning('[StripeWebhook] Rejected: missing webhook secret or signature header.');

            return response('Forbidden', 403);
        }

        $timestamp = null;
        $signatures = explode(',', $sigHeader);

        foreach ($signatures as $part) {
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
                break;
            }
        }

        if (blank($timestamp) || abs(time() - (int) $timestamp) > 300) {
            Log::warning('[StripeWebhook] Rejected: timestamp outside tolerance.');

            return response('Invalid timestamp', 403);
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        $v1Signature = null;
        foreach ($signatures as $part) {
            if (str_starts_with($part, 'v1=')) {
                $v1Signature = substr($part, 3);
                break;
            }
        }

        if (blank($v1Signature) || ! hash_equals($expectedSignature, $v1Signature)) {
            Log::warning('[StripeWebhook] Rejected: signature mismatch.');

            return response('Invalid signature', 403);
        }

        $event = json_decode($payload, true);

        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];
            $appointmentId = $session['client_reference_id'] ?? ($session['metadata']['appointment_id'] ?? null);

            if ($appointmentId) {
                $appointment = Appointment::withoutGlobalScopes()->find($appointmentId);
                if ($appointment && $appointment->deposit_status !== 'paid') {
                    $amount = isset($session['amount_total']) ? ((float) $session['amount_total']) / 100 : (float) $appointment->deposit_amount;
                    $appointment->update([
                        'deposit_status' => 'paid',
                        'paid_amount' => $amount,
                        'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
                        'status' => Appointment::CONFIRMED,
                    ]);
                }
            }
        }

        return response('Webhook Handled', 200);
    }
}
