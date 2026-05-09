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

            $customerType = $checkoutData['customer_type'] ?? 'natural_person';
            $isBusinessCustomer = $customerType === 'business';

            // Calculate total deposit from cart items (snapshot at checkout time)
            $depositTotal = $cart->items->sum(function ($item) {
                return ($item->service->deposit_amount ?? 0) * $item->quantity;
            });

            $now = now();

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
                // Customer data
                'customer_email' => $checkoutData['customer_email'] ?? null,
                'customer_first_name' => $checkoutData['customer_first_name'] ?? null,
                'customer_last_name' => $checkoutData['customer_last_name'] ?? null,
                'customer_phone' => $checkoutData['customer_phone'] ?? null,
                // Legal fields
                'customer_type' => $customerType,
                'customer_pesel' => $checkoutData['customer_pesel'] ?? null,
                'customer_street' => $checkoutData['customer_street'] ?? null,
                'customer_building' => $checkoutData['customer_building'] ?? null,
                'customer_apartment' => $checkoutData['customer_apartment'] ?? null,
                'customer_city' => $checkoutData['customer_city'] ?? null,
                'customer_postal_code' => $checkoutData['customer_postal_code'] ?? null,
                // Invoice — for business always requested
                'invoice_requested' => $isBusinessCustomer ? true : ($checkoutData['invoice_requested'] ?? false),
                'invoice_company_name' => $checkoutData['invoice_company_name'] ?? null,
                'invoice_nip' => $checkoutData['invoice_nip'] ?? null,
                'invoice_street' => $checkoutData['invoice_street'] ?? null,
                'invoice_street_number' => $checkoutData['invoice_street_number'] ?? null,
                'invoice_postal_code' => $checkoutData['invoice_postal_code'] ?? null,
                'invoice_city' => $checkoutData['invoice_city'] ?? null,
                // Business extras
                'company_regon' => $checkoutData['company_regon'] ?? null,
                'company_krs' => $checkoutData['company_krs'] ?? null,
                'company_contact_name' => $checkoutData['company_contact_name'] ?? null,
                'signatory_id_number' => $checkoutData['signatory_id_number'] ?? null,
                'pickup_person_name' => $checkoutData['pickup_person_name'] ?? null,
                'pickup_person_id_number' => $checkoutData['pickup_person_id_number'] ?? null,
                // Deposit (kaucja)
                'deposit_amount' => $depositTotal,
                'deposit_status' => $depositTotal > 0 ? 'pending' : 'not_required',
                // Legal acceptances with timestamps + IP
                'rodo_accepted_at' => $now,
                'rodo_accepted_ip' => $checkoutData['ip'] ?? null,
                'terms_accepted_at' => $now,
                'withdrawal_exclusion_accepted_at' => $now,
                // Meta
                'cart_id' => $cart->id,
                'ip_address' => $checkoutData['ip'] ?? null,
                'expires_at' => $now->addMinutes(20),
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
                    'deposit_amount' => $item->service->deposit_amount ?? 0,
                ]);
            }

            // Optionally persist checkout data back to the user's profile
            if (! empty($checkoutData['save_to_profile'])) {
                $this->saveProfileData($cart->user_id, $checkoutData);
            }

            $cart->update(['status' => 'converted']);

            return $order;
        });
    }

    /**
     * Persist checkout data back to the user profile when "save_to_profile" is requested.
     * Only updates non-null fields to avoid overwriting existing data with empty values.
     */
    private function saveProfileData(int $userId, array $checkoutData): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $updateData = array_filter([
            'customer_type' => $checkoutData['customer_type'] ?? null,
            'pesel' => $checkoutData['customer_pesel'] ?? null,
            'street_name' => $checkoutData['customer_street'] ?? null,
            'street_number' => $checkoutData['customer_building'] ?? null,
            'city' => $checkoutData['customer_city'] ?? null,
            'postal_code' => $checkoutData['customer_postal_code'] ?? null,
            'company_name' => $checkoutData['invoice_company_name'] ?? null,
            'nip' => $checkoutData['invoice_nip'] ?? null,
            'regon' => $checkoutData['company_regon'] ?? null,
            'krs' => $checkoutData['company_krs'] ?? null,
            'billing_street' => $checkoutData['invoice_street'] ?? null,
            'billing_building_number' => $checkoutData['invoice_street_number'] ?? null,
            'billing_postal_code' => $checkoutData['invoice_postal_code'] ?? null,
            'billing_city' => $checkoutData['invoice_city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($updateData)) {
            $user->update($updateData);
        }
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
