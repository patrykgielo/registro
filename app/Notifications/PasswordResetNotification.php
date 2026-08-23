<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * The password-reset e-mail, sent through EmailService so it gets the tenant's
 * template, language, `email_sends` logging, suppression checks and retry
 * semantics — instead of Laravel's stock English "Reset Password Notification".
 *
 * EVERY context-dependent value arrives as a constructor argument. Nothing here
 * consults the ambient request, tenant or settings, and that is the whole point:
 * this notification is queued, and a queue worker has no request, no resolved
 * tenant and no session. Two concrete failures follow from getting that wrong:
 *
 *   - `route()`/`url()` on a worker fall back to APP_URL, because
 *     URL::forceRootUrl() is called by ResolveTenant and only ever runs inside a
 *     request. On today's shared stack APP_URL is the ROOT domain, where
 *     /admin/login is a 404 — measured, not assumed. The link would send a
 *     tenant's admin to a host with no admin panel.
 *   - SettingsManager::appName() resolves through TenantFeature::currentTenant(),
 *     which is null on a worker, so the e-mail would carry the platform's name
 *     instead of the rental company's — exactly the whitelabel promise this
 *     change exists to keep.
 *
 * Both values are therefore resolved by the PasswordResetRequested listener,
 * which runs synchronously inside the request, and passed in.
 *
 * Known limitation, pre-existing and system-wide: a tenant's OWN override of the
 * `password-reset` template still does not apply, because EmailTemplate::
 * resolveActive() resolves the tenant from ambient context too (see its own
 * docblock — the limitation is documented and accepted there for every queued
 * notification, not introduced here). The global template is used, with the
 * tenant's name and URL substituted into it.
 */
class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  Password reset token
     * @param  string  $resetUrl  Absolute URL, built inside the request
     * @param  string  $appName  Tenant brand name, resolved inside the request
     * @param  int  $expiresInMinutes  Token lifetime, for the e-mail body
     */
    public function __construct(
        public User $user,
        public string $token,
        public string $resetUrl,
        public string $appName,
        public int $expiresInMinutes
    ) {
        $this->onQueue('emails');
    }

    /**
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        $language = $notifiable->preferred_language ?? 'pl';

        try {
            // Keys match the template's declared variables exactly. A key the
            // template names but the payload omits is NOT an error — unknown
            // `{{tokens}}` are left verbatim in the body (see
            // EmailTemplate::substitutePlaceholders), so a customer would
            // receive a literal "{{app_name}}". Pinned by
            // PasswordResetEmailTest::test_no_placeholder_survives_rendering.
            $emailService->sendFromTemplate(
                TemplateKey::PASSWORD_RESET->value,
                $language,
                $notifiable->email,
                [
                    'user_name' => $notifiable->name,
                    'app_name' => $this->appName,
                    'reset_url' => $this->resetUrl,
                    'expires_in' => (string) $this->expiresInMinutes,
                ],
                [
                    'user_id' => $notifiable->id,
                    'notification' => 'PasswordResetNotification',
                ]
            );
        } catch (\Throwable $e) {
            // Rethrown so the queue retries: a transient SMTP failure must not
            // silently consume someone's only way back into their account. The
            // operator escape hatch when it never arrives is
            // `php artisan registro:password-setup-link <email> --force`.
            Log::error('PasswordResetNotification failed', [
                'user_id' => $notifiable->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
