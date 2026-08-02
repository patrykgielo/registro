<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Throwable;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates the owner of the Registro installation -- the super-admin who
 * administers the SaaS itself via /platform, as distinct from any tenant's admin.
 *
 * This exists because nothing else does it. `php artisan make:filament-user`,
 * which scripts/deploy-init.sh used to call, is actively misleading here: it
 * builds the user with a `name` field that this schema does not have (the column
 * was dropped in favour of first_name/last_name, and `name` is a read-only
 * accessor), so mass assignment silently discards it. It assigns no role. Then
 * it prints "Success! ... may now log in", which is false -- User::canAccessPanel()
 * requires `super-admin` for /platform and one of super-admin|admin|staff for
 * /admin, so the account it creates can reach nothing. Verified on a real server:
 * first_name=NULL, last_name=NULL, role: none, and a cheerful success message.
 *
 * Roles are the other half of the gap. RolePermissionSeeder is only ever reached
 * through DatabaseSeeder, which also seeds demo services and vehicle types, so it
 * must not run in production -- leaving a fresh install with zero roles and zero
 * permissions. This command seeds roles on its own, without the demo data.
 *
 * Usage:
 *   php artisan registro:create-owner
 *   php artisan registro:create-owner --first-name=Anna --last-name=Kowalska \
 *       --email=anna@example.com --password='...' --no-interaction
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'registro:create-owner
                            {--first-name= : Owner first name}
                            {--last-name= : Owner last name}
                            {--email= : Owner e-mail, used to log in}
                            {--password= : Owner password (prompted if omitted)}
                            {--force : Update an existing account without asking}';

    protected $description = 'Create or update the Registro owner (platform super-admin)';

    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $this->components->info('Registro owner (platform super-admin)');

        if (! $this->ensureRolesExist()) {
            return self::FAILURE;
        }

        $data = $this->collectInput();

        if ($data === null) {
            return self::FAILURE;
        }

        $existing = User::where('email', $data['email'])->first();

        if ($existing !== null && ! $this->confirmOverwrite($existing)) {
            $this->components->warn('Aborted -- no changes made.');

            return self::FAILURE;
        }

        try {
            $user = $this->persist($data, $existing);
        } catch (Throwable $e) {
            $this->components->error('Could not save the account: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->verify($user);
    }

    /**
     * Roles must exist before one can be assigned. Seeding them here rather than
     * telling the operator to run `db:seed` is deliberate: DatabaseSeeder also
     * loads demo services, vehicle types and service areas, which have no place
     * in a production database. RolePermissionSeeder is idempotent on its own
     * (firstOrCreate throughout), so re-running this command is harmless.
     */
    private function ensureRolesExist(): bool
    {
        if (Role::where('name', 'super-admin')->exists()) {
            $this->components->twoColumnDetail('Roles', '<fg=gray>already present</>');

            return true;
        }

        $this->components->task('Seeding roles and permissions', function (): bool {
            app(RolePermissionSeeder::class)->run();

            return true;
        });

        if (! Role::where('name', 'super-admin')->exists()) {
            $this->components->error(
                'RolePermissionSeeder ran but the super-admin role still does not exist. '
                .'Refusing to continue -- the account would be unable to log in.'
            );

            return false;
        }

        return true;
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, password: string}|null
     */
    private function collectInput(): ?array
    {
        $interactive = $this->input->isInteractive();

        $data = [
            'first_name' => $this->option('first-name') ?: ($interactive ? text(
                label: 'First name',
                required: true,
            ) : ''),
            'last_name' => $this->option('last-name') ?: ($interactive ? text(
                label: 'Last name',
                required: true,
            ) : ''),
            'email' => $this->option('email') ?: ($interactive ? text(
                label: 'E-mail',
                required: true,
            ) : ''),
            'password' => $this->option('password') ?: ($interactive ? promptPassword(
                label: 'Password (min '.self::MIN_PASSWORD_LENGTH.' characters)',
                required: true,
            ) : ''),
        ];

        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            if (! $interactive) {
                $this->components->warn(
                    'Running non-interactively -- pass --first-name, --last-name, --email and --password.'
                );
            }

            return null;
        }

        return $validator->validated();
    }

    private function confirmOverwrite(User $existing): bool
    {
        $this->components->warn(sprintf(
            'An account already exists for %s (id %d, roles: %s).',
            $existing->email,
            $existing->id,
            $existing->getRoleNames()->implode(', ') ?: 'none',
        ));
        $this->components->warn('Continuing resets its password and grants super-admin.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Refusing to modify an existing account without --force.');

            return false;
        }

        return $this->confirm('Update this account?', false);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $data
     */
    private function persist(array $data, ?User $existing): User
    {
        return DB::transaction(function () use ($data, $existing): User {
            $user = $existing ?? new User;

            // first_name / last_name explicitly: this schema has no `name` column
            // -- it is an accessor over these two. Writing `name` is what makes
            // make:filament-user produce a nameless account.
            $user->first_name = $data['first_name'];
            $user->last_name = $data['last_name'];
            $user->email = $data['email'];
            $user->password = Hash::make($data['password']);

            // The owner administers the platform, so there is no mail flow to
            // verify through on a fresh install -- and an unverified account can
            // be locked out of the panel it was just created for.
            $user->email_verified_at ??= now();

            $user->save();

            if (! $user->hasRole('super-admin')) {
                $user->assignRole('super-admin');
            }

            return $user->refresh();
        });
    }

    /**
     * The reason this command exists. `make:filament-user` announces success and
     * leaves an account that cannot log in anywhere; asserting the actual gate
     * rather than the absence of an exception is what makes the success message
     * here worth believing.
     */
    private function verify(User $user): int
    {
        $checks = [
            'first name set' => filled($user->first_name),
            'last name set' => filled($user->last_name),
            'password usable' => filled($user->password),
            'e-mail verified' => $user->email_verified_at !== null,
            'has super-admin role' => $user->hasRole('super-admin'),
        ];

        try {
            $checks['can access /platform'] = $user->canAccessPanel(Filament::getPanel('platform'));
        } catch (Throwable $e) {
            $this->components->error('Could not evaluate panel access: '.$e->getMessage());
            $checks['can access /platform'] = false;
        }

        foreach ($checks as $label => $passed) {
            $this->components->twoColumnDetail(
                $label,
                $passed ? '<fg=green>ok</>' : '<fg=red>FAILED</>',
            );
        }

        if (in_array(false, $checks, true)) {
            $this->components->error(
                'The account was saved but cannot use the platform panel. '
                .'Do NOT treat this as a successful setup.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s can now sign in at %s/platform',
            $user->email,
            rtrim((string) config('app.url'), '/'),
        ));

        return self::SUCCESS;
    }
}
