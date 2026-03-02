<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MaintenanceType;
use App\Models\MaintenanceEvent;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MaintenanceService
 *
 * Manages application maintenance mode with cache-based state storage.
 * Uses Redis in production, array cache in testing.
 * Singleton service registered in AppServiceProvider.
 */
class MaintenanceService
{
    /**
     * Cache keys
     */
    private const CACHE_KEY_MODE = 'maintenance:mode';

    private const CACHE_KEY_CONFIG = 'maintenance:config';

    private const CACHE_KEY_ENABLED_AT = 'maintenance:enabled_at';

    private const CACHE_KEY_SECRET_TOKEN = 'maintenance:secret_token';

    public function __construct(
        private SettingsManager $settingsManager
    ) {}

    /**
     * Get the cache store to use for maintenance mode.
     * Uses 'redis' in production/development, falls back to default in testing.
     */
    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        // In testing environment, use default cache (array)
        if (app()->environment('testing')) {
            return Cache::store();
        }

        // In production/development, use Redis
        return Cache::store('redis');
    }

    /**
     * Check if maintenance mode is currently active.
     */
    public function isActive(): bool
    {
        return $this->cacheStore()->has(self::CACHE_KEY_MODE);
    }

    /**
     * Get current maintenance mode type.
     */
    public function getType(): ?MaintenanceType
    {
        $mode = $this->cacheStore()->get(self::CACHE_KEY_MODE);

        if (! $mode) {
            return null;
        }

        return MaintenanceType::from($mode);
    }

    /**
     * Get maintenance configuration.
     * Reads from Settings table (persistent) with Redis cache overlay for active session.
     */
    public function getConfig(): array
    {
        // First check Redis for active session config
        $redisConfig = $this->cacheStore()->get(self::CACHE_KEY_CONFIG, []);

        if (! empty($redisConfig)) {
            return $redisConfig;
        }

        // Fallback to Settings table (persistent storage)
        return $this->getPersistedConfig();
    }

    /**
     * Get persisted config from Settings table.
     */
    public function getPersistedConfig(): array
    {
        $prelaunch = $this->settingsManager->group('prelaunch');

        return [
            'page_title' => $prelaunch['page_title'] ?? null,
            'main_heading' => $prelaunch['heading'] ?? null,
            'tagline' => $prelaunch['tagline'] ?? null,
            'launch_date_label' => $prelaunch['date_label'] ?? null,
            'launch_date' => $prelaunch['launch_date'] ?? null,
            'description_part1' => $prelaunch['description_1'] ?? null,
            'description_part2' => $prelaunch['description_2'] ?? null,
            'contact_heading' => $prelaunch['contact_heading'] ?? null,
            'copyright_text' => $prelaunch['copyright_text'] ?? null,
            'html_lang' => $prelaunch['html_lang'] ?? 'pl',
            'background_image' => $prelaunch['background_image'] ?? null,
        ];
    }

    /**
     * Check if user can bypass maintenance mode.
     */
    public function canBypass(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $type = $this->getType();

        if (! $type) {
            return true; // No maintenance active
        }

        // ONLY super-admin can bypass maintenance (admin sees maintenance page)
        if ($user->hasRole('super-admin')) {
            Log::info('Maintenance bypass granted (super-admin)', [
                'user_id' => $user->id,
                'email' => $user->email,
                'type' => $type->value,
            ]);

            return true;
        }

        // Everyone else (admin, staff, users) - no bypass
        return false;
    }

    /**
     * Check if secret token is valid for bypass.
     */
    public function checkSecretToken(string $token): bool
    {
        $storedToken = $this->cacheStore()->get(self::CACHE_KEY_SECRET_TOKEN);

        if (! $storedToken) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    /**
     * Enable maintenance mode.
     */
    public function enable(
        MaintenanceType $type,
        ?User $user = null,
        array $config = []
    ): void {
        // Generate secret token for bypass (except pre-launch)
        $secretToken = null;
        if ($type !== MaintenanceType::PRELAUNCH) {
            $secretToken = $this->generateSecretToken();
        }

        // Persist config to Settings table (survives disable)
        if ($type === MaintenanceType::PRELAUNCH) {
            $this->persistConfig($config);
        }

        // Store in cache (no TTL - manually disabled)
        $this->cacheStore()->put(self::CACHE_KEY_MODE, $type->value);
        $this->cacheStore()->put(self::CACHE_KEY_CONFIG, $config);
        $this->cacheStore()->put(self::CACHE_KEY_ENABLED_AT, now()->toIso8601String());

        if ($secretToken) {
            $this->cacheStore()->put(self::CACHE_KEY_SECRET_TOKEN, $secretToken);
        }

        // Log event to database
        $this->logEvent(
            action: 'enabled',
            type: $type,
            user: $user,
            metadata: array_merge($config, [
                'secret_token' => $secretToken,
                'enabled_via' => 'MaintenanceService',
            ])
        );

        Log::info('Maintenance mode enabled', [
            'type' => $type->value,
            'user_id' => $user?->id,
            'config' => $config,
            'secret_token' => $secretToken ? 'generated' : 'none',
        ]);
    }

    /**
     * Persist prelaunch config to Settings table.
     * This survives maintenance mode disable/enable cycles.
     */
    public function persistConfig(array $config): void
    {
        $mapping = [
            'page_title' => 'page_title',
            'main_heading' => 'heading',
            'tagline' => 'tagline',
            'launch_date_label' => 'date_label',
            'launch_date' => 'launch_date',
            'description_part1' => 'description_1',
            'description_part2' => 'description_2',
            'contact_heading' => 'contact_heading',
            'copyright_text' => 'copyright_text',
            'html_lang' => 'html_lang',
            'background_image' => 'background_image',
        ];

        foreach ($mapping as $configKey => $settingsKey) {
            if (array_key_exists($configKey, $config) && $config[$configKey] !== null) {
                $this->settingsManager->set("prelaunch.{$settingsKey}", $config[$configKey]);
            }
        }

        Log::info('Maintenance config persisted to Settings table', [
            'keys' => array_keys(array_filter($config, fn ($v) => $v !== null)),
        ]);
    }

    /**
     * Disable maintenance mode.
     */
    public function disable(?User $user = null): void
    {
        $type = $this->getType();

        if (! $type) {
            Log::warning('Attempted to disable maintenance mode when not active');

            return;
        }

        // Remove from cache
        $this->cacheStore()->forget(self::CACHE_KEY_MODE);
        $this->cacheStore()->forget(self::CACHE_KEY_CONFIG);
        $this->cacheStore()->forget(self::CACHE_KEY_ENABLED_AT);
        $this->cacheStore()->forget(self::CACHE_KEY_SECRET_TOKEN);

        // Log event
        $this->logEvent(
            action: 'disabled',
            type: $type,
            user: $user,
            metadata: [
                'disabled_via' => 'MaintenanceService',
            ]
        );

        Log::info('Maintenance mode disabled', [
            'type' => $type->value,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * Get current status information.
     */
    public function getStatus(): array
    {
        $isActive = $this->isActive();
        $type = $this->getType();

        return [
            'active' => $isActive,
            'type' => $type?->value,
            'type_label' => $type?->label(),
            'can_bypass' => $type?->canBypass(),
            'retry_after' => $type?->retryAfter(),
            'enabled_at' => $this->cacheStore()->get(self::CACHE_KEY_ENABLED_AT),
            'config' => $this->getConfig(),
        ];
    }

    /**
     * Get secret bypass token (for sharing with admins).
     */
    public function getSecretToken(): ?string
    {
        return $this->cacheStore()->get(self::CACHE_KEY_SECRET_TOKEN);
    }

    /**
     * Regenerate secret token.
     */
    public function regenerateSecretToken(?User $user = null): string
    {
        $newToken = $this->generateSecretToken();

        $this->cacheStore()->put(self::CACHE_KEY_SECRET_TOKEN, $newToken);

        Log::info('Secret token regenerated', [
            'user_id' => $user?->id,
        ]);

        return $newToken;
    }

    /**
     * Log maintenance event to database.
     */
    private function logEvent(
        string $action,
        MaintenanceType $type,
        ?User $user = null,
        array $metadata = []
    ): void {
        try {
            MaintenanceEvent::create([
                'type' => $type,
                'action' => $action,
                'user_id' => $user?->id,
                'ip_address' => request()->ip(),
                'message' => $metadata['message'] ?? null,
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log maintenance event', [
                'error' => $e->getMessage(),
                'action' => $action,
                'type' => $type->value,
            ]);
        }
    }

    /**
     * Generate random secret token.
     */
    private function generateSecretToken(): string
    {
        return 'registro-'.Str::random(32);
    }
}
