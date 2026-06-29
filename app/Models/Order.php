<?php

declare(strict_types=1);

namespace App\Models;

use App\StateMachines\OrderStatusStateMachine;
use App\Traits\Auditable;
use App\Traits\BelongsToOrganization;
use Asantibanez\LaravelEloquentStateMachines\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, HasStateMachines;

    protected array $auditInclude = [
        'user_id',
        'status',
        'customer_type',
        'customer_first_name',
        'customer_last_name',
        'customer_email',
        'customer_phone',
        'customer_pesel',
        'customer_street',
        'customer_building',
        'customer_apartment',
        'customer_city',
        'customer_postal_code',
        'invoice_company_name',
        'invoice_nip',
        'company_regon',
        'company_krs',
        'company_contact_name',
        'signatory_id_number',
        'pickup_person_name',
        'pickup_person_id_number',
        'invoice_street',
        'invoice_street_number',
        'invoice_postal_code',
        'invoice_city',
        'rodo_accepted_at',
        'terms_accepted_at',
        'withdrawal_exclusion_accepted_at',
        // Financial audit timestamps — moment of payment receipt / cancellation / completion
        'paid_at',
        'cancelled_at',
        'completed_at',
    ];

    // Defensive: fields outside $auditInclude are already rejected by the allowlist.
    // $auditExclude adds defense-in-depth in case the Auditable trait ever changes to opt-out mode.
    protected array $auditExclude = [
        'p24_session_id',
        'p24_order_id',
        'p24_token',
        'p24_amount',
        'expires_at',
        'cart_id',
        'ip_address',
        'rodo_accepted_ip',
    ];

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
        // Legal / customer type
        'customer_type',
        'customer_pesel',
        'customer_street',
        'customer_building',
        'customer_apartment',
        'customer_city',
        'customer_postal_code',
        // Invoice
        'invoice_requested',
        'invoice_company_name',
        'invoice_nip',
        'invoice_street',
        'invoice_street_number',
        'invoice_postal_code',
        'invoice_city',
        // Business extras
        'company_regon',
        'company_krs',
        'company_contact_name',
        'signatory_id_number',
        'pickup_person_name',
        'pickup_person_id_number',
        // Deposit (kaucja)
        'deposit_amount',
        'deposit_status',
        'deposit_collected_at',
        'deposit_returned_at',
        'deposit_notes',
        // Legal acceptances
        'rodo_accepted_at',
        'rodo_accepted_ip',
        'terms_accepted_at',
        'withdrawal_exclusion_accepted_at',
        // Payment
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
            // Legal fields
            'customer_type' => 'string',
            'deposit_amount' => 'decimal:2',
            'deposit_status' => 'string',
            'deposit_collected_at' => 'datetime',
            'deposit_returned_at' => 'datetime',
            'rodo_accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'withdrawal_exclusion_accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $immutable = [
                'organization_id',
                'order_number',
                'total_amount',
                'subtotal',
                'discount_amount',
                'tax_amount',
                'deposit_amount',
                'rodo_accepted_at',
                'rodo_accepted_ip',
                'terms_accepted_at',
                'withdrawal_exclusion_accepted_at',
            ];

            foreach ($immutable as $field) {
                if ($order->isDirty($field)) {
                    throw new \LogicException(
                        "Field '{$field}' is immutable on Order and cannot be changed after creation."
                    );
                }
            }
        });
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
