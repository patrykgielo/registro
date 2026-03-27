<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\RentalUnavailableException;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Models\CartItem;
use App\Models\Service;
use App\Services\Cart\CartService;
use App\Support\TenantFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CartController extends Controller
{
    public function __construct(protected CartService $cart) {}

    /**
     * Display the current user's cart.
     */
    public function show(Request $request): View
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        return view('cart.show', compact('cart'));
    }

    /**
     * Add a rental service item to the cart.
     */
    public function add(AddToCartRequest $request): RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $service = Service::findOrFail($request->service_id);
        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        try {
            $this->cart->addItem(
                $cart,
                $service,
                Carbon::parse($request->start_date),
                Carbon::parse($request->end_date),
                (int) $request->quantity
            );
        } catch (RentalUnavailableException $e) {
            return redirect()->back()->withErrors(['availability' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Dodano do koszyka.');
    }

    /**
     * Remove an item from the cart.
     */
    public function remove(Request $request, CartItem $item): RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        $this->cart->removeItem($cart, $item);

        return redirect()->route('cart.show');
    }

    /**
     * Update the quantity of a cart item.
     */
    public function updateQuantity(Request $request, CartItem $item): RedirectResponse
    {
        $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);

        $cart = $this->cart->getOrCreateCart($org, auth()->user());

        try {
            $this->cart->updateQuantity($cart, $item, (int) $request->quantity);
        } catch (RentalUnavailableException $e) {
            return redirect()->back()->withErrors(['availability' => $e->getMessage()]);
        }

        return redirect()->route('cart.show');
    }
}
