<?php

declare(strict_types=1);

namespace App\Support\Catalog;

/**
 * config/catalog_import.php values that would break the importer if set to nonsense: a budget of
 * 0 seconds fails every import, a lock wait of 0 means "wait forever" to MySQL. A value <= 0 (or
 * non-numeric) falls back to the documented default instead of being used.
 */
final class CatalogImportConfig
{
    public const DEFAULT_IMAGE_PHASE_SECONDS = 900;

    public const DEFAULT_IMAGE_PHASE_BYTES = 1024 * 1024 * 1024;

    public const DEFAULT_AUTO_INCREMENT_LOCK_WAIT_SECONDS = 5;

    public static function imagePhaseSeconds(): int
    {
        return self::positive('catalog_import.image_phase_max_seconds', self::DEFAULT_IMAGE_PHASE_SECONDS);
    }

    public static function imagePhaseBytes(): int
    {
        return self::positive('catalog_import.image_phase_max_bytes', self::DEFAULT_IMAGE_PHASE_BYTES);
    }

    public static function autoIncrementLockWaitSeconds(): int
    {
        return self::positive('catalog_import.auto_increment_lock_wait_seconds', self::DEFAULT_AUTO_INCREMENT_LOCK_WAIT_SECONDS);
    }

    /** Image budget + 1 h for validation, the write transaction and a margin. */
    public static function importLockSeconds(): int
    {
        return self::imagePhaseSeconds() + 3600;
    }

    /** Scratch directories untouched for longer than this are leftovers of killed runs. */
    public static function staleScratchSeconds(): int
    {
        return max(6 * 3600, 2 * self::importLockSeconds());
    }

    private static function positive(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
