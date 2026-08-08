<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\ProvisionTenantOrganization;
use App\Enums\Industry;
use App\Events\TenantRegistered;
use App\Models\Organization;
use App\Models\TenantProvisioningState;
use App\Models\User;
use App\Rules\ValidOrganizationSlug;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

/**
 * Provisions the single organization for a dedicated tenant-stack container.
 *
 * Wraps existing onboarding building blocks (ProvisionTenantOrganization,
 * SeedOrganizationDefaults transitively) rather than duplicating them -- this
 * command's own job is: run the global lookup seeders exactly once per stack
 * (gated by TenantProvisioningState, NOT re-run on every deploy/restart -- see
 * that model for why re-running EmailTemplateSeeder would silently blow away a
 * tenant's customized templates), then create/find the org+owner, then print
 * the setup link to stdout regardless of what happens with e-mail.
 *
 * `TenantRegistered` is dispatched here -- the ONLY place it fires now that the
 * public self-serve wizard is gone (was `BusinessRegisterController`) -- but
 * only on genuine first creation (`organization_was_created`), never on an
 * idempotent re-run, and never allowed to fail the command: a broken mail
 * transport must not stop the operator from getting the setup link that's the
 * actual point of this command. `--no-email` opts out of the dispatch entirely
 * (e.g. a re-provision the operator doesn't want to re-announce).
 *
 * Usage:
 *   php artisan registro:tenant-provision --slug=acme --name="Acme Sp. z o.o." \
 *       --industry=equipment_rental --owner-email=owner@acme.pl --owner-name="Jan Kowalski"
 */
class ProvisionTenantCommand extends Command
{
    protected $signature = 'registro:tenant-provision
                            {--slug= : Organization slug (must match TENANT_SLUG when the container has one)}
                            {--name= : Organization display name}
                            {--industry=equipment_rental : Industry enum value}
                            {--owner-email= : Owner e-mail address}
                            {--owner-name= : Owner full name, split into first/last name on the first space}
                            {--attach-existing-owner : Allow --owner-email to match an account that already exists}
                            {--no-email : Do not dispatch the tenant-registered welcome/operator-notification e-mails}';

    protected $description = 'Provision the single organization for a dedicated tenant-stack container';

    public function __construct(
        private readonly ProvisionTenantOrganization $provision,
    ) {
        parent::__construct();
    }

    /**
     * Refuse to hand an existing account ownership of a new organization unless
     * the operator says so explicitly.
     *
     * On a tenant stack the point is moot — the database holds one organization
     * and one owner. It matters on the shared stack, where TENANT_SLUG is blank
     * and the slug guard above no-ops: there, `--owner-email` pointing at any
     * existing account (a customer of some other tenant, say) would silently
     * grant it the admin role and an owner pivot, with the command reporting
     * success. Re-runs against the org's real owner stay allowed, because
     * idempotency is the whole contract of this command.
     */
    private function ownerEmailIsSafeToUse(string $email, string $slug): bool
    {
        $existing = User::where('email', $email)->first();

        if ($existing === null || $this->option('attach-existing-owner')) {
            return true;
        }

        $ownsThisOrg = Organization::where('slug', $slug)
            ->where('owner_id', $existing->id)
            ->exists();

        if ($ownsThisOrg) {
            return true;
        }

        $this->components->error(sprintf(
            'An account for %s already exists (user #%d) and does not own "%s". Provisioning would grant it the '.
            'admin role and owner rights on that organization. Re-run with --attach-existing-owner if that is intended.',
            $email,
            $existing->id,
            $slug,
        ));

        return false;
    }

