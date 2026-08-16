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
     *   total_amount, deposit_amount, deposit_status, deposit_collected_at, deposit_returned_at,
     *   customer_type, invoice_requested, invoice_company_name, invoice_nip, invoice_street*,
     *   invoice_postal_code, invoice_city, rodo_accepted_at, terms_accepted_at,
     *   withdrawal_exclusion_accepted_at, p24_*, paid_at, cancelled_at, completed_at,
     *   created_at, updated_at.
     *   For customer_type='business': company_regon, company_krs (retained — appear on invoice).
     *
     * ANONYMIZED (PII):
     *   customer_first_name, customer_last_name, customer_email (per-row unique),
     *   customer_phone, customer_pesel, customer_street, customer_building,
     *   customer_apartment, customer_city, customer_postal_code,
     *   signatory_id_number, pickup_person_name, pickup_person_id_number,
     *   ip_address, rodo_accepted_ip (*), notes, company_contact_name, deposit_notes.
     *   For customer_type='natural_person': company_regon, company_krs (JDG REGON = identifies person).
     *
     * (*) rodo_accepted_ip is in Order's Eloquent immutable guard — only safe via DB::table().
     *
     * FIXME(DPO): JDG edge case — for sole traders (jednoosobowa działalność gospodarcza), REGON
     *   identifies the natural person. We null company_regon/company_krs for 'natural_person' rows
     *   as a safe default. However, invoice_nip and invoice_company_name for JDG typically contain
     *   the trader's NIP and full name — these are retained here under Art. 112 VAT obligation.
     *   DPO must confirm whether JDG invoice_nip/invoice_company_name retention is proportionate
     *   after the legal retention period expires, or whether pseudonymization is sufficient.
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
                    $update = [
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
                        'deposit_notes' => null,
                    ];

                    // For natural persons: REGON/KRS must be cleared — they are either
                    // empty (correct) or contain JDG data that identifies the natural person.
                    // For business: retain — they appear on B2B invoices (Art. 106e VAT).
                    if ($row->customer_type === 'natural_person') {
                        $update['company_regon'] = null;
                        $update['company_krs'] = null;
                    }

                    DB::table('orders')->where('id', $row->id)->update($update);
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
     *   first_name, last_name, email (per-row unique), phone,
     *   location_address, location_latitude, location_longitude, location_components,
     *   location_place_id, service_location_type (mobile service client location — CRITICAL PII),
     *   registration_number (vehicle plate = PII per UODO guidance),
     *   notes, cancellation_reason (free-text — may contain PII).
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
                        // Mobile service client location (CRITICAL PII — identifies where customer lives/works)
                        'location_address' => null,
                        'location_latitude' => null,
                        'location_longitude' => null,
                        'location_components' => null,
                        'location_place_id' => null,
                        'service_location_type' => null,
                        // Vehicle registration plate (PII per UODO guidance — identifies natural person)
                        'registration_number' => null,
                        // Free-text fields that may contain customer PII entered by staff
                        'notes' => null,
                        'cancellation_reason' => null,
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
     *   first_name, last_name, email (per-row unique), phone,
     *   notes, cancellation_reason (free-text — may contain customer PII entered by staff).
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
                        'notes' => null,
                        'cancellation_reason' => null,
                    ]);
                }
            });

        return $count;
    }

    /**
     * Anonymize Payment webhook_payload and staff-entered notes.
     *
     * webhook_payload is a P24 webhook JSON blob that may contain PII (buyer name,
     * email, IP from the payment gateway). Amounts, status, and P24 session/order IDs
     * are preserved via the parent Order's fields — we do not need the raw payload.
     *
     * notes is a free-text field staff fill in when recording an offline (cash/bank
     * transfer) payment — see OrderService::recordOfflinePayment() — and nothing
     * constrains what goes in it (receipt number, but just as easily a customer's
     * name or ID number). Same PII risk class as Order's deposit_notes/notes, which
     * anonymizeOrders() already nulls.
     *
     * PRESERVED: p24_session_id, p24_order_id, method, recorded_by, amount, currency,
     *            status, verified_at, order_id, organization_id, created_at, updated_at.
     *
     * ANONYMIZED: webhook_payload (nulled — raw gateway response, PII risk),
     *             notes (nulled — free-text, may contain customer PII entered by staff).
     */
    private function anonymizePayments(int $orgId): int
    {
        $count = DB::table('payments')
            ->where('organization_id', $orgId)
            ->where(function ($query) {
                $query->whereNotNull('webhook_payload')
                    ->orWhereNotNull('notes');
            })
            ->count();

        DB::table('payments')
            ->where('organization_id', $orgId)
            ->where(function ($query) {
                $query->whereNotNull('webhook_payload')
                    ->orWhereNotNull('notes');
            })
            ->update(['webhook_payload' => null, 'notes' => null]);

        return $count;
    }
}
