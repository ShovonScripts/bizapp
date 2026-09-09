<?php

namespace App\Messaging\Contracts;

use App\Messaging\SendResult;
use App\Models\Customer;

/**
 * One channel's ability to deliver a message for one business.
 *
 * A driver instance is bound to a business — MessagingManager builds it that way —
 * so nothing downstream has to remember to pass the right credentials alongside
 * the right recipient. Getting those two out of step would mean one salon's bot
 * messaging another salon's customer, which is the worst failure this system has.
 *
 * Drivers do not know about scheduled_messages, retries or quiet hours. They take
 * a recipient and a finished string, and report what happened. That keeps them
 * usable for the "send a test message" button as well as the queue.
 */
interface MessageDriver
{
    /** whatsapp | telegram | email | sms | log */
    public function channel(): string;

    /**
     * Are the credentials present for this business?
     *
     * Cheap and local — no network call. The planner asks this for every message
     * it considers, so hitting the provider here would turn one cron run into
     * hundreds of HTTP requests.
     */
    public function isConfigured(): bool;

    /**
     * @param  string  $body  Already rendered. Drivers must not template.
     */
    public function send(Customer $customer, string $body): SendResult;
}
