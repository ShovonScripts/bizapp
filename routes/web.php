<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';

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
        "last ".count($lines)." heartbeats (newest last)\n"
        ."server time now: ".now()->toDateTimeString()." UTC\n"
        .str_repeat('-', 60)."\n"
        .implode("\n", $lines)."\n",
        200,
        ['Content-Type' => 'text/plain; charset=utf-8']
    );
});
