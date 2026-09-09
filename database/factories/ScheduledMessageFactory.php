<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledMessage>
 *
 * Same closure trick as AppointmentFactory, for the same reason: declaring
 * `'customer_id' => Customer::factory()` alongside `'business_id' =>
 * Business::factory()` builds two unrelated businesses and hangs the customer off
 * the wrong one. A fixture that is already cross-tenant makes the tenant-isolation
 * tests pass for the wrong reason.
 */
class ScheduledMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),

            'customer_id' => fn (array $attrs) => Customer::factory()
                ->forBusiness($attrs['business_id'])
                ->create()->id,

            'channel' => 'telegram',
            'template_key' => ScheduledMessage::REMINDER_24H,

            'payload' => ['body' => 'Hi there, a quick reminder about tomorrow.'],

            // Due now, so a dispatcher test does not have to travel in time to
            // make the common case happen.
            'send_at' => now()->subMinute(),
            'status' => ScheduledMessage::PENDING,
            'attempts' => 0,
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
        ]);
    }

    /** Attach the message to the record it is about. */
    public function about(object $related): static
    {
        return $this->state(fn () => [
            'related_type' => $related->getMorphClass(),
            'related_id' => $related->id,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function dueAt(\DateTimeInterface|string $when): static
    {
        return $this->state(fn () => ['send_at' => $when]);
    }
}
