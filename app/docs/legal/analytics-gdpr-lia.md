# Analytics — Legitimate Interest Assessment (GDPR Art. 6(1)(f))

## What we collect

| Field | Value | PII? |
|-------|-------|------|
| `session_id` | SHA-256(IP + UA + tenantId + date + app.key) | Pseudonymous |
| `event` | e.g. `page_viewed`, `scroll_50` | No |
| `url` | Full URL of visited page | Potentially (if URL contains PII) |
| `referrer` | Referring URL | Potentially |
| `page_type` | e.g. `homepage`, `catalogue` | No |
| `device_type` | `desktop` / `mobile` / `tablet` | No |
| `viewport_w` | Viewport width in pixels | No |
| `user_id` | FK to users table (logged-in only) | Yes |
| `occurred_at` | Timestamp of event | No |

We do NOT store raw IP addresses.

## Legal basis: Legitimate Interest (Art. 6(1)(f))

### 1. Purpose test — is there a legitimate interest?

Yes. Tenants have a legitimate interest in understanding how visitors use their website in order to:
- Identify usability problems
- Improve user experience
- Measure effectiveness of content and services

This is a widely recognised legitimate interest. See EDPB WP29 Opinion 06/2014 on legitimate interests.

### 2. Necessity test — is processing necessary?

Yes. Basic session-level analytics cannot be achieved without some form of visitor identification. Our implementation uses the minimum necessary data:
- No persistent cookies
- No cross-day tracking (daily-rotating session ID)
- No raw IP storage
- Events older than 13 months are automatically deleted

### 3. Balancing test — do individual rights override?

Risk is low because:
- Session ID cannot be reversed by the tenant (data controller) — only Registro (data processor) holds `app.key`
- Session ID resets daily — no long-term profiling possible
- Users can opt out via browser `Do-Not-Track` setting (honoured by our tracker)
- No data is shared with third parties
- No behavioural profiling or automated decision-making

### Technical safeguards (Privacy by Design)

- ✅ No IP address stored
- ✅ Daily-rotating pseudonymous session ID
- ✅ No persistent tracking cookies
- ✅ DNT header respected
- ✅ Automatic deletion after 13 months
- ✅ Data isolated per tenant (organization_id scoping)

## Tenant obligations

Tenants must include a section in their Privacy Policy stating:

> "This website uses privacy-friendly analytics to understand how visitors use our site. We collect anonymised session data including pages visited, scroll depth and device type. No persistent cookies are used. Data is automatically deleted after 13 months. You can opt out by enabling Do Not Track in your browser settings."

## Data retention

`analytics_events` table: **13 months** maximum. Enforced by `php artisan analytics:prune` scheduled monthly.

## Reviewed by

- Date: 2026-05-23
- Basis: EDPB WP29 Opinion 06/2014, EDPB Guidelines 8/2020 on targeting, Plausible Analytics legal basis (reference implementation)
