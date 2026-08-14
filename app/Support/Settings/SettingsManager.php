<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Organization;
use App\Models\Setting;
use App\Support\TenantFeature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * SettingsManager Service
 *
 * Manages application settings with caching and dot notation support.
 * Singleton service registered in AppServiceProvider.
 */
class SettingsManager
{
    /**
     * Cache duration in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key prefix.
     */
    private const CACHE_PREFIX = 'settings';

    /**
     * Sentinel cached under a tenant's cache key to mean "no row of this
     * tenant's own exists" — deliberately NOT the same thing as caching the
     * inherited global value under that key (see getForOrganization()'s
     * docblock for why that distinction is the whole fix).
     */
    private const TENANT_ROW_MISS = "\0__settings_tenant_row_miss__\0";

    /**
     * Get a setting value by dot notation path.
     *
     * Tenant-aware: checks tenant-specific setting first, falls back to global default.
     *
     * Example: get('booking.business_hours_start', '09:00')
     *
     * @param  string  $path  Dot notation path (group.key)
     * @param  mixed  $default  Default value if setting not found
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return $this->getForOrganization($path, TenantFeature::currentTenant(), $default);
    }

    /**
     * Set a setting value by dot notation path.
     *
     * Example: set('booking.business_hours_start', '10:00')
     *
     * WARNING: targets TenantFeature::currentTenant() ?? null — i.e. the GLOBAL row —
     * whenever no tenant is resolved. That is correct from the Filament admin panel (a
     * real tenant is always resolved there) but is a footgun from `artisan tinker` or a
     * console command run with no ambient tenant context: it writes the row EVERY tenant
     * without their own override inherits from, while an operator who typed `set(...)`
     * expecting to fix one tenant's setting may not realize that. setGlobal()'s own
     * docblock already warns about the opposite direction (a stale `session('tenant_id')`
     * silently tenant-scoping an intended-global write, see models.md's GOTCHA LC-9) — this
     * is the missing warning for this method. Not currently reachable through any path this
     * codebase's Filament panels use (found in review, 2026-08-14).
     *
     * @param  string  $path  Dot notation path (group.key)
     * @param  mixed  $value  Value to store
     */
    public function set(string $path, mixed $value): bool
    {
        [$group, $key] = $this->parsePath($path);

        // Wrap scalar values in array for JSON storage
        $jsonValue = is_array($value) ? $value : [$value];

        $tenantId = $this->getCurrentTenantId();

        Setting::withoutGlobalScope('organization')->updateOrCreate(
            [
                'organization_id' => $tenantId,
                'group' => $group,
                'key' => $key,
            ],
            ['value' => $jsonValue]
        );

        // Clear cache for this setting
        $this->clearCache($group, $key);

        return true;
    }

    /**
     * Read a platform-GLOBAL setting (organization_id IS NULL), bypassing tenant
     * resolution entirely. Use from the platform panel where a stale session
     * `tenant_id` (left by a prior subdomain visit) must NOT scope the lookup.
     */
    public function getGlobal(string $path, mixed $default = null): mixed
    {
        [$group, $key] = $this->parsePath($path);

        return Cache::remember($this->globalCacheKey($group, $key), self::CACHE_TTL, function () use ($group, $key, $default) {
            $setting = Setting::withoutGlobalScope('organization')
                ->whereNull('organization_id')
                ->group($group)
                ->key($key)
                ->first();

            return $setting ? $this->unwrapValue($setting->value) : $default;
        });
    }

