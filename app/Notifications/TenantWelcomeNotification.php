<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\EmailServiceChannel;
use App\Enums\TemplateKey;
use App\Models\Organization;
use App\Services\Email\EmailService;
use App\Support\Settings\SettingsManager;
use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Welcome mail for the person who just had a tenant provisioned for them.
 *
 * Until PR #139 this did not exist. Back when `BusinessRegisterController`
 * (public self-serve wizard, since removed -- see routes/web.php) created the
 * organisation and the owner, it logged them in and sent nothing -- while the
 * end-CUSTOMER registration had a welcome e-mail all along. The owner never
 * received their panel address, so the only way back after closing the
 * browser was to remember the subdomain. `TenantRegistered` is now dispatched
 * by `registro:tenant-provision` instead.
 */
class TenantWelcomeNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
    ) {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'tenant-welcome:'.$this->organization->id;
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
        $language = $notifiable->preferred_language ?? 'pl';

        try {
            $emailService->sendFromTemplate(
                TemplateKey::TENANT_WELCOME->value,
                $language,
                $notifiable->email,
                [
                    'owner_name' => $notifiable->name,
                    'organization_name' => $this->organization->name,
                    'admin_url' => TenantUrl::admin($this->organization),
                    'site_url' => TenantUrl::url($this->organization),
                    'app_name' => app(SettingsManager::class)->appName(),
                ],
                [
                    'user_id' => $notifiable->id,
                    'organization_id' => $this->organization->id,
                    'notification' => 'TenantWelcomeNotification',
                ]
            );
        } catch (Throwable $e) {
            Log::error('TenantWelcomeNotification failed', [
                'organization_id' => $this->organization->id,
                'user_id' => $notifiable->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
