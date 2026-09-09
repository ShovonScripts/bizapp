<?php

namespace Tests\Feature;

use App\Messaging\Telegram\UpdateHandler;
use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Linking is the one place a stranger can reach our data.
 *
 * Everything else in this application sits behind a login and a tenant scope. This
 * does not: an update arrives from Telegram carrying a chat id and a token, with no
 * user, no session and no business. Get it wrong and someone else's name, salon and
 * appointment times start arriving on the wrong phone — a reportable breach under
 * UK GDPR, not a bug report.
 *
 * Http::fake() is on for every test here. The suite already blanks the bot token in
 * phpunit.xml, so a missed fake could not send anything real, but a test that waits
 * on a network timeout is a test people stop running.
 */
class TelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $sarah;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();
        Http::fake();

        // Set here rather than relied on from phpunit.xml, so these tests keep
        // meaning what they say if that file is edited.
        config()->set('messaging.telegram.webhook_secret', 'testing-webhook-secret');

        $this->salon = Business::factory()->create([
            'name' => 'Bright Hair Studio',
            'slug' => 'bright-hair',
        ]);

        $this->sarah = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Sarah Khan',
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->token = $this->sarah->ensureTelegramLinkToken();
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    /* ------------------------------- Helpers ----------------------------- */

    protected function update(string $text, int|string $chatId = 500100): array
    {
        return ['message' => ['chat' => ['id' => $chatId], 'text' => $text]];
    }

    protected function handle(string $text, int|string $chatId = 500100): ?array
    {
        return (new UpdateHandler)->handle($this->update($text, $chatId));
    }

    protected function webhook(array $payload, ?string $secret = 'testing-webhook-secret')
    {
        return $this->postJson(
            route('telegram.webhook'),
            $payload,
            $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret],
        );
    }

    /* ------------------------------ Linking ------------------------------ */

    public function test_start_with_a_valid_token_links_the_chat(): void
    {
        $reply = $this->handle("/start {$this->token}");

        $this->sarah->refresh();

        $this->assertSame('500100', (string) $this->sarah->telegram_chat_id);
        $this->assertSame('telegram', $this->sarah->preferred_channel);

        $this->assertSame(500100, $reply['chat_id']);
        $this->assertStringContainsString('all set', $reply['text']);
        $this->assertStringContainsString('Bright Hair Studio', $reply['text']);

        // The opt-out has to be discoverable without asking anyone.
        $this->assertStringContainsString('/stop', $reply['text']);
    }

    /**
     * They arrived through a Telegram link, so Telegram is where they want their
     * reminders — switching the channel is the whole point of the exercise.
     */
    public function test_linking_moves_the_customer_onto_telegram(): void
    {
        $this->sarah->forceFill(['preferred_channel' => 'sms'])->save();

        $this->handle("/start {$this->token}");

        $this->assertSame('telegram', $this->sarah->fresh()->preferred_channel);
    }

    /**
     * The lookup must ignore the tenant scope entirely.
     *
     * An update arrives with no business attached, so the token is the only thing
     * identifying anyone. If a stray tenant were left set — by a queued job, a
     * previous request, anything — a scoped lookup would silently fail to find a
     * perfectly valid customer and tell them their link had expired.
     */
    public function test_the_token_is_found_regardless_of_which_tenant_is_current(): void
    {
        $other = Business::factory()->create(['name' => 'Iron Works Gym', 'slug' => 'iron-works']);

        Tenant::set($other->id);

        $this->handle("/start {$this->token}");

        $this->assertNotNull($this->sarah->fresh()->telegram_chat_id);
    }

    public function test_start_with_no_token_explains_what_is_needed(): void
    {
        $reply = $this->handle('/start');

        $this->assertStringContainsString('personal link', $reply['text']);
        $this->assertNull($this->sarah->fresh()->telegram_chat_id);
    }

    /**
     * A stale link, a typo and a deleted customer all get the same answer. Telling
     * a stranger which one it was confirms whether that token ever existed, and a
     * token is the only thing standing between them and someone else's reminders.
     */
    public function test_an_unknown_token_gives_nothing_away(): void
    {
        $reply = $this->handle('/start not-a-real-token');

        $this->assertStringContainsString("isn't valid any more", $reply['text']);
        $this->assertStringNotContainsString('Sarah', $reply['text']);
        $this->assertStringNotContainsString('Bright Hair Studio', $reply['text']);
        $this->assertNull($this->sarah->fresh()->telegram_chat_id);
    }

    /* -------------------------- Forwarded links -------------------------- */

    /**
     * The common case, not an attack: someone taps their own link twice, or comes
     * back to the chat months later and presses start again.
     */
    public function test_the_same_chat_tapping_twice_is_treated_kindly(): void
    {
        $this->handle("/start {$this->token}");
        $reply = $this->handle("/start {$this->token}");

        $this->assertStringContainsString('already set up', $reply['text']);
        $this->assertSame('500100', (string) $this->sarah->fresh()->telegram_chat_id);
    }

    /**
     * The attack, and the accident.
     *
     * The owner sends the link by WhatsApp and it gets forwarded into a family
     * group. Whoever taps it next must not end up receiving Sarah's name, her
     * salon and her appointment times — so a used token stays with the chat that
     * used it, and the refusal says nothing about who it belonged to.
     */
    public function test_a_forwarded_link_cannot_hijack_someone_elses_reminders(): void
    {
        $this->handle("/start {$this->token}", chatId: 500100);

        $reply = $this->handle("/start {$this->token}", chatId: 999888);

        $this->assertStringContainsString('already been used', $reply['text']);
        $this->assertStringNotContainsString('Sarah', $reply['text']);
        $this->assertStringNotContainsString('Bright Hair Studio', $reply['text']);

        $this->assertSame(
            '500100',
            (string) $this->sarah->fresh()->telegram_chat_id,
            'The original chat must keep the link.'
        );
    }

    /* ------------------------------ Consent ------------------------------ */

    /**
     * Tapping a link is not a withdrawal of an opt-out.
     *
     * Someone who unsubscribed made an explicit decision. Reading a tap on a link
     * as re-consent is precisely the inferred permission PECR does not accept — so
     * the chat is linked, the position is stated plainly, and the switch stays
     * exactly where they left it.
     */
    public function test_starting_the_bot_does_not_undo_an_unsubscribe(): void
    {
        $this->sarah->unsubscribe();

        $reply = $this->handle("/start {$this->token}");

        $this->sarah->refresh();

        $this->assertNotNull($this->sarah->telegram_chat_id, 'The chat should still be linked.');
        $this->assertNotNull($this->sarah->unsubscribed_at, 'But the opt-out must survive.');
        $this->assertFalse($this->sarah->canReceiveTransactional());

        $this->assertStringContainsString('switched off', $reply['text']);
        $this->assertStringContainsString('Bright Hair Studio', $reply['text']);
    }

    public function test_a_customer_with_reminders_switched_off_is_linked_but_left_off(): void
    {
        $this->sarah->forceFill(['preferred_channel' => 'none'])->save();

        $reply = $this->handle("/start {$this->token}");

        $this->sarah->refresh();

        $this->assertNotNull($this->sarah->telegram_chat_id);
        $this->assertSame('none', $this->sarah->preferred_channel);
        $this->assertStringContainsString('switched off', $reply['text']);
    }

    /* ------------------------------- Opt-out ----------------------------- */

    /**
     * "Stop" from a person means stop, not stop-from-one-of-you.
     *
     * One phone can belong to a customer of two businesses on the same bot. Over-
     * honouring the opt-out costs somebody a reminder; under-honouring it is a
     * PECR breach and a complaint from someone who did the right thing.
     */
    public function test_stop_unsubscribes_every_record_on_that_chat(): void
    {
        $gym = Business::factory()->create(['name' => 'Iron Works Gym', 'slug' => 'iron-works']);

        $sameperson = Customer::factory()->forBusiness($gym)->create([
            'name' => 'Sarah Khan',
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => '500100',
        ]);

        $this->handle("/start {$this->token}");

        $reply = $this->handle('/stop');

        $this->assertStringContainsString("won't get any more messages", $reply['text']);

        $this->assertNotNull($this->sarah->fresh()->unsubscribed_at);
        $this->assertNotNull($sameperson->fresh()->unsubscribed_at, 'The other salon must stop too.');
    }

    public function test_stop_from_a_chat_we_do_not_know_is_answered_politely(): void
    {
        $reply = $this->handle('/stop', chatId: 123456);

        $this->assertStringContainsString('not receiving any reminders', $reply['text']);
    }

    /* ------------------------- Everything else --------------------------- */

    public function test_any_other_message_gets_a_short_explanation(): void
    {
        $reply = $this->handle('hello?');

        $this->assertStringContainsString('appointment reminders', $reply['text']);
        $this->assertStringContainsString('/stop', $reply['text']);
    }

    public function test_an_update_with_nothing_in_it_is_ignored(): void
    {
        $this->assertNull((new UpdateHandler)->handle([]));
        $this->assertNull((new UpdateHandler)->handle(['message' => ['chat' => ['id' => 1]]]));

        // A photo, a sticker, a joined-the-group event: all real updates with no
        // text. Returning null rather than the fallback avoids answering an event
        // the customer did not send.
        $this->assertNull((new UpdateHandler)->handle($this->update('   ')));
    }

    /* ------------------------------ Webhook ------------------------------ */

    public function test_the_webhook_links_a_customer_and_replies(): void
    {
        $this->webhook($this->update("/start {$this->token}"))->assertOk();

        $this->assertNotNull($this->sarah->fresh()->telegram_chat_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && (string) $request['chat_id'] === '500100'
                && str_contains($request['text'], 'all set');
        });
    }

    /**
     * Without the secret check, anyone could post
     * {"message":{"text":"/start SOMEONE_ELSES_TOKEN","chat":{"id":123}}}
     * and attach their own Telegram account to another salon's customer.
     */
    public function test_a_bad_secret_is_refused_and_changes_nothing(): void
    {
        $this->webhook($this->update("/start {$this->token}"), secret: 'wrong')
            ->assertForbidden();

        $this->assertNull($this->sarah->fresh()->telegram_chat_id);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ Not tested here, and it cannot be: CSRF verification.
     *
     * Telegram posts with no session and no token, so `telegram/webhook` is in the
     * exemption list in bootstrap/app.php. Laravel's CSRF middleware skips itself
     * whenever it detects a test runner, which means a test asserting "no 419" would
     * pass just as happily with the exemption deleted — false confidence is worse
     * than none. The exemption has to be checked by reading bootstrap/app.php, and
     * by the first real delivery landing on the server.
     */
    public function test_a_missing_secret_header_is_refused(): void
    {
        $this->webhook($this->update("/start {$this->token}"), secret: null)
            ->assertForbidden();

        $this->assertNull($this->sarah->fresh()->telegram_chat_id);
    }

    /**
     * An endpoint nobody configured should not confirm that it exists.
     */
    public function test_the_webhook_is_invisible_when_no_secret_is_configured(): void
    {
        config()->set('messaging.telegram.webhook_secret', '');

        $this->webhook($this->update("/start {$this->token}"), secret: null)
            ->assertNotFound();

        $this->assertNull($this->sarah->fresh()->telegram_chat_id);
    }

    /**
     * Telegram disables a webhook that keeps failing, so one malformed update must
     * never be allowed to switch off reminders for every business on the platform.
     */
    public function test_an_update_it_cannot_understand_still_returns_200(): void
    {
        $this->webhook(['nonsense' => true])->assertOk();

        Http::assertNothingSent();
    }
}
