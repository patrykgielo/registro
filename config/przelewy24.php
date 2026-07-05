<?php

return [
    'merchant_id' => (int) env('P24_MERCHANT_ID', 0),
    'reports_key' => env('P24_REPORTS_KEY', ''),
    'crc' => env('P24_CRC', ''),
    'is_live' => (bool) env('P24_LIVE', false),
    'pos_id' => env('P24_POS_ID') !== null ? (int) env('P24_POS_ID') : null,

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
