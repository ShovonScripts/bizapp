<?php

namespace App\Services\Calendar;

use App\Models\Appointment;

/**
 * Generates standard RFC 5545 iCalendar (.ics) files and one-click Google Calendar URLs.
 */
class IcsGenerator
{
    /**
     * Generate an RFC 5545 compliant iCalendar (.ics) string.
     */
    public function generate(Appointment $appointment): string
    {
        $business = $appointment->business;
        $service = $appointment->service;
        $staff = $appointment->staffMember;

        $businessName = $business?->name ?? 'Salon';
        $serviceName = $service?->name ?? 'Appointment';
        $staffName = $staff?->name ?? 'Specialist';
        $location = $business?->address ?: ($business?->name ?? 'Salon');

        $startsAt = $appointment->starts_at->clone()->utc();
        $endsAt = $appointment->ends_at->clone()->utc();
        $now = now()->utc();

        $dtStamp = $now->format('Ymd\THis\Z');
        $dtStart = $startsAt->format('Ymd\THis\Z');
        $dtEnd = $endsAt->format('Ymd\THis\Z');

        $uid = "appointment-{$appointment->id}-{$appointment->business_id}@bizapp.io";

        $summary = $this->escapeText("{$serviceName} at {$businessName}");
        $description = $this->escapeText(
            "Appointment for {$serviceName} with {$staffName} at {$businessName}." .
            ($business?->phone ? " Phone: {$business->phone}." : "") .
            ($appointment->notes ? " Notes: {$appointment->notes}" : "")
        );
        $escapedLocation = $this->escapeText($location);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BizApp//Appointment Calendar 1.0//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$dtStamp}",
            "DTSTART:{$dtStart}",
            "DTEND:{$dtEnd}",
            "SUMMARY:{$summary}",
            "DESCRIPTION:{$description}",
            "LOCATION:{$escapedLocation}",
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Generate a one-tap Google Calendar web link.
     */
    public function googleCalendarUrl(Appointment $appointment): string
    {
        $business = $appointment->business;
        $service = $appointment->service;
        $staff = $appointment->staffMember;

        $businessName = $business?->name ?? 'Salon';
        $serviceName = $service?->name ?? 'Appointment';
        $staffName = $staff?->name ?? 'Specialist';
        $location = $business?->address ?: ($business?->name ?? 'Salon');

        $dtStart = $appointment->starts_at->clone()->utc()->format('Ymd\THis\Z');
        $dtEnd = $appointment->ends_at->clone()->utc()->format('Ymd\THis\Z');

        $title = "{$serviceName} at {$businessName}";
        $details = "Appointment for {$serviceName} with {$staffName} at {$businessName}." .
            ($business?->phone ? " Phone: {$business->phone}." : "");

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => "{$dtStart}/{$dtEnd}",
            'details' => $details,
            'location' => $location,
        ]);
    }

    protected function escapeText(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', ''],
            $text
        );
    }
}
