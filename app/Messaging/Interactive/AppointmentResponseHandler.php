<?php

namespace App\Messaging\Interactive;

use App\Models\Appointment;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Handles interactive two-way messaging replies from customers.
 *
 * Shared by both Telegram and WhatsApp Cloud API. Interprets customer
 * intent ("YES" to confirm, "CANCEL" to cancel) and updates the matching
 * appointment in real-time.
 */
class AppointmentResponseHandler
{
    public const INTENT_CONFIRM = 'confirm';

    public const INTENT_CANCEL = 'cancel';

    public const INTENT_UNKNOWN = 'unknown';

    public function handle(Customer $customer, string $messageText): string
    {
        $intent = $this->detectIntent($messageText);
        $appointment = $this->findUpcomingAppointment($customer);
        $business = $customer->business;
        $businessName = $business?->name ?? 'your salon';
        $businessPhone = $business?->phone ?? '';
        $firstName = Str::before($customer->name, ' ');

        if (! $appointment) {
            if ($intent === self::INTENT_CONFIRM || $intent === self::INTENT_CANCEL) {
                return $businessPhone !== ''
                    ? "We couldn't find an upcoming appointment to {$intent}. Please contact {$businessName} directly at {$businessPhone}."
                    : "We couldn't find an upcoming appointment to {$intent}. Please contact {$businessName} directly.";
            }

            return $this->helpMessage($firstName, $businessName, $businessPhone);
        }

        $localStartsAt = $business?->toLocal($appointment->starts_at) ?? $appointment->starts_at;
        $dateFormatted = $localStartsAt->format('l j F');
        $timeFormatted = $localStartsAt->format('g:ia');
        $serviceName = $appointment->service?->name ?? 'your service';

        if ($intent === self::INTENT_CONFIRM) {
            if ($appointment->status === Appointment::CONFIRMED) {
                return "Your appointment for {$serviceName} on {$dateFormatted} at {$timeFormatted} with {$businessName} is already confirmed! We look forward to seeing you.";
            }

            $appointment->update(['status' => Appointment::CONFIRMED]);

            return "Thank you {$firstName}! Your appointment for {$serviceName} on {$dateFormatted} at {$timeFormatted} with {$businessName} is now confirmed. See you then!";
        }

        if ($intent === self::INTENT_CANCEL) {
            $appointment->update(['status' => Appointment::CANCELLED]);

            $rebookPrompt = $businessPhone !== ''
                ? " If you'd like to reschedule, please call us at {$businessPhone}."
                : '';

            return "Your appointment for {$serviceName} on {$dateFormatted} at {$timeFormatted} has been cancelled.{$rebookPrompt}";
        }

        return $this->helpMessage($firstName, $businessName, $businessPhone);
    }

    public function detectIntent(string $text): string
    {
        $normalized = Str::lower(trim($text));
        $normalized = preg_replace('/[[:punct:]]+/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));

        $confirmWords = [
            'yes', 'y', 'confirm', 'confirmed', 'ok', 'okay', 'sure', 'yep', 'yeah',
            'see you then', 'confirmed thanks', 'yes please', 'will be there',
        ];

        if (in_array($normalized, $confirmWords, true)) {
            return self::INTENT_CONFIRM;
        }

        $cancelWords = [
            'cancel', 'cancelled', 'no', 'cant make it', "can't make it", 'cannot make it',
            'reschedule', 'stop booking', 'cancel appointment', 'cancel booking',
        ];

        if (in_array($normalized, $cancelWords, true)) {
            return self::INTENT_CANCEL;
        }

        return self::INTENT_UNKNOWN;
    }

    public function findUpcomingAppointment(Customer $customer): ?Appointment
    {
        return Appointment::withoutGlobalScope('business')
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Appointment::PENDING, Appointment::CONFIRMED])
            ->where('starts_at', '>=', now()->subHours(2))
            ->orderBy('starts_at')
            ->with(['business', 'service'])
            ->first();
    }

    protected function helpMessage(string $firstName, string $businessName, string $businessPhone): string
    {
        $contactPart = $businessPhone !== '' ? " You can also call us at {$businessPhone}." : '';

        return "Hi {$firstName}! To manage your booking with {$businessName}, reply YES to confirm or CANCEL if you cannot make it.{$contactPart}";
    }
}
