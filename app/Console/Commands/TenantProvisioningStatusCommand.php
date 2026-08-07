<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantProvisioningState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cheap, side-effect-free status check for shell tooling (e.g. an `apply` script
 * deciding whether to call registro:tenant-provision). Exit code only -- no
 * database writes, no seeders.
 *
 * --assert additionally cross-checks the container's TENANT_SLUG against what
 * the DATABASE says about itself, and fails loudly on any disagreement.
 *
 * Why that check has to exist somewhere: every tenant-isolation gate in this
 * app (the /register routes, the /platform panel, the organizations.singleton
 * lock, this command's own slug guard) is keyed on the single scalar
 * config('app.tenant_slug'). If a dedicated stack ever boots with it blank --
 * a typo in the stack's .env, an unset shell var interpolated into compose --
 * all of them relax to shared-stack behaviour AT ONCE and nothing says a word.
 * This project has been bitten by exactly that shape before: MCP servers
 * declared under a key settings.json does not read, silently ignored for weeks.
 * The marker row is the independent signal, because it lives in the database
 * rather than in the environment: it says "this database was provisioned as a
 * tenant stack" no matter what the container was handed.
 */
class TenantProvisioningStatusCommand extends Command
{
    protected $signature = 'registro:tenant-provisioned
                            {--assert : Also verify TENANT_SLUG agrees with the database, and fail on mismatch}';

    protected $description = 'Exit 0 if this stack has already been provisioned, exit 1 otherwise';

    public function handle(): int
    {
        if ($this->option('assert') && ! $this->assertConsistent()) {
            return self::FAILURE;
        }

        if (TenantProvisioningState::isProvisioned()) {
            $this->line('provisioned');

            return self::SUCCESS;
        }

        $this->line('not-provisioned');

        return self::FAILURE;
    }

    private function assertConsistent(): bool
    {
        $configuredSlug = config('app.tenant_slug');
        $marker = TenantProvisioningState::query()->whereNotNull('provisioned_at')->first();

        if ($marker !== null && blank($configuredSlug)) {
            $this->components->error(sprintf(
                'This database was provisioned as the tenant stack for "%s", but the container has no TENANT_SLUG. '.
                'Public registration and the /platform panel are live here and the singleton lock is absent. '.
                'Fix the stack environment before serving traffic.',
                $marker->slug,
            ));

            return false;
        }

        if ($marker !== null && $marker->slug !== $configuredSlug) {
            $this->components->error(sprintf(
                'TENANT_SLUG is "%s" but this database was provisioned for "%s" — wrong database for this container.',
                $configuredSlug,
                $marker->slug,
            ));

            return false;
        }

        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $hasLock = Schema::hasColumn('organizations', 'singleton');

        // The lock's migration is conditional, but it is still recorded as run
        // when it no-ops. Setting TENANT_SLUG after that first migrate would
        // leave the lock permanently unbuilt with migrate:status reporting Ran.
        if (filled($configuredSlug) && ! $hasLock) {
            $this->components->error(
                'TENANT_SLUG is set but organizations.singleton is missing — the lock migration ran before '.
                'TENANT_SLUG existed and will never retry. A second organization can be inserted into this stack.'
            );

            return false;
        }

        if (blank($configuredSlug) && $hasLock) {
            $this->components->error(
                'organizations.singleton exists but TENANT_SLUG is blank — a tenant-stack lock is left over on a '.
                'container running in shared mode. It will reject the second organization this stack expects to hold.'
            );

            return false;
        }

        return true;
    }
}
