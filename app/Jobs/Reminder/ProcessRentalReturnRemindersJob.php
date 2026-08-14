<?php

declare(strict_types=1);

namespace App\Jobs\Reminder;

use App\Models\OrderItem;
use App\Notifications\RentalReturnDueSoonNotification;
use App\Notifications\RentalReturnOverdueNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Process Rental Return Reminders
 *
 * Parallel to ProcessRemindersJob (appointments) — deliberately NOT built on
 * top of reminder_configs/reminder_logs. See
 * app/docs/features/rental-return-reminders.md "Design decision" section for
 * the full argument; short version: rentals need exactly two fixed,
 * code-defined reminders (not admin-configurable timing/channel, which is
 * what ReminderConfig actually buys for appointments), and order_items.end_date
 * is a DATE column with no time-of-day component, so the hour/minute-offset
 * + window-buffer machinery `ProcessRemindersJob` needs for timestamp
 * columns does not apply here.
 *
 * Reminder unit is the order ITEM, not the order — a single order can carry
 * items with different end_date values (cart checkout lets each item pick
 * its own rental period; RentalExtensionService can then move one item's
 * end_date independently of the rest). A multi-item order due back on
 * different days sends one email per item, not one combined email — see
 * that same doc for why this is a deliberate simplification, not an
 * oversight.
 *
 * Idempotency: no ReminderLog table. Both notification classes route
 * through EmailServiceChannel, so EmailService's own `message_key` UNIQUE
 * constraint is the dedup mechanism — identical to how OrderPaid/Confirmed/
 * HandedOver/Returned notifications already work, and unlike
 * ProcessRemindersJob, which predates that pattern and keeps its own
 * ReminderLog table. See each notification class's docblock for exactly
 * which metadata keys make up its dedup identity.
 *
 * Scheduled: daily (see routes/console.php). Queue: reminders.
 */
class ProcessRentalReturnRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('reminders');
    }

    public function handle(): void
    {
        Log::info('[ProcessRentalReturnRemindersJob] Starting');

        $stats = [
            'due_soon' => 0,
            'overdue' => 0,
            'skipped_no_user' => 0,
        ];

        $this->processDueSoon($stats);
        $this->processOverdue($stats);

        Log::info('[ProcessRentalReturnRemindersJob] Completed', $stats);
    }

    /**
     * Items whose rental period ends tomorrow, on an order still in progress
     * (equipment not yet returned). Exact-day match — end_date has no time
     * component, so no window/buffer is needed the way ProcessRemindersJob
     * needs one for timestamp columns.
     */
    private function processDueSoon(array &$stats): void
    {
        $tomorrow = Carbon::tomorrow()->toDateString();

        $this->inProgressItemsQuery()
            ->whereDate('end_date', $tomorrow)
            ->get()
            ->each(function (OrderItem $item) use (&$stats): void {
                if ($this->notify($item, fn ($order) => new RentalReturnDueSoonNotification($order, $item), 'due_soon')) {
                    $stats['due_soon']++;
                } else {
                    $stats['skipped_no_user']++;
                }
            });
    }

    /**
     * Items whose rental period ended before today, on an order still in
     * progress. Deliberately `< today`, not `= yesterday`: an item missed by
     * a day the job did not run still gets exactly one overdue notice next
     * run, rather than silently never getting one. The notification's own
     * dedup key (order_item_id only, no date — see its docblock) is what
     * keeps this to one send per item despite the query re-matching it every
     * day the order stays in_progress.
     */
    private function processOverdue(array &$stats): void
    {
        $today = Carbon::today()->toDateString();

        $this->inProgressItemsQuery()
            ->whereDate('end_date', '<', $today)
            ->get()
            ->each(function (OrderItem $item) use (&$stats): void {
                if ($this->notify($item, fn ($order) => new RentalReturnOverdueNotification($order, $item), 'overdue')) {
                    $stats['overdue']++;
                } else {
                    $stats['skipped_no_user']++;
                }
            });
    }

    /**
     * @return Builder<OrderItem>
     */
    private function inProgressItemsQuery(): Builder
    {
        return OrderItem::query()
            ->whereHas('order', fn (Builder $q) => $q->where('status', 'in_progress'))
            ->with('order.user');
    }

    /**
     * @param  \Closure(\App\Models\Order): (RentalReturnDueSoonNotification|RentalReturnOverdueNotification)  $makeNotification
     */
    private function notify(OrderItem $item, \Closure $makeNotification, string $reminderType): bool
    {
        $order = $item->order;

        if ($order === null || $order->user === null) {
            Log::warning('[ProcessRentalReturnRemindersJob] No user attached, skipping', [
                'order_item_id' => $item->id,
                'order_id' => $order?->id,
                'reminder_type' => $reminderType,
            ]);

            return false;
        }

        $order->user->notify($makeNotification($order));

        return true;
    }
}
