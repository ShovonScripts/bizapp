<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The customers screen is the first place a client's real data is typed in by hand,
 * so it is where bad data and cross-tenant mistakes get in. These tests cover the
 * three things that actually hurt in production: a duplicate customer receiving two
 * of every reminder, a number saved in a format that silently fails to send, and
 * one business touching another's records.
 */
class CustomerScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;
    protected Business $gym;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant is static state and PHPUnit shares one process across tests.
        Tenant::forget();

        $this->salon = Business::factory()->create(['name' => 'Salon', 'slug' => 'salon']);
        $this->gym = Business::factory()->create(['name' => 'Gym', 'slug' => 'gym']);

        $this->owner = User::factory()->create(['business_id' => $this->salon->id]);
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::factory()->forBusiness($this->salon)->create($attributes);
    }

    /** Fill the add-customer form with valid values, overriding what the test cares about. */
    protected function form(array $fields = [])
    {
        $component = Volt::test('customers.index');

        foreach (array_merge(['name' => 'Sarah Whitfield', 'phone' => '07700 900123'], $fields) as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    /* ================================================================
     | Access
     * ================================================================ */

    public function test_a_guest_cannot_reach_the_customers_screen(): void
    {
        // Tenant::id() reads the logged-in user, so a guest resolves to null —
        // which the global scope reads as "unscoped", i.e. every client's data.
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_a_super_admin_is_locked_out_rather_than_shown_every_client(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/customers')->assertForbidden();
    }

    public function test_an_owner_sees_only_their_own_businesss_customers(): void
    {
        $this->customer(['name' => 'Salon Customer']);
        Customer::factory()->forBusiness($this->gym)->create(['name' => 'GYM-ONLY Customer']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->assertSee('Salon Customer')
            ->assertDontSee('GYM-ONLY Customer');
    }

    /* ================================================================
     | Creating
     * ================================================================ */

    public function test_creating_a_customer_attaches_them_to_the_current_business(): void
    {
        $this->actingAs($this->owner);

        $this->form()->call('save')->assertHasNoErrors();

        $customer = Customer::firstOrFail();

        // business_id must never come from the form — it is filled by the trait.
        $this->assertSame($this->salon->id, $customer->business_id);
        $this->assertSame('Sarah Whitfield', $customer->name);
    }

    public function test_a_number_typed_the_normal_way_is_stored_as_e164(): void
    {
        $this->actingAs($this->owner);

        $this->form(['phone' => '07700 900123'])->call('save')->assertHasNoErrors();

        // Anything else and WhatsApp rejects the send months from now, when nobody
        // will connect the missed reminder back to this form.
        $this->assertSame('+447700900123', Customer::firstOrFail()->phone);
    }

    /**
     * The bug this screen exists to prevent.
     *
     * The column stores E.164, so validating the raw "07700 900123" finds no match
     * against the stored "+447700900123" — validation passes, then the database's
     * unique index throws and the client gets a 500 page. The fix is normalising
     * before validating; this test is what proves the order never gets swapped back.
     */
    public function test_the_same_number_in_a_different_format_is_a_form_error_not_a_crash(): void
    {
        $this->customer(['phone' => '+447700900123']);

        $this->actingAs($this->owner);

        $this->form(['phone' => '07700 900123'])
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertSame(1, Customer::count());
    }

    /**
     * ...but the index is per-business on purpose. Two salons may legitimately share
     * a customer, and being told "already taken" would leak that the number exists
     * somewhere else on the platform.
     */
    public function test_another_business_holding_the_same_number_does_not_block_us(): void
    {
        Customer::factory()->forBusiness($this->gym)->create(['phone' => '+447700900123']);

        $this->actingAs($this->owner);

        $this->form(['phone' => '07700 900123'])->call('save')->assertHasNoErrors();

        $this->assertSame(1, Customer::count());
    }

    /**
     * A returning customer. Their row was soft-deleted but still owns the phone
     * number as far as the unique index is concerned, so a naive implementation
     * shows "this number is taken" pointing at a customer the owner cannot see —
     * a dead end. Restoring is both the friendlier answer and the correct one,
     * because it reattaches their appointment history and spend.
     */
    public function test_re_adding_a_removed_customer_restores_their_history(): void
    {
        $original = $this->customer([
            'phone' => '+447700900123',
            'name' => 'Sarah Whitfield',
            'total_spend' => 240.00,
            'last_visit_at' => now()->subMonths(8),
        ]);
        $original->delete();

        $this->actingAs($this->owner);

        $this->form(['phone' => '07700 900123'])->call('save')->assertHasNoErrors();

        $restored = Customer::where('phone', '+447700900123')->firstOrFail();

        $this->assertSame($original->id, $restored->id, 'A second row would split their history in two.');
        $this->assertNull($restored->deleted_at);
        $this->assertSame('240.00', $restored->total_spend);
        $this->assertNotNull($restored->last_visit_at);
        $this->assertSame(1, Customer::count());
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->owner);

        $this->form(['name' => ''])->call('save')->assertHasErrors(['name' => 'required']);

        $this->assertSame(0, Customer::count());
    }

    /* ================================================================
     | Phones we cannot use
     * ================================================================ */

    public function test_text_that_is_not_a_phone_number_is_rejected(): void
    {
        $this->actingAs($this->owner);

        $component = $this->form(['phone' => 'ask her next time'])
            ->call('save')
            ->assertHasErrors('phone');

        // The unusable text stays in the box: an error pointing at an empty field
        // reads like a glitch, and the owner cannot tell what we objected to.
        $component->assertSet('phone', 'ask her next time');

        $this->assertSame(0, Customer::count());
    }

    public function test_a_customer_can_be_saved_with_no_phone_at_all(): void
    {
        // Walk-ins happen. The column must end up NULL, not ''.
        $this->actingAs($this->owner);

        $this->form(['phone' => '', 'preferred_channel' => 'none'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Customer::firstOrFail()->phone);
    }

    /**
     * Guards the reason $phone defaults to null instead of ''. A unique index allows
     * many NULLs but only one empty string, so storing '' would make the *second*
     * phoneless customer a database error — the kind of bug that only appears once
     * a client is really using the thing.
     */
    public function test_two_customers_without_a_phone_do_not_collide(): void
    {
        $this->actingAs($this->owner);

        $this->form(['name' => 'Walk In One', 'phone' => '', 'preferred_channel' => 'none'])
            ->call('save')->assertHasNoErrors();

        $this->form(['name' => 'Walk In Two', 'phone' => '', 'preferred_channel' => 'none'])
            ->call('save')->assertHasNoErrors();

        $this->assertSame(2, Customer::count());
    }

    /* ================================================================
     | Consent
     * ================================================================ */

    public function test_ticking_the_consent_box_records_when_and_how_it_was_given(): void
    {
        $this->actingAs($this->owner);

        $this->form(['marketing_consent' => true])->call('save')->assertHasNoErrors();

        $customer = Customer::firstOrFail();

        // "We had consent" is not defensible without the evidence alongside it.
        $this->assertTrue($customer->marketing_consent);
        $this->assertSame('staff_entry', $customer->consent_source);
        $this->assertNotNull($customer->consent_at);
        $this->assertTrue($customer->canReceiveMarketing());
    }

    public function test_leaving_consent_unticked_still_allows_appointment_reminders(): void
    {
        // The distinction that must never be collapsed: reminding someone about an
        // appointment they booked is lawful without consent; marketing is not.
        $this->actingAs($this->owner);

        $this->form(['marketing_consent' => false])->call('save')->assertHasNoErrors();

        $customer = Customer::firstOrFail();

        $this->assertTrue($customer->canReceiveTransactional());
        $this->assertFalse($customer->canReceiveMarketing());
        $this->assertNull($customer->consent_at);
    }

    /**
     * Unticking the box is not the same event as the customer asking us to stop.
     * If it set unsubscribed_at, it would also silence appointment reminders — so a
     * client tidying up their marketing list would quietly stop the reminders they
     * are paying us for, and nobody would notice until a customer missed a slot.
     */
    public function test_unticking_consent_does_not_silence_appointment_reminders(): void
    {
        $customer = $this->customer(['phone' => '+447700900123']);
        $customer->recordConsent('booking_form');

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('edit', $customer->id)
            ->assertSet('marketing_consent', true)
            ->set('marketing_consent', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer->refresh();

        $this->assertFalse($customer->marketing_consent);
        $this->assertNull($customer->unsubscribed_at, 'An owner unticking a box is not an opt-out request.');
        $this->assertTrue($customer->canReceiveTransactional());
    }

    public function test_an_existing_customers_consent_timestamp_is_not_rewritten_on_every_save(): void
    {
        $customer = $this->customer(['phone' => '+447700900123']);
        $customer->recordConsent('booking_form');

        $originalConsentAt = $customer->fresh()->consent_at;
        $this->travel(2)->days();

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('edit', $customer->id)
            ->set('notes', 'Prefers mornings')
            ->call('save')
            ->assertHasNoErrors();

        // Re-stamping consent on an unrelated edit would destroy the audit trail:
        // the record would claim consent was given the day someone fixed a typo.
        $this->assertEquals($originalConsentAt, $customer->fresh()->consent_at);
        $this->assertSame('booking_form', $customer->fresh()->consent_source);
    }

    /* ================================================================
     | Editing and removing — cross-tenant guards
     * ================================================================ */

    public function test_editing_updates_rather_than_duplicating(): void
    {
        $customer = $this->customer(['name' => 'Sara Whitfield', 'phone' => '+447700900123']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('edit', $customer->id)
            ->set('name', 'Sarah Whitfield')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Customer::count());
        $this->assertSame('Sarah Whitfield', $customer->fresh()->name);
    }

    /** Keeping your own number while editing must not trip the unique rule. */
    public function test_saving_an_edit_without_changing_the_phone_is_allowed(): void
    {
        $customer = $this->customer(['phone' => '+447700900123']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('edit', $customer->id)
            ->set('notes', 'Allergic to ammonia')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Allergic to ammonia', $customer->fresh()->notes);
    }

    /**
     * The URL-tampering case. The id is passed straight from the browser, so if the
     * global scope ever stops applying to find(), changing this number would open
     * another salon's customer — a reportable breach, not a bug.
     */
    public function test_editing_another_businesss_customer_is_impossible(): void
    {
        $theirs = Customer::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('customers.index')->call('edit', $theirs->id);
    }

    public function test_deleting_another_businesss_customer_is_impossible(): void
    {
        $theirs = Customer::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('customers.index')->call('delete', $theirs->id);
    }

    public function test_removing_a_customer_keeps_the_row_so_history_survives(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->owner);

        Volt::test('customers.index')->call('delete', $customer->id);

        // Hard-deleting would rewrite last month's revenue and no-show figures.
        $this->assertSoftDeleted($customer);
    }

    /* ================================================================
     | Search and filters
     * ================================================================ */

    public function test_searching_narrows_the_list(): void
    {
        $this->customer(['name' => 'Sarah Whitfield']);
        $this->customer(['name' => 'James Okonkwo']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->set('search', 'Sarah')
            ->assertSee('Sarah Whitfield')
            ->assertDontSee('James Okonkwo');
    }

    public function test_searching_a_phone_number_in_national_format_finds_the_stored_e164(): void
    {
        $this->customer(['name' => 'Sarah Whitfield', 'phone' => '+447700900123']);
        $this->customer(['name' => 'James Okonkwo', 'phone' => '+447700900999']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->set('search', '07700 900123')
            ->assertSee('Sarah Whitfield')
            ->assertDontSee('James Okonkwo');
    }

    public function test_the_lapsed_filter_shows_win_back_candidates_only(): void
    {
        $this->customer(['name' => 'Sarah Whitfield', 'last_visit_at' => now()->subDays(200)]);
        $this->customer(['name' => 'James Okonkwo', 'last_visit_at' => now()->subDays(5)]);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->set('filter', 'lapsed')
            ->assertSee('Sarah Whitfield')
            ->assertDontSee('James Okonkwo');
    }

    public function test_the_marketable_filter_excludes_customers_who_never_consented(): void
    {
        // Getting this backwards is how marketing goes to someone who never agreed.
        $consented = $this->customer(['name' => 'Sarah Whitfield']);
        $consented->recordConsent('booking_form');

        $this->customer(['name' => 'James Okonkwo', 'marketing_consent' => false]);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->set('filter', 'marketable')
            ->assertSee('Sarah Whitfield')
            ->assertDontSee('James Okonkwo');
    }

    public function test_changing_the_search_returns_to_the_first_page(): void
    {
        // Without resetPage() a search from page 2 shows page 2 of the new results,
        // which is usually empty — and reads as "no customers found".
        // Livewire 3 keeps the page in $paginators, not a $page property.
        Customer::factory()->count(20)->forBusiness($this->salon)->create();

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('setPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('search', 'Sarah')
            ->assertSet('paginators.page', 1);
    }

    /* ================================================================
     | Telegram invitations
     * ================================================================ */

    protected function withBot(string $username = 'brighthair_bot'): void
    {
        config()->set('messaging.telegram.bot_username', $username);
    }

    public function test_the_invite_panel_builds_a_link_personal_to_one_customer(): void
    {
        $this->withBot();

        $customer = $this->customer(['name' => 'Sarah Whitfield']);

        $this->actingAs($this->owner);

        $component = Volt::test('customers.index')->call('telegramLink', $customer->id);

        $token = $customer->fresh()->telegram_link_token;

        $this->assertNotNull($token, 'The token is written when the link is first needed.');

        $component
            ->assertSet('linkingId', $customer->id)
            ->assertSet('linkUrl', "https://t.me/brighthair_bot?start={$token}");

        // The ready-made message has to name both sides, or the customer receives a
        // bare link from a number they may not recognise and quite reasonably
        // ignores it.
        $this->assertStringContainsString('Sarah Whitfield', $component->get('linkMessage'));
        $this->assertStringContainsString('Salon', $component->get('linkMessage'));
        $this->assertStringContainsString($token, $component->get('linkMessage'));
    }

    public function test_an_at_sign_in_the_configured_username_is_tolerated(): void
    {
        // BotFather shows the username as @something, so that is what gets pasted
        // into .env. Two slashes and an @ would produce a link that 404s.
        $this->withBot('@brighthair_bot');

        $customer = $this->customer();

        $this->actingAs($this->owner);

        $url = Volt::test('customers.index')->call('telegramLink', $customer->id)->get('linkUrl');

        $this->assertStringStartsWith('https://t.me/brighthair_bot?start=', $url);
    }

    /**
     * A second click must not mint a new token.
     *
     * The owner has often already sent the first link by WhatsApp. Regenerating
     * would silently break it, and the customer would tap a link that tells them it
     * is no longer valid — with no way for either of them to work out why.
     */
    public function test_opening_the_panel_twice_keeps_the_same_link(): void
    {
        $this->withBot();

        $customer = $this->customer();

        $this->actingAs($this->owner);

        $first = Volt::test('customers.index')->call('telegramLink', $customer->id)->get('linkUrl');
        $second = Volt::test('customers.index')->call('telegramLink', $customer->id)->get('linkUrl');

        $this->assertSame($first, $second);
    }

    public function test_no_link_is_offered_when_no_bot_is_configured(): void
    {
        config()->set('messaging.telegram.bot_username', '');

        $customer = $this->customer();

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('telegramLink', $customer->id)
            ->assertSet('linkingId', null);

        // And no token is burned on a link that could never have worked.
        $this->assertNull($customer->fresh()->telegram_link_token);
    }

    /**
     * Same URL-tampering guard as edit and delete. This one matters more than most:
     * the response body would contain another business's customer's link token,
     * which is the one value that lets a stranger receive their reminders.
     */
    public function test_another_businesss_customer_cannot_be_invited(): void
    {
        $this->withBot();

        $theirs = Customer::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('customers.index')->call('telegramLink', $theirs->id);
    }

    public function test_closing_the_panel_clears_the_link_from_the_page(): void
    {
        $this->withBot();

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('telegramLink', $this->customer()->id)
            ->call('closeLink')
            ->assertSet('linkingId', null)
            ->assertSet('linkUrl', '');
    }

    public function test_the_invite_button_appears_for_someone_who_has_not_linked_yet(): void
    {
        $this->customer(['telegram_chat_id' => null, 'preferred_channel' => 'telegram']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')->assertSee('Invite');
    }

    public function test_there_is_nothing_to_invite_someone_who_is_already_linked(): void
    {
        $this->customer(['telegram_chat_id' => '500100', 'preferred_channel' => 'telegram']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')->assertDontSee('Invite');
    }

    public function test_someone_who_asked_for_no_messages_is_not_offered_an_invite(): void
    {
        // Inviting them would be asking a question they have already answered.
        $this->customer(['preferred_channel' => 'none', 'telegram_chat_id' => null]);

        $this->actingAs($this->owner);

        Volt::test('customers.index')->assertDontSee('Invite');
    }

    /* ================================================================
     | Customer 360 Profile Drawer
     * ================================================================ */

    public function test_view_profile_loads_customer_data_and_history(): void
    {
        $customer = $this->customer([
            'name' => 'Emma Watson',
            'phone' => '+447700900999',
            'total_spend' => 120.00,
            'notes' => 'Prefers herbal tea',
        ]);

        $this->actingAs($this->owner);

        $component = Volt::test('customers.index')
            ->call('viewProfile', $customer->id)
            ->assertSet('viewingCustomerId', $customer->id)
            ->assertSet('profileNotes', 'Prefers herbal tea')
            ->assertSee('Emma Watson')
            ->assertSee('Customer Insights');

        $viewingData = $component->viewData('viewingData');
        $this->assertNotNull($viewingData);
        $this->assertSame('Emma Watson', $viewingData['customer']->name);
        $this->assertStringContainsString('447700900999', $viewingData['whatsappUrl']);
    }

    public function test_view_profile_cannot_access_another_business_customer(): void
    {
        $otherCustomer = Customer::factory()->forBusiness($this->gym)->create([
            'name' => 'Gym Member',
        ]);

        $this->actingAs($this->owner);

        $this->expectException(ModelNotFoundException::class);

        Volt::test('customers.index')->call('viewProfile', $otherCustomer->id);
    }

    public function test_profile_notes_can_be_saved_inline(): void
    {
        $customer = $this->customer(['name' => 'Emma Watson', 'notes' => 'Original note']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('viewProfile', $customer->id)
            ->set('profileNotes', 'Updated allergy information')
            ->call('saveProfileNotes');

        $customer->refresh();
        $this->assertSame('Updated allergy information', $customer->notes);
    }

    public function test_close_profile_resets_drawer_state(): void
    {
        $customer = $this->customer(['name' => 'Emma Watson']);

        $this->actingAs($this->owner);

        Volt::test('customers.index')
            ->call('viewProfile', $customer->id)
            ->call('closeProfile')
            ->assertSet('viewingCustomerId', null)
            ->assertSet('profileNotes', '');
    }
}
