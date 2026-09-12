<?php

namespace App\Support;

/**
 * Resolves "which business are we acting as right now".
 *
 * Web requests: taken from the logged-in user.
 * Console / queue: nothing is set by default, so Tenant::id() returns null and
 * the BelongsToBusiness global scope does NOT filter — that is intentional,
 * because commands like messages:plan need to iterate every business.
 *
 * ⚠️ Because null means "unscoped", any console command that touches
 * tenant data for ONE business must wrap the work in Tenant::for($id, fn () => ...).
 * Forgetting this is the one way to leak data between clients.
 */
class Tenant
{
    protected static ?int $businessId = null;

    /** True once set()/for() has been called, so we can distinguish "set to null" from "never set". */
    protected static bool $overridden = false;

    public static function set(?int $businessId): void
    {
        static::$businessId = $businessId;
        static::$overridden = true;
    }

    public static function forget(): void
    {
        static::$businessId = null;
        static::$overridden = false;
    }

    /**
     * Run $callback scoped to one business, then restore whatever was set before.
     * Use this in every console command that works per-business.
     */
    public static function for(?int $businessId, callable $callback): mixed
    {
        $previousId = static::$businessId;
        $previousOverridden = static::$overridden;

        static::set($businessId);

        try {
            return $callback();
        } finally {
            static::$businessId = $previousId;
            static::$overridden = $previousOverridden;
        }
    }

    public static function id(): ?int
    {
        if (static::$overridden) {
            return static::$businessId;
        }

        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return session('operate_business_id') ?? $user->business_id;
        }

        return $user?->business_id;
    }

    public static function check(): bool
    {
        return static::id() !== null;
    }

    /** True when a super-admin is actively operating a specific business. */
    public static function isOperating(): bool
    {
        return (bool) (auth()->user()?->isSuperAdmin() && session()->has('operate_business_id'));
    }

    /** Set or clear the active business being operated by a super-admin. */
    public static function operateAs(?int $businessId): void
    {
        if ($businessId) {
            session(['operate_business_id' => $businessId]);
        } else {
            session()->forget('operate_business_id');
        }
    }

    /** Retrieve the current business being operated by the super-admin. */
    public static function operatingBusiness(): ?\App\Models\Business
    {
        if (! static::isOperating()) {
            return null;
        }

        $id = static::id();

        return $id ? \App\Models\Business::find($id) : null;
    }
}