    /**
     * Write a platform-GLOBAL setting (organization_id IS NULL), bypassing tenant
     * resolution. Counterpart to getGlobal() — see its docblock for why.
     */
    public function setGlobal(string $path, mixed $value): bool
    {
        [$group, $key] = $this->parsePath($path);

        // withoutEvents mutes the Setting model's BelongsToOrganization `creating` hook,
        // which would otherwise auto-fill organization_id from a stale session tenant_id
        // (left by a prior subdomain visit) and scope this "global" write to that tenant.
        // withoutGlobalScope skips the read-side tenant filter when matching the existing row.
        Setting::withoutEvents(function () use ($group, $key, $value) {
            Setting::withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => null, 'group' => $group, 'key' => $key],
                ['value' => is_array($value) ? $value : [$value]]
            );
        });

        Cache::forget($this->globalCacheKey($group, $key));
        Cache::forget(self::CACHE_PREFIX.':tenant:global:'.$group);

        return true;
    }

    private function globalCacheKey(string $group, string $key): string
    {
        return self::CACHE_PREFIX.":tenant:global:{$group}:{$key}";
    }

    /**
     * Read a setting scoped to an EXPLICITLY given organization (or none), bypassing
     * currentTenant()/session-fallback resolution entirely.
     *
     * Use when the calling context has already deterministically resolved the tenant for
     * THIS request (e.g. the `tenant` request attribute set by ResolveTenant) and the
     * decision must not be silently overridden by a stale `session('tenant_id')` left by
     * a prior subdomain visit — see CheckRegistrationEnabled for the motivating case.
     *
     * A tenant with no row of its own INHERITS the global value — that inherited value is
     * NEVER cached under the tenant's own cache key (only "this tenant has no row of its
     * own" is, via TENANT_ROW_MISS). The inherited read instead delegates to getGlobal(),
     * which caches under the global key that setGlobal() already invalidates correctly.
     * Caching the inherited value under the tenant key was the original bug here
     * (2026-08-14, feature/settings-store-disconnect code review): setGlobal()'s
     * invalidation only ever clears the global key, so a tenant that had already read
     * (and cached) the OLD inherited value kept serving it for up to CACHE_TTL after an
     * operator corrected the global default — see SettingsManagerGlobalInvalidationTest.
     */
    public function getForOrganization(string $path, ?Organization $organization, mixed $default = null): mixed
    {
        [$group, $key] = $this->parsePath($path);
        $tenantId = $organization?->id;

        if ($tenantId) {
            $tenantCacheKey = self::CACHE_PREFIX.":tenant:{$tenantId}:{$group}:{$key}";

            $tenantValue = Cache::remember($tenantCacheKey, self::CACHE_TTL, function () use ($group, $key, $tenantId) {
                $setting = Setting::withoutGlobalScope('organization')
                    ->where('organization_id', $tenantId)
                    ->group($group)
                    ->key($key)
                    ->first();

                return $setting ? $this->unwrapValue($setting->value) : self::TENANT_ROW_MISS;
            });

            if ($tenantValue !== self::TENANT_ROW_MISS) {
                return $tenantValue;
            }
        }

        return $this->getGlobal($path, $default);
    }

    /**
     * Bulk update multiple groups of settings.
     *
     * Example: updateGroups(['booking' => ['business_hours_start' => '10:00'], 'email' => [...]])
     *
     * @param  array<string, array<string, mixed>>  $groups  Associative array of groups and their key-value pairs
     */
    public function updateGroups(array $groups): bool
    {
        foreach ($groups as $group => $settings) {
            foreach ($settings as $key => $value) {
                $this->set("{$group}.{$key}", $value);
            }
        }

        return true;
    }

    /**
     * Get all settings grouped by group.
     *
     * Returns: ['booking' => ['business_hours_start' => '09:00', ...], 'email' => [...]]
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $tenantId = $this->getCurrentTenantId();
        $query = Setting::withoutGlobalScope('organization');

        if ($tenantId) {
            // Get global defaults + tenant overrides (tenant wins)
            $query->where(function ($q) use ($tenantId) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', $tenantId);
            });
        } else {
            $query->whereNull('organization_id');
        }

        $settings = $query->get();
        $grouped = [];

        foreach ($settings as $setting) {
            $value = $this->unwrapValue($setting->value);
            $groupKey = $setting->group.'.'.$setting->key;

            // Tenant setting overrides global
            if ($setting->organization_id !== null || ! isset($grouped[$setting->group][$setting->key])) {
                $grouped[$setting->group][$setting->key] = $value;
            }
        }

        return $grouped;
    }

    /**
     * Get all settings for a specific group.
     *
     * Example: group('booking') returns ['business_hours_start' => '09:00', ...]
     *
     * @param  string  $group  Group name
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $cacheKey = $this->getCacheKey($group);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($group) {
            $tenantId = $this->getCurrentTenantId();
            $query = Setting::withoutGlobalScope('organization')->group($group);

            if ($tenantId) {
                $query->where(function ($q) use ($tenantId) {
                    $q->whereNull('organization_id')
                        ->orWhere('organization_id', $tenantId);
                });
            } else {
                $query->whereNull('organization_id');
            }

            $settings = $query->get();
            $result = [];

            foreach ($settings as $setting) {
                $value = $this->unwrapValue($setting->value);

                // Tenant setting overrides global
                if ($setting->organization_id !== null || ! isset($result[$setting->key])) {
                    $result[$setting->key] = $value;
                }
            }

            return $result;
        });
    }

    /**
     * Parse dot notation path into group and key.
     *
     * @param  string  $path  Dot notation path (e.g., 'booking.business_hours_start')
     * @return array{0: string, 1: string} [group, key]
     *
     * @throws \InvalidArgumentException
     */
    private function parsePath(string $path): array
    {
        $parts = explode('.', $path, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(
                "Invalid setting path: {$path}. Expected format: 'group.key'"
            );
        }

        return $parts;
    }

    /**
     * Generate cache key for a setting.
     *
     * @param  string  $group  Group name
     * @param  string|null  $key  Setting key (optional)
     */
    /**
     * Unwrap single-element scalar arrays for consistency.
     * Preserves arrays (Repeater data) even if they have one item.
     */
    private function unwrapValue(mixed $value): mixed
    {
        if (is_array($value) && count($value) === 1 && array_key_exists(0, $value) && ! is_array($value[0])) {
            return $value[0];
        }

        return $value;
    }

    /**
     * Get current tenant ID from Filament or public request context.
     */
    private function getCurrentTenantId(): ?int
    {
        return TenantFeature::currentTenant()?->id;
    }

    private function getCacheKey(string $group, ?string $key = null): string
    {
        $tenantId = $this->getCurrentTenantId() ?? 'global';
        $prefix = self::CACHE_PREFIX.":tenant:{$tenantId}";

        if ($key === null) {
            return "{$prefix}:{$group}";
        }

        return "{$prefix}:{$group}:{$key}";
    }

    /**
     * Clear cache for a specific setting or entire group.
     *
     * @param  string  $group  Group name
     * @param  string|null  $key  Setting key (optional, clears entire group if null)
     */
    private function clearCache(string $group, ?string $key = null): void
    {
        if ($key === null) {
            // Clear entire group cache
            Cache::forget($this->getCacheKey($group));
        } else {
            // Clear specific setting cache
            Cache::forget($this->getCacheKey($group, $key));
            // Also clear group cache as it contains this setting
            Cache::forget($this->getCacheKey($group));
        }
    }

    /**
     * Get tenant VAT rate percentage.
     */
    public function vatRate(): int
    {
        return (int) $this->get('general.vat_rate', 23);
    }

    /**
     * Calculate net price from gross (brutto → netto).
     */
    public function nettoPrice(float $brutto): float
    {
        return round($brutto / (1 + $this->vatRate() / 100), 2);
    }

    // ========================================================================
    // Helper Methods for Common Setting Groups
    // ========================================================================

    /**
     * Get all booking configuration settings.
     *
     * @return array<string, mixed>
     */
    public function bookingConfiguration(): array
    {
        return $this->group('booking');
    }

    /**
     * Get booking business hours.
     *
     * @return array{start: string, end: string}
     */
    public function bookingBusinessHours(): array
    {
        return [
            'start' => $this->get('booking.business_hours_start', '09:00'),
            'end' => $this->get('booking.business_hours_end', '18:00'),
        ];
    }

    /**
     * Get advance booking hours requirement.
     */
    public function advanceBookingHours(): int
    {
        return (int) $this->get('booking.advance_booking_hours', 24);
    }

    /**
     * Get cancellation policy hours.
     */
    public function cancellationHours(): int
    {
        return (int) $this->get('booking.cancellation_hours', 24);
    }

    /**
     * Get time slot interval in minutes.
     */
    public function slotIntervalMinutes(): int
    {
        return (int) $this->get('booking.slot_interval_minutes', 30);
    }

    /**
     * Get service location types for booking wizard.
     *
     * @return array<int, array{name: string, icon: string|null, description: string|null}>
     */
    public function serviceLocationTypes(): array
    {
        $types = $this->get('booking_wizard.service_location_types', []);

        return is_array($types) ? $types : [];
    }

    /**
     * Get all map configuration settings.
     *
     * @return array<string, mixed>
     */
    public function mapConfiguration(): array
    {
        return $this->group('map');
    }

    /**
     * Check if online booking (time-slot appointments) is enabled.
     * Returns false for rental-only tenants regardless of the setting.
     */
    public function isBookingEnabled(): bool
    {
        $tenant = TenantFeature::currentTenant();

        // Rental-only tenants (item_rental) never support appointment booking
        if ($tenant && ! $tenant->supportsAppointments()) {
            return false;
        }

        return (bool) $this->get('booking.booking_enabled', true);
    }

    /**
     * Check if the rental (cart/checkout) flow is enabled.
     *
     * Returns true for 'item_rental' and 'both' booking types.
     * Returns false for 'time_slot'-only organizations.
     *
     * When no tenant is resolved, returns true and defers the 404 decision
     * to the controller (consistent with how isBookingEnabled behaves).
     */
    public function isRentalEnabled(): bool
    {
        $tenant = TenantFeature::currentTenant();

        if ($tenant === null) {
            return true;
        }

        return $tenant->supportsRentals();
    }

    /**
     * Check if customers can request rental order extensions.
     */
    public function isRentalExtensionEnabled(): bool
    {
        return (bool) $this->get('rentals.rental_extension_enabled', false);
    }

    /**
     * Check if user registration is enabled.
     */
    public function isRegistrationEnabled(): bool
    {
        return $this->isRegistrationEnabledFor(TenantFeature::currentTenant());
    }

    /**
     * Check if user registration is enabled for an EXPLICITLY given organization
     * (or none), bypassing currentTenant()/session-fallback resolution.
     *
     * @see getForOrganization()
     */
    public function isRegistrationEnabledFor(?Organization $organization): bool
    {
        return (bool) $this->getForOrganization('auth.registration_enabled', $organization, true);
    }

    /**
     * Get all contact information settings.
     *
     * @return array<string, mixed>
     */
    public function contactInformation(): array
    {
        return $this->group('contact');
    }

    /**
     * The canonical, single place that reads a tenant's contact details from the
     * `settings` TABLE — what SystemSettings' Contact tab actually writes, tenant row
     * falling back to the global row via getForOrganization(). Every caller that needs
     * a tenant's address/phone/email for a customer-facing surface (order-paid email,
     * handover/return protocol PDFs, the customer's own order page) MUST go through
     * this method rather than reading $organization->settings directly.
     *
     * Why this exists: three call sites independently hand-rolled this same five-key
     * lookup, and two of the three reached for the WRONG store — organizations.settings,
     * the unrelated JSON column that only ever holds modules/features/location — with
     * nobody noticing for as long as the feature existed (feature/settings-store-disconnect,
     * 2026-08-14; full incident account in tenant-branding.md's "two settings stores"
     * section). A docblock on each copy saying "read via getForOrganization(), not the
     * JSON column" is a convention the next caller can still get wrong by copy-pasting
     * the wrong sibling. A single accessor that already reads the correct store makes
     * that mistake structurally impossible instead: there is no store decision left for
     * a new caller to get wrong, only formatting.
     *
     * Deliberately returns the five RAW fields, not a pre-assembled "address_line, city"
     * string — the three current callers each want a different SHAPE (the notification
     * and the PDF combine address_line+postal_code+city into one display string, the
     * customer's own order page renders them as two separate <dt>/<dd> lines) and none of
     * that formatting choice is where the actual bug was. Combine the raw fields at each
     * call site as needed; do not add a second "assembled" variant of this method for
     * that — see this docblock the next time you're tempted to.
     *
     * @return array{address_line: string, postal_code: string, city: string, phone: string, email: string}
     */
    public function contactDetailsFor(?Organization $organization): array
    {
        return [
            'address_line' => (string) $this->getForOrganization('contact.address_line', $organization, ''),
            'postal_code' => (string) $this->getForOrganization('contact.postal_code', $organization, ''),
            'city' => (string) $this->getForOrganization('contact.city', $organization, ''),
            'phone' => (string) $this->getForOrganization('contact.phone', $organization, ''),
            'email' => (string) $this->getForOrganization('contact.email', $organization, ''),
        ];
    }

    /**
     * Get the email address for closure requests.
     *
     * Falls back to the contact email, then to the platform default.
     */
    public function closureRequestEmail(): string
    {
        $val = $this->get('account.closure_request_email');

        if (! empty($val) && is_string($val)) {
            return $val;
        }

        return $this->contactInformation()['email'] ?? 'kontakt@registro.app';
    }

    /**
     * Get all marketing content settings.
     *
     * @return array<string, mixed>
     */
    public function marketingContent(): array
    {
        return $this->group('marketing');
    }

    // ========================================================================
    // Appearance Helper Methods
    // ========================================================================

    /**
     * Extract and validate file path from various FileUpload formats.
     *
     * Handles: string, numeric array, associative array (UUID keys)
     * Security: validates against path traversal and absolute paths
     */
    private function extractFilePath(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $path = null;

        if (is_string($value)) {
            $path = $value;
        } elseif (is_array($value)) {
            // reset() zwraca pierwszy element niezależnie od typu klucza
            $firstValue = reset($value);
            $path = is_string($firstValue) ? $firstValue : null;
        }

        if ($path === null) {
            return null;
        }

        // Security: validate path
        return $this->validateFilePath($path);
    }

    /**
     * Validate file path against security threats.
     */
    private function validateFilePath(string $path): ?string
    {
        // Reject empty paths
        if (empty(trim($path))) {
            return null;
        }

        // Reject absolute paths (Unix and Windows)
        if (str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path)) {
            return null;
        }

        // Reject path traversal attempts
        if (str_contains($path, '../') || str_contains($path, '..\\')) {
            return null;
        }

        // Reject livewire-tmp paths (not finalized)
        if (str_contains($path, 'livewire-tmp')) {
            return null;
        }

        // Normalize path separators
        $normalized = str_replace('\\', '/', $path);

        // Verify file exists in storage
        if (! Storage::disk('public')->exists($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Get header logo URL (for navigation), or null if the tenant hasn't
     * configured one. Callers must fall back to a text brand name — never
     * to a bundled asset, which would show a foreign/placeholder brand.
     */
    public function headerLogo(): ?string
    {
        $path = $this->extractFilePath($this->get('appearance.header_logo'));

        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Get footer logo URL (for footer), or null if the tenant hasn't
     * configured one. Callers must fall back to a text brand name — never
     * to a bundled asset, which would show a foreign/placeholder brand.
     */
    public function footerLogo(): ?string
    {
        $path = $this->extractFilePath($this->get('appearance.footer_logo'));

        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Get the configured application name.
     *
     * Falls back to config('app.name') if no setting exists.
     */
    public function appName(): string
    {
        $name = $this->get('general.app_name');

        if (empty($name) || ! is_string($name)) {
            return config('app.name', 'Registro');
        }

        return $name;
    }

    /**
     * Get logo alt text.
     */
    public function logoAlt(): string
    {
        $alt = $this->get('appearance.logo_alt');

        // Return default if value is null or empty
        if (empty($alt) || ! is_string($alt)) {
            return $this->appName();
        }

        return $alt;
    }

    /**
     * Get footer column title.
     */
    public function footerColumnTitle(): string
    {
        $title = $this->get('cms.footer_column_title');

        if (empty($title) || ! is_string($title)) {
            return 'Nawigacja';
        }

        return $title;
    }

    // ========================================================================
    // Design Helper Methods
    // ========================================================================

    /**
     * Get the tenant's brand color (hex).
     *
     * Returns the configured brand_color or the default Registro Indigo.
     */
    public function brandColor(): string
    {
        $color = $this->get('design.brand_color', '#6366f1');

        if (empty($color) || ! is_string($color)) {
            return '#6366f1';
        }

        // Validate hex format before returning
        if (! preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $color)) {
            return '#6366f1';
        }

        return $color;
    }

    /**
     * Get the tenant's configured font family key.
     *
     * Returns one of: inter | system | roboto | poppins | montserrat
     */
    public function fontFamily(): string
    {
        $font = $this->get('design.font_family', 'inter');

        $allowed = ['inter', 'system', 'roboto', 'poppins', 'montserrat'];

        if (! in_array($font, $allowed, true)) {
            return 'inter';
        }

        return $font;
    }

    /**
     * Get the tenant's brand name for public display.
     *
     * Returns brand_name_override if set, otherwise falls back to appName().
     */
    public function brandName(): string
    {
        $override = $this->get('design.brand_name_override');

        if (! empty($override) && is_string($override)) {
            return $override;
        }

        return $this->appName();
    }

    /**
     * Check if the tenant wants their logo injected in emails.
     */
    public function useLogoInEmails(): bool
    {
        return (bool) $this->get('design.use_logo_in_emails', true);
    }

    /**
     * Check if the tenant wants their brand color used in emails.
     */
    public function useColorInEmails(): bool
    {
        return (bool) $this->get('design.use_color_in_emails', true);
    }
}
