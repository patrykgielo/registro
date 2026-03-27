<?php

return [
    'merchant_id' => (int) env('P24_MERCHANT_ID', 0),
    'reports_key' => env('P24_REPORTS_KEY', ''),
    'crc' => env('P24_CRC', ''),
    'is_live' => (bool) env('P24_LIVE', false),
    'pos_id' => env('P24_POS_ID') !== null ? (int) env('P24_POS_ID') : null,
];
