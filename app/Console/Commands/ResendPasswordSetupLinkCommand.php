<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\AdminCreatedUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The operator-facing escape hatch for "the setup link expired before the
 * owner used it" -- the CLI equivalent of UserResource's "Wyślij email z
 * hasłem" row action, for stacks/situations where nobody is going to open
 * Filament first (a brand-new tenant stack, a broken panel login, or an
 * operator working straight off `instalacja-tenanta-od-zera.md`).
 *
 * Mirrors ProvisionTenantCommand's contract: the link printed to stdout is
 * the actual deliverable, and a broken mail transport must never stand
 * between the operator and it -- so the notification dispatch is best-effort
 * and isolated from the command's own success.
 *
 * Usage:
 *   php artisan registro:password-setup-link owner@acme.pl
 *   php artisan registro:password-setup-link owner@acme.pl --no-email
 */
class ResendPasswordSetupLinkCommand extends Command
{
    protected $signature = 'registro:password-setup-link
                            {email : E-mail address of the account to generate a link for}
                            {--no-email : Print the link only, do not dispatch the notification}
                            {--force : Also allow accounts that already have a password set}';

    protected $description = 'Generate a new password-setup link for an existing account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No account found for {$email}.");

            return self::FAILURE;
        }

        if ($user->password !== null && ! $this->option('force')) {
            $this->components->error(sprintf(
                'The account for %s already has a password set. Generating a new link would let anyone who '.
                'holds it reset that password without knowing the old one. Re-run with --force only if this is '.
                'genuinely intended (e.g. the owner is locked out and this is a deliberate reset).',
                $email,
            ));

            return self::FAILURE;
        }

        $token = $user->initiatePasswordSetup();
        $link = route('password.setup', ['token' => $token]);

        $emailStatus = $this->dispatchNotification($user);

        $this->components->info('Password setup link generated');
        $this->components->twoColumnDetail('User', $user->email);

        $organizations = $user->organizations()->pluck('name')->implode(', ');
        if ($organizations !== '') {
            $this->components->twoColumnDetail('Organization(s)', $organizations);
        }

        $this->components->twoColumnDetail('Valid for', User::PASSWORD_SETUP_TTL_HOURS.' hours');
        $this->components->twoColumnDetail('Notification', $emailStatus);
        $this->newLine();
        $this->line($link);

        return self::SUCCESS;
    }

    /**
     * Best-effort, isolated from the command's own success -- see class docblock.
     */
    private function dispatchNotification(User $user): string
    {
        if ($this->option('no-email')) {
            return '<fg=gray>skipped — --no-email</>';
        }

        try {
            AdminCreatedUser::dispatch($user);

            return '<fg=green>dispatched</>';
        } catch (\Throwable $e) {
            Log::error('registro:password-setup-link: failed to dispatch AdminCreatedUser', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return '<fg=red>failed — see logs, link above is unaffected</>';
        }
    }
}
