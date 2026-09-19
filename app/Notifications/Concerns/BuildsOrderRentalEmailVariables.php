<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Order;
use App\Support\Email\TrustedHtml;
use App\Support\Settings\SettingsManager;

/**
 * Shared template-variable builder for order emails that list rented items
 * (items table, deposit line, pickup address/phone). Extracted from
 * OrderPaidNotification so OrderAcceptedOfflineNotification can reuse the
 * exact same rendering without duplicating the escaping/TrustedHtml logic.
 */
trait BuildsOrderRentalEmailVariables
{
    /**
     * Build rental-specific template variables from order data.
     *
     * Reads pickup/contact info via SettingsManager::pickupDetailsFor() (Faza 6 krok
     * 6.5) — the single canonical accessor for this, queue-safe because it takes the
     * order explicitly rather than resolving TenantFeature::currentTenant() (which
     * depends on request/session state a queue worker doesn't have). Prefers the
     * order's own checkout-time pickup-location snapshot when one exists, falling
     * back to the tenant's contact settings otherwise — same resolver, same fallback,
     * as the customer's own order page and the protocol PDFs. Do NOT read
     * $order->organization->settings (the JSON column) — see
     * SettingsManager::contactDetailsFor()'s own docblock for why a shared accessor
     * exists instead of each caller reading the settings table directly.
     *
     * `pickup_location_name`/`pickup_location_address` are exposed as new template
     * variables (empty string when no snapshot exists) for a FUTURE template edit —
     * no existing seeded template references them yet, so adding them here changes
     * nothing about what a customer sees today. `pickup_address`/`pickup_phone`
     * DO change behaviour: they already exist in ORDER_ACCEPTED_OFFLINE/ORDER_PAID
     * bodies and now resolve to the branch address when a snapshot exists, exactly
     * like the order page above — no template body edit required.
     *
     * @return array<string, string|TrustedHtml>
     */
    private function buildRentalVariables(Order $order): array
    {
        $itemsHtml = '';
        $itemsText = '';

        foreach ($order->items as $item) {
            $dates = ($item->start_date && $item->end_date)
                ? $item->start_date->format('d.m.Y').' – '.$item->end_date->format('d.m.Y')
                : '';
            $qty = $item->quantity > 1 ? ' × '.$item->quantity : '';
            $price = number_format((float) $item->total_price, 2, ',', ' ').' zł';

            $itemsHtml .= '<tr>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">'
                .htmlspecialchars($item->service_name.$qty)
                .'<br><small style="color:#6b7280;">'.$dates.'</small>'
                .'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;">'
                .$price
                .'</td>'
                .'</tr>';

            $itemsText .= '- '.$item->service_name.$qty.($dates ? ' ('.$dates.')' : '').': '.$price."\n";
        }

        $itemsListHtml = $itemsHtml
            ? '<table style="width:100%;border-collapse:collapse;font-size:14px;">'.$itemsHtml.'</table>'
            : '';

        $depositAmount = ($order->deposit_amount ?? 0) > 0
            ? number_format((float) $order->deposit_amount, 2, ',', ' ').' zł'
            : '';

        $contact = app(SettingsManager::class)->pickupDetailsFor($order);
        $phone = $contact['phone'];

        $pickupAddress = trim(implode(', ', array_filter([
            $contact['address_line'],
            trim($contact['postal_code'].' '.$contact['city']),
        ])));

        return [
            'items_list_html' => new TrustedHtml($itemsListHtml),
            'items_list_text' => rtrim($itemsText),
            'deposit_amount' => $depositAmount,
            'pickup_address' => $pickupAddress,
            'pickup_phone' => $phone,
            'pickup_location_name' => $contact['location_name'] ?? '',
            'pickup_location_address' => $order->pickup_location_address ?? '',
        ];
    }
}
