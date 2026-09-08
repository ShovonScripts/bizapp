<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The single most important test in this codebase.
 *
 * Every client's customer records live in the same tables, separated only by
 * business_id and a global scope. If that scope ever stops working, salon A can
 * read salon B's customer list — which under UK GDPR is a reportable personal
 * data breach, not a bug we quietly patch. Manual checks in tinker are not
 * enough, because the failure is silent.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;
    protected Business $gym;
    protected User $salonOwner;
    protected User $gymOwner;
    protected Customer $salonCustomer;
    protected Customer $gymCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant holds STATIC state. PHPUnit runs every test in one PHP process,
        // so without this a Tenant::set() in one test leaks into the next and the
        // results become order-dependent.
        Tenant::forget();

        $this->salon = Business::factory()->create(['name' => 'Salon', 'slug' => 'salon']);
        $this->gym = Business::factory()->create(['name' => 'Gym', 'slug' => 'gym']);

        $this->salonOwner = User::factory()->create(['business_id' => $this->salon->id]);
        $this->gymOwner = User::factory()->create(['business_id' => $this->gym->id]);

        $this->salonCustomer = Customer::factory()
            ->forBusiness($this->salon)
            ->create(['name' => 'Salon Customer']);

        $this->gymCustomer = Customer::factory()
            ->forBusiness($this->gym)
            ->create(['name' => 'Gym Customer']);
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    /* ================================================================
     | The core guarantee
     * ================================================================ */

    public function test_logged_in_user_only_sees_their_own_businesss_customers(): void
    {
        $this->actingAs($this->salonOwner);

        $names = Customer::pluck('name');

        $this->assertContains('Salon Customer', $names);
        $this->assertNotContains('Gym Customer', $names);
        $this->assertCount(1, $names);
    }

    /**
     * The dangerous one. A scope that filters listings but not find() is worse
     * than no scope, because it feels safe: /customers/5 would happily show
     * another tenant's record just by changing the number in the URL.
     */
    public function test_cannot_fetch_another_businesss_record_by_id(): void
    {
        $this->actingAs($this->salonOwner);

        $this->assertNull(Customer::find($this->gymCustomer->id));
        $this->assertNotNull(Customer::find($this->salonCustomer->id));
    }

    public function test_cannot_count_or_aggregate_across_businesses(): void
    {
        $this->actingAs($this->salonOwner);

        $this->assertSame(1, Customer::count());
        $this->assertSame(0, Customer::where('name', 'Gym Customer')->count());
    }

    public function test_scope_applies_to_every_tenant_scoped_model(): void
    {
        Service::factory()->forBusiness($this->salon)->create(['name' => 'Salon Service']);
        Service::factory()->forBusiness($this->gym)->create(['name' => 'Gym Service']);

        StaffMember::factory()->forBusiness($this->salon)->create(['name' => 'Salon Staff']);
        StaffMember::factory()->forBusiness($this->gym)->create(['name' => 'Gym Staff']);

        // NOTE: AppointmentFactory creates its own customer, service and staff inside
        // whichever business it is given, so these two lines add incidental rows on
        // both sides. That is why the loop below asserts "nothing from another
        // business is visible" instead of an exact row count — a count assertion
        // breaks whenever a fixture creates an extra record, which is a false alarm
        // rather than a leak, and a test that cries wolf gets ignored.
        Appointment::factory()->forBusiness($this->salon)->create();
        Appointment::factory()->forBusiness($this->gym)->create();

        $this->actingAs($this->salonOwner);

        foreach ([Customer::class, Service::class, StaffMember::class, Appointment::class] as $model) {
            $businessIds = $model::pluck('business_id')->unique()->values()->all();

            $this->assertSame(
                [$this->salon->id],
                $businessIds,
                $model.' returned rows belonging to another business.'
            );
        }

        // And the named fixtures specifically: present on our side, absent from theirs.
        $this->assertContains('Salon Service', Service::pluck('name')->all());
        $this->assertNotContains('Gym Service', Service::pluck('name')->all());
        $this->assertContains('Salon Staff', StaffMember::pluck('name')->all());
        $this->assertNotContains('Gym Staff', StaffMember::pluck('name')->all());
    }

    /* ================================================================
     | Writes
     * ================================================================ */

    public function test_business_id_is_filled_automatically_on_create(): void
    {
        $this->actingAs($this->gymOwner);

        $customer = Customer::create(['name' => 'Auto Scoped', 'phone' => '07700900555']);

        $this->assertSame($this->gym->id, $customer->business_id);
    }

    public function test_an_explicit_business_id_is_not_silently_overwritten(): void
    {
        // Needed by importers and console commands that write on behalf of a tenant.
        $customer = Customer::create([
            'business_id' => $this->salon->id,
            'name' => 'Explicit',
            'phone' => '07700900556',
        ]);

        $this->assertSame($this->salon->id, $customer->business_id);
    }

    /* ================================================================
     | Tenant::for() — used by every per-business console command
     * ================================================================ */

    public function test_tenant_for_scopes_queries_without_a_logged_in_user(): void
    {
        $this->assertFalse(Tenant::check(), 'No user and no override means unscoped.');

        $salonNames = Tenant::for($this->salon->id, fn () => Customer::pluck('name')->all());
        $gymNames = Tenant::for($this->gym->id, fn () => Customer::pluck('name')->all());

        $this->assertSame(['Salon Customer'], $salonNames);
        $this->assertSame(['Gym Customer'], $gymNames);
    }

    public function test_tenant_for_restores_the_previous_tenant_afterwards(): void
    {
        Tenant::set($this->salon->id);

        Tenant::for($this->gym->id, fn () => Customer::count());

        $this->assertSame($this->salon->id, Tenant::id(), 'for() must restore, not clobber.');
    }

    public function test_tenant_for_restores_even_when_the_callback_throws(): void
    {
        Tenant::set($this->salon->id);

        try {
            Tenant::for($this->gym->id, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // If this fails, one failed job could leave a queue worker permanently
        // scoped to the wrong tenant — every later job would write to the wrong client.
        $this->assertSame($this->salon->id, Tenant::id());
    }

    /* ================================================================
     | Documented, deliberate exceptions
     * ================================================================ */

    public function test_unscoped_context_sees_everything_which_the_planner_relies_on(): void
    {
        // No auth, no override. messages:plan must iterate every business.
        $this->assertSame(2, Customer::count());
    }

    public function test_super_admin_sees_across_all_businesses(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        $this->assertNull($admin->business_id);
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame(2, Customer::count());
    }

    public function test_across_all_businesses_scope_bypasses_the_filter_on_purpose(): void
    {
        $this->actingAs($this->salonOwner);

        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Customer::acrossAllBusinesses()->count());
    }

    /* ================================================================
     | Regression guards
     * ================================================================ */

    /**
     * Guards the recursion trap documented on the User model: putting
     * BelongsToBusiness there makes the scope call auth()->user() while
     * auth()->user() is still resolving, blowing the stack on every
     * authenticated request. If someone "helpfully" adds the trait, this dies.
     */
    public function test_authenticated_requests_still_work_ie_user_model_is_not_globally_scoped(): void
    {
        $this->actingAs($this->salonOwner)
            ->get('/dashboard')
            ->assertOk();

        $this->assertSame(2, User::count(), 'User must not be tenant-scoped.');
    }

    public function test_in_current_business_scope_is_the_manual_replacement_for_users(): void
    {
        $this->actingAs($this->salonOwner);

        $emails = User::inCurrentBusiness()->pluck('email')->all();

        $this->assertSame([$this->salonOwner->email], $emails);
    }

    /**
     * Registration must never mint a super-admin. Breeze's stock component calls
     * User::create() with no business_id, and business_id = null means unscoped
     * access to every client's data — so this is a security test, not a UX one.
     *
     * Driven through Volt, not $this->post(): with the Livewire stack /register is
     * a GET route rendering a component, there is no POST endpoint to hit.
     */
    public function test_registration_creates_a_business_and_never_a_super_admin(): void
    {
        Volt::test('pages.auth.register')
            ->set('business_name', 'Brand New Salon')
            ->set('name', 'New Owner')
            ->set('email', 'new@example.com')
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertNotNull($user->business_id, 'A registrant must never be a super-admin.');
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('owner', $user->role);
        $this->assertSame('Brand New Salon', $user->business->name);
        $this->assertSame('brand-new-salon', $user->business->slug);
    }

    /**
     * Validation must run before anything is written. If someone ever reorders
     * this so the Business is created first, a rejected signup would leave an
     * orphan tenant behind — a business row nobody can log into, which then shows
     * up in every client count and revenue figure.
     */
    public function test_a_rejected_registration_writes_nothing(): void
    {
        $businessesBefore = Business::count();

        Volt::test('pages.auth.register')
            ->set('business_name', 'Ghost Salon')
            ->set('name', 'Ghost')
            ->set('email', $this->salonOwner->email)   // already taken
            ->set('password', 'password-1234')
            ->set('password_confirmation', 'password-1234')
            ->call('register')
            ->assertHasErrors('email');

        $this->assertSame($businessesBefore, Business::count());
        $this->assertGuest();
    }

    public function test_two_businesses_with_the_same_name_get_different_slugs(): void
    {
        Business::create(['name' => 'Same Name', 'slug' => Business::uniqueSlug('Same Name')]);
        $second = Business::create(['name' => 'Same Name', 'slug' => Business::uniqueSlug('Same Name')]);

        $this->assertSame('same-name-2', $second->slug);
    }

    /** A soft-deleted business still owns its slug, because the unique index does. */
    public function test_unique_slug_skips_soft_deleted_businesses(): void
    {
        $business = Business::create(['name' => 'Gone', 'slug' => Business::uniqueSlug('Gone')]);
        $business->delete();

        $this->assertSame('gone-2', Business::uniqueSlug('Gone'));
    }
}