    public function handle(): int
    {
        $this->components->info('Registro tenant provisioning');

        $input = $this->validatedInput();

        if ($input === null) {
            return self::FAILURE;
        }

        $configuredSlug = config('app.tenant_slug');
        if (filled($configuredSlug) && $configuredSlug !== $input['slug']) {
            $this->components->error(sprintf(
                "--slug=%s does not match this container's TENANT_SLUG=%s. Refusing to provision the wrong tenant.",
                $input['slug'],
                $configuredSlug,
            ));

            return self::FAILURE;
        }

        if (! $this->ownerEmailIsSafeToUse($input['owner_email'], $input['slug'])) {
            return self::FAILURE;
        }

        $alreadyProvisioned = TenantProvisioningState::isProvisioned();
        $this->runGlobalSeedersOnce($alreadyProvisioned);

        [$ownerFirstName, $ownerLastName] = $this->splitOwnerName($input['owner_name']);

        $result = $this->provision->execute(
            slug: $input['slug'],
            name: $input['name'],
            industry: Industry::from($input['industry']),
            ownerEmail: $input['owner_email'],
            ownerFirstName: $ownerFirstName,
            ownerLastName: $ownerLastName,
        );

        $org = $result['organization'];
        $owner = $result['owner'];

        if ((int) $org->owner_id !== $owner->id) {
            $this->components->warn(sprintf(
                "Organization '%s' already has a different owner (user #%d). ".
                'The requested --owner-email was linked as an additional owner, not as a replacement.',
                $org->slug,
                $org->owner_id,
            ));
        }

        $emailStatus = $this->dispatchTenantRegistered($org, $owner, $result['organization_was_created']);

        $token = $owner->initiatePasswordSetup();
        $link = route('password.setup', ['token' => $token]);

        $this->newLine();
        $this->components->twoColumnDetail(
            'Organization',
            sprintf('%s (%s)%s', $org->name, $org->slug, $result['organization_was_created'] ? '' : ' <fg=gray>already existed</>')
        );
        $this->components->twoColumnDetail(
            'Owner',
            $owner->email.($result['owner_was_created'] ? '' : ' <fg=gray>already existed</>')
        );
        $this->components->twoColumnDetail('Global seeders', $alreadyProvisioned ? '<fg=gray>already provisioned — skipped</>' : '<fg=green>seeded</>');
        $this->components->twoColumnDetail('Welcome e-mail', $emailStatus);
        $this->newLine();
        $this->line($link);

        return self::SUCCESS;
    }

    /**
     * Best-effort side effect, deliberately isolated from the command's own
     * success/failure: the setup link printed below is the actual deliverable
     * of this command, and a broken mail transport (SMTP down, queue
     * connection refused) must never stop the operator from getting it. Only
     * fires on a genuine new organization -- re-running this idempotent
     * command against one that already exists must not re-announce it to the
     * owner and operator every time.
     */
    private function dispatchTenantRegistered(Organization $org, User $owner, bool $organizationWasCreated): string
    {
        if (! $organizationWasCreated) {
            return '<fg=gray>skipped — organization already existed</>';
        }

        if ($this->option('no-email')) {
            return '<fg=gray>skipped — --no-email</>';
        }

        try {
            TenantRegistered::dispatch($org, $owner);

            return '<fg=green>dispatched</>';
        } catch (\Throwable $e) {
            Log::error('registro:tenant-provision: failed to dispatch TenantRegistered', [
                'organization_id' => $org->id,
                'error' => $e->getMessage(),
            ]);

            return '<fg=red>failed — see logs, setup link below is unaffected</>';
        }
    }

    /**
     * @return array{slug: string, name: string, industry: string, owner_email: string, owner_name: string}|null
     */
    private function validatedInput(): ?array
    {
        $data = [
            'slug' => (string) $this->option('slug'),
            'name' => (string) $this->option('name'),
            'industry' => (string) $this->option('industry'),
            'owner_email' => (string) $this->option('owner-email'),
            'owner_name' => (string) $this->option('owner-name'),
        ];

        $validator = Validator::make($data, [
            'slug' => ['required', 'string', new ValidOrganizationSlug],
            'name' => ['required', 'string', 'max:100'],
            'industry' => ['required', new Enum(Industry::class)],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return null;
        }

        return $validator->validated();
    }

    /**
     * RolePermissionSeeder/SettingSeeder/EmailTemplateSeeder seed global (NULL-org)
     * lookup data -- roles+permissions, default settings, transactional e-mail
     * templates. Gated behind TenantProvisioningState so a re-run of this command
     * (container restart, re-applied stack config) never re-seeds them:
     * EmailTemplateSeeder in particular uses updateOrCreate and would silently
     * overwrite templates the tenant has since customized.
     */
    private function runGlobalSeedersOnce(bool $alreadyProvisioned): void
    {
        if ($alreadyProvisioned) {
            return;
        }

        $this->components->task('Seeding roles and permissions', function (): bool {
            app(RolePermissionSeeder::class)->setCommand($this)->run();

            return true;
        });

        $this->components->task('Seeding default settings', function (): bool {
            app(SettingSeeder::class)->setCommand($this)->run();

            return true;
        });

        $this->components->task('Seeding e-mail templates', function (): bool {
            app(EmailTemplateSeeder::class)->setCommand($this)->run();

            return true;
        });
    }

    /**
     * @return array{0: string, 1: string} [firstName, lastName]
     */
    private function splitOwnerName(string $fullName): array
    {
        $fullName = trim($fullName);

        if (! str_contains($fullName, ' ')) {
            return [$fullName, ''];
        }

        [$first, $last] = explode(' ', $fullName, 2);

        return [$first, $last];
    }
}
