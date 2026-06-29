<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the organization owner that their data export is ready.
 *
 * Sent after organizations:export-data completes successfully.
 * The signed URL (30-day validity) allows the owner to download the ZIP
 * without needing to log in — the signature is the authorization.
 *
 * Legal: Art. 28(3)(g) RODO — procesor zwraca dane administratorowi
 * przy zakończeniu umowy powierzenia przetwarzania danych.
 */
class OrganizationDataExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $downloadUrl,
        private readonly string $organizationName,
    ) {
        $this->onQueue('emails');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Twoja kopia danych jest gotowa — {$this->organizationName}")
            ->greeting('Dzień dobry, '.$notifiable->first_name.'!')
            ->line("Przygotowaliśmy pełną kopię danych firmy **{$this->organizationName}** zgodnie z art. 28 ust. 3 lit. g RODO.")
            ->line('Archiwum ZIP zawiera dane zamówień, wizyt, wypożyczeń, płatności oraz ustawień w formatach JSON i CSV.')
            ->action('Pobierz dane firmy', $this->downloadUrl)
            ->line('Link do pobrania jest ważny przez **30 dni**. Po tym czasie należy wygenerować nowy eksport.')
            ->line('Jeśli nie prosiłeś(-aś) o eksport danych, skontaktuj się z nami niezwłocznie.')
            ->salutation('Pozdrawiamy, Zespół Registro');
    }
}
