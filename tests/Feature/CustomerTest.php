<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::factory()->forBusiness($this->business)->create($attributes);
    }

    /* ================================================================
     | Phone normalisation — what makes the unique index real
     * ================================================================ */

    public function test_phone_is_normalised_on_create(): void
    {
        $customer = $this->customer(['phone' => '07700 900123']);

        $this->assertSame('+447700900123', $customer->fresh()->phone);
    }

    public function test_phone_is_normalised_on_update_too(): void
    {
        // The mutator must fire on every write path, not just inserts — a client
        // editing a customer by hand is the most likely source of a bad number.
        $customer = $this->customer();

        $customer->update(['phone' => '+44 (0)7700 900456']);

        $this->assertSame('+447700900456', $customer->fresh()->phone);
    }

    public function test_whatsapp_number_is_normalised(): void
    {
        $customer = $this->customer(['whatsapp_number' => '07700 900789']);

        $this->assertSame('+447700900789', $customer->fresh()->whatsapp_number);
    }

    public function test_whatsapp_target_falls_back_to_the_main_phone(): void
    {
        $customer = $this->customer(['phone' => '07700900111', 'whatsapp_number' => null]);
        $this->assertSame('+447700900111', $customer->whatsappTarget());

        $customer->update(['whatsapp_number' => '07700900222']);
        $this->assertSame('+447700900222', $customer->fresh()->whatsappTarget());
    }

    /**
     * The whole point of normalising. Without the mutator these two writes create
     * two customer records for one person, and she gets two of every reminder.
     */
    public function test_the_same_number_in_two_formats_cannot_be_saved_twice(): void
    {
        $this->customer(['phone' => '+447700900123']);

        $this->expectException(QueryException::class);

        $this->customer(['phone' => '07700 900123']);
    }

    /**
     * ...but the index is per-business on purpose. Two salons on the platform may
     * legitimately share a customer, and neither may see the other's copy.
     */
    public function test_two_businesses_may_each_hold_the_same_phone_number(): void
    {
        $other = Business::factory()->create();

        $this->customer(['phone' => '+447700900123']);
        Customer::factory()->forBusiness($other)->create(['phone' => '+447700900123']);

        $this->assertSame(2, Customer::withoutGlobalScope('business')
            ->where('phone', '+447700900123')->count());
    }

    /**
     * KNOWN GOTCHA, asserted so it is a decision rather than a surprise in
     * production: a soft-deleted customer still owns their phone number, because
     * the unique index does not know about deleted_at. The customer UI must
     * therefore offer to restore an existing record rather than blindly insert.
     */
    public function test_a_soft_deleted_customer_still_holds_their_phone_number(): void
    {
        $this->customer(['phone' => '+447700900123'])->delete();

        $this->expectException(QueryException::class);

        $this->customer(['phone' => '+447700900123']);
    }

    /* ================================================================
     | Consent — transactional vs marketing
     * ================================================================ */

    /**
     * The distinction that must never be collapsed. Reminding someone about an
     * appointment they booked is lawful without consent (contract). Texting them
     * "20% off, we miss you" is not. One method for both = marketing sent without
     * consent, which under PECR is the ICO's business, not a support ticket.
     */
    public function test_a_customer_without_consent_may_still_get_appointment_reminders(): void
    {
        $customer = $this->customer([
            'marketing_consent' => false,
            'unsubscribed_at' => null,
            'preferred_channel' => 'telegram',
        ]);

        $this->assertTrue($customer->canReceiveTransactional());
        $this->assertFalse($customer->canReceiveMarketing());
    }

    public function test_unsubscribing_stops_marketing_and_transactional_alike(): void
    {
        // An explicit "stop" outranks our lawful basis for transactional sends.
        // Legally we could keep going; practically it produces complaints.
        $customer = $this->customer(['marketing_consent' => true]);

        $customer->unsubscribe();

        $this->assertFalse($customer->canReceiveMarketing());
        $this->assertFalse($customer->canReceiveTransactional());
        $this->assertNotNull($customer->unsubscribed_at);
        $this->assertFalse($customer->marketing_consent);
    }

    public function test_channel_none_silences_everything(): void
    {
        $customer = $this->customer([
            'marketing_consent' => true,
            'preferred_channel' => 'none',
        ]);

        $this->assertFalse($customer->canReceiveTransactional());
        $this->assertFalse($customer->canReceiveMarketing());
    }

    public function test_recording_consent_logs_when_and_where_it_was_given(): void
    {
        // "We have consent" is not defensible without the source and timestamp;
        // the ICO expects you to show how it was obtained.
        $customer = $this->customer(['marketing_consent' => false]);

        $customer->recordConsent('booking_form');

        $this->assertTrue($customer->marketing_consent);
        $this->assertSame('booking_form', $customer->consent_source);
        $this->assertNotNull($customer->consent_at);
    }

    public function test_a_customer_can_opt_back_in_after_unsubscribing(): void
    {
        $customer = $this->customer(['marketing_consent' => true]);
        $customer->unsubscribe();

        $customer->recordConsent('reply_yes');

        $this->assertNull($customer->unsubscribed_at, 'Re-consent must clear the old opt-out.');
        $this->assertTrue($customer->canReceiveMarketing());
    }

    /**
     * The drift bug this guards against: scopeMarketable() picks the recipients
     * and canReceiveMarketing() is the per-record guard before sending. If someone
     * edits one and not the other, the two disagree — and the direction of the
     * disagreement decides whether we send marketing to a customer who never
     * consented. So assert they agree across every combination.
     */
    public function test_marketable_scope_and_can_receive_marketing_never_disagree(): void
    {
        $cases = [];

        foreach ([true, false] as $consent) {
            foreach ([null, now()->subDay()] as $unsubscribed) {
                foreach (['telegram', 'none'] as $channel) {
                    $cases[] = $this->customer([
                        'marketing_consent' => $consent,
                        'unsubscribed_at' => $unsubscribed,
                        'preferred_channel' => $channel,
                    ]);
                }
            }
        }

        $this->assertCount(8, $cases);

        $marketableIds = Customer::marketable()->pluck('id')->all();

        foreach ($cases as $customer) {
            $this->assertSame(
                $customer->canReceiveMarketing(),
                in_array($customer->id, $marketableIds, true),
                sprintf(
                    'Disagreement for consent=%s unsubscribed=%s channel=%s',
                    var_export($customer->marketing_consent, true),
                    $customer->unsubscribed_at === null ? 'null' : 'set',
                    $customer->preferred_channel,
                )
            );
        }

        // Sanity check that the combinations were not all one answer.
        $this->assertCount(1, $marketableIds);
    }

    /* ================================================================
     | Scopes
     * ================================================================ */

    public function test_lapsed_includes_customers_who_have_never_visited(): void
    {
        // A customer with no visits is the most valuable win-back target there is,
        // and whereNull is easy to lose in a refactor.
        $never = $this->customer(['last_visit_at' => null]);
        $old = $this->customer(['last_visit_at' => now()->subDays(120)]);
        $recent = $this->customer(['last_visit_at' => now()->subDays(10)]);

        $lapsed = Customer::lapsed(90)->pluck('id')->all();

        $this->assertContains($never->id, $lapsed);
        $this->assertContains($old->id, $lapsed);
        $this->assertNotContains($recent->id, $lapsed);
    }

    public function test_lapsed_respects_the_day_threshold(): void
    {
        $customer = $this->customer(['last_visit_at' => now()->subDays(100)]);

        $this->assertContains($customer->id, Customer::lapsed(90)->pluck('id')->all());
        $this->assertNotContains($customer->id, Customer::lapsed(200)->pluck('id')->all());
    }

    /**
     * Regression guard for a bug that was in this scope: normalising a name like
     * "Sarah" returns null, which built `phone like '%%'` and matched every
     * customer in the business — so a search for one person returned the whole list.
     */
    public function test_searching_a_name_does_not_match_every_customer(): void
    {
        $sarah = $this->customer(['name' => 'Sarah Whitfield']);
        $this->customer(['name' => 'James Okonkwo']);
        $this->customer(['name' => 'Priya Nair']);

        $results = Customer::search('Sarah')->pluck('id')->all();

        $this->assertSame([$sarah->id], $results);
    }

    public function test_searching_a_phone_number_works_in_any_format(): void
    {
        $customer = $this->customer(['phone' => '+447700900123']);
        $this->customer(['phone' => '+447700900999']);

        foreach (['07700 900123', '+447700900123', '447700900123'] as $term) {
            $this->assertSame(
                [$customer->id],
                Customer::search($term)->pluck('id')->all(),
                "Failed searching for: {$term}"
            );
        }
    }

    public function test_an_empty_search_returns_everyone(): void
    {
        $this->customer();
        $this->customer();

        $this->assertSame(2, Customer::search(null)->count());
        $this->assertSame(2, Customer::search('')->count());
    }

    /* ================================================================
     | Telegram linking
     * ================================================================ */

    public function test_telegram_link_token_is_generated_once_and_kept(): void
    {
        $customer = $this->customer();

        $first = $customer->ensureTelegramLinkToken();
        $second = $customer->ensureTelegramLinkToken();

        // Must be stable: the token goes into a QR code the salon may have printed.
        $this->assertSame($first, $second);
        $this->assertSame($first, $customer->fresh()->telegram_link_token);
        $this->assertSame(24, strlen($first));
    }

    /**
     * The token must be globally unique, not unique per business: the Telegram
     * webhook arrives with only the token, no tenant context, so a collision
     * across two businesses would attach a chat to the wrong salon's customer.
     */
    public function test_telegram_link_tokens_are_unique_across_businesses(): void
    {
        $other = Business::factory()->create();
        $token = $this->customer()->ensureTelegramLinkToken();

        $this->expectException(QueryException::class);

        Customer::factory()->forBusiness($other)->create(['telegram_link_token' => $token]);
    }

    public function test_telegram_linked_reflects_whether_the_chat_exists(): void
    {
        // Telegram will not let a bot open a conversation, so an unlinked customer
        // is unreachable on that channel no matter what preferred_channel says.
        $this->assertFalse($this->customer(['telegram_chat_id' => null])->telegramLinked());
        $this->assertTrue($this->customer(['telegram_chat_id' => '123456789'])->telegramLinked());
    }
}
