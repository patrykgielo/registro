<?php

namespace App\Models;

use App\Enums\RentalStatus;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rental extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'service_id',
        'customer_id',
        'quantity',
        'start_date',
        'end_date',
        'pricing_unit',
        'unit_price_at_booking',
        'total_price',
        'deposit_amount',
        'status',
        'notes',
        'cancellation_reason',
        // Contact info snapshot
        'first_name',
        'last_name',
        'email',
        'phone',
        // Invoice data
        'invoice_requested',
        'invoice_company_name',
        'invoice_nip',
        'invoice_street',
        'invoice_street_number',
        'invoice_postal_code',
        'invoice_city',
        // Status timestamps
        'confirmed_at',
        'picked_up_at',
        'returned_at',
        'cancelled_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'quantity' => 'integer',
        'unit_price_at_booking' => 'decimal:2',
        'total_price' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'invoice_requested' => 'boolean',
        'confirmed_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => RentalStatus::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (Rental $rental) {
            if ($rental->isDirty('status')) {
                match ($rental->status) {
                    RentalStatus::Confirmed => $rental->confirmed_at = now(),
                    RentalStatus::Active => $rental->picked_up_at = now(),
                    RentalStatus::Returned => $rental->returned_at = now(),
                    RentalStatus::Cancelled => $rental->cancelled_at = now(),
                    default => null,
                };
            }
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    // Scopes
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', RentalStatus::Pending);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', RentalStatus::Confirmed);
    }

    public function scopeActive($query)
    {
        return $query->where('status', RentalStatus::Active);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', RentalStatus::Returned);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', RentalStatus::Cancelled);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', [RentalStatus::Pending, RentalStatus::Confirmed])
            ->where('start_date', '>=', now()->toDateString())
            ->orderBy('start_date');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', RentalStatus::Active)
            ->where('end_date', '<', now()->toDateString());
    }

    // Accessors
    public function getDurationDaysAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === RentalStatus::Active && $this->end_date->isPast();
    }

    public function getCustomerNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }

        return $this->customer?->name ?? '';
    }
}
