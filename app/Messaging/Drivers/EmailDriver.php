<?php

namespace App\Messaging\Drivers;

use App\Mail\AppointmentNotificationMail;
use App\Messaging\Contracts\MessageDriver;
use App\Messaging\SendResult;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers automated reminders and booking notifications via Email (SMTP / Mailgun / Postmark / Resend).
 *
 * Attaches standard RFC 5545 (.ics) calendar invites so customers can add the
 * booking to Google Calendar, Apple Calendar, or Outlook with one tap.
 */
class EmailDriver implements MessageDriver
{
    public function __construct(
        protected Business $business,
        protected ?ChannelConnection $connection = null,
    ) {}

    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return filled(config('mail.from.address')) || filled($this->business->email);
    }

    public function send(Customer $customer, string $body): SendResult
    {
        if (! $this->isConfigured()) {
            return SendResult::permanentFailure(
                'Email sending is not configured. Set MAIL_FROM_ADDRESS in .env.'
            );
        }

        if (blank($customer->email)) {
            return SendResult::permanentFailure(
                'This customer has no email address on file.'
            );
        }

        // Validate basic email structure
        if (! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            return SendResult::permanentFailure(
                "Customer email [{$customer->email}] is not a valid email address."
            );
        }

        $appointment = Appointment::withoutGlobalScope('business')
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Appointment::PENDING, Appointment::CONFIRMED])
            ->where('starts_at', '>=', now()->subHours(2))
            ->orderBy('starts_at')
            ->first();

        $messageId = 'mail_'.Str::lower(Str::random(16));

        try {
            if ($appointment) {
                Mail::to($customer->email)->send(
                    new AppointmentNotificationMail(
                        $appointment,
                        "Appointment Reminder: {$this->business->name}",
                        $body,
                        true
                    )
                );
            } else {
                Mail::raw($body, function ($msg) use ($customer) {
                    $msg->to($customer->email)
                        ->subject("Message from {$this->business->name}");
                });
            }

            return SendResult::sent($messageId);
        } catch (Throwable $e) {
            return SendResult::retryableFailure(
                'Could not send email: '.$e->getMessage()
            );
        }
    }
}
