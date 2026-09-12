<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; color: #1e293b;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; padding: 32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                    
                    <!-- Header Banner -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #e11d48, #4f46e5); padding: 32px 24px; text-align: center;">
                            <div style="display: inline-block; width: 48px; height: 48px; line-height: 48px; border-radius: 12px; background-color: rgba(255, 255, 255, 0.2); color: #ffffff; font-size: 22px; font-weight: 800; margin-bottom: 12px;">
                                {{ substr($business->name, 0, 1) }}
                            </div>
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.025em;">
                                {{ $business->name }}
                            </h1>
                            @if($business->niche)
                                <p style="margin: 4px 0 0 0; color: rgba(255, 255, 255, 0.85); font-size: 13px; text-transform: capitalize;">
                                    {{ $business->niche }}
                                </p>
                            @endif
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 32px 28px;">
                            <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 700; color: #0f172a;">
                                Hi {{ $customer->name }},
                            </h2>
                            <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.6; color: #475569;">
                                {{ $introMessage }}
                            </p>

                            <!-- Appointment Summary Card -->
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="padding-bottom: 12px; border-bottom: 1px dashed #cbd5e1;">
                                                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Service</span>
                                                    <div style="font-size: 17px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                                                        {{ $appointment->service?->name ?? 'Appointment' }}
                                                    </div>
                                                </td>
                                                <td align="right" style="padding-bottom: 12px; border-bottom: 1px dashed #cbd5e1;">
                                                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Price</span>
                                                    <div style="font-size: 17px; font-weight: 800; color: #e11d48; margin-top: 2px;">
                                                        &pound;{{ number_format($appointment->price, 2) }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-top: 12px; padding-bottom: 12px; border-bottom: 1px dashed #cbd5e1;">
                                                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Date &amp; Time</span>
                                                    <div style="font-size: 15px; font-weight: 600; color: #0f172a; margin-top: 2px;">
                                                        {{ $localStartsAt->format('l, j F Y') }}
                                                    </div>
                                                    <div style="font-size: 14px; font-weight: 700; color: #4f46e5; margin-top: 1px;">
                                                        {{ $localStartsAt->format('g:i A') }} ({{ $appointment->service?->duration_minutes ?? 30 }} mins)
                                                    </div>
                                                </td>
                                                <td align="right" style="padding-top: 12px; padding-bottom: 12px; border-bottom: 1px dashed #cbd5e1;">
                                                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Specialist</span>
                                                    <div style="font-size: 15px; font-weight: 600; color: #0f172a; margin-top: 2px;">
                                                        {{ $appointment->staffMember?->name ?? 'Any Specialist' }}
                                                    </div>
                                                </td>
                                            </tr>
                                            @if($business->address)
                                                <tr>
                                                    <td colspan="2" style="padding-top: 12px;">
                                                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Location</span>
                                                        <div style="font-size: 14px; color: #334155; margin-top: 2px;">
                                                            {{ $business->address }}
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- One-Click Google Calendar & Actions -->
                            <div style="text-align: center; margin-bottom: 24px;">
                                <a href="{{ $googleCalendarUrl }}" target="_blank" style="display: inline-block; background-color: #0f172a; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; padding: 12px 24px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    &#128197; Add to Google Calendar
                                </a>
                                @if($downloadIcsUrl)
                                    <div style="margin-top: 10px;">
                                        <a href="{{ $downloadIcsUrl }}" style="color: #4f46e5; font-size: 13px; font-weight: 600; text-decoration: underline;">
                                            Download Apple / Outlook Calendar Invite (.ics)
                                        </a>
                                    </div>
                                @endif
                            </div>

                            <!-- Interactive Instructions -->
                            <div style="background-color: #f1f5f9; border-radius: 8px; padding: 14px 18px; font-size: 13px; color: #475569; line-height: 1.5; text-align: center;">
                                Need to reschedule or change your appointment? Simply reply <strong>YES</strong> to confirm or <strong>CANCEL</strong> to cancel, or call us at <a href="tel:{{ $business->phone }}" style="color: #e11d48; font-weight: 700; text-decoration: none;">{{ $business->phone }}</a>.
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 24px; text-align: center; font-size: 12px; color: #94a3b8;">
                            Sent by <strong>{{ $business->name }}</strong> &middot; Powered by BizApp
                            @if($business->phone)
                                <br>Telephone: {{ $business->phone }}
                            @endif
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
