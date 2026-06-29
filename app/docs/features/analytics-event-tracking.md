# Frontend Event Tracking — Analytics Layer

## Overview

Raw event tracking for frontend user behavior, scoped per tenant. Complements the aggregate `statistics_daily_snapshots` system with granular, per-session data.

## Architecture

```
POST /api/track (throttle:analytics, ResolveTenant)
  └─ EventTrackingController::store()
       ├─ validates batch (max 30 events)
       ├─ stamps: organization_id (from tenant), ip_hash (SHA-256)
       └─ dispatches IngestAnalyticsEventsJob → queue: analytics
            └─ DB::table('analytics_events')->insert($rows)
```

## Database

Table: `analytics_events`

| Column | Type | Notes |
|--------|------|-------|
| `organization_id` | bigint unsigned NOT NULL | Server-stamped, never from client |
| `user_id` | bigint unsigned NULL | NULL for guests |
| `session_id` | varchar(64) NULL | Daily-rotating client hash |
| `event` | varchar(100) | snake_case, e.g. `page_view`, `add_to_cart` |
| `url` | varchar(2048) NULL | |
| `referrer` | varchar(2048) NULL | |
| `page_type` | varchar(50) NULL | `homepage\|service\|booking\|cart\|checkout\|confirmation` |
| `device_type` | varchar(20) NULL | `mobile\|tablet\|desktop` (varchar for SQLite compat) |
| `viewport_w` | smallint unsigned NULL | |
| `properties` | json NULL | Per-event variable data |
| `ip_hash` | varchar(64) NULL | `SHA-256(ip + app.key + date)` — never raw IP |
| `occurred_at` | datetime | Client timestamp, clamped to max 5 min in past |
| `received_at` | datetime | Server-stamped |

Indexes:
- `ae_org_time_event` on `(organization_id, occurred_at, event)` — primary query pattern
- `ae_org_session` on `(organization_id, session_id)`
- `ae_org_user_time` on `(organization_id, user_id, occurred_at)`

## Security

- `organization_id` is always set from `$request->attributes->get('tenant')` — never from client payload
- IP is hashed daily: `SHA-256(ip + app.key + YYYY-MM-DD)` — GDPR-compliant, not reversible
- Route requires `ResolveTenant` middleware — returns 400 if no tenant context
- Rate limited: 120 req/min/IP **and** 600 req/min/tenant (double bucket via named limiter `analytics`)
- `events.*.url` / `events.*.referrer`: must start with `http://` or `https://` — rejects `javascript:` URLs (XSS, stored)
- `events.*.properties.*`: string values are rejected when longer than 256 chars (blocks PII injection)
- `IngestAnalyticsEventsJob`: strips query string from `url`/`referrer` before INSERT (GDPR minimisation — removes tokens, emails from query params); also acts as second `javascript:` guard
- Blade `analytics-overview`: `href` is rendered only when `url` starts with `http` — belt-and-suspenders against stored XSS

## VALID_EVENTS (allow-list)

`IngestAnalyticsEventsJob::VALID_EVENTS` — events not in this list are silently dropped:

**Client-side (tracker JS):** `page_viewed`, `scroll_25/50/75/90/100`, `exit_intent`, `rage_click`, `section_visible`, `page.time_spent`, `product_viewed`, `calendar_interacted`, `add_to_cart`, `cart_viewed`, `form_field_focused`, `form_abandoned`, `back_navigation`

**Server-side (AnalyticsEventDispatcher):** `cart.abandoned`, `checkout.started`, `checkout.submitted`, `order.completed`

## Queue

Dispatched on queue `analytics`. Run a dedicated worker or add to the default worker:

```bash
php artisan queue:work --queue=analytics,default
```

## Files

- `database/migrations/2026_05_23_162048_create_analytics_events_table.php`
- `app/Models/AnalyticsEvent.php`
- `app/Jobs/IngestAnalyticsEventsJob.php`
- `app/Http/Controllers/Api/EventTrackingController.php`
- `tests/Feature/Analytics/EventTrackingTest.php`

## API

