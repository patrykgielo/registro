<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataExportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('emails');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Eksport danych zakończony - Registro')
            ->greeting('Cześć '.$notifiable->first_name.'!')
            ->line('Twój eksport danych osobowych został zakończony pomyślnie.')
            ->line('Plik JSON zawiera wszystkie Twoje dane zgodnie z art. 20 RODO (prawo do przenoszenia danych).')
            ->line('Ze względów bezpieczeństwa, link do pobrania wygaśnie za 24 godziny.')
            ->action('Pobierz dane', url('/moje-konto/bezpieczenstwo'))
            ->line('Jeśli nie prosiłeś o eksport danych, skontaktuj się z nami natychmiast.')
            ->salutation('Pozdrawiamy, Zespół Registro');
    }
}
