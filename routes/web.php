<?php

use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Models\Appointment;
use App\Services\Calendar\IcsGenerator;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('pricing', 'pricing')->name('pricing');
Route::view('privacy', 'privacy')->name('privacy');
Route::view('terms', 'terms')->name('terms');

/*
| Public client self-booking portal: /book/{slug}
| Accessible to any customer without authentication. Tenant scoping is
| resolved strictly inside the component using the unique business slug.
*/
Volt::route('book/{slug}', 'booking.public')->name('booking.public');

/*
| Public customer cancellation: /book/{slug}/cancel/{token}
| Allows a customer to cancel their appointment without logging in.
*/
Volt::route('book/{slug}/cancel/{token}', 'booking.cancel')->name('booking.cancel');

/*
| The dashboard sits outside the tenant group below on purpose.
|
| Those screens abort 403 without a business. This one branches instead — a
| super-admin gets a plain "no business selected" panel — because the logo and
| the Dashboard link in the navigation are shown to everyone, so a 403 here
| would leave an admin with nowhere at all to land. See the component for the
| full reasoning, and DashboardTest for the proof that the admin branch shows
| no client data.
*/
Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

/*
|--------------------------------------------------------------------------
| Tenant screens
|
| Every route here must stay behind 'auth'. Tenant::id() reads the logged-in
| user, so an unauthenticated request would resolve to null — which the
| BelongsToBusiness scope treats as "unscoped" and would expose every
| client's data. Never expose a tenant-scoped page to a guest.
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('appointments', 'appointments.index')->name('appointments.index');
    Volt::route('customers', 'customers.index')->name('customers.index');
    Volt::route('services', 'services.index')->name('services.index');
    Volt::route('staff', 'staff.index')->name('staff.index');
    Volt::route('settings', 'settings.index')->name('settings.index');
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Telegram webhook
|
| Public by necessity — Telegram posts here with no session and no CSRF
| token (see the exemption in bootstrap/app.php). Authentication is the
| secret header it returns on every delivery; the controller checks that
| first and refuses everything else.
|
| Not needed locally. `php artisan telegram:poll` reads the same updates
| over an outbound connection, which is why linking can be tested on XAMPP
| without a tunnel.
|--------------------------------------------------------------------------
*/

Route::post('telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::match(['get', 'post'], 'whatsapp/webhook', WhatsAppWebhookController::class)
    ->name('whatsapp.webhook');

/*
|--------------------------------------------------------------------------
| Stripe Deposit Payments
|--------------------------------------------------------------------------
*/
Route::get('booking/{appointment}/payment-success', [StripePaymentController::class, 'success'])
    ->name('stripe.payment.success');

Route::get('booking/{appointment}/payment-cancel', [StripePaymentController::class, 'cancel'])
    ->name('stripe.payment.cancel');

Route::post('stripe/webhook', [StripePaymentController::class, 'webhook'])
    ->name('stripe.webhook');

/*
|--------------------------------------------------------------------------
| Public Calendar Invite Download (.ics)
|--------------------------------------------------------------------------
*/
Route::get('appointments/{cancellation_token}/calendar.ics', function (string $cancellation_token) {
    $appointment = Appointment::where('cancellation_token', $cancellation_token)
        ->with(['business', 'service', 'staffMember'])
        ->firstOrFail();

    $icsContent = app(IcsGenerator::class)->generate($appointment);

    return response($icsContent, 200, [
        'Content-Type' => 'text/calendar; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="invite.ics"',
    ]);
})->name('appointments.calendar.ics');

/*
|--------------------------------------------------------------------------
| Heartbeat viewer — lets you confirm from a browser that cPanel's cron
| is firing the scheduler on the live domain, without needing SSH open.
|
| Visit:  https://yourdomain.com/heartbeat?token=YOUR_SECRET
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Storage;

Route::get('/heartbeat', function () {
    $expected = config('app.heartbeat_token');

    abort_if(blank($expected), 404);
    abort_unless(hash_equals($expected, (string) request('token')), 403);

    $log = Storage::disk('local')->exists('heartbeat.log')
        ? Storage::disk('local')->get('heartbeat.log')
        : '';

    $lines = array_slice(array_filter(explode("\n", $log)), -30);

    return response(
        'last '.count($lines)." heartbeats (newest last)\n"
        .'server time now: '.now()->toDateTimeString()." UTC\n"
        .str_repeat('-', 60)."\n"
        .implode("\n", $lines)."\n",
        200,
        ['Content-Type' => 'text/plain; charset=utf-8']
    );
});
