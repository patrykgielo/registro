<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Traits\Auditable;
use App\Traits\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    /** @use HasFactory<\Database\Factories\AppointmentFactory> */
    use Auditable, BelongsToOrganization, HasFactory;

    /**
     * Fields to include in audit logging (booking data - GDPR relevant)
     */
    protected array $auditInclude = [
        'customer_id',
        'staff_id',
        'service_id',
        'appointment_date',
        'start_time',
        'end_time',
        'status',
        'notes',
        'cancellation_reason',
        'location_address',
        'service_location_type',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * The event map for the model.
     *
     * Automatically dispatches events on model lifecycle
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => AppointmentCreated::class,
    ];

    /**
     * The "booted" method of the model.
     *
     * Register model event listeners for status changes
     */
    protected static function booted(): void
    {
        // Normalize start_time/end_time to a canonical H:i:s string before the
        // active_slot/unique-index logic below. The 'datetime:H:i' cast only
        // reformats on GET (attribute access) — Eloquent's setAttribute() skips
        // fromDateTime() normalization entirely for 'custom_datetime' casts
        // (only plain 'date'/'datetime' casts get that treatment), so the RAW
        // stored value is whatever string the caller passed in verbatim. Two
        // logically-identical times — '10:00:00' (raw Eloquent::create(), most
        // factories) vs '10:00' (validated 'H:i' form input from the booking
        // controllers) — would otherwise be stored as different strings and
        // silently defeat appointments_staff_slot_unique's string comparison.
        // Discovered while writing the double-booking regression test (2026-07).
        static::saving(function (Appointment $appointment) {
            if ($appointment->start_time) {
                $appointment->start_time = $appointment->start_time->format('H:i:s');
            }
            if ($appointment->end_time) {
                $appointment->end_time = $appointment->end_time->format('H:i:s');
            }
        });

        // Maintain `active_slot` — backs the appointments_staff_slot_unique DB
        // constraint (double-booking guard). NULL for cancelled appointments so
        // they never collide with the unique index (both MySQL and SQLite treat
        // every NULL as distinct); `true` for every other status so two
        // non-cancelled appointments for the same staff/date/start_time collide.
        // Deliberately NOT fillable — always derived from `status`, never
        // client-supplied. See database/migrations/2026_07_05_000001_*.
        static::saving(function (Appointment $appointment) {
            $appointment->active_slot = $appointment->status === AppointmentStatus::Cancelled ? null : true;
        });

        // Detect appointment reschedule (date/time change)
        static::updating(function (Appointment $appointment) {
            if ($appointment->isDirty(['appointment_date', 'start_time', 'end_time'])
                && $appointment->status !== AppointmentStatus::Cancelled) {
                $oldDate = Carbon::parse(
                    $appointment->getOriginal('appointment_date')->format('Y-m-d').' '.$appointment->getOriginal('start_time')->format('H:i')
                );
                $newDate = Carbon::parse(
                    $appointment->appointment_date->format('Y-m-d').' '.$appointment->start_time->format('H:i')
                );

                // start_time/end_time only carry minute precision -- the TimePicker UI and the
                // 'datetime:H:i' cast both drop seconds, and the normalization above
                // (static::saving(), first hook in this method) re-derives a canonical ':00'
                // second on every single save. A raw isDirty() on these columns is therefore
                // spuriously true whenever the ORIGINAL row happened to carry non-zero seconds
                // (e.g. AppointmentFactory's fake()->time('H:i:s')) even though nothing about the
                // appointment's actual schedule changed -- caught by PanelWalkthroughTest's no-op
                // save check. Compare at the precision the appointment can actually express (and
                // end_time the same way) before treating this as a real reschedule; a no-op save
                // must never fire this event or the customer-facing notification behind it.
                $endTimeMoved = $appointment->getOriginal('end_time')->format('H:i') !== $appointment->end_time->format('H:i');

                if (! $oldDate->equalTo($newDate) || $endTimeMoved) {
                    event(new AppointmentRescheduled($appointment, $oldDate, $newDate));
                }
            }

            // Detect appointment confirmation (status change to 'confirmed')
            if ($appointment->isDirty('status') && $appointment->status === AppointmentStatus::Confirmed) {
                event(new AppointmentConfirmed($appointment));
            }

            // Detect appointment cancellation (status change to 'cancelled')
            if ($appointment->isDirty('status') && $appointment->status === AppointmentStatus::Cancelled) {
                $appointment->cancelled_at = now();
                event(new AppointmentCancelled($appointment));
            }

            // Auto-set completed_at timestamp
            if ($appointment->isDirty('status') && $appointment->status === AppointmentStatus::Completed) {
                $appointment->completed_at = now();
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'service_id',
        'customer_id',
        'staff_id',
        'appointment_date',
        'start_time',
        'end_time',
        'status',
        'notes',
        'cancellation_reason',
        'location_address',
        'location_latitude',
        'location_longitude',
        'location_place_id',
        'location_components',
        'service_location_type',
        'vehicle_type_id',
        'car_brand_id',
        'car_model_id',
        'vehicle_year',
        'registration_number',
        'vehicle_custom_brand',
        'vehicle_custom_model',
        // Contact information captured at time of booking
        'first_name',
        'last_name',
        'email',
        'phone',
        'notify_email',
        'notify_sms',
        // Invoice data captured at time of booking
        'invoice_requested',
        'invoice_company_name',
        'invoice_nip',
        'invoice_street',
        'invoice_street_number',
        'invoice_postal_code',
        'invoice_city',
        // Service snapshot captured at time of booking
        'service_price_at_booking',
        'service_name_at_booking',
        'service_duration_at_booking',
        // Status timestamps
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'location_components' => 'array',
        'invoice_requested' => 'boolean',
        'active_slot' => 'boolean',
        'service_price_at_booking' => 'decimal:2',
        'service_duration_at_booking' => 'integer',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => AppointmentStatus::class,
    ];

    // Relationships
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function carBrand()
    {
        return $this->belongsTo(CarBrand::class);
    }

    public function carModel()
    {
        return $this->belongsTo(CarModel::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ReminderLog, $this>
     */
    public function reminderLogs()
    {
        return $this->hasMany(ReminderLog::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<AuditLog, $this>
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    // Scopes
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', AppointmentStatus::Pending);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', AppointmentStatus::Confirmed);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', AppointmentStatus::Cancelled);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', AppointmentStatus::Completed);
    }

    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
            ->where('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('start_time');
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForStaff($query, int $staffId)
    {
        return $query->where('staff_id', $staffId);
    }

    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('appointment_date', [$startDate, $endDate]);
    }

    // Accessors
    public function getIsUpcomingAttribute(): bool
    {
        return $this->status->isActive()
            && $this->appointment_date >= now()->toDateString();
    }

    public function getIsPastAttribute(): bool
    {
        return $this->appointment_date < now()->toDateString();
    }

    public function getCanBeCancelledAttribute(): bool
    {
        // Only pending or confirmed appointments can be cancelled
        if (! $this->status->isActive()) {
            return false;
        }

        // Check if appointment is in the future
        if ($this->appointment_date < now()->toDateString()) {
            return false;
        }

        // Check 24-hour cancellation policy
        $appointmentDateTime = \Carbon\Carbon::parse(
            $this->appointment_date->format('Y-m-d').' '.$this->start_time->format('H:i:s')
        );

        $cancellationHours = app(\App\Support\Settings\SettingsManager::class)->cancellationHours();
        $cancellationDeadline = $appointmentDateTime->subHours($cancellationHours);

        return now()->lte($cancellationDeadline);
    }

    public function getCancellationDeadlineAttribute(): string
    {
        if (! $this->appointment_date || ! $this->start_time) {
            return '';
        }

        $appointmentDateTime = \Carbon\Carbon::parse(
            $this->appointment_date->format('Y-m-d').' '.$this->start_time->format('H:i:s')
        );

        $cancellationHours = app(\App\Support\Settings\SettingsManager::class)->cancellationHours();
        $deadline = $appointmentDateTime->subHours($cancellationHours);

        return $deadline->format('Y-m-d H:i');
    }

    public function getFormattedLocationAttribute(): ?string
    {
        if ($this->location_address) {
            return $this->location_address;
        }

        // Fallback to customer's legacy address fields
        if ($this->customer) {
            $parts = array_filter([
                $this->customer->street_name,
                $this->customer->street_number,
                $this->customer->postal_code,
                $this->customer->city,
            ]);

            return ! empty($parts) ? implode(', ', $parts) : null;
        }

        return null;
    }

    /**
     * Get Google Maps link for viewing location
     * Opens map with location marker
     */
    public function getGoogleMapsLinkAttribute(): ?string
    {
        // Priority 1: Use Place ID with /place/ format (most reliable marker display)
        if (! empty($this->location_place_id)) {
            return 'https://www.google.com/maps/place/?q=place_id:'.urlencode($this->location_place_id);
        }

        // Priority 2: Use coordinates with /place/ format (reliable marker)
        if (! empty($this->location_latitude) && ! empty($this->location_longitude)) {
            $lat = round($this->location_latitude, 8);
            $lng = round($this->location_longitude, 8);

            return "https://www.google.com/maps/place/{$lat},{$lng}";
        }

        // Priority 3: Use address string with simple query format
        if (! empty($this->location_address)) {
            return 'https://www.google.com/maps?q='.urlencode($this->location_address);
        }

        // No location data available
        return null;
    }

    /**
     * Get Google Maps directions link
     * Opens directly in navigation/directions mode
     */
    public function getGoogleMapsDirectionsLinkAttribute(): ?string
    {
        $baseUrl = 'https://www.google.com/maps/dir/?api=1';

        // Use coordinates with Place ID for best accuracy
        if (! empty($this->location_latitude) && ! empty($this->location_longitude)) {
            $destination = round($this->location_latitude, 8).','.round($this->location_longitude, 8);
            $url = $baseUrl.'&destination='.urlencode($destination);

            // Add Place ID if available (increases accuracy)
            if (! empty($this->location_place_id)) {
                $url .= '&destination_place_id='.urlencode($this->location_place_id);
            }

            return $url;
        }

        // Fallback to address
        if (! empty($this->location_address)) {
            return $baseUrl.'&destination='.urlencode($this->location_address);
        }

        // No location data available
        return null;
    }

    /**
     * Check if appointment has location data
     */
    public function hasLocationData(): bool
    {
        return ! empty($this->location_place_id)
            || (! empty($this->location_latitude) && ! empty($this->location_longitude))
            || ! empty($this->location_address);
    }

    /**
     * Get formatted vehicle display string
     */
    public function getVehicleDisplayAttribute(): ?string
    {
        // If we have a car model, use it
        if ($this->carModel) {
            $display = $this->carModel->full_name;
            if ($this->vehicle_year) {
                $display .= ' ('.$this->vehicle_year.')';
            }

            return $display;
        }

        // If we have custom brand/model
        if ($this->vehicle_custom_brand || $this->vehicle_custom_model) {
            $parts = array_filter([
                $this->vehicle_custom_brand,
                $this->vehicle_custom_model,
                $this->vehicle_year,
            ]);

            return ! empty($parts) ? implode(' ', $parts) : null;
        }

        return null;
    }
}
