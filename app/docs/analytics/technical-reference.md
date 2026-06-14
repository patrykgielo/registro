# Analytics Technical Reference

> **Audience:** Developers, PMs, QA Engineers
> **Last updated:** 2026-06-15
> **Branch:** feature/analytics-phase2 (PR #69)

---

## 1. System Overview

Registro ships **two independent analytics systems** that answer different questions:

| System | Purpose | Data Source | Location |
|--------|---------|------------|---------|
| **Statistics** | Business KPIs — revenue, order counts | Pre-aggregated `statistics_daily_snapshots` table | `/admin/statystyki`, dashboard widgets |
| **Analytics Events** | Visitor behaviour — pages, sessions, funnel | Raw `analytics_events` table | `/admin/analityka` |

They are not connected — Statistics reads from order/appointment/rental tables; Analytics Events reads from JS tracker + server-side event hooks. Never confuse counts between the two.

### Data flow overview

```
STATISTICS SYSTEM
─────────────────
Orders / Appointments / Rentals tables
    ↓ (hourly: RecalculateDailyStatisticsJob)
statistics_daily_snapshots
    ↓ (on-demand read)
StatisticsService → Filament widgets + /admin/statystyki

ANALYTICS EVENTS SYSTEM
────────────────────────
Browser (JS tracker)   Server-side hooks
        ↓                      ↓
POST /api/track     AnalyticsEventDispatcher
        └──────────────┬────────────────────┘
                IngestAnalyticsEventsJob (analytics queue)
                       ↓
               analytics_events table
                       ↓
              /admin/analityka page
```

---

## 2. Admin Panel Locations

### 2.1 Dashboard Widgets (`/admin`)

Two widgets appear on the tenant dashboard for all logged-in admins.

#### TenantStatsOverviewWidget

| Property | Value |
|----------|-------|
| File | `app/Filament/Widgets/TenantStatsOverviewWidget.php` |
| Sort order | 1 (first widget) |
| Width | Full width |
| Data source | `statistics_daily_snapshots` via `StatisticsService` |
| Period | Fixed: current calendar month (this_month) |
| Refresh | On page load only |

**Stats displayed:**

| Stat | Column read | Visible when |
|------|------------|-------------|
| Total Revenue (PLN) | SUM(revenue) all sources | Always |
| Orders — count + revenue | source = 'orders' | Always |
| Wizyty — count + revenue | source = 'appointments' | `$org->hasModule('bookings')` = true |
| Wypożyczenia — count + revenue | source = 'rentals' | `$org->hasModule('rentals')` = true |

Revenue format: Polish locale — `1 234,50 zł`.

#### RevenueChartWidget

| Property | Value |
|----------|-------|
| File | `app/Filament/Widgets/RevenueChartWidget.php` |
| Sort order | 2 (below overview) |
| Chart type | Line (Chart.js via Filament) |
| Data source | `statistics_daily_snapshots` |
| Period | Fixed: last 30 calendar days |
| Refresh | On page load only |

Series: single line — total daily revenue (orders + appointments + rentals). X-axis: `dd.mm` date labels.

---

### 2.2 Statistics Page (`/admin/statystyki`)

| Property | Value |
|----------|-------|
| File | `app/Filament/Pages/Statistics.php` |
| View | `resources/views/filament/pages/statistics.blade.php` |
| Navigation | Group "Raporty", label "Statystyki", icon `heroicon-o-chart-bar`, sort 99 |
| Access | roles: admin, super-admin |
| URL params | `?period=today\|this_week\|this_month\|this_year\|last_month\|last_year` |
| Default period | `this_month` |

**Components:**

| Component | Type | Data |
|-----------|------|------|
| Revenue total | KPI card | SUM(revenue) all sources in period |
| Orders block | KPI card (count + revenue) | source = 'orders' |
| Appointments block | KPI card (count + revenue) | source = 'appointments' |
| Rentals block | KPI card (count + revenue) | source = 'rentals' |
| 30-day chart | ApexCharts area (3 series) | Daily by source, capped at last 30 days regardless of period |
| Top-10 services | Table | Live query: JOIN order_items + orders, grouped by service_id, sorted by SUM(price) |
| Export CSV | Header action | UTF-8 BOM, semicolon delimited |
| Export PDF | Header action | barryvdh/laravel-dompdf, no charts |

**Data source logic:**
```
For any date that is NOT today:
  → read from statistics_daily_snapshots (fast, pre-aggregated)

For today:
  → if snapshot computed_at > 2 hours ago: live query from raw tables
  → otherwise: read snapshot (may be up to 2h stale)
```

**Revenue counting rules:**

| Source | Counted status | Value field | Date field |
|--------|---------------|------------|-----------|
| orders | paid | total_amount | paid_at |
| appointments | confirmed, completed | service_price_at_booking | appointment_date |
| rentals | confirmed, active, returned | total_price | start_date |

> **Important:** `deposit_amount` is NOT included in any revenue figure. It is a refundable deposit, not revenue.

---

### 2.3 Analytics Overview Page (`/admin/analityka`)

| Property | Value |
|----------|-------|
| File | `app/Filament/Pages/AnalyticsOverview.php` |
| View | `resources/views/filament/pages/analytics-overview.blade.php` |
| Navigation | Group "Raporty", label "Analityka", icon `heroicon-o-cursor-arrow-rays`, sort 100 |
| Access | roles: admin, super-admin |
| URL params | `?period=today\|this_week\|this_month\|last_month` |
| Default period | `this_month` |

**Components:**

| Component | Type | Query |
|-----------|------|-------|
| Page views | KPI | COUNT(*) WHERE event = 'page_viewed' |
| Unique sessions | KPI | COUNT(DISTINCT session_id) WHERE event = 'page_viewed' |
| Unique users | KPI | COUNT(DISTINCT user_id) WHERE user_id IS NOT NULL |
| Avg events/session | KPI | COUNT(*) / COUNT(DISTINCT session_id) |
| Device breakdown | Bar | GROUP BY device_type |
| Page type distribution | Table/pie | GROUP BY page_type, COUNT(*) |
| Top 10 pages | Table | GROUP BY url, COUNT(*) AS views, COUNT(DISTINCT session_id) |
| Scroll depth | Progress bars | COUNT(*) WHERE event IN (scroll_25, scroll_50, scroll_75, scroll_90, scroll_100) |
| Daily page views | Line chart | COUNT(*) WHERE event = 'page_viewed' GROUP BY DATE(occurred_at) |

All queries scoped to current tenant: `WHERE organization_id = :current_org_id AND occurred_at BETWEEN :from AND :to`.

---

### 2.4 Platform Statistics Page (`/platform/statystyki`)

| Property | Value |
|----------|-------|
| File | `app/Filament/Platform/Pages/Statistics.php` |
| Access | Super-admin only (platform panel) |
| Purpose | SaaS business KPIs — not tenant data |

**Components:**

| Component | Data |
|-----------|------|
| Total tenants | COUNT(organizations) |
| Active | WHERE subscription_status = 'active' |
| Trial | WHERE subscription_status = 'trial' |
| Expiring ≤7 days | WHERE trial_ends_at BETWEEN NOW() AND NOW()+7d |
| Monthly Recurring Revenue (MRR) | SUM(monthly_fee) WHERE subscription_status = 'active' |
| New registrations | COUNT(*) WHERE created_at IN period |
| Registrations chart | COUNT(*) GROUP BY DATE(created_at) |
| All tenants table | All organizations with owner info |
| Expiring trials table | trial_ends_at within 14 days |

---

## 3. Event Taxonomy

All events follow `object.verb` or `category_action` naming in snake_case.

### 3.1 Client-side events (JS tracker)

Fired by `resources/js/tracker/registro-tracker.js` via `POST /api/track`.

| Event | Trigger | Key properties |
|-------|---------|---------------|
| `page_viewed` | Page load + Livewire navigation | `page_type`, UTM (last-touch) |
| `scroll_25` | 25% scroll depth reached | — |
| `scroll_50` | 50% scroll depth reached | — |
| `scroll_75` | 75% scroll depth reached | — |
| `scroll_90` | 90% scroll depth reached | — |
| `scroll_100` | 100% scroll depth (bottom) | — |
| `page.time_spent` | visibilitychange → hidden | `seconds` (active time only, excludes tab-hidden) |
| `section_visible` | `[data-track-section]` enters viewport (IntersectionObserver) | `section_name`, `block_type`, `section_position` |
| `rage_click` | 3+ clicks within 750ms, within 100px radius | `selector`, `count` |
| `exit_intent` | `mouseleave` with `clientY ≤ 0` (desktop only) | `page_type` |

**scroll milestones:** Fire once per page load (not per scroll back up). Tracked via `scrollFired` Set.

**UTM capture:**
- First-touch: `localStorage._tk_utm_ft` — JSON with utm_* keys + `_ts` timestamp. Written once, never overwritten.
- Last-touch: `sessionStorage._tk_utm_lt` — overwritten on every new UTM-bearing URL. Merged into every event's `properties`.
- First-touch is stored but not sent per-event (reserved for Phase 3 PostHog integration).

**session_id lifecycle:**
1. First `/api/track` request → no session_id sent
2. Server generates: `SHA-256(IP + UserAgent + TenantID + Date + APP_KEY)` → returns in response
3. Client stores in `sessionStorage._tk_session` → sent on subsequent requests
4. Rotates daily (date component changes at midnight)

---

### 3.2 Server-side events

Fired by Laravel code directly to `IngestAnalyticsEventsJob`.

| Event | Fired by | Trigger | Key properties |
|-------|---------|---------|---------------|
| `checkout.started` | `CheckoutController::show()` | First time user opens checkout page for a given cart (`checkout_started_at === null`) | `item_count`, `cart_total` |
| `checkout.submitted` | `CheckoutController::submit()` | After successful `convertToOrder()` call | `order_id`, `total_amount` |
| `order.completed` | `RecordAnalyticsOnOrderPaid` listener | `OrderPaid` event (payment confirmed) | `order_id`, `order_number`, `total_amount`, `item_count`, `is_b2b` |
| `cart.abandoned` | `MarkCartsAbandonedJob` | Cart status = 'active', `updated_at` < 30 min ago — processed every 5 min | `cart_id`, `item_count`, `checkout_started`, `last_step` |

**session_id for server-side events:**
- Cart events: `server-cart-{cart->id}`
- Order events: `server-order-{order->id}`
- These are synthetic — not linked to browser session_id. Cannot be joined with client-side events.

---

### 3.3 Event status matrix

| Event | Status | Since | Source |
|-------|--------|-------|--------|
| `page_viewed` | LIVE | 2026-05-23 | Client JS |
| `scroll_25/50/75/90/100` | LIVE | 2026-05-23 | Client JS |
| `section_visible` | LIVE | 2026-05-23 | Client JS |
| `page.time_spent` | LIVE | 2026-06-15 | Client JS |
| `rage_click` | LIVE | 2026-06-15 | Client JS |
| `exit_intent` | LIVE | 2026-06-15 | Client JS |
| `checkout.started` | LIVE | 2026-06-15 | Server |
| `checkout.submitted` | LIVE | 2026-06-15 | Server |
| `order.completed` | LIVE | 2026-06-15 | Server |
| `cart.abandoned` | LIVE | 2026-06-15 | Server |
| `checkout.step_changed` | PLANNED | — | Client JS (requires JS hook on step form) |
| `funnel.*` (widget) | PLANNED | Phase 4 | Derived from above |

---

## 4. API Contract

### POST /api/track

```
URL:         POST /api/track
Middleware:  throttle:analytics (120 req/min per IP), ResolveTenant
Auth:        None required (public endpoint, but must have tenant context from subdomain)
Response:    202 Accepted
```

**Request body:**

```json
{
  "events": [
    {
      "event":       "page_viewed",          // required, string, max:100
      "url":         "https://...",           // nullable, string, max:2048
      "referrer":    "https://google.com/",  // nullable, string, max:2048
      "timestamp":   "2026-06-15T12:00:00Z", // nullable, ISO 8601 (clamped to ±5min)
      "page_type":   "homepage",             // nullable, string, max:50
      "device_type": "mobile",               // nullable, string, max:20
      "viewport_w":  390,                    // nullable, int, 0-65535
      "properties":  { "utm_source": "google" } // nullable, object, max 20 keys
    }
  ]
}
```

**Response:**

```json
{ "ok": true, "session_id": "sha256hex..." }   // 202 — events queued
{ "ok": false, "error": "tenant_required" }     // 400 — no tenant context
```

**Error responses:** 422 for validation failures.

**Batch limits:** Max 30 events per request. JS tracker batches over 2s intervals.

---

## 5. Database Schema

### analytics_events

| Column | Type | Nullable | Notes |
|--------|------|---------|-------|
| id | BIGINT UNSIGNED | NO | PK, auto-increment |
| organization_id | BIGINT UNSIGNED | YES | FK → organizations (SET NULL on delete) |
| user_id | BIGINT UNSIGNED | YES | FK → users; null for anonymous visitors |
| session_id | VARCHAR(64) | YES | Daily-rotating hash or `server-{type}-{id}` |
| event | VARCHAR(100) | NO | Event name in snake_case |
| url | VARCHAR(2048) | YES | Full page URL at time of event |
| referrer | VARCHAR(2048) | YES | HTTP referrer (full URL) |
| page_type | VARCHAR(50) | YES | homepage\|service\|booking\|cart\|checkout\|confirmation |
| device_type | VARCHAR(20) | YES | mobile\|tablet\|desktop |
| viewport_w | UNSIGNED SMALLINT | YES | Viewport width in pixels |
| properties | JSON | YES | Per-event extra data |
| ip_hash | VARCHAR(64) | YES | SHA-256(IP + APP_KEY + date) — column exists, not populated |
| utm_source | VARCHAR(255) | YES | Last-touch UTM source |
| utm_medium | VARCHAR(255) | YES | Last-touch UTM medium |
| utm_campaign | VARCHAR(255) | YES | Last-touch UTM campaign |
| referrer_domain | VARCHAR(255) | YES | Hostname extracted from referrer |
| occurred_at | DATETIME | NO | Client timestamp, clamped ±5 min from received_at |
| received_at | DATETIME | NO | Server timestamp at ingestion |

**Indexes:**
- `ae_org_time_event` on (organization_id, occurred_at, event) — primary read pattern
- `ae_org_session` on (organization_id, session_id) — session lookups
- `ae_org_user_time` on (organization_id, user_id, occurred_at) — user journeys
- `idx_org_event` on (organization_id, event) — funnel aggregations

**Retention:** 13 months. Enforced by `php artisan analytics:prune`.

---

### statistics_daily_snapshots

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | PK |
| organization_id | BIGINT UNSIGNED | FK → organizations |
| date | DATE | |
| source | ENUM | 'orders' \| 'appointments' \| 'rentals' |
| revenue | DECIMAL(12,2) | DEFAULT 0 |
| count | UNSIGNED INT | DEFAULT 0 |
| computed_at | TIMESTAMP | Auto-updated on upsert |

**Unique constraint:** (organization_id, date, source). Safe to re-run upserts.

**Row count:** 3 rows per tenant per day (one per source).

---

### carts (analytics columns added Phase 2)

| Column | Type | Notes |
|--------|------|-------|
| customer_email | VARCHAR(255) | NULL — Phase 5 only, not yet populated |
| checkout_started_at | TIMESTAMP | Set on first CheckoutController::show() call |
| last_checkout_step | VARCHAR(50) | personal\|address\|payment (JS, future) |
| abandoned_at | TIMESTAMP | Set by MarkCartsAbandonedJob |
| utm_source | VARCHAR(255) | Captured at cart creation (future JS hook) |
| utm_medium | VARCHAR(100) | |
| utm_campaign | VARCHAR(255) | |

**Index added:** `(status, updated_at)` — enables efficient abandoned cart scanner query.

---

## 6. Data Availability & Latency

| Data type | Typical latency | Edge cases |
|-----------|----------------|-----------|
| JS tracker events (page_viewed, scroll, etc.) | < 60 seconds | Ad blockers suppress ~15% of events; sendBeacon may drop under memory pressure |
| Server-side events (checkout.*, order.completed) | < 30 seconds | Queue worker delay possible under high load |
| `cart.abandoned` marking | 30 min after last activity + up to 5 min scheduler delay | Only fires for carts in `active` status |
| Statistics snapshots (dashboard KPIs) | Hourly | Today's data is live-queried if snapshot is >2h stale; historical data never re-queried |
| `today` live fallback | Instant (synchronous DB query) | Slightly slower page load for first visitor of the day |

### When does data appear in charts?

- **`/admin/analityka`** (Analytics Events): Near real-time. Events appear within ~2 minutes of browser activity.
- **`/admin/statystyki`** chart and KPIs: Updated hourly. Revenue from orders paid this hour may not appear until next hourly tick.
- **Dashboard widgets**: Same as statistics — hourly refresh cycle.
- **`today` stats via live fallback**: Appears within seconds of the transaction completing.

### Known data gaps

| Gap | Cause | Workaround |
|-----|-------|-----------|
| No UTM attribution on orders before 2026-06-15 | Tracking not instrumented | None; historical data not backfillable |
| No client session linkage for server-side events | session_id is synthetic (`server-cart-{id}`) | Future: pass session_id through checkout form |
| Ad-blocked visitors not counted | Browser blocks `/api/track` | ~15% undercount typical for European B2C traffic |
| DNT users not counted | Tracker respects `navigator.doNotTrack = 1` | By design (GDPR) |
| Analytics events for anonymous tenants | organization_id can be NULL after tenant deletion | Queries must handle NULL org_id |
| `page.time_spent` accuracy | Only counts active (visible) time; tab switching excludes time | By design — active time is more meaningful |
| Internal team traffic | No IP exclusion implemented | Filter manually by user_id if known |
| `customer_email` in carts | Not yet populated | Phase 5 feature |

---

## 7. Jobs & Scheduler

### RecalculateDailyStatisticsJob

```php
// Signature
new RecalculateDailyStatisticsJob(Carbon $date, ?int $organizationId = null)

// Scheduled: every hour, for yesterday + today
// Processes all tenants if organizationId is null
```

**Manual trigger:** `php artisan statistics:recalculate --from=2026-01-01 --to=2026-06-15`

---

### MarkCartsAbandonedJob

```php
// Scheduled: every 5 minutes, withoutOverlapping()
// Cutoff: 30 minutes of inactivity
// Processes: Cart::active()->where('updated_at', '<', now()->subMinutes(30))
// Chunk size: 100 carts per chunk
```

---

### PruneAnalyticsEventsCommand

```bash
php artisan analytics:prune              # deletes events older than 13 months
php artisan analytics:prune --months=6   # custom retention
```

Scheduled: monthly. Uses raw `DB::table()` to bypass global scopes (deletes across all tenants).

---

## 8. Queue Configuration

Analytics-related jobs run on the `analytics` queue (highest priority in Horizon).

| Job/Listener | Queue | Tries | Backoff |
|-------------|-------|-------|--------|
| `IngestAnalyticsEventsJob` | analytics | 3 | 5s |
| `RecordAnalyticsOnOrderPaid` | analytics | — | — |
| `MarkCartsAbandonedJob` | default | — | — |
| `RecalculateDailyStatisticsJob` | default | — | — |

---

## 9. Privacy & GDPR

See full assessment: `app/docs/legal/analytics-gdpr-lia.md`

| Aspect | Implementation |
|--------|---------------|
| Legal basis | Legitimate Interest (Art. 6(1)(f)) — no consent banner required |
| IP storage | Never stored raw; SHA-256(IP + APP_KEY + date) — column `ip_hash` exists but not populated |
| Session rotation | Daily — no long-term profiling possible |
| Opt-out | DNT header (`navigator.doNotTrack = '1'`) — tracker skips all events |
| Retention | 13 months (monthly prune job) |
| Cookies | None set — cookieless analytics |
| Third parties | None — all first-party |
| Cross-tenant | Never — all queries scoped by `organization_id` |

---

## 10. Operations

### Backfill historical statistics

```bash
# All tenants, specific date range
docker compose exec app php artisan statistics:recalculate --from=2026-01-01 --to=2026-06-14

# Single tenant
docker compose exec app php artisan statistics:recalculate --from=2026-01-01 --org=42

# Today only (all tenants)
docker compose exec app php artisan statistics:recalculate
```

### Prune analytics events

```bash
docker compose exec app php artisan analytics:prune          # default: 13 months
docker compose exec app php artisan analytics:prune --months=3  # aggressive
```

### Inspect queue state

```bash
docker compose exec app php artisan queue:monitor analytics   # monitor analytics queue
docker compose exec app php artisan horizon:status            # full queue dashboard
```

### Debug event ingestion

Check `analytics_events` for recent events:
```sql
SELECT event, COUNT(*) AS cnt, MAX(received_at) AS last_seen
FROM analytics_events
WHERE organization_id = :org_id
  AND received_at > NOW() - INTERVAL 1 HOUR
GROUP BY event
ORDER BY cnt DESC;
```

---

## 11. Implementation Checklist (for new analytics features)

When adding a new event or widget:

- [ ] Event name follows `object.verb` pattern, snake_case, no interpolated values
- [ ] Properties documented in this file under §3 Event Taxonomy
- [ ] Status field set (PLANNED → IN_DEV → LIVE)
- [ ] Server-side: dispatched via `AnalyticsEventDispatcher` to `analytics` queue
- [ ] Client-side: fired via `push('event_name', { prop: value })` in tracker
- [ ] GDPR LIA updated if new PII-adjacent data collected (`app/docs/legal/analytics-gdpr-lia.md`)
- [ ] Test written (see `tests/Feature/Analytics/`)
- [ ] Widget null state documented (what shows with zero data?)
- [ ] Data availability lag documented (§6)

---

## 12. Related Files

| File | Purpose |
|------|---------|
| `app/Filament/Pages/Statistics.php` | Admin stats page |
| `app/Filament/Pages/AnalyticsOverview.php` | Admin analytics page |
| `app/Filament/Platform/Pages/Statistics.php` | Platform SaaS KPIs |
| `app/Filament/Widgets/TenantStatsOverviewWidget.php` | Dashboard KPI widget |
| `app/Filament/Widgets/RevenueChartWidget.php` | Dashboard revenue chart |
| `app/Services/Statistics/StatisticsService.php` | Stats aggregation logic |
| `app/Services/Statistics/StatisticsExportService.php` | CSV + PDF export |
| `app/Services/Analytics/AnalyticsEventDispatcher.php` | Server-side event helper |
| `app/Jobs/IngestAnalyticsEventsJob.php` | Async event persistence |
| `app/Jobs/RecalculateDailyStatisticsJob.php` | Snapshot computation |
| `app/Jobs/MarkCartsAbandonedJob.php` | Cart abandonment detection |
| `app/Listeners/RecordAnalyticsOnOrderPaid.php` | order.completed event |
| `app/Http/Controllers/Api/EventTrackingController.php` | POST /api/track |
| `app/Models/AnalyticsEvent.php` | Event model + scopes |
| `app/Models/StatisticsSnapshot.php` | Snapshot model |
| `app/Console/Commands/PruneAnalyticsEventsCommand.php` | GDPR retention |
| `app/Console/Commands/StatisticsRecalculateCommand.php` | Backfill CLI |
| `resources/js/tracker/registro-tracker.js` | Browser JS tracker |
| `app/docs/legal/analytics-gdpr-lia.md` | GDPR LIA document |
| `app/docs/features/statistics-analytics.md` | Statistics feature history |
| `app/docs/features/analytics-event-tracking.md` | Tracking spec (Phase 1) |
