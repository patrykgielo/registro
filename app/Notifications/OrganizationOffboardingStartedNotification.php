<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the organization owner that the graceful offboarding process has started.
 *
 * Sent when StartOrganizationOffboarding::execute() is called.
 * Informs the owner that:
 * - in-flight obligations are being cancelled and customers notified
 * - a grace window exists to restore (Closing → Active via Reactivate action)
 * - after the grace window the org auto-transitions to Closed
 *
 * ShouldBeUnique: prevents duplicate emails if the action is triggered twice for the same org.
 * Lock TTL: 1 hour per organization.
 */
class OrganizationOffboardingStartedNotification extends Notification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
    ) {
        $this->onQueue('emails');
    }

    public function uniqueId(): string
    {
        return 'offboarding-started:'.$this->organization->id;
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
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $graceDays = (int) config('retention.closing_grace_days', 14);
        $orgName = $this->organization->name;

        return (new MailMessage)
            ->subject("Rozpoczęto proces zamknięcia organizacji — {$orgName}")
            ->greeting('Dzień dobry, '.$notifiable->first_name.'!')
            ->line("Proces zamknięcia organizacji **{$orgName}** został zainicjowany.")
            ->line('Wszystkie aktywne wizyty, zamówienia i wypożyczenia są **automatycznie anulowane**, a Twoi klienci zostaną powiadomieni o anulowaniu.')
            ->line("Masz **{$graceDays} dni** na zmianę decyzji. W tym czasie możesz przywrócić organizację do stanu aktywnego, kontaktując się z administratorem platformy Registro (akcja Reaktywuj w panelu).")
            ->line("Jeśli nie podejmiesz działania, organizacja zostanie automatycznie zamknięta po upływie {$graceDays} dni, a dane klientów zostaną zanonimizowane zgodnie z polityką retencji RODO.")
            ->line('W razie pytań skontaktuj się z supportem Registro.')
            ->salutation('Pozdrawiamy, Zespół Registro');
    }
}
