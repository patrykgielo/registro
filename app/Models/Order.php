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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
        // Financial totals — only ever mutated via applyFinancialAdjustment() (see below),
        // included here so that escape-hatch is actually captured in the audit trail.
        'subtotal',
        'total_amount',
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

    /**
     * Transient, non-persisted flag — a real declared PHP property, NOT an
     * Eloquent attribute, so setting it never touches $attributes/getDirty()
     * and can never leak into an UPDATE query.
     *
     * Consulted by OrderStatusStateMachine's 'cancelled' afterTransitionHook
     * to decide whether to fire the customer-facing OrderCancelled
     * notification. Defaults to true (normal cancellations always notify).
     * Set to false via OrderService::cancel($order, $reason, notify: false)
     * for internal-compensation scenarios (e.g. P24 registration failure)
     * where the customer never actually saw a completed order and a
     * "your order was cancelled" email would just be confusing noise ahead
     * of an immediate, successful retry.
     */
    public bool $notifyOnCancel = true;

    /**
     * Transient, non-persisted escape hatch — mirrors
     * Organization::$forceLifecycleTransition. `total_amount`/`subtotal` are
     * normally immutable (see booted()'s updating guard) because a naive
     * ->update() must never silently drift an order's financial totals away
     * from its line items. Some flows (e.g. RentalExtensionService::approve())
     * legitimately need to adjust them — call applyFinancialAdjustment()
     * instead of mutating the attributes directly; it flips this flag for the
     * duration of a single save() and the static::saved() listener below
     * resets it immediately after, so it can never leak into an unrelated
     * later save.
     */
    public bool $allowFinancialAdjustment = false;

    /**
     * Sanctioned way to mutate Order's normally-immutable financial totals
     * (subtotal/total_amount). Applies each delta additively (positive to
     * increase, negative to decrease) and saves through the normal Eloquent
     * lifecycle — NOT saveQuietly() — so the updated `updated` event fires,
     * Auditable::bootAuditable() logs the change (subtotal/total_amount are
     * both in $auditInclude above), and the audit trail stays intact.
     *
     * @param  array<string, float|int|string>  $deltas  e.g. ['subtotal' => 400.00, 'total_amount' => 400.00]
     */
    public function applyFinancialAdjustment(array $deltas, string $reason): void
    {
        $this->allowFinancialAdjustment = true;

        foreach ($deltas as $field => $delta) {
            $this->{$field} = $this->{$field} + $delta;
        }

        $this->save();
    }

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
                // total_amount/subtotal are exempted while allowFinancialAdjustment is
                // true — set only by applyFinancialAdjustment(), for the duration of a
                // single save() (mirrors Organization::$forceLifecycleTransition).
                if (in_array($field, ['total_amount', 'subtotal'], true) && $order->allowFinancialAdjustment) {
                    continue;
                }

                if ($order->isDirty($field)) {
                    throw new \LogicException(
                        "Field '{$field}' is immutable on Order and cannot be changed after creation."
                    );
                }
            }
        });

        // Resets allowFinancialAdjustment so it can never leak into a later,
        // unrelated save() on the same model instance. Uses saved() rather than
        // updated() because updated() only fires when the save actually wrote
        // dirty attributes — saved() fires on every save(), including no-op
        // ones, and is therefore the correct cleanup hook (same reasoning as
        // OrganizationObserver::saved() for forceLifecycleTransition).
        static::saved(function (self $order): void {
            $order->allowFinancialAdjustment = false;
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

    /**
     * @return HasManyThrough<OrderItemExtensionRequest, OrderItem, $this>
     */
    public function extensionRequests(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItemExtensionRequest::class, OrderItem::class);
    }

    public function scopePendingPayment(Builder $query): Builder
    {
        return $query->where('status', 'pending_payment');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    /**
     * Clamped `przelewy24.transaction_grace_minutes` config value.
     *
     * Single source of truth shared by scopeExpired() (this class) and
     * OrderItem::scopeBlockingAvailability() — the two MUST stay in sync,
     * otherwise an order the expiry scope still considers "alive" could stop
     * blocking the inventory it's holding (overbooking) or vice versa.
     *
     * Clamped to [0, 1440] minutes (0 = no extra grace, 1440 = 24h max) so a
     * misconfigured negative value can't invert the intent (cancelling
     * P24-registered orders EARLY, mid-payment) and an absurd value can't
     * effectively disable expiry for P24-registered orders indefinitely.
     */
    public static function ttlGraceMinutes(): int
    {
        return max(0, min(1440, (int) config('przelewy24.transaction_grace_minutes', 120)));
    }

    /**
     * Orders eligible for TTL cleanup (orders:cleanup-expired).
     *
     * An order that already has a P24 transaction registered (p24_token set)
     * means the customer is/was actively on P24's own gateway — a slow
     * bank/BLIK confirmation must not be cancelled out from under them while
     * a real payment attempt may still be in flight. Such orders get an
     * extended effective TTL (normal expires_at + a configurable grace
     * period) before they're eligible for cancellation. Orders with no
     * registered transaction keep the normal 20-minute TTL.
     *
     * IMPORTANT: mirrors OrderItem::scopeBlockingAvailability()'s
     * pending_payment branch exactly — an order this scope still considers
     * "alive" must also still block the inventory it's holding.
     */
    public function scopeExpired(Builder $query): Builder
    {
        $graceMinutes = self::ttlGraceMinutes();

        return $query->where('status', 'pending_payment')
            ->where(function (Builder $q) use ($graceMinutes): void {
                $q->where(function (Builder $noTransaction): void {
                    $noTransaction->whereNull('p24_token')
                        ->where('expires_at', '<', now());
                })->orWhere(function (Builder $withTransaction) use ($graceMinutes): void {
                    $withTransaction->whereNotNull('p24_token')
                        ->where('expires_at', '<', now()->subMinutes($graceMinutes));
                });
            });
    }
}
