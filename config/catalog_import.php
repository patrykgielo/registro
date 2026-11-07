<?php

/*
|--------------------------------------------------------------------------
| catalog:import — image fetching politeness (app/docs/features/catalog-json-importer.md)
|--------------------------------------------------------------------------
| Wikimedia and similar hosts rate-limit anonymous clients hard (HTTP 429 after a
| couple of quick requests) and require a descriptive User-Agent. Never put a
| person's e-mail address here.
*/
return [
    'user_agent' => env('CATALOG_IMPORT_USER_AGENT', 'RegistroCatalogImporter/1.0 (+'.env('APP_URL', 'http://localhost').')'),

    // Minimum pause between two requests to the SAME host (milliseconds). Overridable per run: --image-delay=
    'image_delay_ms' => (int) env('CATALOG_IMPORT_IMAGE_DELAY_MS', 1000),

    // 429 / 503 only. Any other 4xx fails the image immediately.
    'max_retries' => 3,
    'max_retry_wait_seconds' => 60,
];
