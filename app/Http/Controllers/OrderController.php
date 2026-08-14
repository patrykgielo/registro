<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Order\OrderService;
use App\Support\Settings\SettingsManager;
use App\Support\TenantFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected SettingsManager $settings
    ) {}

    public function index(Request $request): View
    {
        $orders = Order::where('user_id', auth()->id())
            ->where('organization_id', TenantFeature::currentTenant()?->id)
            ->latest()
            ->paginate(15);

        return view('orders.index', compact('orders'));
    }

    public function show(Request $request, Order $order): View
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);
        abort_unless($order->user_id === auth()->id() && $order->organization_id === $org->id, 403);

        $rentalExtensionEnabled = $this->settings->isRentalExtensionEnabled();
        $order->load(['items.extensionRequests', 'organization']);

        // "Miejsce odbioru sprzętu" section — the settings TABLE's contact.*
        // group (what SystemSettings' Contact tab saves), via the single
        // canonical accessor. NOT $order->organization->settings, the JSON
        // column — see contactDetailsFor()'s own docblock for why.
        $pickup = $this->settings->contactDetailsFor($order->organization);

        return view('orders.show', compact('order', 'rentalExtensionEnabled', 'pickup'));
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);
        abort_unless($order->user_id === auth()->id() && $order->organization_id === $org->id, 403);
        abort_unless($order->status === 'pending_payment', 403);

        $this->orderService->cancel($order, 'Anulowane przez klienta');

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Zamówienie zostało anulowane.');
    }
}