```
POST /api/track
Host: {tenant}.registro.local:8444
Content-Type: application/json

{
  "events": [
    {
      "event": "page_view",
      "session_id": "abc123def456",
      "url": "https://example.com/uslugi/rower",
      "referrer": "https://google.com",
      "page_type": "service",
      "device_type": "mobile",
      "viewport_w": 390,
      "timestamp": "2026-05-23T14:00:00.000Z",
      "properties": { "service_id": 42 }
    }
  ]
}

→ 202 { "ok": true }
→ 400 if no tenant context
→ 422 if validation fails (>30 events, missing event name, etc.)
→ 429 if rate limited
```

---

## Phase 2 — Funnel Tracking, Cart Abandonment, UTM Attribution

### Schema Extensions (migration 2026_06_15_100001)

New columns on `analytics_events`:

| Column | Type | Notes |
|--------|------|-------|
| `utm_source` | varchar(255) NULL | Extracted from `properties.utm_source` at ingestion |
| `utm_medium` | varchar(255) NULL | |
| `utm_campaign` | varchar(255) NULL | |
| `referrer_domain` | varchar(255) NULL | `parse_url(referrer, PHP_URL_HOST)` at ingestion |

`organization_id` is now `NULLABLE` (for server-side events fired before tenant context resolves). FK changed from `CASCADE DELETE` to `SET NULL`.

New index: `idx_org_event (organization_id, event)` for funnel queries.

### Cart Analytics Columns (migration 2026_06_15_100002)

New columns on `carts`:

| Column | Notes |
|--------|-------|
| `customer_email` | Captured on checkout email blur (future JS hook) |
| `checkout_started_at` | Set in `CheckoutController::show()` on first visit |
| `last_checkout_step` | Updated via JS: `personal\|address\|payment` |
| `abandoned_at` | Set by `MarkCartsAbandonedJob` |
| `utm_source/medium/campaign` | UTM at cart creation time (future: copy from session) |

### Server-Side Analytics Events

All dispatched via `AnalyticsEventDispatcher` → `IngestAnalyticsEventsJob` on `analytics` queue.

| Event | Trigger | Properties |
|-------|---------|------------|
| `checkout.started` | `CheckoutController::show()`, first visit | `item_count`, `cart_total` |
| `checkout.submitted` | `CheckoutController::submit()`, after convertToOrder | `order_id`, `total_amount` |
| `cart.abandoned` | `MarkCartsAbandonedJob` (every 5 min) | `cart_id`, `item_count`, `checkout_started`, `last_step` |
| `order.completed` | `RecordAnalyticsOnOrderPaid` listener on `OrderPaid` event | `order_id`, `order_number`, `total_amount`, `item_count`, `is_b2b` |

Session IDs for server-side events use synthetic format:
- `server-cart-{id}` — for cart events
- `server-order-{id}` — for order events

### UTM Attribution (JS Tracker)

`registro-tracker.js` now:
1. Calls `captureUtm()` on init — reads `?utm_*` params from current URL
2. Stores first-touch in `localStorage._tk_utm_ft` (persists across sessions)
3. Stores last-touch in `sessionStorage._tk_utm_lt` (current session only)
4. Merges last-touch UTM into every `push()` call via `properties`
5. `IngestAnalyticsEventsJob` extracts UTM from `properties` into dedicated columns

### New Behavioral Events (JS Tracker)

| Event | Trigger |
|-------|---------|
| `rage_click` | 3+ clicks within 750ms in 100px radius. Props: `selector`, `count` |
| `exit_intent` | `mouseleave` at `clientY <= 0` (desktop only). Props: `page_type` |
| `page.time_spent` | On `visibilitychange → hidden`. Props: `seconds` (active time only) |

### Files Added (Phase 2)

- `database/migrations/2026_06_15_100001_add_funnel_columns_to_analytics_events_table.php`
- `database/migrations/2026_06_15_100002_add_analytics_columns_to_carts_table.php`
- `app/Jobs/MarkCartsAbandonedJob.php`
- `app/Services/Analytics/AnalyticsEventDispatcher.php`
- `app/Listeners/RecordAnalyticsOnOrderPaid.php`
- `tests/Feature/Analytics/FunnelTrackingTest.php`
