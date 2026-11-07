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

    // GLOBAL budget of the image phase: retry waits, redirects and the per-host delay can otherwise
    // stretch a run without bound. Checked before every attempt and before every sleep (sleeps are
    // truncated to what is left); the lock TTL is derived from it (ImportCatalog::lockSeconds()).
    'image_phase_max_seconds' => (int) env('CATALOG_IMPORT_IMAGE_PHASE_MAX_SECONDS', 900),
    'image_phase_max_bytes' => (int) env('CATALOG_IMPORT_IMAGE_PHASE_MAX_BYTES', 1024 * 1024 * 1024),

    // `ALTER TABLE … AUTO_INCREMENT` waits on the metadata lock of any open transaction that touched
    // the table — and every later query on that table queues behind the ALTER. Bound the wait.
    'auto_increment_lock_wait_seconds' => (int) env('CATALOG_IMPORT_AI_LOCK_WAIT_SECONDS', 5),
];
