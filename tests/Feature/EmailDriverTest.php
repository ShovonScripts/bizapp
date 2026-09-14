<?php

namespace Tests\Feature;

use App\Mail\AppointmentNotificationMail;
use App\Messaging\Drivers\EmailDriver;
use App\Messaging\MessagingManager;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\Calendar\IcsGenerator;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class EmailDriverTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $customer;

    protected Service $service;

    protected StaffMember $staff;

    protected Appointment $appointment;

    protected MessagingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00', 'Europe/London'));

        $this->salon = Business::factory()->create([
            'name' => 'Rosewater Salon & Spa',
            'slug' => 'rosewater-salon',
            'email' => 'contact@rosewatersalon.co.uk',
            'phone' => '+441614960777',
            'address' => '42 King Street, Manchester',
            'timezone' => 'Europe/London',
        ]);

        $this->service = Service::factory()->forBusiness($this->salon)->create([
            'name' => 'Deluxe Facial',
            'duration_minutes' => 60,
            'price' => 75.00,
        ]);

        $this->staff = StaffMember::factory()->forBusiness($this->salon)->create([
            'name' => 'Elena Rostova',
        ]);

        $this->customer = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Hannah Abbott',
            'email' => 'hannah@example.com',
            'phone' => '+447700900888',
            'preferred_channel' => 'email',
        ]);

        $startsAt = Carbon::parse('2026-07-16 14:00', 'Europe/London');

        $this->appointment = Appointment::factory()->create([
            'business_id' => $this->salon->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'staff_member_id' => $this->staff->id,
            'starts_at' => $startsAt->clone()->utc(),
            'ends_at' => $startsAt->clone()->addHour()->utc(),
            'status' => Appointment::CONFIRMED,
            'price' => 75.00,
        ]);

        $this->manager = app(MessagingManager::class);
    }

    public function test_email_channel_is_registered_and_supported(): void
    {
        $this->assertTrue($this->manager->supports('email'));
        $this->assertContains('email', $this->manager->supportedChannels());

        config(['messaging.driver' => null]);
        $driver = $this->manager->driver($this->salon, 'email');

        $this->assertInstanceOf(EmailDriver::class, $driver);
        $this->assertSame('email', $driver->channel());
    }

    public function test_driver_reports_configured_when_mail_from_present(): void
    {
        config(['mail.from.address' => 'hello@bizapp.io']);

        $driver = new EmailDriver($this->salon);
        $this->assertTrue($driver->isConfigured());

        config(['mail.from.address' => null]);
        $salonWithoutEmail = Business::factory()->create(['email' => null]);
        $driverUnconfigured = new EmailDriver($salonWithoutEmail);
        $this->assertFalse($driverUnconfigured->isConfigured());
    }

    public function test_driver_sends_email_with_ics_calendar_attachment(): void
    {
        Mail::fake();

        config([
            'mail.from.address' => 'hello@bizapp.io',
            'mail.from.name' => 'Rosewater Salon',
        ]);

        $driver = new EmailDriver($this->salon);
        $result = $driver->send($this->customer, 'Hi Hannah, this is your appointment reminder.');

        $this->assertTrue($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringStartsWith('mail_', $result->providerMessageId);

        Mail::assertSent(AppointmentNotificationMail::class, function (AppointmentNotificationMail $mail) {
            $this->assertSame('hannah@example.com', $this->customer->email);
            $this->assertTrue($mail->hasTo('hannah@example.com'));
            $this->assertSame($this->appointment->id, $mail->appointment->id);

            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);

            return true;
        });
    }

    public function test_driver_fails_permanently_if_customer_has_no_email(): void
    {
        $customerWithoutEmail = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'No Email Customer',
            'email' => null,
        ]);

        $driver = new EmailDriver($this->salon);
        $result = $driver->send($customerWithoutEmail, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('no email address', $result->error);
    }

    public function test_driver_fails_permanently_if_customer_email_is_invalid(): void
    {
        $customerBadEmail = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Bad Email Customer',
            'email' => 'invalid-email-address',
        ]);

        $driver = new EmailDriver($this->salon);
        $result = $driver->send($customerBadEmail, 'Reminder');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('not a valid email', $result->error);
    }

    public function test_ics_generator_produces_rfc5545_vcalendar_output(): void
    {
        $generator = new IcsGenerator;
        $ics = $generator->generate($this->appointment);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Deluxe Facial at Rosewater Salon & Spa', $ics);
        $this->assertStringContainsString('LOCATION:42 King Street\, Manchester', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function test_ics_generator_produces_google_calendar_url(): void
    {
        $generator = new IcsGenerator;
        $url = $generator->googleCalendarUrl($this->appointment);

        $this->assertStringContainsString('https://calendar.google.com/calendar/render?', $url);
        $this->assertStringContainsString('action=TEMPLATE', $url);
        $this->assertStringContainsString('Deluxe+Facial+at+Rosewater+Salon+%26+Spa', $url);
    }

    public function test_public_download_route_serves_ics_file(): void
    {
        $response = $this->get("/appointments/{$this->appointment->cancellation_token}/calendar.ics");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="invite.ics"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
    }

    public function test_public_booking_flow_sends_confirmation_email_and_generates_calendar_urls(): void
    {
        Mail::fake();

        $tomorrow = Carbon::parse('2026-07-16', 'Europe/London')->toDateString();

        $component = Volt::test('booking.public', ['slug' => $this->salon->slug])
            ->call('selectService', $this->service->id)
            ->call('selectStaff', $this->staff->id)
            ->set('selectedDate', $tomorrow)
            ->call('selectTime', '10:00')
            ->set('customer_name', 'Grace Hopper')
            ->set('customer_phone', '+447700900999')
            ->set('customer_email', 'grace@example.com')
            ->call('submitBooking');

        $component->assertSet('step', 5);
        $component->assertSet('emailConfirmationSent', true);
        $this->assertNotNull($component->get('googleCalendarUrl'));
        $this->assertNotNull($component->get('downloadIcsUrl'));

        Mail::assertSent(AppointmentNotificationMail::class, function (AppointmentNotificationMail $mail) {
            return $mail->hasTo('grace@example.com')
                && str_contains($mail->customSubject, 'Booking Confirmed');
        });
    }
}
