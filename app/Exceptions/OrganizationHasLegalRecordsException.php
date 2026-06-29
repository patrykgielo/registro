<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a delete of an Organization is blocked because it still holds
 * legal records (orders, payments, rentals, tenant_payments) that must be
 * retained for the legally required retention period.
 *
 * Legal obligation: Art. 74 Ustawy o rachunkowości + Art. 112 ustawy o VAT
 * (5–6 year retention). Hard-deleting these records would constitute a
 * violation of financial reporting law.
 *
 * Resolution: anonymise PII on the records, then permanently delete the entire
 * organisation via the Faza 5.3 purge command once the retention window has
 * elapsed. Setting $org->bypassDeleteGuard = true intentionally SKIPS this
 * application-level check — the DB-level RESTRICT FK remains as final backstop.
 */
class OrganizationHasLegalRecordsException extends \RuntimeException {}
