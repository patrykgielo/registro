<?php

declare(strict_types=1);

/**
 * Data retention periods for GDPR compliance and Polish tax/civil law.
 *
 * Legal basis:
 * - legal_records_years: Art. 112 VAT + Art. 70 Ordynacja Podatkowa — invoices/payments
 *   must be retained for 5 full years after the year in which the tax obligation arose.
 *   Using 6 years to cover edge cases (year boundary + processing lag).
 * - claims_b2c_years: KC art. 118 — B2C prescription period (general claims): 6 years.
 * - claims_b2b_years: KC art. 118 — B2B prescription period (business claims): 3 years.
 * - purge_grace_days: Grace window between Closed state and PII anonymization.
 *   Allows the tenant operator to appeal/re-activate before PII is destroyed.
 *   Set to 30 days per offboarding policy (Faza 5.4).
 *
 * Ephemeral data (no legal obligation — purged as soon as no longer needed):
 * - analytics_months: GDPR LIA (Legitimate Interest Assessment) retention cap.
 *   13 months = 1 full year of comparisons + current month.
 * - carts_days: Abandoned carts hold no booking value after 7 days.
 * - statistics_days: Daily snapshots beyond 1 year have diminishing analytical value.
 *   Super-admin can backfill historical data from orders if needed.
 */
return [
    // --- Legal records (invoicing / tax) ---
    'legal_records_years' => 6,

    // --- Civil law claims ---
    'claims_b2c_years' => 6,
    'claims_b2b_years' => 3,

    // --- Offboarding ---
    'purge_grace_days' => 30,

    // --- Ephemeral / analytics ---
    'analytics_months' => 13,
    'carts_days' => 7,
    'statistics_days' => 365,

    // --- Organization data exports ---
    // Signed download URLs are valid for 7 days; keep ZIPs for 8 days (TTL + 1 day margin).
    // GDPR art. 5(1)(e): data must not be retained longer than necessary.
    'export_files_days' => 8,
];
