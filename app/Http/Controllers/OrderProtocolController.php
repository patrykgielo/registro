<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Order\OrderProtocolPdfService;
use App\Support\TenantFeature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Download of the two printable rental documents (handover protocol /
 * return protocol) — shared by the customer's own order page AND the admin
 * Filament actions (OrderResource / EditOrder point straight at this route
 * via ->url(), same web `auth` guard, one code path instead of a second
 * download mechanism inside Filament — see order-protocols.md §"Filament
 * actions and the Livewire file-download bug").
 *
 * Authorization deliberately returns 404 (not 403 like OrderController's own
 * checks) for BOTH "wrong tenant/not your order" and "wrong order state" —
 * a customer probing order numbers must not be able to tell an existing
 * order from a nonexistent one, or a valid-but-not-yet-eligible order from
 * one that will never be eligible.
 */
class OrderProtocolController extends Controller
{
    public function __construct(
        protected OrderProtocolPdfService $protocolPdfService
    ) {}

    public function handover(Request $request, Order $order): Response
    {
        $this->authorizeAccess($order);

        try {
            return $this->protocolPdfService->handoverProtocol($order);
        } catch (\DomainException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function returned(Request $request, Order $order): Response
    {
        $this->authorizeAccess($order);

        try {
            return $this->protocolPdfService->returnProtocol($order);
        } catch (\DomainException $e) {
            abort(404, $e->getMessage());
        }
    }

    /**
     * THE ENTIRE DEFENSE against a cross-tenant or cross-customer download —
     * not defense-in-depth on top of Order's BelongsToOrganization global
     * scope. That scope does NOT protect this route: it depends on
     * TenantFeature::currentTenant(), which has nothing to do with the
     * {order} route parameter, so implicit route-model binding resolves
     * ANY order ID regardless of tenant. Confirmed empirically (code review,
     * 2026-08-13): stripping this method's body down to just the tenant
     * check served a cross-tenant order's PDF with a 200. Do not "simplify"
     * this away as redundant with the model's scope — it is not.
     *
     * Two distinct populations are allowed through, both still tenant-scoped
     * by the check above: the order's own customer, and staff of the SAME
     * tenant (mirrors OrderResource::canViewAny()'s role check — this route
     * is also where the admin Filament actions point, see the class
     * docblock). A staff member of a DIFFERENT tenant is rejected by the
     * tenant check same as any other stranger.
     */
    private function authorizeAccess(Order $order): void
    {
        $org = TenantFeature::currentTenant();

        abort_unless($org !== null, 404);
        abort_unless($order->organization_id === $org->id, 404);

        $user = auth()->user();
        $isOwner = $user !== null && $order->user_id === $user->id;
        $isStaff = $user?->hasAnyRole(['admin', 'super-admin']) ?? false;

        abort_unless($isOwner || $isStaff, 404);
    }
}
