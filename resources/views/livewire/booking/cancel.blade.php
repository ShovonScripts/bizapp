<div class="mx-auto max-w-lg">
    @if ($invalid)
        <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100">
                <svg class="h-6 w-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <h1 class="mt-4 text-xl font-semibold text-gray-900">Link expired or invalid</h1>
            <p class="mt-2 text-sm text-gray-600">This cancellation link is no longer valid. The appointment may have already been cancelled, or the link was incorrect.</p>
            <p class="mt-4 text-sm text-gray-600">Need help? Call <span class="font-medium text-gray-900">{{ $business->phone ?? $business->name }}</span>.</p>
        </div>
    @elseif ($confirmed)
        <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100">
                <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </div>
            <h1 class="mt-4 text-xl font-semibold text-gray-900">Booking cancelled</h1>
            <p class="mt-2 text-sm text-gray-600">Your {{ $serviceName }} on {{ $startTime }} has been cancelled.</p>
            <p class="mt-4 text-sm text-gray-600">We hope to see you again soon.</p>
        </div>
    @else
        <div class="rounded-2xl border border-gray-200 bg-white p-8">
            <h1 class="text-xl font-semibold text-gray-900">Cancel your booking</h1>
            <p class="mt-2 text-sm text-gray-600">You are cancelling:</p>

            <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ $customerName }}</p>
                <p class="mt-1">{{ $serviceName }}</p>
                <p class="mt-1">{{ $startTime }}</p>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <a href="{{ route('booking.public', $slug) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Keep booking</a>
                <button type="button" wire:click="cancel" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500">Yes, cancel</button>
            </div>
        </div>
    @endif
</div>
