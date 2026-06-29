<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationDataExportReadyNotification;
use App\Services\Lifecycle\OrganizationDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Faza 5.3b: Organization data export tests.
 *
 * Covers:
 * - Service generates ZIP with correct structure and all expected files
 * - Cross-tenant isolation: org B data never appears in org A's export
 * - CSV formula injection: values starting with = are prefixed with '
 * - Signed route: valid signature downloads ZIP (200)
 * - Signed route: expired/invalid/tampered signature returns 403
 * - Signed route: non-existent file returns 404
 * - Signed route: path traversal attempt returns 403
 * - Super-admin can download without signature (authenticated bypass)
 * - Super-admin path traversal (no signature) returns 403
 * - Regular user without signature returns 403
 * - CLI command: generates file and sends OrganizationDataExportReadyNotification to owner
 * - CLI command: returns FAILURE for unknown organization
 * - CLI command: returns FAILURE when organization has no owner
 * - Cleanup command: deletes old export ZIPs, keeps fresh ones
 */
class OrganizationDataExportTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationDataExportService $service;

    /** @var array<string> */
    private array $exportedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrganizationDataExportService::class);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        foreach ($this->exportedFiles as $path) {
            Storage::disk('local')->delete($path);
        }
        parent::tearDown();
    }

    // ─── OrganizationDataExportService ───────────────────────────────────────

    public function test_generate_creates_zip_with_all_expected_files(): void
    {
        $org = Organization::factory()->create();

        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $fullPath = Storage::disk('local')->path($path);
        $this->assertFileExists($fullPath);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($fullPath), 'ZipArchive::open() failed — ZIP may be corrupt or unreadable');

        $expectedEntries = [
            'manifest.json',
            'orders.json', 'orders.csv',
            'appointments.json', 'appointments.csv',
            'rentals.json', 'rentals.csv',
            'payments.json', 'payments.csv',
            'tenant_payments.json', 'tenant_payments.csv',
            'settings.json', 'settings.csv',
        ];

        foreach ($expectedEntries as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "ZIP is missing entry: {$entry}");
        }

        $zip->close();
    }

    public function test_manifest_contains_correct_org_metadata(): void
    {
        $org = Organization::factory()->create();

        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($path));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertEquals($org->id, $manifest['organization_id']);
        $this->assertEquals($org->name, $manifest['organization_name']);
        $this->assertEquals($org->slug, $manifest['organization_slug']);
        $this->assertArrayHasKey('generated_at', $manifest);
        $this->assertArrayHasKey('legal_basis', $manifest);
    }

    public function test_export_contains_only_the_requesting_organizations_orders(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $orderA = Order::factory()->create(['organization_id' => $orgA->id]);
        $orderB = Order::factory()->create(['organization_id' => $orgB->id]);

        $path = $this->service->generate($orgA);
        $this->exportedFiles[] = $path;

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($path));
        $orders = json_decode((string) $zip->getFromName('orders.json'), true);
        $zip->close();

        $exportedIds = array_column($orders, 'id');
        $this->assertContains($orderA->id, $exportedIds, 'Org A order must be in export');
        $this->assertNotContains($orderB->id, $exportedIds, 'Org B order must NOT appear in Org A export');
    }

    public function test_csv_values_starting_with_formula_prefix_are_escaped(): void
    {
        $org = Organization::factory()->create();
        Order::factory()->create([
            'organization_id' => $org->id,
            'notes' => '=HYPERLINK("http://evil.com","click")',
        ]);

        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($path));
        $csvContent = (string) $zip->getFromName('orders.csv');
        $zip->close();

        $this->assertStringContainsString("'=HYPERLINK", $csvContent, 'Formula injection value must be prefixed with single quote');
        $this->assertStringNotContainsString('=HYPERLINK("http://evil.com"', $csvContent, 'Bare formula prefix must not appear in CSV');
    }

    public function test_export_path_is_scoped_to_organization_directory(): void
    {
        $org = Organization::factory()->create();

        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $this->assertStringStartsWith("exports/org-{$org->id}/", $path);
        $this->assertStringEndsWith('.zip', $path);
    }

    // ─── Signed Route: Authorization ─────────────────────────────────────────

    public function test_valid_signed_url_returns_zip_download(): void
    {
        $org = Organization::factory()->create();
        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $url = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            ['organization' => $org->id, 'file' => $path],
        );

        $response = $this->get($url);

        $response->assertOk();
    }

    public function test_expired_signed_url_returns_403(): void
    {
        $org = Organization::factory()->create();

        $url = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->subSecond(), // already expired
            ['organization' => $org->id, 'file' => "exports/org-{$org->id}/fake.zip"],
        );

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_tampered_signature_returns_403(): void
    {
        $org = Organization::factory()->create();
        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $validUrl = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            ['organization' => $org->id, 'file' => $path],
        );

        // Tamper with the signature query parameter
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=aaabbbccc000', $validUrl);

        $response = $this->get((string) $tamperedUrl);

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_without_signature_returns_403(): void
    {
        $org = Organization::factory()->create();

        $response = $this->get(route('platform.organization.data-export', [
            'organization' => $org->id,
            'file' => "exports/org-{$org->id}/fake.zip",
        ]));

        $response->assertForbidden();
    }

    public function test_regular_user_without_signature_returns_403(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('platform.organization.data-export', [
                'organization' => $org->id,
                'file' => "exports/org-{$org->id}/fake.zip",
            ]));

        $response->assertForbidden();
    }

    public function test_super_admin_can_download_without_signed_url(): void
    {
        $org = Organization::factory()->create();
        $path = $this->service->generate($org);
        $this->exportedFiles[] = $path;

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $response = $this->actingAs($superAdmin)
            ->get(route('platform.organization.data-export', [
                'organization' => $org->id,
                'file' => $path,
            ]));

        $response->assertOk();
    }

    public function test_super_admin_path_traversal_returns_403(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        // Super-admin bypasses signature check, so the path guard is the only protection.
        $response = $this->actingAs($superAdmin)
            ->get(route('platform.organization.data-export', [
                'organization' => $org->id,
                'file' => "exports/org-{$org->id}/../../.env",
            ]));

        $response->assertForbidden();
    }

    // ─── Signed Route: File Resolution ───────────────────────────────────────

    public function test_missing_file_returns_404(): void
    {
        $org = Organization::factory()->create();
        $nonExistentPath = "exports/org-{$org->id}/nonexistent.zip";

        $url = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            ['organization' => $org->id, 'file' => $nonExistentPath],
        );

        $response = $this->get($url);

        $response->assertNotFound();
    }

    public function test_path_traversal_attempt_returns_403(): void
    {
        $org = Organization::factory()->create();

        // Even with a valid signature, paths containing ".." must be rejected
        $maliciousPath = "exports/org-{$org->id}/../../sensitive/file.zip";

        $url = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            ['organization' => $org->id, 'file' => $maliciousPath],
        );

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_file_path_for_different_org_returns_403(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $path = $this->service->generate($orgB);
        $this->exportedFiles[] = $path;

        // Sign URL for orgA but reference orgB's file — prefix check rejects it
        $url = URL::temporarySignedRoute(
            'platform.organization.data-export',
            now()->addDays(30),
            ['organization' => $orgA->id, 'file' => $path],
        );

        $response = $this->get($url);

        $response->assertForbidden();
    }

    // ─── CLI Command ──────────────────────────────────────────────────────────

    public function test_command_generates_export_and_notifies_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        $this->artisan('organizations:export-data', ['organization' => (string) $org->id])
            ->assertSuccessful();

        // Register generated files for tearDown cleanup before any potentially failing assertion
        foreach (Storage::disk('local')->files("exports/org-{$org->id}") as $file) {
            $this->exportedFiles[] = $file;
        }

        Notification::assertSentTo(
            $owner,
            OrganizationDataExportReadyNotification::class,
        );
    }

    public function test_command_accepts_org_slug(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        $this->artisan('organizations:export-data', ['organization' => $org->slug])
            ->assertSuccessful();

        foreach (Storage::disk('local')->files("exports/org-{$org->id}") as $file) {
            $this->exportedFiles[] = $file;
        }

        Notification::assertSentTo($owner, OrganizationDataExportReadyNotification::class);
    }

    public function test_command_fails_for_nonexistent_organization(): void
    {
        $this->artisan('organizations:export-data', ['organization' => '99999'])
            ->assertFailed();
    }

    public function test_command_fails_for_organization_without_owner(): void
    {
        $owner = User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id]);

        // Delete the owner so $org->owner resolves to null.
        // With FK constraints temporarily disabled the org row is orphaned (not cascade-deleted),
        // which is the only realistic path where owner_id points to a missing user.
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('users')->where('id', $owner->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->artisan('organizations:export-data', ['organization' => (string) $org->id])
            ->assertFailed();
    }

    // ─── Cleanup Command ─────────────────────────────────────────────────────

    public function test_cleanup_command_removes_old_files_keeps_fresh_ones(): void
    {
        // Create stub ZIP files directly — avoids the service's timestamp collision
        // when two generate() calls occur within the same second.
        $oldPath = 'exports/org-cleanup-test/old.zip';
        $freshPath = 'exports/org-cleanup-test/fresh.zip';

        Storage::disk('local')->put($oldPath, 'stub');
        Storage::disk('local')->put($freshPath, 'stub');

        $this->exportedFiles[] = $freshPath; // tearDown cleans up if command leaves it

        // Backdate the old ZIP's mtime to 10 days ago
        touch(Storage::disk('local')->path($oldPath), now()->subDays(10)->timestamp);
        // The fresh ZIP's mtime stays as now() — well beyond the 8-day cutoff

        $this->artisan('organizations:cleanup-exports', ['--days' => 8])
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($oldPath), 'Old export ZIP must be deleted');
        $this->assertTrue(Storage::disk('local')->exists($freshPath), 'Fresh export ZIP must be kept');
    }
}
