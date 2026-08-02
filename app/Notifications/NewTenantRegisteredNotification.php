<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Support\Settings\SettingsManager;
use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells whoever runs this Registro installation that a new business signed up.
 *
 * Sent to an address configured in /platform, not to a User -- the operator is
 * not necessarily a row in the users table of the installation they run, and
 * routing this through `Notification::route()` keeps it that way.
 */
class NewTenantRegisteredNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public User $owner,
    ) {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'new-tenant-registered:'.$this->organization->id;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [EmailServiceChannel::class];
    }

    public function toEmailService(object $notifiable, EmailService $emailService): void
    {
        // AnonymousNotifiable carries the address in `routes`, a plain User in
        // `email`. This notification is normally the former.
        $recipient = $notifiable instanceof AnonymousNotifiable
            ? ($notifiable->routes[EmailServiceChannel::class] ?? $notifiable->routes['mail'] ?? null)
            : ($notifiable->email ?? null);

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('NewTenantRegisteredNotification: no operator address configured, skipping', [
                'organization_id' => $this->organization->id,
            ]);

            return;
        }

        try {
            $emailService->sendFromTemplate(
                TemplateKey::TENANT_REGISTERED_OPERATOR->value,
                'pl',
                $recipient,
                [
                    'organization_name' => $this->organization->name,
                    'organization_slug' => $this->organization->slug,
                    'owner_name' => $this->owner->name,
                    'owner_email' => $this->owner->email,
                    'site_url' => TenantUrl::url($this->organization),
                    'registered_at' => now()->format('Y-m-d H:i'),
                    'app_name' => app(SettingsManager::class)->appName(),
                ],
                [
                    'organization_id' => $this->organization->id,
                    'notification' => 'NewTenantRegisteredNotification',
                ]
            );
        } catch (Throwable $e) {
            Log::error('NewTenantRegisteredNotification failed', [
                'organization_id' => $this->organization->id,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
