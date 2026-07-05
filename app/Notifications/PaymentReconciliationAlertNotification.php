<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts super-admins to a Przelewy24 webhook event that could not follow the
 * normal pending_payment -> paid flow and required (or would have required)
 * manual attention.
 *
 * Two scenarios, selected via $type:
 * - 'reconciled': the order was already 'cancelled' (TTL cleanup raced a late
 *   P24 webhook) but the payment verified successfully against P24 and the
 *   order was automatically recovered back to 'paid'. Nothing further to do,
 *   but this MUST be visible to staff — real money moved on an order they may
 *   already consider dead.
 * - 'blocked': the state machine still refused the transition to 'paid' (an
 *   order status outside the reconciliation path, e.g. refunded/completed).
 *   A Payment row with status=success now exists that is NOT reflected on the
 *   Order — this requires manual DB/staff review, it is NOT auto-resolved.
 *
 * NOT ShouldBeUnique — same fan-out reasoning as
 * OrganizationClosureRequestedNotification: Laravel dispatches one queued job
 * per notifiable sharing a single lock key, so only the first super-admin in
 * the recipient list would actually receive the mail.
 */
class PaymentReconciliationAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly string $type,
        private readonly string $details = '',
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
        $order = $this->order;
        $adminUrl = url("/admin/orders/{$order->id}/edit");

        if ($this->type === 'blocked') {
            $mail = (new MailMessage)
                ->subject("PILNE: Płatność P24 wymaga ręcznej weryfikacji — {$order->order_number}")
                ->error()
                ->greeting('Uwaga!')
                ->line("Płatność Przelewy24 dla zamówienia **{$order->order_number}** została zweryfikowana pomyślnie (środki pobrane), ale zamówienie NIE mogło zostać automatycznie oznaczone jako opłacone.")
                ->line("Aktualny status zamówienia: **{$order->status}**.")
                ->line('Rekord Payment ze statusem "success" istnieje w bazie, ale zamówienie wymaga ręcznej weryfikacji i korekty statusu.');

            if ($this->details !== '') {
                $mail->line("Szczegóły: {$this->details}");
            }

            return $mail
                ->action('Otwórz zamówienie', $adminUrl)
                ->salutation('Pozdrawiamy, System Registro');
        }

        return (new MailMessage)
            ->subject("Płatność P24 zrekoncyliowana — {$order->order_number}")
            ->greeting('Informacja')
            ->line("Zamówienie **{$order->order_number}** zostało wcześniej anulowane (np. przez automatyczne czyszczenie wygasłych rezerwacji), ale przyszedł spóźniony, poprawny webhook Przelewy24 potwierdzający udaną płatność.")
            ->line('System automatycznie przywrócił zamówienie do statusu "opłacone", ponieważ środki zostały faktycznie pobrane od klienta.')
            ->line('Zalecana weryfikacja: upewnij się, że dostępność / rezerwacja przedmiotu nie koliduje z inną rezerwacją utworzoną w międzyczasie.')
            ->action('Otwórz zamówienie', $adminUrl)
            ->salutation('Pozdrawiamy, System Registro');
    }
}
