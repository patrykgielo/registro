# Checkout UX Improvements

**Branch:** `feature/checkout-ux-improvements`
**Date:** 2026-05-19
**Status:** Implemented

---

## Overview

UX and customer communication improvements for the rental checkout flow. All changes are rental-specific — focused on deposit transparency, clear process explanation, and post-order pickup information.

---

## Changes

### 1. ORDER_PAID Email — Rental Enrichment

**File:** `app/Notifications/OrderPaidNotification.php`

Added `buildRentalVariables(Order $order)` private method. When `recipientType === 'customer'`, the email now includes:

| Variable | Source | Content |
|----------|--------|---------|
| `items_list_html` | `$order->items` | HTML table: service name, dates, quantity, price per item |
| `items_list_text` | `$order->items` | Plain-text fallback list |
| `deposit_amount` | `$order->deposit_amount` | Formatted "200,00 zł" or empty string if no deposit |
| `pickup_address` | `$order->organization->settings` JSON | `contact.address_line` + `contact.postal_code` + `contact.city` |
| `pickup_phone` | `$order->organization->settings` JSON | `contact.phone` |

**Critical:** Uses `$order->organization->settings` JSON directly (NOT `SettingsManager`) — queue-safe, no HTTP context required. Uses `$order->loadMissing(['items', 'organization'])` to avoid N+1.

**Template seeder:** `database/seeders/EmailTemplateSeeder.php` — ORDER_PAID templates (PL + EN) updated to use new variables. Seeder uses `updateOrCreate` so re-running updates existing DB records.

### 2. "Co się dzieje dalej?" — Checkout Process Steps

**File:** `resources/views/checkout/show.blade.php`

Added numbered step list in the sticky checkout sidebar, between the kaucja block and the payment button. Shows 3 steps:
1. Opłać → e-mail z potwierdzeniem
2. Administrator potwierdza dostępność
3. Odbiór osobisty z dokumentem tożsamości

### 3. Pickup Location Section — orders/show

**File:** `resources/views/orders/show.blade.php`

Added new `<section aria-labelledby="pickup-heading">` after "Dane kontaktowe", visible only when `$order->organization->settings` contains at least one of: `contact.address_line`, `contact.city`, `contact.phone`.

Renders address (address_line + postal_code + city) and phone as `<a href="tel:...">` link.

No migration needed — data comes from existing `organizations.settings` JSON column.

### 4. Status Label: `in_progress` → "Sprzęt u klienta"

Changed in 4 locations:
- `resources/views/orders/show.blade.php` — customer order detail page
- `resources/views/orders/index.blade.php` — customer orders list
- `app/Filament/Resources/OrderResource.php` — admin panel (both `formatStateUsing` and filter `options`)

Previously inconsistent: frontend used "W realizacji", Filament used "W trakcie". Now unified as "Sprzęt u klienta" across all views.

### 5. PESEL Hint Text Update

**File:** `resources/views/checkout/show.blade.php`

Updated `#customer_pesel-hint` from legal-oriented copy to customer-facing:

- Before: "Numer PESEL jest wymagany do zawarcia umowy najmu i ewentualnego dochodzenia roszczeń."
- After: "Wymagany do weryfikacji tożsamości przy odbiorze sprzętu."

---

## What Was NOT Changed

- Delivery/shipping option — personal pickup only (by design)
- SMS notifications — separate phase
- `ORDER_READY_FOR_PICKUP` template — separate phase
- `ORDER_RETURN_REMINDER` template — separate phase
