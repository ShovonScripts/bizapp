<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Services\Calendar\IcsGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $customSubject,
        public string $introMessage,
        public bool $attachIcs = true,
    ) {}

    public function envelope(): Envelope
    {
        $business = $this->appointment->business;
        $fromName = $business?->name ?? config('mail.from.name', 'BizApp');

        return new Envelope(
            subject: $this->customSubject,
            from: config('mail.from.address'),
        );
    }

    public function content(): Content
    {
        $appointment = $this->appointment;
        $business = $appointment->business;
        $customer = $appointment->customer;
        $localStartsAt = $business?->toLocal($appointment->starts_at) ?? $appointment->starts_at;

        $icsGenerator = app(IcsGenerator::class);
        $googleCalendarUrl = $icsGenerator->googleCalendarUrl($appointment);
        $downloadIcsUrl = route('appointments.calendar.ics', $appointment->cancellation_token);

        return new Content(
            view: 'emails.appointment-notification',
            with: [
                'appointment' => $appointment,
                'business' => $business,
                'customer' => $customer,
                'localStartsAt' => $localStartsAt,
                'googleCalendarUrl' => $googleCalendarUrl,
                'downloadIcsUrl' => $downloadIcsUrl,
                'subject' => $this->customSubject,
                'introMessage' => $this->introMessage,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachIcs) {
            return [];
        }

        $icsGenerator = app(IcsGenerator::class);
        $icsContent = $icsGenerator->generate($this->appointment);

        return [
            Attachment::fromData(fn () => $icsContent, 'invite.ics')
                ->withMime('text/calendar; charset=utf-8; method=REQUEST'),
        ];
    }
}
