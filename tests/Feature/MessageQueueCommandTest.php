<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The outbox viewer.
 *
 * Small surface, but it is the thing someone reaches for when a customer says
 * they never got their reminder — so the two properties worth pinning down are
 * that it answers the question (the skip reason is visible) and that it does not
 * leak a chat id into a terminal someone will screenshot.
 */
class MessageQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();

        $this->salon = Business::factory()->create([
            'name' => 'Bright Hair Studio',
            'slug' => 'bright-hair',
            'timezone' => 'Europe/London',
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::factory()
            ->forBusiness($this->salon)
            ->telegramLinked()
            ->create(array_merge(['name' => 'Sarah Khan'], $attributes));
    }

    public function test_an_empty_outbox_says_what_to_do_next(): void
    {
        $this->artisan('messages:queue')
            ->expectsOutputToContain('messages:plan')
            ->assertSuccessful();
    }

    public function test_it_lists_a_queued_message(): void
    {
        ScheduledMessage::factory()->forCustomer($this->customer())->create();

        $this->withoutMockingConsoleOutput();

        \Artisan::call('messages:queue');
        $output = \Artisan::output();

        $this->assertStringContainsString('Sarah Khan', $output);
        $this->assertStringContainsString('Bright Hair Studio', $output);
    }

    /**
     * The reason a message was not sent is the entire point of the skipped status.
     * A queue that shows "skipped" without saying why is no better than silence.
     */
    public function test_a_skip_reason_is_visible(): void
    {
        $message = ScheduledMessage::factory()->forCustomer($this->customer())->create();
        $message->skip('Telegram not linked yet.');

        $this->artisan('messages:queue')
            ->expectsOutputToContain('Telegram not linked yet.')
            ->assertSuccessful();
    }

    /**
     * Console output gets pasted into chat threads and pull requests, and a
     * Telegram chat id is enough to message somebody.
     */
    public function test_it_never_prints_a_chat_id(): void
    {
        $customer = $this->customer(['telegram_chat_id' => '777123456']);

        ScheduledMessage::factory()->forCustomer($customer)->create();

        $this->artisan('messages:queue')
            ->doesntExpectOutputToContain('777123456')
            ->assertSuccessful();

        $this->artisan('messages:queue', [
            '--body' => (string) ScheduledMessage::query()->sole()->id,
        ])->doesntExpectOutputToContain('777123456')->assertSuccessful();
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        ScheduledMessage::factory()->forCustomer($this->customer())->create();

        $this->artisan('messages:queue', ['--status' => 'sent'])
            ->expectsOutputToContain('Nothing in the outbox with that status.')
            ->assertSuccessful();
    }

    /**
     * The body is rendered at plan time, so what this prints is byte-for-byte what
     * the customer receives — which is the only reason printing it is useful.
     */
    public function test_it_prints_the_exact_text_that_will_be_sent(): void
    {
        $message = ScheduledMessage::factory()
            ->forCustomer($this->customer())
            ->create(['payload' => ['body' => 'Hi Sarah Khan, see you tomorrow at 2:00pm.']]);

        $this->artisan('messages:queue', ['--body' => (string) $message->id])
            ->expectsOutputToContain('Hi Sarah Khan, see you tomorrow at 2:00pm.')
            ->assertSuccessful();
    }

    public function test_asking_for_a_message_that_is_not_there_fails_loudly(): void
    {
        $this->artisan('messages:queue', ['--body' => '4040'])
            ->expectsOutputToContain('No message with id 4040.')
            ->assertFailed();
    }
}
