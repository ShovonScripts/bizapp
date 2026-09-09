<?php

namespace App\Messaging\Drivers;

use App\Messaging\Contracts\MessageDriver;
use App\Messaging\SendResult;
use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pretends to send, writes to the log instead.
 *
 * Used everywhere MESSAGING_DRIVER=log is set: the whole test suite, and local
 * development until the moment you deliberately point it at a real bot. It is the
 * thing standing between a seeded database of fifty fictional customers and fifty
 * real phones, so it is intentionally boring and cannot fail.
 *
 * It still returns a provider message id, because code that only ever sees a null
 * id in development will happily do the wrong thing with a real one.
 */
class LogDriver implements MessageDriver
{
    public function __construct(
        protected Business $business,
        protected string $pretendingToBe = 'log',
    ) {}

    public function channel(): string
    {
        return $this->pretendingToBe;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Customer $customer, string $body): SendResult
    {
        $id = 'log_'.Str::lower(Str::random(16));

        Log::channel(config('logging.default'))->info('[messaging] pretend send', [
            'business' => $this->business->name,
            'business_id' => $this->business->id,
            'channel' => $this->pretendingToBe,
            'customer_id' => $customer->id,
            'customer' => $customer->name,

            // Not the phone number or chat id. Application logs get copied into
            // support tickets and pasted into chat windows; the message text is
            // enough to debug a template, and the contact details are the part
            // that turns a log file into a personal-data incident.
            'body' => $body,
            'provider_message_id' => $id,
        ]);

        return SendResult::sent($id);
    }
}
