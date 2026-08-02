<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This command decides what goes into a publicly-logged TLS certificate, so the
 * inclusion rules matter more than the formatting: every name lands in
 * Certificate Transparency for the certificate's lifetime.
 */
class ListTenantHostnamesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'example.test']);
    }

    /**
     * @return array<int, string>
     */
    private function hostnames(): array
    {
        // Artisan::call(), not $this->artisan(): PendingCommand does not feed
        // Artisan::output(), so the assertions were silently running on nothing.
        $exit = \Illuminate\Support\Facades\Artisan::call('tenants:hostnames');
        $this->assertSame(0, $exit);

        return array_values(array_filter(
            preg_split('/\R/', \Illuminate\Support\Facades\Artisan::output()) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    public function test_it_always_includes_the_base_domain(): void
    {
        $this->assertSame(['example.test'], $this->hostnames());
    }

    public function test_it_includes_active_tenants(): void
    {
        Organization::factory()->create([
            'slug' => 'budowlana',
            'lifecycle_state' => OrganizationLifecycleState::Active,
        ]);

        $this->assertContains('budowlana.example.test', $this->hostnames());
    }

    /**
     * A suspension is temporary and its 503 page should still load over HTTPS,
     * not behind a browser security warning.
     */
    public function test_it_includes_suspended_tenants(): void
    {
        Organization::factory()->create([
            'slug' => 'zawieszona',
            'lifecycle_state' => OrganizationLifecycleState::Suspended,
        ]);

        $this->assertContains('zawieszona.example.test', $this->hostnames());
    }

    /**
     * Closed and closing tenants serve nothing, and each name stays in public
     * Certificate Transparency logs for the life of the certificate.
     */
    public function test_it_excludes_closed_and_closing_tenants(): void
    {
        Organization::factory()->create([
            'slug' => 'zamknieta',
            'lifecycle_state' => OrganizationLifecycleState::Closed,
        ]);
        Organization::factory()->create([
            'slug' => 'zamykana',
            'lifecycle_state' => OrganizationLifecycleState::Closing,
        ]);

        $hostnames = $this->hostnames();

        $this->assertNotContains('zamknieta.example.test', $hostnames);
        $this->assertNotContains('zamykana.example.test', $hostnames);
    }

    public function test_output_is_bare_one_hostname_per_line(): void
    {
        Organization::factory()->create(['slug' => 'aaa', 'lifecycle_state' => OrganizationLifecycleState::Active]);
        Organization::factory()->create(['slug' => 'bbb', 'lifecycle_state' => OrganizationLifecycleState::Active]);

        foreach ($this->hostnames() as $line) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9.-]+$/',
                $line,
                'the shell consumes this directly, so no decoration may leak in',
            );
        }
    }

    public function test_it_is_deterministic_so_the_shell_can_compare_sets(): void
    {
        Organization::factory()->create(['slug' => 'zzz', 'lifecycle_state' => OrganizationLifecycleState::Active]);
        Organization::factory()->create(['slug' => 'aaa', 'lifecycle_state' => OrganizationLifecycleState::Active]);

        $this->assertSame($this->hostnames(), $this->hostnames());
    }

    /**
     * Soft-deleted organisations must not keep a name on the certificate.
     */
    public function test_it_excludes_deleted_tenants(): void
    {
        $org = Organization::factory()->create([
            'slug' => 'usunieta',
            'lifecycle_state' => OrganizationLifecycleState::Active,
        ]);

        // OrganizationObserver::deleting() refuses anything that is not Closed,
        // so reaching the soft-delete at all requires going through that state.
        $org->lifecycle_state = OrganizationLifecycleState::Closed;
        $org->saveQuietly();
        $org->delete();

        $this->assertNotContains('usunieta.example.test', $this->hostnames());
    }
}
