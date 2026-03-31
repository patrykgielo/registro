<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{name: string, email: string, phone?: string|null, message?: string|null}  $data
     */
    public function __construct(
        private readonly Service $service,
        private readonly array $data,
    ) {
        $this->onQueue('emails');
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Zapytanie o cenę: {$this->service->name}")
            ->greeting('Nowe zapytanie o cenę')
            ->line("Produkt: **{$this->service->name}**")
            ->line("Imię i nazwisko: {$this->data['name']}")
            ->line("Email: {$this->data['email']}")
            ->lineIf(! empty($this->data['phone']), "Telefon: {$this->data['phone']}")
            ->lineIf(! empty($this->data['message']), "Wiadomość: {$this->data['message']}");
    }
}
