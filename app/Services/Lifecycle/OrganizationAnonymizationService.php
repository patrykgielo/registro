<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Anonymizes PII for a closed organization while preserving legally required accounting data.
 *
 * Legal framework (Polish law + RODO):
 * - PII of natural persons (name, email, PESEL, address, IP) must be anonymized once the
 *   processing purpose has expired (RODO art. 5(1)(e), art. 17).
 * - Accounting data (NIP, REGON, invoice amounts, order numbers, fiscal timestamps) must
 *   be retained for ≥5–6 years (Art. 112 VAT / Art. 70 Ordynacja Podatkowa).
 * - Consents (rodo_accepted_at, terms_accepted_at) are timestamps that prove a legal act;
 *   they are retained as part of the accounting record. The IP that recorded the consent
 *   (rodo_accepted_ip) is PII and is anonymized.
 *
 * All updates use DB::table() directly to:
 * - Bypass the Order model's Eloquent immutable-field guard (rodo_accepted_ip is immutable
 *   via the booted() updating() hook, which fires only on Eloquent Model::update()).
 * - Avoid loading models into memory for bulk updates (performance).
 *
 * Idempotent: re-running is safe — already-anonymized fields are overwritten with the
 * same placeholder values. The email placeholder includes the order/appointment/rental id
 * to remain unique per row across repeated runs.
 */
class OrganizationAnonymizationService
{
    /**
     * Anonymize all PII for the given organization.
     *
     * Processes Orders, Appointments, Rentals, and Payments.
     * Runs inside a DB transaction; caller is responsible for wrapping in try/catch.
     *
     * @return array{orders: int, appointments: int, rentals: int, payments: int}
     */
    public function anonymize(Organization $org): array
    {
        $orgId = $org->id;
        $counts = ['orders' => 0, 'appointments' => 0, 'rentals' => 0, 'payments' => 0];

        DB::transaction(function () use ($orgId, &$counts) {
            $counts['orders'] = $this->anonymizeOrders($orgId);
            $counts['appointments'] = $this->anonymizeAppointments($orgId);
            $counts['rentals'] = $this->anonymizeRentals($orgId);
            $counts['payments'] = $this->anonymizePayments($orgId);
        });

        return $counts;
    }

    /**
     * Anonymize Order PII.
     *
     * PRESERVED (accounting/legal):
     *   order_number, status, currency, subtotal, discount_amount, tax_amount,
     *   total_amount, deposit_*, customer_type, invoice_requested,
     *   invoice_company_name, invoice_nip, invoice_street*, invoice_postal_code,
     *   invoice_city, company_regon, company_krs, rodo_accepted_at,
     *   terms_accepted_at, withdrawal_exclusion_accepted_at, p24_*, paid_at,
     *   cancelled_at, completed_at, created_at, updated_at.
     *
     * ANONYMIZED (PII):
     *   customer_first_name, customer_last_name, customer_email (per-row unique),
     *   customer_phone, customer_pesel, customer_street, customer_building,
     *   customer_apartment, customer_city, customer_postal_code,
     *   signatory_id_number, pickup_person_name, pickup_person_id_number,
     *   ip_address, rodo_accepted_ip (*), notes, company_contact_name.
     *
     * (*) rodo_accepted_ip is in Order's Eloquent immutable guard — only safe via DB::table().
     */
    private function anonymizeOrders(int $orgId): int
    {
        $count = DB::table('orders')->where('organization_id', $orgId)->count();

        // Use chunkById for per-row unique email placeholder.
        // DB::table() bypasses Order's Eloquent immutable-field guard.
        DB::table('orders')
            ->where('organization_id', $orgId)
            ->chunkById(500, function ($chunk) {
                foreach ($chunk as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'customer_first_name' => 'Anonimizowane',
                        // customer_last_name is NOT NULL in schema — use placeholder, not null
                        'customer_last_name' => 'Anonimizowane',
                        'customer_email' => "anon_{$row->id}@anonymized.local",
                        'customer_phone' => null,
                        'customer_pesel' => null,
                        'customer_street' => null,
                        'customer_building' => null,
                        'customer_apartment' => null,
                        'customer_city' => null,
                        'customer_postal_code' => null,
                        'signatory_id_number' => null,
                        'pickup_person_name' => null,
                        'pickup_person_id_number' => null,
                        'ip_address' => null,
                        'rodo_accepted_ip' => null,
                        'notes' => null,
                        'company_contact_name' => null,
                    ]);
                }
            });

        return $count;
    }

    /**
     * Anonymize Appointment PII.
     *
     * PRESERVED (accounting/legal):
     *   invoice_requested, invoice_company_name, invoice_nip, invoice_street*,
     *   invoice_postal_code, invoice_city, service_price_at_booking,
     *   service_name_at_booking, service_duration_at_booking,
     *   appointment_date, start_time, end_time, status, completed_at,
     *   cancelled_at, created_at, updated_at.
     *
     * ANONYMIZED (PII):
     *   first_name, last_name, email (per-row unique), phone.
     */
    private function anonymizeAppointments(int $orgId): int
    {
        $count = DB::table('appointments')->where('organization_id', $orgId)->count();

        DB::table('appointments')
            ->where('organization_id', $orgId)
            ->chunkById(500, function ($chunk) {
                foreach ($chunk as $row) {
                    DB::table('appointments')->where('id', $row->id)->update([
                        'first_name' => 'Anonimizowane',
                        'last_name' => null,
                        'email' => "anon_{$row->id}@anonymized.local",
                        'phone' => null,
                    ]);
                }
            });

        return $count;
    }

    /**
     * Anonymize Rental PII.
     *
     * PRESERVED (accounting/legal):
     *   invoice_requested, invoice_company_name, invoice_nip, invoice_street*,
     *   invoice_postal_code, invoice_city, total_price, unit_price_at_booking,
     *   deposit_amount, quantity, start_date, end_date, status, created_at,
     *   updated_at.
     *
     * ANONYMIZED (PII):
     *   first_name, last_name, email (per-row unique), phone.
     */
    private function anonymizeRentals(int $orgId): int
    {
        $count = DB::table('rentals')->where('organization_id', $orgId)->count();

        DB::table('rentals')
            ->where('organization_id', $orgId)
            ->chunkById(500, function ($chunk) {
                foreach ($chunk as $row) {
                    DB::table('rentals')->where('id', $row->id)->update([
                        'first_name' => 'Anonimizowane',
                        'last_name' => null,
                        'email' => "anon_{$row->id}@anonymized.local",
                        'phone' => null,
                    ]);
                }
            });

        return $count;
    }

    /**
     * Anonymize Payment webhook_payload.
     *
     * webhook_payload is a P24 webhook JSON blob that may contain PII (buyer name,
     * email, IP from the payment gateway). Amounts, status, and P24 session/order IDs
     * are preserved via the parent Order's fields — we do not need the raw payload.
     *
     * PRESERVED: p24_session_id, p24_order_id, amount, currency, status, verified_at,
     *            order_id, organization_id, created_at, updated_at.
     *
     * ANONYMIZED: webhook_payload (nulled — raw gateway response, PII risk).
     */
    private function anonymizePayments(int $orgId): int
    {
        $count = DB::table('payments')
            ->where('organization_id', $orgId)
            ->whereNotNull('webhook_payload')
            ->count();

        DB::table('payments')
            ->where('organization_id', $orgId)
            ->whereNotNull('webhook_payload')
            ->update(['webhook_payload' => null]);

        return $count;
    }
}
