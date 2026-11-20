<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operator mail: the tenant took its account-closure request back.
 *
 * Counterpart of OrganizationClosureRequestedNotification, sent on-demand to the same global
 * `account.closure_request_email` address (never a role: a dedicated tenant stack has no
 * super-admin). Text lives in lang/{pl,en}/account_closure.php under `mail.withdrawn`.
 */
class OrganizationClosureWithdrawnNotification extends Notification implements ShouldQueue
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
        $requesterLabel = $this->requester
            ? "{$this->requester->first_name} {$this->requester->last_name} ({$this->requester->email})"
            : __('account_closure.mail.unknown_requester');

        return (new MailMessage)
            ->subject(__('account_closure.mail.withdrawn.subject', ['org' => $this->organization->name]))
            ->greeting(__('account_closure.mail.greeting'))
            ->line(__('account_closure.mail.withdrawn.intro', [
                'org' => $this->organization->name,
                'slug' => $this->organization->slug,
            ]))
            ->line(__('account_closure.mail.withdrawn.by', ['who' => $requesterLabel]))
            ->line(__('account_closure.mail.withdrawn.next'))
            ->salutation(__('account_closure.mail.salutation'));
    }
}
