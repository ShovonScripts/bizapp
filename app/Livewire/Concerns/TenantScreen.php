<?php

namespace App\Livewire\Concerns;

use App\Models\Business;
use App\Support\Tenant;

/**
 * Shared behaviour for every screen that shows one business's data.
 *
 * The guard lives here so the rule has a single definition, but each component
 * still calls it explicitly from mount(). That is deliberate: a Livewire trait
 * lifecycle hook that silently fails to fire would leave the screen open, and a
 * security check must never fail open. Explicit and visible beats clever here.
 *
 * TenantScreenAccessTest walks every tenant route as a super-admin, so a screen
 * that forgets the call fails loudly in CI rather than quietly in production.
 */
trait TenantScreen
{
    /**
     * Resolved per request, never persisted.
     *
     * Livewire only serialises PUBLIC properties, so a protected one is re-resolved
     * on every request — which is exactly right for a model instance. Making this
     * public would ship the whole Business row (including its settings JSON) to the
     * browser and back on every interaction, and trust it on the way back.
     */
    protected ?Business $tenantBusiness = null;

    /**
     * Refuse to render outside a single business.
     *
     * A super-admin has business_id = null, which BelongsToBusiness reads as
     * "unscoped" — they would see every client's records merged into one list, and
     * anything they created would have no business to attach to. Until there is a
     * proper business switcher, they are locked out rather than shown a broken page.
     */
    protected function guardTenant(): void
    {
        abort_unless(Tenant::check(), 403, 'Select a business before opening this screen.');
    }

    /**
     * The business being viewed. Needed anywhere local time or currency matters.
     *
     * Safe to call only after guardTenant(), which is why it findOrFail()s rather
     * than returning null: reaching here without a tenant is a routing bug, and a
     * loud failure beats a screen that quietly renders in UTC.
     */
    protected function business(): Business
    {
        return $this->tenantBusiness ??= Business::findOrFail(Tenant::id());
    }

    /** Brief confirmation banner. Paired with the x-data toast wrapper in the view. */
    protected function toast(string $message): void
    {
        $this->dispatch('toast', message: $message);
    }
}
