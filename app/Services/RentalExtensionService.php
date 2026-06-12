<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtensionRequestStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\User;
use App\Notifications\RentalExtensionApprovedNotification;
use App\Notifications\RentalExtensionRejectedNotification;
use App\Notifications\RentalExtensionRequestedNotification;
use App\Support\TenantFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RentalExtensionService
{
    private const EXTENDABLE_STATUSES = ['paid', 'confirmed', 'in_progress'];

    public function __construct(
        protected RentalAvailabilityService $availabilityService
    ) {}

    /**
     * Check if a customer can request an extension for this item.
     */
    public function canRequestExtension(Order $order, OrderItem $item): bool
    {
        if (! in_array($order->status, self::EXTENDABLE_STATUSES, true)) {
            return false;
        }

        if ($item->end_date === null) {
            return false;
        }

        // Block if a pending request already exists for this item
        if ($item->extensionRequests()->pending()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Check available quantity for the extension window (end_date+1 → requestedEndDate).
     * The current item naturally does NOT overlap this window (end_date < end_date+1).
     */
    public function checkAvailabilityForExtension(OrderItem $item, Carbon $requestedEndDate): int
    {
        $service = $item->service;

        if ($service === null) {
            return 0;
        }

        $extensionStart = $item->end_date->copy()->addDay();

        return $this->availabilityService->getAvailableQuantity($service, $extensionStart, $requestedEndDate);
    }

    /**
     * Calculate the additional cost for the extension period.
     */
    public function calculateAdditionalAmount(OrderItem $item, int $additionalDays): float
    {
        $service = $item->service;

        if ($service === null) {
            return 0.0;
        }

        $pricing = $this->availabilityService->calculatePricing($service, $additionalDays, $item->quantity);

        return (float) $pricing['total'];
    }

    /**
     * Submit an extension request for a given order item.
     *
     * @throws RentalUnavailableException
     */
    public function requestExtension(
        OrderItem $item,
        User $user,
        Carbon $requestedEndDate,
        ?string $notes
    ): OrderItemExtensionRequest {
        return DB::transaction(function () use ($item, $user, $requestedEndDate, $notes) {
            // Lock item row to prevent concurrent submissions (eager-load order to avoid lazy load inside lock)
            $item = OrderItem::with('order')->where('id', $item->id)->lockForUpdate()->first();

            if ($item->extensionRequests()->pending()->exists()) {
                throw new RentalUnavailableException('Istnieje już oczekujący wniosek o przedłużenie dla tej pozycji.');
            }

            $available = $this->checkAvailabilityForExtension($item, $requestedEndDate);

            if ($available < $item->quantity) {
                throw new RentalUnavailableException('Wybrany sprzęt nie jest dostępny w podanym terminie.');
            }

            $additionalDays = (int) $item->end_date->diffInDays($requestedEndDate);
            $additionalAmount = $this->calculateAdditionalAmount($item, $additionalDays);

            $request = OrderItemExtensionRequest::create([
                'organization_id' => $item->order->organization_id,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'requested_by_user_id' => $user->id,
                'status' => ExtensionRequestStatus::Pending,
                'original_end_date' => $item->end_date,
                'requested_end_date' => $requestedEndDate,
                'additional_days' => $additionalDays,
                'additional_amount' => $additionalAmount,
                'customer_notes' => $notes,
            ]);

            // Notify tenant admin
            $tenant = TenantFeature::currentTenant();
            if ($tenant && $tenant->owner_id) {
                $owner = \App\Models\User::find($tenant->owner_id);
                if ($owner) {
                    $owner->notify(new RentalExtensionRequestedNotification($request));
                }
            }

            return $request;
        });
    }

    /**
     * Approve an extension request, updating the order item and totals.
     *
     * @throws RentalUnavailableException
     */
    public function approve(OrderItemExtensionRequest $extensionRequest, User $admin): void
    {
        DB::transaction(function () use ($extensionRequest, $admin) {
            // Pessimistic lock on request + item + service
            $extensionRequest = OrderItemExtensionRequest::where('id', $extensionRequest->id)->lockForUpdate()->first();

            if ($extensionRequest->status !== ExtensionRequestStatus::Pending) {
                throw new \RuntimeException('Wniosek nie jest już oczekujący.');
            }

            $item = OrderItem::where('id', $extensionRequest->order_item_id)->lockForUpdate()->first();

            // Re-validate availability (state may have changed)
            $available = $this->checkAvailabilityForExtension($item, $extensionRequest->requested_end_date);

            if ($available < $item->quantity) {
                throw new RentalUnavailableException('Sprzęt nie jest już dostępny w podanym terminie. Wniosek nie może zostać zatwierdzony.');
            }

            // Update item
            $item->update([
                'end_date' => $extensionRequest->requested_end_date,
                'rental_days' => $item->rental_days + $extensionRequest->additional_days,
                'total_price' => $item->total_price + $extensionRequest->additional_amount,
            ]);

            // Update order totals
            $order = $extensionRequest->order;
            $order->increment('subtotal', $extensionRequest->additional_amount);
            $order->increment('total_amount', $extensionRequest->additional_amount);

            // Mark as approved
            $extensionRequest->update([
                'status' => ExtensionRequestStatus::Approved,
                'approved_by_user_id' => $admin->id,
                'approved_at' => now(),
            ]);

            // Auto-reject any other pending requests for the same item
            OrderItemExtensionRequest::where('order_item_id', $item->id)
                ->where('id', '!=', $extensionRequest->id)
                ->pending()
                ->update([
                    'status' => ExtensionRequestStatus::Rejected,
                    'rejection_reason' => 'Automatycznie odrzucone — zatwierdzone inne przedłużenie.',
                ]);

            // Notify customer
            $customer = $extensionRequest->requestedBy;
            if ($customer) {
                $customer->notify(new RentalExtensionApprovedNotification($extensionRequest));
            }
        });
    }

    /**
     * Reject an extension request.
     */
    public function reject(OrderItemExtensionRequest $extensionRequest, User $admin, string $reason): void
    {
        DB::transaction(function () use ($extensionRequest, $admin, $reason) {
            $extensionRequest = OrderItemExtensionRequest::where('id', $extensionRequest->id)->lockForUpdate()->first();

            if ($extensionRequest->status !== ExtensionRequestStatus::Pending) {
                throw new \RuntimeException('Wniosek nie jest już oczekujący.');
            }

            $extensionRequest->update([
                'status' => ExtensionRequestStatus::Rejected,
                'approved_by_user_id' => $admin->id,
                'rejection_reason' => $reason,
            ]);

            // Notify customer
            $customer = $extensionRequest->requestedBy;
            if ($customer) {
                $customer->notify(new RentalExtensionRejectedNotification($extensionRequest));
            }
        });
    }
}
