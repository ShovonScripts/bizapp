<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ⚠️⚠️ DO NOT add the BelongsToBusiness trait to this model. ⚠️⚠️
 *
 * The global scope calls Tenant::id(), which calls auth()->user().
 * Laravel's session guard resolves the user by running a query on this model
 * and only caches the result AFTER the query returns — so the scope would call
 * auth()->user() while auth()->user() is still resolving, and you get infinite
 * recursion / a stack overflow on every authenticated request.
 *
 * Scope user queries explicitly instead, e.g. User::inCurrentBusiness()->get()
 * for a staff list.
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'business_id',
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** Manual replacement for the global scope — safe to use in controllers/components. */
    public function scopeInCurrentBusiness(Builder $query): Builder
    {
        return $query->where('business_id', \App\Support\Tenant::id());
    }

    public function isSuperAdmin(): bool
    {
        return $this->business_id === null;
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
