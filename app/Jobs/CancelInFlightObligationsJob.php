<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AppointmentStatus;
use App\Enums\RentalStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Rental;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cancels all in-flight obligations (appointments, orders, rentals) for a closing organization.
 *
 * Dispatched by StartOrganizationOffboarding when the org transitions to Closing.
 * Each cancellation fires the existing domain events (OrderCancelled, AppointmentCancelled,
 * RentalCancelled) which in turn notify the affected customers automatically.
 *
 * Idempotent: already-cancelled/terminal records are skipped.
 *
 * IMPORTANT: Refunds are NOT executed — paid orders and deposits are only FLAGGED
 * via Log::info() as needing manual or future-automated refund processing.
 *
 * Queue: default
 */
class CancelInFlightObligationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $organizationId,
        private readonly string $reason,
    ) {}

    public function handle(OrderService $orderService): void
    {
        Log::info('CancelInFlightObligationsJob: starting', [
            'organization_id' => $this->organizationId,
            'reason' => $this->reason,
        ]);

        $cancelledOrders = $this->cancelOrders($orderService);
        $cancelledAppointments = $this->cancelAppointments();
        $cancelledRentals = $this->cancelRentals();

        Log::info('CancelInFlightObligationsJob: completed', [
            'organization_id' => $this->organizationId,
            'cancelled_orders' => $cancelledOrders,
            'cancelled_appointments' => $cancelledAppointments,
            'cancelled_rentals' => $cancelledRentals,
        ]);
    }

    private function cancelOrders(OrderService $orderService): int
    {
        $cancelled = 0;

        Order::withoutGlobalScope('organization')
            ->where('organization_id', $this->organizationId)
            ->whereIn('status', ['pending_payment', 'paid', 'confirmed', 'in_progress'])
            ->chunkById(100, function ($orders) use ($orderService, &$cancelled): void {
                foreach ($orders as $order) {
                    if (in_array($order->status, ['paid', 'in_progress'])) {
                        Log::info('offboarding: refund required for order', [
                            'organization_id' => $this->organizationId,
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'total_amount' => $order->total_amount,
                            'status' => $order->status,
                            'reason' => 'Zamknięcie działalności — wymaga ręcznego zwrotu przez Przelewy24',
                        ]);
                    }

                    try {
                        $orderService->cancel($order, $this->reason);
                        $cancelled++;
                    } catch (\LogicException $e) {
                        Log::warning('offboarding: could not cancel order', [
                            'organization_id' => $this->organizationId,
                            'order_id' => $order->id,
                            'status' => $order->status,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $cancelled;
    }

    private function cancelAppointments(): int
    {
        $cancelled = 0;

        Appointment::withoutGlobalScope('organization')
            ->where('organization_id', $this->organizationId)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
            ])
            ->chunkById(100, function ($appointments) use (&$cancelled): void {
                foreach ($appointments as $appointment) {
                    try {
                        // Set reason BEFORE status change — AppointmentCancelled event dispatches
                        // from booted() updating() hook without carrying the reason; it must be
                        // stored on the model so the notification can read it.
                        $appointment->cancellation_reason = $this->reason;
                        $appointment->status = AppointmentStatus::Cancelled;
                        $appointment->save();
                        $cancelled++;
                    } catch (\Throwable $e) {
                        Log::error('offboarding: could not cancel appointment', [
                            'organization_id' => $this->organizationId,
                            'appointment_id' => $appointment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $cancelled;
    }

    private function cancelRentals(): int
    {
        $cancelled = 0;

        Rental::withoutGlobalScope('organization')
            ->where('organization_id', $this->organizationId)
            ->whereIn('status', [
                RentalStatus::Held->value,
                RentalStatus::Pending->value,
                RentalStatus::Confirmed->value,
                RentalStatus::Active->value,
            ])
            ->chunkById(100, function ($rentals) use (&$cancelled): void {
                foreach ($rentals as $rental) {
                    if ($rental->deposit_amount > 0) {
                        Log::info('offboarding: deposit refund required for rental', [
                            'organization_id' => $this->organizationId,
                            'rental_id' => $rental->id,
                            'deposit_amount' => $rental->deposit_amount,
                            'reason' => 'Zamknięcie działalności — wymaga ręcznego zwrotu kaucji',
                        ]);
                    }

                    try {
                        $rental->cancellation_reason = $this->reason;
                        $rental->status = RentalStatus::Cancelled;
                        $rental->save();
                        $cancelled++;
                    } catch (\Throwable $e) {
                        Log::error('offboarding: could not cancel rental', [
                            'organization_id' => $this->organizationId,
                            'rental_id' => $rental->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $cancelled;
    }
}
