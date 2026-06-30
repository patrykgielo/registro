<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// NOT ShouldBeUnique: Laravel dispatches one job per notifiable, all sharing a
// single org-keyed lock — only the first super-admin would receive the mail.
// The atomic closure_requested_at guard in SystemSettings::requestClosure()
// already prevents duplicate requests, so fan-out dedup must stay off here.
class OrganizationClosureRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly ?User $requester = null,
    ) {
        $this->onQueue('emails');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $orgName = $this->organization->name;
        $orgSlug = $this->organization->slug;
        $requesterLabel = $this->requester
            ? "{$this->requester->first_name} {$this->requester->last_name} ({$this->requester->email})"
            : 'nieznany';

        $platformUrl = url("/platform/organizations/{$this->organization->id}/edit");

        return (new MailMessage)
            ->subject("Wniosek o zamknięcie konta — {$orgName}")
            ->greeting('Dzień dobry!')
            ->line("Organizacja **{$orgName}** (slug: `{$orgSlug}`) złożyła wniosek o zamknięcie konta.")
            ->line("Wnioskujący: **{$requesterLabel}**")
            ->line('Zweryfikuj wniosek i jeśli zasadny — zainicjuj proces graceful offboarding w panelu platformy.')
            ->action('Otwórz organizację w panelu', $platformUrl)
            ->line('Aby odrzucić wniosek bez zamykania konta, użyj akcji "Odrzuć wniosek" przy organizacji w panelu.')
            ->salutation('Pozdrawiamy, System Registro');
    }
}
