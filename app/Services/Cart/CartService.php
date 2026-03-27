<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Support\Carbon;

class CartService
{
    public function __construct(
        protected RentalAvailabilityService $availability
    ) {}

    /**
     * Returns an existing active cart or creates a new one.
     */
    public function getOrCreateCart(Organization $organization, User $user): Cart
    {
        return Cart::active()
            ->forUser($user)
            ->where('organization_id', $organization->id)
            ->first()
            ?? Cart::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'status' => 'active',
                'expires_at' => now()->addHours(2),
            ]);
    }

    /**
     * Adds an item to the cart after checking availability.
     *
     * @throws \Exception when requested quantity exceeds available stock
     */
    public function addItem(Cart $cart, Service $service, Carbon $start, Carbon $end, int $quantity): CartItem
    {
        $available = $this->availability->getAvailableQuantity($service, $start, $end);

        if ($quantity > $available) {
            throw new \Exception("Dostępnych tylko {$available} szt.");
        }

        $rentalDays = $end->diffInDays($start) + 1;
        $pricing = $this->availability->calculatePricing($service, $rentalDays, $quantity);

        return CartItem::create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'quantity' => $quantity,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'rental_days' => $rentalDays,
            'unit_price' => $pricing['unit_price'],
            'total_price' => $pricing['total'],
            'price_snapshot' => $pricing,
        ]);
    }

    /**
     * Removes an item from the cart, verifying ownership first.
     *
     * @throws \Exception when item does not belong to the cart
     */
    public function removeItem(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            throw new \Exception('Item nie należy do tego koszyka');
        }

        $item->delete();
    }

    /**
     * Updates item quantity after re-checking availability.
     *
     * @throws \Exception when item does not belong to the cart or quantity exceeds stock
     */
    public function updateQuantity(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        if ($item->cart_id !== $cart->id) {
            throw new \Exception('Item nie należy do tego koszyka');
        }

        $start = Carbon::parse($item->start_date);
        $end = Carbon::parse($item->end_date);

        $available = $this->availability->getAvailableQuantity($item->service, $start, $end);

        if ($quantity > $available) {
            throw new \Exception("Dostępnych tylko {$available} szt.");
        }

        $pricing = $this->availability->calculatePricing($item->service, $item->rental_days, $quantity);

        $item->update([
            'quantity' => $quantity,
            'unit_price' => $pricing['unit_price'],
            'total_price' => $pricing['total'],
            'price_snapshot' => $pricing,
        ]);

        return $item->fresh();
    }
}
