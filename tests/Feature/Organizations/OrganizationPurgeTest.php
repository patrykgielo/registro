<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use App\Models\User;
use App\Services\Lifecycle\OrganizationAnonymizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 5.3a: OrganizationAnonymizationService + PurgeClosedOrganizationsCommand tests.
 *
 * These tests verify:
 * - PII is cleared while accounting/legal data is preserved (RODO + Art. 112 VAT)
 * - The purge command processes eligible orgs (Closed + purge_after past)
 * - Ephemeral data (carts, analytics, snapshots) is hard-deleted
 * - Legal records (orders, payments) remain (anonymized)
 * - Non-eligible orgs (future purge_after, non-Closed) are skipped
 * - Dry-run makes no changes
 * - purge_after is set by observer when transitioning to Closed
 * - Soft-deleted orgs are excluded from normal queries
 */
class OrganizationPurgeTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationAnonymizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrganizationAnonymizationService::class);
    }

    // ─── Anonymization: Orders ────────────────────────────────────────────────

    public function test_anonymize_clears_order_pii_but_keeps_accounting_fields(): void
    {
        $org = Organization::factory()->create();

        // Insert a complete order row with known PII via DB::table to avoid
        // Order's Eloquent immutable guard (rodo_accepted_ip is immutable via booted()).
        DB::table('orders')->insert([
            'organization_id' => $org->id,
            'user_id' => $org->owner_id,
            'order_number' => 'TEST-001',
            'status' => 'completed',
            'currency' => 'PLN',
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '500.00',
            'customer_type' => 'natural_person',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'customer_email' => 'jan@example.com',
            'customer_phone' => '123456789',
            'customer_pesel' => '85010112345',
            'customer_street' => 'ul. Marszałkowska',
            'customer_building' => '5',
            'customer_apartment' => '10',
            'customer_city' => 'Warszawa',
            'customer_postal_code' => '00-001',
            'invoice_requested' => 1,
            'invoice_company_name' => 'ACME Sp. z o.o.',
            'invoice_nip' => '1234563218',
            'invoice_street' => 'ul. Główna',
            'invoice_street_number' => '1',
            'invoice_postal_code' => '00-100',
            'invoice_city' => 'Warszawa',
            'company_contact_name' => 'Piotr Nowak',
            'ip_address' => '192.168.1.1',
            'rodo_accepted_ip' => '192.168.1.1',
            'rodo_accepted_at' => now(),
            'terms_accepted_at' => now(),
            'notes' => 'Admin note about customer',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->anonymize($org);

        $row = DB::table('orders')->where('organization_id', $org->id)->first();

        // PII must be cleared
        $this->assertSame('Anonimizowane', $row->customer_first_name);
        // customer_last_name is NOT NULL — anonymized to placeholder, not null
        $this->assertSame('Anonimizowane', $row->customer_last_name);
        $this->assertStringContainsString('@anonymized.local', $row->customer_email);
        $this->assertNull($row->customer_phone);
        $this->assertNull($row->customer_pesel);
        $this->assertNull($row->customer_street);
        $this->assertNull($row->customer_building);
        $this->assertNull($row->customer_apartment);
        $this->assertNull($row->customer_city);
        $this->assertNull($row->customer_postal_code);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->rodo_accepted_ip);
        $this->assertNull($row->notes);
        $this->assertNull($row->company_contact_name);

        // Accounting fields MUST be preserved
        $this->assertSame('TEST-001', $row->order_number);
        $this->assertSame('completed', $row->status);
        $this->assertSame('PLN', $row->currency);
        // SQLite returns decimal columns as numeric — use assertEquals (loose type)
        $this->assertEquals('500.00', $row->total_amount);
        $this->assertSame('natural_person', $row->customer_type);
        $this->assertSame('1', (string) $row->invoice_requested);
        $this->assertSame('ACME Sp. z o.o.', $row->invoice_company_name);
        $this->assertSame('1234563218', $row->invoice_nip);
        $this->assertSame('ul. Główna', $row->invoice_street);
        $this->assertSame('Warszawa', $row->invoice_city);
        $this->assertNotNull($row->rodo_accepted_at);
        $this->assertNotNull($row->terms_accepted_at);
    }

    public function test_anonymize_email_placeholder_is_unique_per_order(): void
    {
        $org = Organization::factory()->create();

        // Insert two orders — customer_first_name/last_name are NOT NULL (no default)
        DB::table('orders')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'order_number' => 'A-001', 'status' => 'completed', 'currency' => 'PLN',
            'subtotal' => '100.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total_amount' => '100.00', 'customer_email' => 'a@a.com',
            'customer_first_name' => 'Alice', 'customer_last_name' => 'Smith',
            'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'order_number' => 'A-002', 'status' => 'completed', 'currency' => 'PLN',
            'subtotal' => '200.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total_amount' => '200.00', 'customer_email' => 'b@b.com',
            'customer_first_name' => 'Bob', 'customer_last_name' => 'Jones',
            'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->anonymize($org);

        $emails = DB::table('orders')->where('organization_id', $org->id)->pluck('customer_email');
        $this->assertCount(2, $emails->unique(), 'Each anonymized email must be unique per order');
    }

    public function test_anonymize_clears_payment_webhook_payload(): void
    {
        $org = Organization::factory()->create();

        // Insert a payment with a webhook payload containing PII
        // customer_first_name/last_name are NOT NULL in orders schema
        DB::table('orders')->insert([
            'id' => 9000, 'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'order_number' => 'P-001', 'status' => 'completed', 'currency' => 'PLN',
            'subtotal' => '100.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total_amount' => '100.00', 'customer_email' => 'x@x.com',
            'customer_first_name' => 'Payer', 'customer_last_name' => 'Test',
            'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'organization_id' => $org->id,
            'order_id' => 9000,
            'p24_session_id' => 'sess-123',
            'p24_order_id' => 'ord-456',
            'amount' => 10000,
            'currency' => 'PLN',
            'status' => 'verified',
            'webhook_payload' => json_encode(['email' => 'x@x.com', 'amount' => 10000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->anonymize($org);

        $payment = DB::table('payments')->where('organization_id', $org->id)->first();

        // webhook_payload must be cleared — it may contain buyer PII from gateway
        $this->assertNull($payment->webhook_payload);

        // Accounting identifiers preserved
        $this->assertSame('sess-123', $payment->p24_session_id);
        $this->assertSame('ord-456', $payment->p24_order_id);
        $this->assertSame(10000, (int) $payment->amount);
        $this->assertSame('verified', $payment->status);
    }

    public function test_anonymize_clears_appointment_pii_but_keeps_invoice_fields(): void
    {
        $org = Organization::factory()->create();
        $service = \App\Models\Service::factory()->create(['organization_id' => $org->id]);
        $customer = User::factory()->create();
        // staff_id is NOT NULL on SQLite — the 5.2 nullable migration skips SQLite.
        // Use org owner as staff member for simplicity.
        $staffId = $org->owner_id;

        DB::table('appointments')->insert([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $staffId,
            'appointment_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'completed',
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'anna@example.com',
            'phone' => '987654321',
            'invoice_requested' => 1,
            'invoice_company_name' => 'BetaCorp Sp. z o.o.',
            'invoice_nip' => '5260208967',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->anonymize($org);

        $row = DB::table('appointments')->where('organization_id', $org->id)->first();

        // PII cleared
        $this->assertSame('Anonimizowane', $row->first_name);
        $this->assertNull($row->last_name);
        $this->assertStringContainsString('@anonymized.local', $row->email);
        $this->assertNull($row->phone);

        // Invoice/accounting preserved
        $this->assertSame('BetaCorp Sp. z o.o.', $row->invoice_company_name);
        $this->assertSame('5260208967', $row->invoice_nip);
    }

    public function test_anonymize_clears_rental_pii_but_keeps_invoice_fields(): void
    {
        $org = Organization::factory()->create();
        $service = \App\Models\Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('rentals')->insert([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'customer_id' => $org->owner_id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'pricing_unit' => 'daily',
            'unit_price_at_booking' => '100.00',
            'total_price' => '300.00',
            'status' => 'returned',
            'first_name' => 'Tomasz',
            'last_name' => 'Wiśniewski',
            'email' => 'tomasz@example.com',
            'phone' => '111222333',
            'invoice_requested' => 1,
            'invoice_company_name' => 'GammaCorp S.A.',
            'invoice_nip' => '1234563218',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->anonymize($org);

        $row = DB::table('rentals')->where('organization_id', $org->id)->first();

        // PII cleared
        $this->assertSame('Anonimizowane', $row->first_name);
        $this->assertNull($row->last_name);
        $this->assertStringContainsString('@anonymized.local', $row->email);
        $this->assertNull($row->phone);

        // Accounting preserved
        $this->assertSame('GammaCorp S.A.', $row->invoice_company_name);
        $this->assertSame('1234563218', $row->invoice_nip);
        // SQLite returns decimal columns as numeric — use assertEquals, not assertSame
        $this->assertEquals('300.00', $row->total_price);
    }

    public function test_anonymize_is_idempotent(): void
    {
        $org = Organization::factory()->create();

        DB::table('orders')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'order_number' => 'IDEM-001', 'status' => 'completed', 'currency' => 'PLN',
            'subtotal' => '100.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total_amount' => '100.00', 'customer_email' => 'idempotent@example.com',
            'customer_first_name' => 'Test', 'customer_last_name' => 'User',
            'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->anonymize($org);
        $first = DB::table('orders')->where('organization_id', $org->id)->first();

        $this->service->anonymize($org); // second run
        $second = DB::table('orders')->where('organization_id', $org->id)->first();

        // Second run produces same values — idempotent
        $this->assertSame($first->customer_first_name, $second->customer_first_name);
        $this->assertSame($first->customer_email, $second->customer_email);
        // customer_last_name is NOT NULL — anonymized to placeholder, not null
        $this->assertSame('Anonimizowane', $second->customer_last_name);
    }

    // ─── Observer: purge_after set on Closed transition ──────────────────────

    public function test_observer_sets_purge_after_on_transition_to_closed(): void
    {
        $org = Organization::factory()->closing()->create();

        $org->lifecycle_state = OrganizationLifecycleState::Closed;
        $org->save();

        $fresh = $org->fresh();
        $this->assertNotNull($fresh->purge_after, 'purge_after must be set when transitioning to Closed');

        $expectedDays = (int) config('retention.purge_grace_days', 30);
        $this->assertEqualsWithDelta(
            now()->addDays($expectedDays)->timestamp,
            $fresh->purge_after->timestamp,
            60,
            'purge_after should be approximately now + purge_grace_days'
        );
    }

    public function test_observer_does_not_overwrite_existing_purge_after(): void
    {
        $org = Organization::factory()->closing()->create();
        $existingDate = now()->addDays(90);
        DB::table('organizations')->where('id', $org->id)->update([
            'purge_after' => $existingDate,
        ]);
        $org->refresh();

        $org->lifecycle_state = OrganizationLifecycleState::Closed;
        $org->save();

        $fresh = $org->fresh();
        $this->assertEqualsWithDelta(
            $existingDate->timestamp,
            $fresh->purge_after->timestamp,
            60,
            'purge_after must not be overwritten when already set'
        );
    }

    // ─── SoftDeletes: soft-deleted orgs excluded from normal queries ──────────

    public function test_soft_deleted_org_excluded_from_normal_queries(): void
    {
        $org = Organization::factory()->closed()->create();

        $org->bypassDeleteGuard = true;
        $org->delete();

        $found = Organization::find($org->id);
        $this->assertNull($found, 'Soft-deleted org must not appear in normal find()');

        $foundInQuery = Organization::where('id', $org->id)->first();
        $this->assertNull($foundInQuery, 'Soft-deleted org must not appear in where() query');
    }

    public function test_soft_deleted_org_retrievable_with_trashed(): void
    {
        $org = Organization::factory()->closed()->create();

        $org->bypassDeleteGuard = true;
        $org->delete();

        $found = Organization::withTrashed()->find($org->id);
        $this->assertNotNull($found, 'Soft-deleted org must be retrievable with withTrashed()');
        $this->assertNotNull($found->deleted_at);
    }

    // ─── Purge command tests ──────────────────────────────────────────────────

    private function makeClosedOrgWithPastPurgeAfter(): Organization
    {
        $org = Organization::factory()->closed()->create();
        DB::table('organizations')->where('id', $org->id)->update([
            'purge_after' => now()->subDay(),
            'closed_at' => now()->subMonths(2),
        ]);

        return $org->refresh();
    }

    public function test_purge_command_processes_eligible_closed_org(): void
    {
        $org = $this->makeClosedOrgWithPastPurgeAfter();

        // Legal record (order) — should stay (anonymized)
        DB::table('orders')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'order_number' => 'CMD-001', 'status' => 'completed', 'currency' => 'PLN',
            'subtotal' => '100.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'customer_first_name' => 'Legal', 'customer_last_name' => 'Record',
            'customer_email' => 'legal@example.com',
            'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->where('organization_id', $org->id)->value('id');

        // Ephemeral data — should be deleted
        // user_id is NOT NULL in carts (constrained FK, no nullable())
        DB::table('carts')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'status' => 'abandoned',
            'expires_at' => now()->addHours(1), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Both occurred_at and received_at are NOT NULL in analytics_events
        DB::table('analytics_events')->insert([
            'organization_id' => $org->id, 'event' => 'page.view',
            'occurred_at' => now()->subHour(),
            'received_at' => now()->subHour(),
        ]);
        DB::table('statistics_daily_snapshots')->insert([
            'organization_id' => $org->id, 'date' => now()->toDateString(),
            'source' => 'orders', 'revenue' => '100.00', 'count' => 1,
            'computed_at' => now(),
        ]);

        $this->artisan('organizations:purge', ['--force' => true])
            ->assertSuccessful();

        // Org is soft-deleted
        $this->assertSoftDeleted('organizations', ['id' => $org->id]);

        // Legal record exists (anonymized)
        $this->assertNotNull(DB::table('orders')->where('id', $orderId)->first());
        $orderRow = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame('Anonimizowane', $orderRow->customer_first_name, 'Order PII must be anonymized');

        // Ephemeral data deleted
        $this->assertDatabaseMissing('carts', ['organization_id' => $org->id]);
        $this->assertDatabaseMissing('analytics_events', ['organization_id' => $org->id]);
        $this->assertDatabaseMissing('statistics_daily_snapshots', ['organization_id' => $org->id]);
    }

    public function test_purge_command_skips_org_with_future_purge_after(): void
    {
        $org = Organization::factory()->closed()->create();
        DB::table('organizations')->where('id', $org->id)->update([
            'purge_after' => now()->addDays(10),
            'closed_at' => now()->subDay(),
        ]);

        $this->artisan('organizations:purge', ['--force' => true])
            ->assertSuccessful();

        // Org must NOT be soft-deleted
        $this->assertNull(Organization::withTrashed()->find($org->id)->deleted_at);
    }

    public function test_purge_command_skips_non_closed_org(): void
    {
        // Active org — must be ignored even if purge_after is set
        $org = Organization::factory()->create();
        DB::table('organizations')->where('id', $org->id)->update([
            'purge_after' => now()->subDay(),
        ]);

        $this->artisan('organizations:purge', ['--force' => true])
            ->assertSuccessful();

        $this->assertNull(Organization::withTrashed()->find($org->id)->deleted_at);
    }

    public function test_purge_command_dry_run_makes_no_changes(): void
    {
        $org = $this->makeClosedOrgWithPastPurgeAfter();

        // user_id is NOT NULL in carts
        DB::table('carts')->insert([
            'organization_id' => $org->id, 'user_id' => $org->owner_id,
            'status' => 'active',
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('organizations:purge', ['--dry-run' => true])
            ->assertSuccessful();

        // Nothing changed — org still active (not soft-deleted), cart still there
        $this->assertNull(Organization::withTrashed()->find($org->id)->deleted_at);
        $this->assertDatabaseHas('carts', ['organization_id' => $org->id]);
    }
}
