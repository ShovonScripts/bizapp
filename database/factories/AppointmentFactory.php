<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * ⚠️ WHY THE CLOSURES.
     *
     * The naive version —
     *     'business_id'  => Business::factory(),
     *     'customer_id'  => Customer::factory(),
     * — creates TWO different businesses and hangs a customer off the wrong one.
     * The appointment would then reference a customer from another tenant: the
     * exact bug our tenant-isolation test is meant to catch, baked into the
     * fixtures, so the test would be lying to us.
     *
     * Laravel expands attributes in declaration order and hands each closure the
     * values resolved so far, so $attrs['business_id'] below is the real id of
     * the business created on the line above.
     */
    public function definition(): array
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('-2 months', '+1 month'))
            ->setMinute(fake()->randomElement([0, 15, 30, 45]))
            ->setSecond(0);

        return [
            'business_id' => Business::factory(),

            'customer_id' => fn (array $attrs) => Customer::factory()
                ->forBusiness($attrs['business_id'])
                ->create()->id,

            'service_id' => fn (array $attrs) => Service::factory()
                ->forBusiness($attrs['business_id'])
                ->create()->id,

            'staff_member_id' => fn (array $attrs) => StaffMember::factory()
                ->forBusiness($attrs['business_id'])
                ->create()->id,

            'starts_at' => $startsAt,

            // Kept consistent with the service's real duration by the closure below.
            'ends_at' => fn (array $attrs) => Carbon::parse($attrs['starts_at'])
                ->addMinutes(Service::withoutGlobalScope('business')->find($attrs['service_id'])?->duration_minutes ?? 30),

            // Past appointments default to completed, future ones to confirmed —
            // otherwise revenue figures in the demo look nonsensical.
            'status' => $startsAt->isPast() ? Appointment::COMPLETED : Appointment::CONFIRMED,

            'price' => fn (array $attrs) => Service::withoutGlobalScope('business')
                ->find($attrs['service_id'])?->price ?? 30.00,

            'source' => 'manual',
        ];
    }

    public function forBusiness(Business|int $business): static
    {
        return $this->state(fn () => [
            'business_id' => $business instanceof Business ? $business->id : $business,
        ]);
    }

    /** Tomorrow at a given local hour — what the 24h reminder planner looks for. */
    public function tomorrowAt(int $hour = 10, string $timezone = 'Europe/London'): static
    {
        return $this->state(function (array $attrs) use ($hour, $timezone) {
            $local = Carbon::now($timezone)->addDay()->setTime($hour, 0);

            return [
                'starts_at' => $local->clone()->utc(),
                'ends_at' => $local->clone()->addMinutes(30)->utc(),
                'status' => Appointment::CONFIRMED,
            ];
        });
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => ['status' => Appointment::NO_SHOW]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => Appointment::CANCELLED]);
    }
}
