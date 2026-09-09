<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenant;
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

        /* Tenant is static state and PHPUnit shares one process, so a business left
           set by an earlier test file would scope every query here to the wrong
           business — and a test that counts rows would then quietly find none. */
        Tenant::forget();

        $this->business = Business::factory()->create();
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
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
     | Can we actually reach them?
     |
     | A separate question from consent, and the one the dashboard and the
     | reminder engine both hang off. "5 reminders tomorrow" has to mean five
     | messages that will arrive, or the owner finds out from the client.
     * ================================================================ */

    /**
     * The trap this method exists for.
     *
     * A customer who prefers Telegram and has a perfectly good mobile number is
     * still unreachable until they start the bot — Telegram will not let us message
     * someone who hasn't. A "do they have a phone number?" check calls this person
     * contactable, promises a reminder, and sends nothing.
     */
    public function test_a_telegram_customer_with_a_phone_but_no_chat_is_not_reachable(): void
    {
        $customer = $this->customer([
            'preferred_channel' => 'telegram',
            'phone' => '+447700900123',
            'telegram_chat_id' => null,
        ]);

        $this->assertFalse($customer->hasContactRoute());
        $this->assertSame('Telegram not linked yet', $customer->unreachableReason());

        // Same person, same number, once they have started the bot.
        $customer->update(['telegram_chat_id' => '600000001']);

        $this->assertTrue($customer->fresh()->hasContactRoute());
        $this->assertNull($customer->fresh()->unreachableReason());
    }

    public function test_a_whatsapp_customer_falls_back_to_their_main_number(): void
    {
        $onMainNumber = $this->customer([
            'preferred_channel' => 'whatsapp',
            'phone' => '+447700900201',
            'whatsapp_number' => null,
        ]);

        $onSeparateNumber = $this->customer([
            'preferred_channel' => 'whatsapp',
            'phone' => null,
            'whatsapp_number' => '+447700900202',
        ]);

        $this->assertTrue($onMainNumber->hasContactRoute());
        $this->assertTrue($onSeparateNumber->hasContactRoute());
    }

    /**
     * The reasons, in priority order.
     *
     * "Asked us to stop" has to win over everything below it, because everything
     * below it is a gap for the owner to fill in and that one is a decision the
     * customer made. Get the order wrong and the screen invites them to "fix" the
     * details of someone who opted out.
     */
    public function test_unreachable_reason_names_the_thing_to_fix(): void
    {
        $optedOut = $this->customer([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => '600000002',
            'unsubscribed_at' => now()->subDay(),
        ]);

        $this->assertSame('asked us to stop', $optedOut->unreachableReason());

        $this->assertSame(
            'reminders turned off',
            $this->customer(['preferred_channel' => 'none'])->unreachableReason()
        );

        $this->assertSame(
            'no mobile number',
            $this->customer(['preferred_channel' => 'sms', 'phone' => null])->unreachableReason()
        );

        $this->assertSame(
            'no email address',
            $this->customer(['preferred_channel' => 'email', 'email' => null])->unreachableReason()
        );

        $this->assertNull(
            $this->customer(['preferred_channel' => 'sms', 'phone' => '+447700900203'])->unreachableReason()
        );
    }

    /**
     * The PHP check and the SQL scope must agree, always.
     *
     * Same reasoning as marketable() above: the dashboard counts with the scope
     * because loading every customer to count them does not scale, and the sender
     * decides with the method because it has the row in hand. The moment they
     * disagree, the page promises a number of messages the dispatcher then refuses
     * to send — and nobody would think to look here for the cause.
     *
     * The unsubscribed dimension is in the matrix even though neither the method
     * nor the scope looks at it, because BusinessSnapshot::tomorrow() relies on
     * "unreachableReason() === null" meaning exactly "may be messaged AND can be
     * messaged", and that equivalence is asserted here too.
     */
    public function test_with_contact_route_scope_and_has_contact_route_never_disagree(): void
    {
        // Every value the preferred_channel column is allowed to hold.
        $channels = ['whatsapp', 'telegram', 'email', 'sms', 'none'];

        $cases = [];
        $i = 0;

        foreach ($channels as $channel) {
            foreach ([true, false] as $hasPhone) {
                foreach ([true, false] as $hasEmail) {
                    foreach ([true, false] as $hasTelegram) {
                        foreach ([null, now()->subDay()] as $unsubscribed) {
                            $i++;

                            $cases[] = $this->customer([
                                'preferred_channel' => $channel,
                                /* Distinct per row: (business_id, phone) really is a
                                   unique index, so a repeat would fail the insert
                                   rather than the assertion. Ofcom's reserved
                                   07700 900xxx drama range, as everywhere else. */
                                'phone' => $hasPhone
                                    ? '+44770090'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)
                                    : null,
                                'email' => $hasEmail ? "route{$i}@example.test" : null,
                                'telegram_chat_id' => $hasTelegram ? (string) (600000100 + $i) : null,
                                'whatsapp_number' => null,
                                'unsubscribed_at' => $unsubscribed,
                            ]);
                        }
                    }
                }
            }
        }

        $this->assertCount(80, $cases);

        $withRoute = Customer::withContactRoute()->pluck('id')->all();

        foreach ($cases as $customer) {
            $describe = sprintf(
                'channel=%s phone=%s email=%s telegram=%s unsubscribed=%s',
                $customer->preferred_channel,
                $customer->phone ?? 'null',
                $customer->email ?? 'null',
                $customer->telegram_chat_id ?? 'null',
                $customer->unsubscribed_at === null ? 'null' : 'set',
            );

            $this->assertSame(
                $customer->hasContactRoute(),
                in_array($customer->id, $withRoute, true),
                "Scope and method disagree for {$describe}"
            );

            $this->assertSame(
                $customer->canReceiveTransactional() && $customer->hasContactRoute(),
                $customer->unreachableReason() === null,
                "unreachableReason() disagrees with the two checks it stands for, for {$describe}"
            );
        }

        // Sanity check that the matrix did not come out all one answer, which would
        // make both assertions above pass while proving nothing.
        $this->assertNotEmpty($withRoute);
        $this->assertLessThan(count($cases), count($withRoute));
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
