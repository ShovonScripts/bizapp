<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add this trait to EVERY model that holds client data.
 *
 * It does two things:
 *   1. Filters all queries to the current business (so one client can never
 *      read another's rows — in the UK that is a GDPR breach, not just a bug).
 *   2. Fills business_id automatically on create, so we never write it by hand.
 *
 * Escape hatches, both deliberate and both to be used sparingly:
 *   Model::withoutGlobalScope('business')->...   // super-admin panel only
 *   Tenant::for($id, fn () => ...)               // console commands
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $builder) {
            $businessId = Tenant::id();

            if ($businessId !== null) {
                $builder->where(
                    $builder->getModel()->getTable().'.business_id',
                    $businessId
                );
            }
        });

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = Tenant::id();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Convenience for the super-admin panel. */
    public function scopeAcrossAllBusinesses(Builder $query): Builder
    {
        return $query->withoutGlobalScope('business');
    }
}
