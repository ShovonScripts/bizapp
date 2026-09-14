<?php

namespace App\Livewire\Booking;

use App\Models\Appointment;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.booking')]
#[Title('Cancel Appointment')]
class Cancel extends Component
{
    public string $slug = '';
    public ?int $businessId = null;
    public string $token = '';

    public ?int $appointmentId = null;
    public ?string $customerName = null;
    public ?string $serviceName = null;
    public ?string $startTime = null;

    public bool $confirmed = false;

    public function mount(string $slug, string $token): void
    {
        $this->slug = $slug;
        $this->token = $token;

        $business = \App\Models\Business::where('slug', $slug)->firstOrFail();
        $this->businessId = $business->id;
        Tenant::set($business->id);

        $appointment = Appointment::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('cancellation_token', $token)
            ->with(['service', 'customer'])
            ->first();

        if (! $appointment || $appointment->status === \App\Models\Appointment::CANCELLED) {
            abort(404);
        }

        $this->appointmentId = $appointment->id;
        $this->customerName = $appointment->customer?->name ?? 'Valued Client';
        $this->serviceName = $appointment->service?->name ?? 'your appointment';
        $this->startTime = $business->toLocal($appointment->starts_at)->format('l, j F Y \a\t g:i A');
    }

    public function cancel(): void
    {
        $this->validate([
            'appointmentId' => 'required|integer',
        ]);

        $appointment = Appointment::withoutGlobalScopes()
            ->where('business_id', $this->businessId)
            ->where('id', $this->appointmentId)
            ->firstOrFail();

        if ($appointment->status === \App\Models\Appointment::CANCELLED) {
            abort(404);
        }

        $appointment->update([
            'status' => \App\Models\Appointment::CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->confirmed = true;
    }

    public function render(): mixed
    {
        return view('livewire.booking.cancel');
    }
}
