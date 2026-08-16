<?php

/*
 * TYPES HERE ARE NOT COSMETIC — they are the SDK's constructor signature.
 *
 * Przelewy24\Przelewy24::__construct() takes (int $merchantId, string
 * $reportsKey, string $crc, bool $isLive, ?string $posId). Przelewy24Service
 * is a `declare(strict_types=1)` file, so strict mode applies at the CALL
 * site: handing it an int for $posId is a fatal TypeError — an \Error, not an
 * \Exception — thrown before any network I/O happens.
 *
 * That is exactly what took production down on 2026-08-16: an unconfigured
 * gateway (`P24_POS_ID=` present-but-empty in .env, as .env.production.example
 * ships it) made this file yield int(0), and every online checkout submit
 * returned a 500 instead of a payment redirect.
 *
 * Two rules follow, and both must hold for EVERY value below:
 *   1. Cast to the type the SDK declares — never leave a raw env() string.
 *   2. An empty/absent env var must become the SDK's own "not set" value
 *      (null for $posId), never a coerced 0 or ''. `(int) ''` is 0, a
 *      perfectly valid-looking merchant id that fails much later and far
 *      less clearly; and '' passed down to Config's ?int $posId is itself a
 *      TypeError in the vendor's weak-mode coercion.
 *
 * "Is the gateway usable at all" is decided in one place only —
 * Przelewy24Service::isConfigured() — which reads these values back.
 */

$posId = env('P24_POS_ID');

return [
    'merchant_id' => (int) env('P24_MERCHANT_ID', 0),
    'reports_key' => (string) env('P24_REPORTS_KEY', ''),
    'crc' => (string) env('P24_CRC', ''),
    'is_live' => (bool) env('P24_LIVE', false),

    // ?string, per the SDK. Empty string and null both mean "not configured";
    // the SDK then falls back to merchant_id (see Przelewy24\Config::posId()).
    'pos_id' => ($posId === null || trim((string) $posId) === '') ? null : (string) $posId,

    /*
     * Extra grace period (minutes) applied ON TOP OF the normal expires_at TTL
     * before orders:cleanup-expired is allowed to cancel a pending_payment order
     * that already has a P24 transaction registered (p24_token/p24_session_id set).
     *
     * A registered transaction means the customer is (or recently was) actively
     * on P24's own gateway — a slow bank/BLIK confirmation must not be cancelled
     * out from under them. See Order::scopeExpired() and
     * OrderItem::scopeBlockingAvailability() (the two MUST mirror each other).
     *
     * Read through Order::ttlGraceMinutes() ONLY — never read this raw config
     * value directly, it is NOT bounds-checked here. That helper clamps the
     * value to a sane [0, 1440] minutes (0-24h) range: a negative value would
     * invert the intent (cancelling P24-registered orders EARLY, mid-payment)
     * and an absurdly large one would effectively disable expiry for
     * P24-registered orders indefinitely.
     */
    'transaction_grace_minutes' => (int) env('P24_TRANSACTION_GRACE_MINUTES', 120),
];
