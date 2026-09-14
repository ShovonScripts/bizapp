<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StaffScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private Service $service;

    private StaffMember $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::forget();

        $this->business = Business::factory()->create([
            'name' => 'Luxe Glow Studio',
            'slug' => 'luxe-glow-schedule',
            'timezone' => 'Europe/London',
            'phone' => '07700900123',
        ]);

        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->service = Service::factory()->for($this->business)->create([
            'name' => 'Cut & Blow Dry',
            'duration_minutes' => 60,
            'price' => 65.00,
            'active' => true,
        ]);

        $this->staff = StaffMember::factory()->for($this->business)->create([
            'name' => 'Elena Rostova',
            'phone' => '07700900888',
            'active' => true,
            'working_hours' => null,
            'time_off' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }

    public function test_staff_defaults_to_standard_working_hours(): void
    {
        $this->assertNull($this->staff->working_hours);

        // Monday (1) should default to active 09:00 - 17:00
        $mondayHours = $this->staff->workingHoursFor(1);
        $this->assertTrue($mondayHours['active']);
        $this->assertEquals('09:00', $mondayHours['start']);
        $this->assertEquals('17:00', $mondayHours['end']);

        // Sunday (0) should default to inactive
        $sundayHours = $this->staff->workingHoursFor(0);
        $this->assertFalse($sundayHours['active']);

        $summary = $this->staff->workingDaysSummary();
        $this->assertStringContainsString('Mon', $summary);
        $this->assertStringContainsString('Sat', $summary);
    }

    public function test_staff_custom_working_hours_and_shift_boundaries(): void
    {
        // Custom hours: Elena only works Wednesday from 10:00 to 14:00
        $customHours = StaffMember::defaultWorkingHours();
        foreach ($customHours as $day => &$config) {
            $config['is_working'] = ($day === 'wednesday');
            if ($day === 'wednesday') {
                $config['start'] = '10:00';
                $config['end'] = '14:00';
            }
        }
        $this->staff->working_hours = $customHours;
        $this->staff->save();

        // Check Tuesday (2) - should not be working
        $tuesdayDate = Carbon::parse('next tuesday', 'Europe/London');
        $this->assertFalse($this->staff->isWorkingOnDate($tuesdayDate));

        // Check Wednesday (3) - should be working
        $wednesdayDate = Carbon::parse('next wednesday', 'Europe/London');
        $this->assertTrue($this->staff->isWorkingOnDate($wednesdayDate));

        // Test slot availability on Wednesday
        // 09:00 - 10:00 (Starts before shift) -> false
        $earlySlotStart = $wednesdayDate->copy()->setTime(9, 0);
        $earlySlotEnd = $wednesdayDate->copy()->setTime(10, 0);
        $this->assertFalse($this->staff->isAvailableForSlot($earlySlotStart, $earlySlotEnd));

        // 10:00 - 11:00 (Inside shift) -> true
        $validSlotStart = $wednesdayDate->copy()->setTime(10, 0);
        $validSlotEnd = $wednesdayDate->copy()->setTime(11, 0);
        $this->assertTrue($this->staff->isAvailableForSlot($validSlotStart, $validSlotEnd));

        // 13:30 - 14:30 (Exceeds shift end at 14:00) -> false
        $lateSlotStart = $wednesdayDate->copy()->setTime(13, 30);
        $lateSlotEnd = $wednesdayDate->copy()->setTime(14, 30);
        $this->assertFalse($this->staff->isAvailableForSlot($lateSlotStart, $lateSlotEnd));
    }

    public function test_staff_all_day_time_off_blocks_entire_day(): void
    {
        $wednesdayDate = Carbon::parse('next wednesday', 'Europe/London')->toDateString();

        $this->staff->time_off = [
            [
                'id' => 'to_123',
                'date' => $wednesdayDate,
                'is_all_day' => true,
                'reason' => 'Doctor Appointment',
            ],
        ];
        $this->staff->save();

        $this->assertFalse($this->staff->isWorkingOnDate($wednesdayDate));

        $slotStart = Carbon::parse($wednesdayDate.' 10:00:00', 'Europe/London');
        $slotEnd = Carbon::parse($wednesdayDate.' 11:00:00', 'Europe/London');
        $this->assertFalse($this->staff->isAvailableForSlot($slotStart, $slotEnd));
    }

    public function test_staff_partial_time_off_blocks_only_overlapping_slots(): void
    {
        $wednesdayDate = Carbon::parse('next wednesday', 'Europe/London')->toDateString();

        $this->staff->time_off = [
            [
                'id' => 'to_456',
                'date' => $wednesdayDate,
                'is_all_day' => false,
                'start_time' => '12:00',
                'end_time' => '13:30',
                'reason' => 'Dentist Checkup',
            ],
        ];
        $this->staff->save();

        $this->assertTrue($this->staff->isWorkingOnDate($wednesdayDate));

        // 10:00 - 11:00 (Before time-off) -> Available
        $morningStart = Carbon::parse($wednesdayDate.' 10:00:00', 'Europe/London');
        $morningEnd = Carbon::parse($wednesdayDate.' 11:00:00', 'Europe/London');
        $this->assertTrue($this->staff->isAvailableForSlot($morningStart, $morningEnd));

        // 12:00 - 13:00 (Direct overlap with time-off) -> Blocked
        $overlapStart = Carbon::parse($wednesdayDate.' 12:00:00', 'Europe/London');
        $overlapEnd = Carbon::parse($wednesdayDate.' 13:00:00', 'Europe/London');
        $this->assertFalse($this->staff->isAvailableForSlot($overlapStart, $overlapEnd));

        // 14:00 - 15:00 (After time-off) -> Available
        $afternoonStart = Carbon::parse($wednesdayDate.' 14:00:00', 'Europe/London');
        $afternoonEnd = Carbon::parse($wednesdayDate.' 15:00:00', 'Europe/London');
        $this->assertTrue($this->staff->isAvailableForSlot($afternoonStart, $afternoonEnd));
    }

    public function test_staff_screen_livewire_saves_schedule_and_time_off(): void
    {
        $this->actingAs($this->owner);

        $nextFriday = Carbon::parse('next friday')->toDateString();

        Volt::test('staff.index')
            ->call('edit', $this->staff->id)
            ->assertSet('showForm', true)
            ->assertSet('activeTab', 'details')
            ->set('activeTab', 'schedule')
            ->set('workingHours.monday.is_working', true)
            ->set('workingHours.monday.start', '08:30')
            ->set('workingHours.monday.end', '16:30')
            ->set('activeTab', 'timeoff')
            ->set('newTimeOffDate', $nextFriday)
            ->set('newTimeOffReason', 'Personal Development Day')
            ->set('newTimeOffAllDay', true)
            ->call('addTimeOff')
            ->assertCount('timeOffList', 1)
            ->call('save')
            ->assertSet('showForm', false);

        $this->staff->refresh();
        $this->assertEquals('08:30', $this->staff->working_hours['monday']['start']);
        $this->assertEquals('16:30', $this->staff->working_hours['monday']['end']);
        $this->assertCount(1, $this->staff->time_off);
        $this->assertEquals('Personal Development Day', $this->staff->time_off[0]['reason']);
        $this->assertEquals($nextFriday, $this->staff->time_off[0]['date']);
    }

    public function test_public_booking_filters_slots_based_on_specialist_shift_and_leave(): void
    {
        // Specialist only works on Thursday 10:00 - 13:00
        $customHours = StaffMember::defaultWorkingHours();
        foreach ($customHours as $day => &$config) {
            $config['is_working'] = ($day === 'thursday');
            if ($day === 'thursday') {
                $config['start'] = '10:00';
                $config['end'] = '13:00';
            }
        }
        $this->staff->working_hours = $customHours;

        $nextThursday = Carbon::parse('next thursday', 'Europe/London')->toDateString();
        $nextWednesday = Carbon::parse('next wednesday', 'Europe/London')->toDateString();

        $this->staff->save();

        // Testing Wednesday (Specialist Off Duty)
        $componentWed = Volt::test('booking.public', ['slug' => $this->business->slug])
            ->call('selectService', $this->service->id)
            ->call('selectStaff', $this->staff->id)
            ->call('selectDate', $nextWednesday);

        $slotsWed = $componentWed->get('availableSlots');
        $this->assertEmpty($slotsWed, 'No slots should be available when specialist is off duty.');

        // Testing Thursday (Specialist On Duty 10:00 - 13:00)
        // 60-min service: 10:00, 10:30, 11:00, 11:30, 12:00 fit within 10:00-13:00
        $componentThu = Volt::test('booking.public', ['slug' => $this->business->slug])
            ->call('selectService', $this->service->id)
            ->call('selectStaff', $this->staff->id)
            ->call('selectDate', $nextThursday);

        $slotsThu = $componentThu->get('availableSlots');
        $this->assertNotEmpty($slotsThu, 'Slots should be available during specialist shift hours.');

        // Slot at 09:00 should not exist
        $times = array_column($slotsThu, 'time');
        $this->assertNotContains('09:00', $times);
        $this->assertContains('10:00', $times);
        $this->assertContains('12:00', $times);
        // 13:00 would finish at 14:00 which is after 13:00 end
        $this->assertNotContains('13:00', $times);
    }
}
