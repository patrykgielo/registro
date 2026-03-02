<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
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
     * Get a setting value by dot notation path.
     *
     * Example: get('booking.business_hours_start', '09:00')
     *
     * @param  string  $path  Dot notation path (group.key)
     * @param  mixed  $default  Default value if setting not found
     */
    public function get(string $path, mixed $default = null): mixed
    {
        [$group, $key] = $this->parsePath($path);

        $cacheKey = $this->getCacheKey($group, $key);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($group, $key, $default) {
            $setting = Setting::group($group)->key($key)->first();

            if (! $setting) {
                return $default;
            }

            // If value is an array with single scalar element, return the element.
            // Preserve arrays (Repeater data) even if they have one item.
            $value = $setting->value;

            // Unwrap single-element arrays only if the element is a scalar (not an array).
            if (is_array($value) && count($value) === 1 && array_key_exists(0, $value) && ! is_array($value[0])) {
                return $value[0];
            }

            return $value;
        });
    }

    /**
     * Set a setting value by dot notation path.
     *
     * Example: set('booking.business_hours_start', '10:00')
     *
     * @param  string  $path  Dot notation path (group.key)
     * @param  mixed  $value  Value to store
     */
    public function set(string $path, mixed $value): bool
    {
        [$group, $key] = $this->parsePath($path);

        // Wrap scalar values in array for JSON storage
        $jsonValue = is_array($value) ? $value : [$value];

        Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $jsonValue]
        );

        // Clear cache for this setting
        $this->clearCache($group, $key);

        return true;
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
        $settings = Setting::all();

        $grouped = [];

        foreach ($settings as $setting) {
            $value = $setting->value;

            // Unwrap single-element arrays only if the element is a scalar (not an array).
            // This preserves Repeater data with one item (e.g., [{"name": "X"}]).
            if (is_array($value) && count($value) === 1 && array_key_exists(0, $value) && ! is_array($value[0])) {
                $value = $value[0];
            }

            $grouped[$setting->group][$setting->key] = $value;
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
            $settings = Setting::group($group)->get();

            $result = [];

            foreach ($settings as $setting) {
                $value = $setting->value;

                // Unwrap single-element arrays only if the element is a scalar (not an array).
                // This preserves Repeater data with one item (e.g., [{"name": "X"}]).
                if (is_array($value) && count($value) === 1 && array_key_exists(0, $value) && ! is_array($value[0])) {
                    $value = $value[0];
                }

                $result[$setting->key] = $value;
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
    private function getCacheKey(string $group, ?string $key = null): string
    {
        if ($key === null) {
            return self::CACHE_PREFIX.":{$group}";
        }

        return self::CACHE_PREFIX.":{$group}:{$key}";
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
     * Check if online booking is enabled.
     */
    public function isBookingEnabled(): bool
    {
        return (bool) $this->get('booking.booking_enabled', true);
    }

    /**
     * Check if user registration is enabled.
     */
    public function isRegistrationEnabled(): bool
    {
        return (bool) $this->get('auth.registration_enabled', true);
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
     * Get header logo URL (for navigation).
     */
    public function headerLogo(): string
    {
        $path = $this->extractFilePath($this->get('appearance.header_logo'));

        if ($path) {
            return Storage::disk('public')->url($path);
        }

        return asset('images/logo.svg');
    }

    /**
     * Get footer logo URL (for footer).
     */
    public function footerLogo(): string
    {
        $path = $this->extractFilePath($this->get('appearance.footer_logo'));

        if ($path) {
            return Storage::disk('public')->url($path);
        }

        return asset('images/logo.svg');
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
}
