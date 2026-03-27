<?php

declare(strict_types=1);

namespace App\Models;

use App\StateMachines\OrderStatusStateMachine;
use App\Traits\BelongsToOrganization;
use Asantibanez\LaravelEloquentStateMachines\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToOrganization, HasFactory, HasStateMachines;

    /** @var array<string, class-string> */
    public $stateMachines = [
        'status' => OrderStatusStateMachine::class,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'order_number',
        'status',
        'currency',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'customer_email',
        'customer_first_name',
        'customer_last_name',
        'customer_phone',
        'invoice_requested',
        'invoice_company_name',
        'invoice_nip',
        'invoice_street',
        'invoice_street_number',
        'invoice_postal_code',
        'invoice_city',
        'p24_session_id',
        'p24_order_id',
        'p24_token',
        'p24_amount',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'completed_at',
        'cart_id',
        'ip_address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'invoice_requested' => 'boolean',
            'p24_amount' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class)->withDefault();
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopePendingPayment(Builder $query): Builder
    {
        return $query->where('status', 'pending_payment');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'pending_payment')
            ->where('expires_at', '<', now());
    }
}
