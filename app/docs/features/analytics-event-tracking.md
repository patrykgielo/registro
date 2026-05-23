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
- Rate limited to 120 req/min/IP via named limiter `analytics`

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
