<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionRequestStatus;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemExtensionRequest extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'order_id',
        'order_item_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'status',
        'original_end_date',
        'requested_end_date',
        'additional_days',
        'additional_amount',
        'customer_notes',
        'rejection_reason',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtensionRequestStatus::class,
            'original_end_date' => 'date',
            'requested_end_date' => 'date',
            'additional_days' => 'integer',
            'additional_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExtensionRequestStatus::Pending->value);
    }
}
