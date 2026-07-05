<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'expires_at',
        'customer_email',
        'checkout_started_at',
        'last_checkout_step',
        'abandoned_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'checkout_started_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'status' => 'string',
        ];
    }

    /**
     * Keeps `active_slot` in sync with `status` so the DB-level unique constraint
     * `(organization_id, user_id, active_slot)` can enforce "at most one active
     * cart per user per org" while still allowing many non-active
     * (converted/abandoned) carts — NULL is not unique-constrained.
     */
    protected static function booted(): void
    {
        static::saving(function (Cart $cart): void {
            $cart->active_slot = $cart->status === 'active' ? 1 : null;
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where('status', 'abandoned');
    }
}
