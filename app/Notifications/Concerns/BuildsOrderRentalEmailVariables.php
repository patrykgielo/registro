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
     * Reads pickup/contact info via SettingsManager::contactDetailsFor() — the single
     * canonical accessor for this, queue-safe because it takes the organization
     * explicitly rather than resolving TenantFeature::currentTenant() (which depends on
     * request/session state a queue worker doesn't have). Do NOT read
     * $order->organization->settings (the JSON column) — see contactDetailsFor()'s own
     * docblock for why a shared accessor exists instead of each caller reading the
     * settings table directly.
     *
     * @return array<string, string>
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

        $contact = app(SettingsManager::class)->contactDetailsFor($order->organization);
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
        ];
    }
}
