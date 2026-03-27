<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\CartItemOwnershipException;
use App\Exceptions\CartNotActiveException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * @throws RentalUnavailableException when requested quantity exceeds available stock
     */
    public function addItem(Cart $cart, Service $service, Carbon $start, Carbon $end, int $quantity): CartItem
    {
        $available = $this->availability->getAvailableQuantity($service, $start, $end);

        if ($quantity > $available) {
            throw new RentalUnavailableException("Dostępnych tylko {$available} szt.");
        }

        $rentalDays = (int) $start->diffInDays($end) + 1;
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
     * @throws CartItemOwnershipException when item does not belong to the cart
     */
    public function removeItem(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            throw CartItemOwnershipException::make();
        }

        $item->delete();
    }

    /**
     * Converts an active cart into a pending Order within a single transaction.
     *
     * @param  array<string, mixed>  $checkoutData
     *
     * @throws CartNotActiveException when cart is not active
     */
    public function convertToOrder(Cart $cart, array $checkoutData): Order
    {
        return DB::transaction(function () use ($cart, $checkoutData): Order {
            $cart->refresh()->lockForUpdate();

            if ($cart->status !== 'active') {
                throw CartNotActiveException::make();
            }

            $orderNumber = $this->generateOrderNumber($cart->organization_id);

            $subtotal = $cart->items->sum('total_price');

            $order = Order::create([
                'organization_id' => $cart->organization_id,
                'user_id' => $cart->user_id,
                'order_number' => $orderNumber,
                'status' => 'pending_payment',
                'currency' => 'PLN',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $subtotal,
                'customer_email' => $checkoutData['customer_email'] ?? null,
                'customer_first_name' => $checkoutData['customer_first_name'] ?? null,
                'customer_last_name' => $checkoutData['customer_last_name'] ?? null,
                'customer_phone' => $checkoutData['customer_phone'] ?? null,
                'invoice_requested' => $checkoutData['invoice_requested'] ?? false,
                'invoice_company_name' => $checkoutData['invoice_company_name'] ?? null,
                'invoice_nip' => $checkoutData['invoice_nip'] ?? null,
                'invoice_street' => $checkoutData['invoice_street'] ?? null,
                'invoice_street_number' => $checkoutData['invoice_street_number'] ?? null,
                'invoice_postal_code' => $checkoutData['invoice_postal_code'] ?? null,
                'invoice_city' => $checkoutData['invoice_city'] ?? null,
                'cart_id' => $cart->id,
                'ip_address' => $checkoutData['ip'] ?? null,
                'expires_at' => now()->addMinutes(20),
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'service_id' => $item->service_id,
                    'service_name' => $item->service->name,
                    'quantity' => $item->quantity,
                    'start_date' => $item->start_date,
                    'end_date' => $item->end_date,
                    'rental_days' => $item->rental_days,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'price_snapshot' => $item->price_snapshot,
                ]);
            }

            $cart->update(['status' => 'converted']);

            return $order;
        });
    }

    /**
     * Generates a unique, sequential order number for the given organisation and current year.
     *
     * Must be called inside a DB transaction. lockForUpdate() on the latest order row
     * serialises concurrent checkouts so that count() + 1 races are impossible.
     */
    private function generateOrderNumber(int $organizationId): string
    {
        $last = Order::where('organization_id', $organizationId)
            ->whereYear('created_at', now()->year)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $seq = $last ? ((int) substr($last->order_number, -5)) + 1 : 1;

        return 'ORG'.$organizationId.'-'.now()->year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Updates item quantity after re-checking availability.
     *
     * @throws CartItemOwnershipException when item does not belong to the cart
     * @throws RentalUnavailableException when quantity exceeds available stock
     */
    public function updateQuantity(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        if ($item->cart_id !== $cart->id) {
            throw CartItemOwnershipException::make();
        }

        $start = Carbon::parse($item->start_date);
        $end = Carbon::parse($item->end_date);

        $available = $this->availability->getAvailableQuantity($item->service, $start, $end);

        if ($quantity > $available) {
            throw new RentalUnavailableException("Dostępnych tylko {$available} szt.");
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
