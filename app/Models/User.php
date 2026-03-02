<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\Auditable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable;

    /**
     * Fields to exclude from audit logging (sensitive data)
     */
    protected array $auditExclude = [
        'password',
        'remember_token',
        'deletion_token',
        'pending_email_token',
        'updated_at',
        'created_at',
    ];

    /**
     * Fields to include in audit logging (personal data - GDPR relevant)
     */
    protected array $auditInclude = [
        'first_name',
        'last_name',
        'email',
        'phone_e164',
        'street_name',
        'street_number',
        'city',
        'postal_code',
        'billing_street',
        'billing_building_number',
        'billing_apartment_number',
        'billing_postal_code',
        'billing_city',
        'nip',
        'company_name',
        'sms_consent_given_at',
        'sms_opted_out_at',
        'email_marketing_consent_at',
        'email_marketing_opted_out_at',
        'email_newsletter_consent_at',
        'email_newsletter_opted_out_at',
        'sms_marketing_consent_at',
        'sms_marketing_opted_out_at',
        'deletion_requested_at',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'email_verified_at',
        'phone_e164',
        'street_name',
        'street_number',
        'city',
        'postal_code',
        'access_notes',
        'preferred_language',
        'sms_consent_given_at',
        'sms_consent_ip',
        'sms_consent_user_agent',
        'sms_opted_out_at',
        'sms_opt_out_method',
        // Billing address fields
        'billing_street',
        'billing_building_number',
        'billing_apartment_number',
        'billing_postal_code',
        'billing_city',
        'nip',
        'company_name',
        // Profile feature fields
        'max_vehicles',
        'max_addresses',
        'email_marketing_consent_at',
        'email_marketing_consent_ip',
        'email_marketing_opted_out_at',
        'email_newsletter_consent_at',
        'email_newsletter_consent_ip',
        'email_newsletter_opted_out_at',
        'sms_marketing_consent_at',
        'sms_marketing_consent_ip',
        'sms_marketing_opted_out_at',
        'pending_email',
        'pending_email_token',
        'pending_email_expires_at',
        'deletion_requested_at',
        'deletion_token',
        // Password setup fields (admin-created users)
        'password_setup_token',
        'password_setup_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sms_consent_given_at' => 'datetime',
            'sms_opted_out_at' => 'datetime',
            // Profile feature casts
            'max_vehicles' => 'integer',
            'max_addresses' => 'integer',
            'email_marketing_consent_at' => 'datetime',
            'email_marketing_opted_out_at' => 'datetime',
            'email_newsletter_consent_at' => 'datetime',
            'email_newsletter_opted_out_at' => 'datetime',
            'sms_marketing_consent_at' => 'datetime',
            'sms_marketing_opted_out_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            // Password setup casts
            'password_setup_expires_at' => 'datetime',
        ];
    }

    /**
     * Check if user has given SMS consent and has not opted out.
     */
    public function hasSmsConsent(): bool
    {
        return $this->sms_consent_given_at !== null && $this->sms_opted_out_at === null;
    }

    /**
     * Grant SMS consent with tracking.
     *
     * @param  string|null  $ip  IP address of consent
     * @param  string|null  $userAgent  User agent string
     */
    public function grantSmsConsent(?string $ip = null, ?string $userAgent = null): void
    {
        $this->update([
            'sms_consent_given_at' => now(),
            'sms_consent_ip' => $ip,
            'sms_consent_user_agent' => $userAgent,
            'sms_opted_out_at' => null,
            'sms_opt_out_method' => null,
        ]);

        // Record consent in audit trail (GDPR compliance)
        UserConsent::recordConsent($this, 'sms', 'granted');
    }

    /**
     * Revoke SMS consent (opt-out).
     *
     * @param  string  $method  Opt-out method: 'manual', 'STOP_reply', 'admin'
     */
    public function revokeSmsConsent(string $method = 'manual'): void
    {
        $this->update([
            'sms_opted_out_at' => now(),
            'sms_opt_out_method' => $method,
        ]);

        // Record consent revocation in audit trail (GDPR compliance)
        UserConsent::recordConsent($this, 'sms', 'revoked');
    }

    /**
     * Get the user's full name.
     * Returns concatenation of first_name and last_name.
     */
    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /**
     * Get the user's full name via the 'name' attribute.
     *
     * This accessor provides a 'name' attribute by combining first_name and last_name.
     * Used throughout the application by notifications, emails, Blade templates, and API responses.
     *
     * Background: The User model stores names in two separate fields (first_name, last_name)
     * for flexibility, but many parts of the application expect a single 'name' property
     * (notifications, email templates, legacy code). This accessor bridges that gap without
     * requiring changes to all consuming code.
     *
     * @return string Full name (first_name + last_name)
     *
     * @example
     * $user->name         // Returns "Jan Kowalski" (via this accessor)
     * $user->full_name    // Returns "Jan Kowalski" (via getFullNameAttribute)
     * $user->first_name   // Returns "Jan"
     * $user->last_name    // Returns "Kowalski"
     *
     * @see getFullNameAttribute() - Canonical implementation of name concatenation
     * @see getFilamentName() - Used by Filament admin panel (also calls getFullNameAttribute)
     * @since November 2025 - Added to support email notifications and templates
     */
    public function getNameAttribute(): string
    {
        return $this->getFullNameAttribute();
    }

    /**
     * Get the name to display in Filament (avatar, menu, etc.)
     * Required by Filament\Models\Contracts\HasName interface.
     */
    public function getFilamentName(): string
    {
        return $this->getFullNameAttribute();
    }

    /**
     * Get user's preferred language with fallback.
     */
    public function getPreferredLanguageAttribute(?string $value): string
    {
        return $value ?? 'pl';
    }

    /**
     * Get the user's phone number in display format.
     *
     * Converts E.164 format (+48123456789) to user-friendly display format.
     * Returns null if no phone number is set.
     *
     * @return string|null Phone number in display format
     */
    public function getPhoneAttribute(): ?string
    {
        if (empty($this->phone_e164)) {
            return null;
        }

        // Return phone in display format (keep E.164 for consistency with booking wizard)
        return $this->phone_e164;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // NOTE: Maintenance check is handled by AdminMaintenanceCheck middleware
        // AFTER authentication. Do NOT check maintenance here - it breaks login flow
        // because canAccessPanel() is called during attemptWhen() callback.

        // Layer 1: Role-based access control
        if (! $this->hasAnyRole(['super-admin', 'admin', 'staff'])) {
            \Log::warning('Unauthorized panel access attempt', [
                'user' => $this->email,
                'roles' => $this->roles->pluck('name'),
                'ip' => request()->ip(),
            ]);

            return false;
        }

        // Layer 2: Session integrity check (prevents session fixation attacks)
        $sessionUserId = auth()->id();
        if ($sessionUserId && $sessionUserId !== $this->id) {
            \Log::critical('Session fixation attack detected', [
                'session_user_id' => $sessionUserId,
                'current_user_id' => $this->id,
                'user' => $this->email,
                'ip' => request()->ip(),
            ]);

            // Force logout on session mismatch
            auth()->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return false;
        }

        return true;
    }

    // Helper methods for role checking
    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    public function isStaff(): bool
    {
        return $this->hasRole('staff');
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'super-admin']);
    }

    // Relationships
    public function staffAppointments()
    {
        return $this->hasMany(Appointment::class, 'staff_id');
    }

    public function customerAppointments()
    {
        return $this->hasMany(Appointment::class, 'customer_id');
    }

    /**
     * Get the staff schedules (base weekly patterns) for this user.
     */
    public function staffSchedules()
    {
        return $this->hasMany(StaffSchedule::class, 'user_id');
    }

    /**
     * Get the date exceptions for this user.
     */
    public function dateExceptions()
    {
        return $this->hasMany(StaffDateException::class, 'user_id');
    }

    /**
     * Get the vacation periods for this user.
     */
    public function vacationPeriods()
    {
        return $this->hasMany(StaffVacationPeriod::class, 'user_id');
    }

    /**
     * Get the services that this staff member can perform.
     */
    public function services()
    {
        return $this->belongsToMany(Service::class, 'service_staff', 'user_id', 'service_id')
            ->withTimestamps();
    }

    // =========================================================================
    // PROFILE FEATURE: Vehicles & Addresses
    // =========================================================================

    /**
     * Get all vehicles for this user.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(UserVehicle::class);
    }

    /**
     * Get the default vehicle for this user.
     */
    public function vehicle(): HasOne
    {
        return $this->hasOne(UserVehicle::class)->where('is_default', true);
    }

    /**
     * Get all saved addresses for this user.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Get the default address for this user.
     */
    public function address(): HasOne
    {
        return $this->hasOne(UserAddress::class)->where('is_default', true);
    }

    /**
     * Get all consent history records for this user (GDPR compliance).
     */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    /**
     * Check if user can add more vehicles.
     */
    public function canAddVehicle(): bool
    {
        return $this->vehicles()->count() < $this->getVehicleLimitAttribute();
    }

    /**
     * Check if user can add more addresses.
     */
    public function canAddAddress(): bool
    {
        return $this->addresses()->count() < $this->getAddressLimitAttribute();
    }

    /**
     * Get the vehicle limit for this user.
     */
    public function getVehicleLimitAttribute(): int
    {
        return $this->max_vehicles ?? 1;
    }

    /**
     * Get the address limit for this user.
     */
    public function getAddressLimitAttribute(): int
    {
        return $this->max_addresses ?? 1;
    }

    // =========================================================================
    // PROFILE FEATURE: Email Marketing Consent
    // =========================================================================

    /**
     * Check if user has email marketing consent (not opted out).
     */
    public function hasEmailMarketingConsent(): bool
    {
        return $this->email_marketing_consent_at !== null
            && $this->email_marketing_opted_out_at === null;
    }

    /**
     * Grant email marketing consent.
     */
    public function grantEmailMarketingConsent(?string $ip = null): void
    {
        $this->update([
            'email_marketing_consent_at' => now(),
            'email_marketing_consent_ip' => $ip,
            'email_marketing_opted_out_at' => null,
        ]);
    }

    /**
     * Revoke email marketing consent.
     */
    public function revokeEmailMarketingConsent(): void
    {
        $this->update([
            'email_marketing_opted_out_at' => now(),
        ]);
    }

    // =========================================================================
    // PROFILE FEATURE: Email Newsletter Consent
    // =========================================================================

    /**
     * Check if user has email newsletter consent (not opted out).
     */
    public function hasEmailNewsletterConsent(): bool
    {
        return $this->email_newsletter_consent_at !== null
            && $this->email_newsletter_opted_out_at === null;
    }

    /**
     * Grant email newsletter consent.
     */
    public function grantEmailNewsletterConsent(?string $ip = null): void
    {
        $this->update([
            'email_newsletter_consent_at' => now(),
            'email_newsletter_consent_ip' => $ip,
            'email_newsletter_opted_out_at' => null,
        ]);
    }

    /**
     * Revoke email newsletter consent.
     */
    public function revokeEmailNewsletterConsent(): void
    {
        $this->update([
            'email_newsletter_opted_out_at' => now(),
        ]);
    }

    // =========================================================================
    // PROFILE FEATURE: SMS Marketing Consent
    // =========================================================================

    /**
     * Check if user has SMS marketing consent (not opted out).
     */
    public function hasSmsMarketingConsent(): bool
    {
        return $this->sms_marketing_consent_at !== null
            && $this->sms_marketing_opted_out_at === null;
    }

    /**
     * Grant SMS marketing consent.
     */
    public function grantSmsMarketingConsent(?string $ip = null): void
    {
        $this->update([
            'sms_marketing_consent_at' => now(),
            'sms_marketing_consent_ip' => $ip,
            'sms_marketing_opted_out_at' => null,
        ]);
    }

    /**
     * Revoke SMS marketing consent.
     */
    public function revokeSmsMarketingConsent(): void
    {
        $this->update([
            'sms_marketing_opted_out_at' => now(),
        ]);
    }

    // =========================================================================
    // PROFILE FEATURE: Email Change Flow
    // =========================================================================

    /**
     * Request email change (generates token, stores pending email).
     *
     * @param  string  $newEmail  The new email to change to
     * @return string The verification token
     */
    public function requestEmailChange(string $newEmail): string
    {
        $token = Str::random(64);

        $this->update([
            'pending_email' => $newEmail,
            'pending_email_token' => $token,
            'pending_email_expires_at' => now()->addHours(24),
        ]);

        return $token;
    }

    /**
     * Confirm email change with token.
     *
     * @param  string  $token  The verification token
     * @return bool True if successful, false if invalid/expired
     */
    public function confirmEmailChange(string $token): bool
    {
        if ($this->pending_email_token !== $token) {
            return false;
        }

        if ($this->pending_email_expires_at && $this->pending_email_expires_at->isPast()) {
            return false;
        }

        $newEmail = $this->pending_email;

        $this->update([
            'email' => $newEmail,
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
        ]);

        return true;
    }

    /**
     * Cancel pending email change.
     */
    public function cancelEmailChange(): void
    {
        $this->update([
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_expires_at' => null,
        ]);
    }

    /**
     * Check if there's a pending email change.
     */
    public function hasPendingEmailChange(): bool
    {
        return $this->pending_email !== null
            && $this->pending_email_expires_at
            && $this->pending_email_expires_at->isFuture();
    }

    // =========================================================================
    // PROFILE FEATURE: Account Deletion (GDPR Art. 17)
    // =========================================================================

    /**
     * Request account deletion.
     *
     * @return string The confirmation token
     */
    public function requestAccountDeletion(): string
    {
        $token = Str::random(64);

        $this->update([
            'deletion_requested_at' => now(),
            'deletion_token' => $token,
        ]);

        return $token;
    }

    /**
     * Confirm account deletion with token.
     * Anonymizes user data instead of hard delete (preserves appointment history).
     *
     * @param  string  $token  The confirmation token
     * @return bool True if successful
     */
    public function confirmAccountDeletion(string $token): bool
    {
        if ($this->deletion_token !== $token) {
            return false;
        }

        // Anonymize user data
        $this->update([
            'first_name' => 'Usunięty',
            'last_name' => 'Użytkownik',
            'email' => "deleted_{$this->id}@deleted.local",
            'phone_e164' => null,
            'street_name' => null,
            'street_number' => null,
            'city' => null,
            'postal_code' => null,
            'access_notes' => null,
            'password' => bcrypt(Str::random(32)),
            'remember_token' => null,
            // Clear all consent data
            'sms_consent_given_at' => null,
            'sms_consent_ip' => null,
            'sms_consent_user_agent' => null,
            'email_marketing_consent_at' => null,
            'email_marketing_consent_ip' => null,
            'email_newsletter_consent_at' => null,
            'email_newsletter_consent_ip' => null,
            'sms_marketing_consent_at' => null,
            'sms_marketing_consent_ip' => null,
            // Clear deletion request
            'deletion_requested_at' => null,
            'deletion_token' => null,
        ]);

        // Delete related data
        $this->vehicles()->delete();
        $this->addresses()->delete();

        return true;
    }

    /**
     * Cancel account deletion request.
     */
    public function cancelAccountDeletion(): void
    {
        $this->update([
            'deletion_requested_at' => null,
            'deletion_token' => null,
        ]);
    }

    /**
     * Check if there's a pending deletion request.
     */
    public function hasPendingDeletion(): bool
    {
        return $this->deletion_requested_at !== null;
    }

    // =========================================================================
    // PASSWORD SETUP FLOW (Admin-Created Users)
    // =========================================================================

    /**
     * Initiate password setup for admin-created user (generates 30-minute token).
     *
     * @return string The password setup token
     */
    public function initiatePasswordSetup(): string
    {
        $token = Str::random(64);

        \Log::info('[PASSWORD_SETUP] Token generation started', [
            'user_id' => $this->id,
            'email' => $this->email,
            'token_length' => strlen($token),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ]);

        try {
            $updated = $this->update([
                'password_setup_token' => $token,
                'password_setup_expires_at' => now()->addMinutes(30),
            ]);

            if (! $updated) {
                \Log::error('[PASSWORD_SETUP] Update failed (returned false)', [
                    'user_id' => $this->id,
                    'email' => $this->email,
                ]);
                throw new \RuntimeException('Failed to save password setup token');
            }

            // Verify token was actually saved
            $this->refresh();
            if ($this->password_setup_token !== $token) {
                \Log::critical('[PASSWORD_SETUP] Token mismatch after save!', [
                    'user_id' => $this->id,
                    'expected' => $token,
                    'actual' => $this->password_setup_token,
                ]);
                throw new \RuntimeException('Token verification failed after save');
            }

            \Log::info('[PASSWORD_SETUP] Token saved successfully', [
                'user_id' => $this->id,
                'email' => $this->email,
                'token_preview' => substr($token, 0, 8).'...',
            ]);

            return $token;
        } catch (\Exception $e) {
            \Log::error('[PASSWORD_SETUP] Exception during token generation', [
                'user_id' => $this->id,
                'email' => $this->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Complete password setup with token validation.
     *
     * @param  string  $token  The setup token
     * @param  string  $password  The new password (will be hashed)
     * @return bool True if successful, false if invalid/expired
     */
    public function completePasswordSetup(string $token, string $password): bool
    {
        if ($this->password_setup_token !== $token) {
            return false;
        }

        if ($this->password_setup_expires_at && $this->password_setup_expires_at->isPast()) {
            return false;
        }

        $this->update([
            'password' => $password, // Will be hashed by 'hashed' cast
            'password_setup_token' => null,
            'password_setup_expires_at' => null,
        ]);

        return true;
    }
}
