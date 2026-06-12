<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\RentalUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\RentalExtensionService;
use App\Support\Settings\SettingsManager;
use App\Support\TenantFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RentalExtensionController extends Controller
{
    public function __construct(
        protected RentalExtensionService $extensionService,
        protected SettingsManager $settings
    ) {}

    public function checkAvailability(Request $request, Order $order, OrderItem $orderItem): JsonResponse
    {
        abort_unless($this->settings->isRentalExtensionEnabled(), 404);

        $org = TenantFeature::currentTenant();
        abort_unless($org !== null, 404);
        abort_unless($order->user_id === auth()->id() && $order->organization_id === $org->id, 403);
        abort_unless($orderItem->order_id === $order->id, 403);

        $request->validate([
            'new_end_date' => ['required', 'date', 'after:'.$orderItem->end_date->toDateString()],
        ]);

        $requestedEndDate = Carbon::parse($request->input('new_end_date'))->startOfDay();

        $additionalDays = (int) $orderItem->end_date->diffInDays($requestedEndDate);
        $available = $this->extensionService->checkAvailabilityForExtension($orderItem, $requestedEndDate);
        $canExtend = $available >= $orderItem->quantity
            && $this->extensionService->canRequestExtension($order, $orderItem);

        $estimatedAmount = $canExtend
            ? $this->extensionService->calculateAdditionalAmount($orderItem, $additionalDays)
            : 0.0;

        return response()->json([
            'available' => $available,
            'additional_days' => $additionalDays,
            'estimated_amount' => $estimatedAmount,
            'can_extend' => $canExtend,
        ]);
    }

    public function store(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        abort_unless($this->settings->isRentalExtensionEnabled(), 404);

        $org = TenantFeature::currentTenant();
        abort_unless($org !== null, 404);
        abort_unless($order->user_id === auth()->id() && $order->organization_id === $org->id, 403);
        abort_unless($orderItem->order_id === $order->id, 403);
        abort_unless(in_array($order->status, ['paid', 'confirmed', 'in_progress'], true), 422);

        $validated = $request->validate([
            'new_end_date' => ['required', 'date', 'after:'.$orderItem->end_date->toDateString()],
            'customer_notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->extensionService->requestExtension(
                $orderItem,
                auth()->user(),
                Carbon::parse($validated['new_end_date'])->startOfDay(),
                $validated['customer_notes'] ?? null
            );

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Wniosek o przedłużenie został złożony. Poczekaj na potwierdzenie od wypożyczalni.');
        } catch (RentalUnavailableException $e) {
            return back()->withErrors(['new_end_date' => $e->getMessage()]);
        }
    }
}
