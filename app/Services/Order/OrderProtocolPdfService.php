<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use App\Support\Settings\SettingsManager;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Generates the two printable, sign-at-the-counter rental documents:
 * handover protocol (protokół wydania) and return protocol (protokół zwrotu).
 *
 * Generated on demand, never persisted — same pattern as
 * StatisticsExportService::toPdf(). The signed PAPER copy is the legal
 * record of what was handed over/returned, not this PDF.
 *
 * NOT because the underlying data is immutable — it mostly isn't. The views
 * render checkout-time SNAPSHOT columns (customer_first_name, service_name,
 * ...), copied onto orders/order_items once and never re-read through the
 * live user/service relations, so a later catalogue rename or profile edit
 * cannot silently rewrite an already-issued protocol's text. But most of
 * those snapshot columns are ordinary mutable columns with no guard at all:
 * deposit_status changes by design over an order's life (see the deposit
 * table in both blade views), and RentalExtensionService::approve() legally
 * rewrites OrderItem::end_date/total_price after handover — a handover
 * protocol and a later return protocol for the same items can genuinely
 * disagree about the rental period. A reprint always reflects the CURRENT
 * row, not the row at the moment the event it documents happened; both
 * views say so next to the deposit line. See
 * app/docs/features/order-protocols.md for the full reasoning.
 */
class OrderProtocolPdfService
{
    /** @var array<int, string> Statuses reachable only after the equipment left the counter. */
    private const HANDOVER_ELIGIBLE_STATUSES = ['in_progress', 'completed', 'refunded'];

    /** @var array<int, string> Statuses reachable only after the return was accepted. */
    private const RETURN_ELIGIBLE_STATUSES = ['completed', 'refunded'];

    public function __construct(protected SettingsManager $settings) {}

    public function handoverProtocol(Order $order): Response
    {
        if (! $this->canDownloadHandoverProtocol($order)) {
            throw new \DomainException('Protokół wydania jest dostępny dopiero po wydaniu sprzętu klientowi.');
        }

        return $this->render('orders.protocols.handover', $order, 'protokol-wydania');
    }

    public function returnProtocol(Order $order): Response
    {
        if (! $this->canDownloadReturnProtocol($order)) {
            throw new \DomainException('Protokół zwrotu jest dostępny dopiero po przyjęciu zwrotu sprzętu.');
        }

        return $this->render('orders.protocols.return', $order, 'protokol-zwrotu');
    }

    /**
     * `completed` has no path to `cancelled` in the state machine's own
     * transitions() map (only `completed -> refunded`), so unlike the
     * handover side there is no forced-cancellation edge case to account
     * for here — a plain status check is enough. Kept as its own public
     * method anyway (not inlined at call sites), same reasoning as
     * canDownloadHandoverProtocol()'s docblock above.
     */
    public function canDownloadReturnProtocol(Order $order): bool
    {
        return in_array($order->status, self::RETURN_ELIGIBLE_STATUSES, true);
    }

    /**
     * `in_progress -> cancelled` is a legal transition (forced offboarding of
     * a closing tenant, see OrderStatusStateMachine::transitions()) — an
     * order whose equipment genuinely left the counter must not permanently
     * lose access to the document proving it just because it was later
     * force-cancelled. `cancelled` is also reachable from pending_payment/
     * paid/confirmed, where handover never happened, so it cannot simply be
     * added to HANDOVER_ELIGIBLE_STATUSES — that would generate a false
     * document for those. No handed_over_at column exists (deliberate, see
     * order-notifications.md), so the state machine's own audit trail
     * (state_histories, via HasStateMachines::stateHistory()) is the source
     * of truth for "did this order ever reach in_progress before being
     * cancelled" — no new column needed.
     *
     * Public: also the single source of truth for whether the download
     * BUTTON should be shown (Filament row/header actions, the customer's
     * own order page) — not just for whether the download itself succeeds.
     * Do not re-implement this eligibility check as a plain in_array() at
     * any of those call sites; call this method instead, or the UI and the
     * actual download can silently disagree again exactly like the
     * deposit-status bug this branch already fixed once.
     */
    public function canDownloadHandoverProtocol(Order $order): bool
    {
        if (in_array($order->status, self::HANDOVER_ELIGIBLE_STATUSES, true)) {
            return true;
        }

        return $order->status === 'cancelled'
            && $order->stateHistory()->where('field', 'status')->where('to', 'in_progress')->exists();
    }

    private function render(string $view, Order $order, string $filenamePrefix): Response
    {
        $order->loadMissing(['items', 'organization']);

        $pdf = Pdf::loadView($view, [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => $this->pickupDetails($order),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ]);

        $filename = $filenamePrefix.'-'.$order->order_number.'.pdf';

        return $pdf->download($filename);
    }

    /**
     * organizations has no legal identity (NIP/REGON/registered address) —
     * only 'name' plus the `settings` table's contact.* group, read via
     * SettingsManager::contactDetailsFor() — the single canonical accessor
     * for this, tenant row falling back to the global row. Mirrors
     * OrderPaidNotification::buildRentalVariables(); do not invent extra
     * settings keys here, that is a product decision pending separately
     * (see order-protocols.md). Do NOT read $order->organization->settings
     * (the JSON column) — see contactDetailsFor()'s own docblock for why a
     * shared accessor exists instead of each caller reading the settings
     * table directly.
     *
     * @return array{address: string, phone: string, email: string}
     */
    private function pickupDetails(Order $order): array
    {
        $contact = $this->settings->contactDetailsFor($order->organization);

        $address = trim(implode(', ', array_filter([
            $contact['address_line'],
            trim($contact['postal_code'].' '.$contact['city']),
        ])));

        return ['address' => $address, 'phone' => $contact['phone'], 'email' => $contact['email']];
    }
}
