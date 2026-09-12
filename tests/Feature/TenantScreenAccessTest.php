<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A safety net for the guard that is easiest to forget.
 *
 * Every screen showing one business's data calls guardTenant() in mount(). Nothing
 * forces a new screen to do that, and a screen that forgets it does not look broken
 * — it looks fine, while showing a super-admin every client's records merged into
 * one list. In this app that is a reportable UK GDPR breach, not a cosmetic bug.
 *
 * So instead of listing the screens to check, this test walks every reachable GET
 * route and inverts the burden of proof: a page is assumed to be tenant-scoped
 * unless it is named below as deliberately not. Add a screen and forget the guard
 * and this test fails; add a genuinely business-agnostic page and you have to say
 * so explicitly, in one obvious place.
 */
class TenantScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages that deliberately do not show one business's data.
     *
     * Before adding anything here, check it really is business-agnostic: not just
     * that it happens to render for a super-admin today.
     */
    protected const NON_TENANT_ROUTES = [
        '/',                 // marketing page, no data at all
        'pricing',           // public marketing page, no tenant data
        'privacy',           // public legal page
        'terms',             // public legal page
        'up',                // framework health check
        'profile',           // the logged-in user's own account
        'verify-email',      // auth flow, belongs to the user not the business
        'confirm-password',  // auth flow
        'heartbeat',         // ops endpoint, token-protected, no business data
        'whatsapp/webhook',  // Meta webhook verification handshake (GET challenge)

        /*
         * dashboard — the one screen that branches instead of aborting.
         *
         * Not an oversight and not a temporary exemption. It is where login lands,
         * and the navigation shows the logo and Dashboard links to everyone, so a
         * 403 here would leave a super-admin on a page where every visible link
         * 403s as well. It therefore renders platform counts for them and one
         * business's figures for an owner.
         *
         * That makes it the one place a tenant leak could hide behind a 200, so it
         * does not get to rely on this sweep: DashboardTest asserts directly that a
         * super-admin sees no end-customer name, and that an owner sees only their
         * own. If you touch that component, run that file.
         */
        'dashboard',
    ];

    /** Routes the framework and its packages register for themselves. */
    protected const INTERNAL_PREFIXES = [
        'livewire/',
        'sanctum/',
        'storage/',
        '_debugbar',
        '_ignition',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant is static state and PHPUnit shares one process across tests.
        Tenant::forget();
    }

    protected function tearDown(): void
    {
        Tenant::forget();

        parent::tearDown();
    }

    /**
     * Every tenant screen, discovered rather than listed.
     *
     * Skips guest-only routes (an authenticated user is redirected away from those,
     * so they can prove nothing) and anything taking a route parameter, which
     * cannot be requested without inventing an id.
     *
     * @return list<string>
     */
    protected function tenantRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (str_contains($route->uri(), '{')) {
                continue;
            }

            if (Str::startsWith($route->uri(), static::INTERNAL_PREFIXES)) {
                continue;
            }

            if (in_array('guest', $route->gatherMiddleware(), true)) {
                continue;
            }

            if (in_array($route->uri(), static::NON_TENANT_ROUTES, true)) {
                continue;
            }

            $uris[] = $route->uri();
        }

        return array_values(array_unique($uris));
    }

    /**
     * Guards the discovery itself. If a refactor breaks the route walk above, every
     * assertion in this file would pass over an empty list and report success —
     * the most dangerous kind of green test.
     */
    public function test_the_route_walk_actually_finds_the_tenant_screens(): void
    {
        $found = $this->tenantRoutes();

        foreach (['appointments', 'customers', 'services', 'staff', 'settings'] as $expected) {
            $this->assertContains(
                $expected,
                $found,
                "The route walk missed /{$expected}, so this whole test file is proving nothing."
            );
        }
    }

    public function test_no_tenant_screen_is_reachable_by_a_guest(): void
    {
        foreach ($this->tenantRoutes() as $uri) {
            $response = $this->get('/'.ltrim($uri, '/'));

            // Tenant::id() reads the logged-in user, so a guest resolves to null —
            // which BelongsToBusiness treats as "unscoped", i.e. every client at once.
            $this->assertSame(
                302,
                $response->getStatusCode(),
                "/{$uri} answered a request with no login instead of redirecting."
            );

            $this->assertStringContainsString(
                '/login',
                (string) $response->headers->get('Location'),
                "/{$uri} redirected somewhere other than the login page."
            );
        }
    }

    public function test_no_tenant_screen_renders_for_a_super_admin(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        foreach ($this->tenantRoutes() as $uri) {
            $this->assertSame(
                403,
                $this->get('/'.ltrim($uri, '/'))->getStatusCode(),
                "/{$uri} rendered for a super-admin (business_id = null), so it is missing "
                ."guardTenant() in mount() and is showing every client's data at once. Add the "
                .'guard, or add the route to NON_TENANT_ROUTES if it really is business-agnostic.'
            );
        }
    }

    /** The other half: an ordinary owner must still be able to open all of them. */
    public function test_every_tenant_screen_opens_for_a_business_owner(): void
    {
        // UserFactory attaches a business by default, so this is a real owner.
        $this->actingAs(User::factory()->create());

        foreach ($this->tenantRoutes() as $uri) {
            $this->assertSame(
                200,
                $this->get('/'.ltrim($uri, '/'))->getStatusCode(),
                "/{$uri} does not open for a business owner."
            );
        }
    }
}
