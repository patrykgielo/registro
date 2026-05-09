<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InquiryNotification extends Notification implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return $this->service->id.'_'.$this->data['email'].'_'.floor(time() / 60);
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $name = e($this->data['name']);
        $email = e($this->data['email']);
        $phone = ! empty($this->data['phone']) ? e($this->data['phone']) : null;
        $message = ! empty($this->data['message']) ? e($this->data['message']) : null;

        return (new MailMessage)
            ->subject("Zapytanie o cenę: {$this->service->name}")
            ->greeting('Nowe zapytanie o cenę')
            ->line("Produkt: **{$this->service->name}**")
            ->line("Imię i nazwisko: {$name}")
            ->line("Email: {$email}")
            ->lineIf($phone !== null, "Telefon: {$phone}")
            ->lineIf($message !== null, "Wiadomość: {$message}");
    }
}
