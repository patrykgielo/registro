# Behavioral Analytics — Implementation Plan

**Status:** PLANNING (approved for implementation, phase-by-phase)  
**Research base:** 3-agent deep research (2026-06-16) — PostHog, Amplitude, Mixpanel, Baymard, Contentsquare  
**Quality gate per phase:** `laravel-senior-architect` → code → `pint + tests` → `code-reviewer` → [UI: `frontend-ui-architect` → `frontend-quality-auditor`]

---

## Current State Audit

### What exists
- `analytics_events` table: `organization_id`, `user_id`, `session_id`, `event`, `url`, `referrer`, `page_type`, `device_type`, `viewport_w`, `properties` (JSON), `occurred_at`, `received_at`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer_domain`
- JS tracker (`registro-tracker.js` 251 LOC): `page_viewed`, `scroll_25/50/75/90/100`, `exit_intent`, `rage_click`, `section_visible`, `page.time_spent`, `livewire:navigated`
- `IngestAnalyticsEventsJob` — 'analytics' queue ✓ (fixed 2026-06-16, was missing from queue worker)
- `AnalyticsOverview` Filament page: period filter (`#[Url]`), page views KPI, scroll depth, top pages, device breakdown, ApexCharts chart
- `PruneAnalyticsEventsCommand` — exists

### What's missing (per phase below)

---

## Phase A — Bot Behavior Scenarios

**Where:** `/var/www/projects/registro/traffic-bots/bots/` (Python, no Laravel)  
**Agent:** N/A — pure Python implementation  
**Branch:** `feature/bot-scenarios`

### Goal
Replace single 100%-checkout flow with 5 realistic personas matching real-world conversion benchmarks (2.5–3% conversion rate).

### Distribution
```
BounceSession          28%  — katalog only, exit (5–15s)
BrowseSession          32%  — katalog + 2–3 produkty, no cart
ProductResearchSession 16%  — deep product engagement + calendar
CartAbandonSession     21%  — 3 subtypes (pre-checkout, mid-form, payment)
CheckoutSession         3%  — full flow (current implementation)
```

### Files to create/modify
```
bots/browser/scenarios/
  __init__.py
  bounce.py           ← new
  browse.py           ← new
  research.py         ← new
  cart_abandon.py     ← new (subtypes D1/D2/D3)
  checkout.py         ← refactor current checkout.py → move here
bots/browser/checkout.py    ← becomes thin dispatcher
bots/orchestrator/runner.py ← add weighted random persona selection
```

### Scenario specs

**BounceSession** — emits: `page_viewed`, `scroll_25`, `page.time_spent`
- Duration: `gauss(10, 4)` seconds
- Scroll: random.choice([0, 25]) — 50% don't even scroll 25%
- Navigation: `/wypozyczalnia` only

**BrowseSession** — emits: `page_viewed` (×2–4), `scroll_25`, `scroll_50`, `exit_intent`, `page.time_spent`
- Pages: catalogue + `random.randint(1, 3)` product pages
- Think time: `gauss(2.5, 1.2)` between clicks
- Uses browser `go_back()` 1–2x (Playwright: `page.go_back()`)
- Scroll depth: 40–65% on catalogue (stops at scroll_50 for browse)

**ProductResearchSession** — emits: all of Browse + `product_viewed`, `calendar_interacted` (action: 'date_selected' OR 'closed_without_cart')
- Deep scroll: 75–90% on product page
- Opens calendar: 60% probability — selects dates but does NOT add to cart
- Duration: `gauss(150, 50)` seconds on product page
- Think time after calendar: `gauss(8.0, 3.0)`

**CartAbandonSession — D1 (pre-checkout)**: adds to cart, visits `/koszyk`, leaves
- Emits: `add_to_cart`, `cart_viewed`, `page.time_spent` on /koszyk
- Sees deposit (kaucja) in cart → leaves
- Duration on /koszyk: `gauss(35, 12)` seconds

**CartAbandonSession — D2 (mid-form)**: full to checkout, fills 1–3 fields, abandons
- Emits: `checkout.started`, `form_field_focused` (first 1–3 fields), `form_abandoned`
- Most common abort: `phone` field (37% IRL) — `random.choices(['phone','email','last_name'], weights=[37,6,3])`
- Time in form before abandon: `gauss(103, 30)` seconds

**CartAbandonSession — D3 (payment step)**: fills form completely, abandons at payment
- Emits: all D2 events + `form_field_focused` for all fields + `back_navigation`
- Time: `gauss(245, 60)` seconds

### Think time utility
```python
import random, asyncio

async def think(mean_s: float, std_s: float, min_s: float = 0.5, max_s: float = 15.0) -> None:
    t = random.gauss(mean_s, std_s)
    await asyncio.sleep(max(min_s, min(t, max_s)))
```

### Quality gate — Phase A
1. Run probe: `python scripts/probe_one.py` — all 8 steps OK
2. Run 5 probes (one per persona type) — manual verification
3. DB check: `analytics_events` shows event variety, not just checkouts
4. Distribution verification: after 100 runs, confirm ~28% bounce, ~3% orders

---

## Phase B — Schema Enhancement

**Where:** `database/migrations/`  
**Agent:** `laravel-senior-architect`  
**Branch:** `feature/analytics-schema-v2`

### Goal
Add missing first-class columns + generated virtual columns with indexes + hourly rollup table. Monthly partitioning is NOT included (requires table recreation — too risky for existing data, defer to ClickHouse migration).

### Migration 1: Add missing columns to analytics_events

```php
// 2026_06_XX_100001_enhance_analytics_events_table.php
Schema::table('analytics_events', function (Blueprint $table) {
    // Anonymous identifier (pre-auth tracking)
    $table->string('anonymous_id', 64)->nullable()->after('session_id');
    
    // Device context (browser + os missing, device_type already exists)
    $table->string('browser', 100)->nullable()->after('device_type');
    $table->string('os', 100)->nullable()->after('browser');
    
    // Indexes for new columns
    $table->index(['organization_id', 'utm_source'], 'ae_org_utm');
    $table->index('anonymous_id', 'ae_anon_id');
});
```

### Migration 2: Generated virtual columns for JSON paths

**IMPORTANT:** Generated columns in MySQL 8.0 with `->json()` require raw SQL — Laravel Blueprint doesn't support `GENERATED AS`. Use `DB::statement()`.

```php
// 2026_06_XX_100002_add_analytics_virtual_columns.php
// SQLite-safe (skip on SQLite — used only in testing)
if (DB::getDriverName() !== 'sqlite') {
    DB::statement("
        ALTER TABLE analytics_events
        ADD COLUMN _product_slug VARCHAR(100)
            GENERATED ALWAYS AS (properties->>'$.service_slug') VIRTUAL,
        ADD COLUMN _cart_id VARCHAR(64)
            GENERATED ALWAYS AS (properties->>'$.cart_id') VIRTUAL,
        ADD COLUMN _order_id VARCHAR(64)
            GENERATED ALWAYS AS (properties->>'$.order_id') VIRTUAL,
        ADD COLUMN _revenue DECIMAL(10,2)
            GENERATED ALWAYS AS (
                CAST(NULLIF(properties->>'$.total', '') AS DECIMAL(10,2))
            ) VIRTUAL
    ");
    DB::statement("ALTER TABLE analytics_events ADD INDEX ae_product_slug (_product_slug(100))");
    DB::statement("ALTER TABLE analytics_events ADD INDEX ae_cart_id (_cart_id(64))");
    DB::statement("ALTER TABLE analytics_events ADD INDEX ae_order_id (_order_id(64))");
    DB::statement("ALTER TABLE analytics_events ADD INDEX ae_revenue (_revenue)");
}
```

**down():** `DB::statement("ALTER TABLE analytics_events DROP COLUMN _product_slug, DROP COLUMN _cart_id, DROP COLUMN _order_id, DROP COLUMN _revenue")` (wrapped in sqlite check)

### Migration 3: Hourly rollup table

```php
// 2026_06_XX_100003_create_analytics_events_hourly_table.php
Schema::create('analytics_events_hourly', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('organization_id');
    $table->string('event', 100);
    $table->dateTime('hour_bucket');  // floor to hour: 2026-06-16 13:00:00
    $table->unsignedInteger('event_count')->default(0);
    $table->unsignedInteger('unique_sessions')->default(0);
    $table->unsignedInteger('unique_users')->default(0);
    $table->decimal('total_revenue', 12, 2)->nullable();
    
    $table->unique(['organization_id', 'event', 'hour_bucket'], 'aeh_unique_bucket');
    $table->index(['organization_id', 'event', 'hour_bucket'], 'aeh_org_event_hour');
    
    $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
});
```

### Command: RollupAnalyticsHourlyCommand

New Artisan command `analytics:rollup-hourly` — runs every hour via scheduler.

```php
// Aggregates previous hour's raw events into analytics_events_hourly
// Uses INSERT ... ON DUPLICATE KEY UPDATE (upsert pattern)
// Process only hours where raw data exists (don't process future hours)
// Scope: completed hours only (current hour excluded — data still arriving)
```

### AnalyticsEvent model update

Add virtual column accessors and `anonymous_id` fillable:
```php
protected $fillable = [..., 'anonymous_id', 'browser', 'os'];

// Append virtual columns for JSON access
public function getProductSlugAttribute(): ?string
{
    return $this->properties['service_slug'] ?? null;
}
```

### Scheduler registration (in `routes/console.php`)
```php
Schedule::command('analytics:rollup-hourly')->hourly()->withoutOverlapping();
```

### Quality gate — Phase B
1. `./vendor/bin/pint --test`
2. `php artisan test` (all existing tests pass — SQLite skips virtual column migration)
3. `php artisan migrate` on dev MySQL — verify columns exist: `DESCRIBE analytics_events`
4. `code-reviewer` agent reviews all 3 migrations + model + command
5. Manual: `php artisan analytics:rollup-hourly` — verify analytics_events_hourly populated

---

## Phase C — New Event Types

**Where:** `resources/js/tracker/`, `app/Http/Controllers/Api/`, `app/Jobs/`  
**Agent:** `laravel-senior-architect` (backend validation) + `frontend-ui-architect` (JS tracker)  
**Branch:** `feature/analytics-event-taxonomy`

### Goal
Add 6 new event types emitted by JS tracker + validated server-side.

### New events (JS tracker additions)

**1. `product_viewed`** — fires on `/uslugi/{slug}` page load
```js
// Read from data attributes on <body> or hidden inputs
push('product_viewed', {
    service_slug: document.body.dataset.serviceSlug,
    service_id: document.body.dataset.serviceId,
    price: parseFloat(document.body.dataset.servicePrice ?? '0'),
    category: document.body.dataset.serviceCategory ?? null,
    currency: 'PLN',
});
```
Blade: `<body data-service-slug="{{ $service->slug }}" data-service-id="{{ $service->id }}" data-service-price="{{ $service->price }}" data-service-category="{{ $service->category ?? '' }}">`  
Trigger condition: `document.body.dataset.pageType === 'service'` (already exists on service pages)

**2. `calendar_interacted`** — fires on availability calendar in Alpine.js component
```js
// Hook into Alpine.js data mutation (in alpine-tracker-plugin.js)
// Detect when selectedStart + selectedEnd are both set
Alpine.plugin((Alpine) => {
    Alpine.magic('trackCalendar', (el) => (action, extra = {}) => {
        push('calendar_interacted', { action, ...extra });
    });
});
// In show.blade.php: x-init="$watch('selectedStart', val => { if (val && selectedEnd) $trackCalendar('date_selected', { service_slug: '{{ $service->slug }}' }) })"
```
OR simpler: emit from existing Alpine watch hooks in show.blade.php on date selection.

**3. `add_to_cart`** — fires after successful form.submit() (on /koszyk page load)
```js
// Detect: if current page is /koszyk and referrer was /uslugi/* 
// Read cart data from DOM (cart total, item count visible on /koszyk)
if (location.pathname === '/koszyk' && document.referrer.includes('/uslugi/')) {
    const total = parseFloat(document.querySelector('[data-cart-total]')?.dataset.cartTotal ?? '0');
    push('add_to_cart', {
        cart_total: total,
        referrer_service: document.referrer.split('/uslugi/')[1]?.split('?')[0],
    });
}
```

**4. `form_field_focused`** — fires on checkout form field focus
```js
// In alpine-tracker-plugin.js or injected in checkout/show.blade.php
document.querySelectorAll('[data-checkout-form] input, [data-checkout-form] select')
    .forEach(el => el.addEventListener('focus', () => {
        push('form_field_focused', { field: el.name ?? el.id });
    }, { once: true })); // once=true: only first focus per field
```

**5. `form_abandoned`** — fires on visibilitychange if on /koszyk/zamowienie without order completion
```js
// Set flag when checkout form is loaded
let checkoutStarted = location.pathname.includes('koszyk/zamowienie');
let lastField = null;
let fieldCount = 0;

// Track last focused field
document.addEventListener('focusin', (e) => {
    if (e.target.closest('[data-checkout-form]')) {
        lastField = e.target.name;
        fieldCount++;
    }
});

// On page leave without order completion
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden' && checkoutStarted && !orderCompleted) {
        push('form_abandoned', {
            last_field: lastField,
            fields_interacted: fieldCount,
            page: 'checkout',
        });
    }
});
```

**6. `back_navigation`** — fires when user navigates back from checkout
```js
// Detect via Navigation API (modern) or popstate (legacy)
window.addEventListener('popstate', () => {
    if (document.referrer.includes('koszyk')) {
        push('back_navigation', {
            from_page: 'checkout',
            to_url: location.href,
        });
    }
});
```

### Backend: IngestAnalyticsEventsJob validation update

Add new event names to allowed list. Validate new property shapes:
```php
private const VALID_EVENTS = [
    'page_viewed', 'scroll_25', 'scroll_50', 'scroll_75', 'scroll_90', 'scroll_100',
    'exit_intent', 'rage_click', 'section_visible', 'page.time_spent',
    // NEW:
    'product_viewed', 'calendar_interacted', 'add_to_cart', 'cart_viewed',
    'form_field_focused', 'form_abandoned', 'back_navigation',
];
```

### Bot scenarios emit new events (Phase A coordination)
ProductResearchSession emits `product_viewed` + `calendar_interacted`  
CartAbandonSession emits `form_field_focused` + `form_abandoned`  
All sessions emit `add_to_cart` when applicable

### Quality gate — Phase C
1. `./vendor/bin/pint --test && php artisan test`
2. `code-reviewer` agent — reviews tracker JS changes + backend validation
3. `frontend-quality-auditor` — audits JS tracker for performance (no per-keystroke events, correct debouncing)
4. Browser test: `/uslugi/{slug}` → open DevTools Network → verify `/api/track` receives `product_viewed`
5. DB check: new event types appear in `analytics_events`

---

## Phase D — Admin Filter UI

**Where:** `app/Filament/Pages/AnalyticsOverview.php`, `resources/views/filament/pages/analytics-overview.blade.php`  
**Agent:** `frontend-ui-architect` (primary) + `laravel-senior-architect` (query layer)  
**Branch:** `feature/analytics-filter-ui`

### Goal
Extend existing `AnalyticsOverview` page with URL-persistent multi-dimensional filters + funnel widget + behavioral summary widgets.

### URL filter architecture (4-layer)

```php
// Layer 1 — Period (already exists, keep)
#[Url(as: 'period', except: 'this_week')]
public string $period = 'this_week';

// Layer 2 — Custom date range
#[Url(as: 'from', except: '')]
public string $dateFrom = '';

#[Url(as: 'to', except: '')]
public string $dateTo = '';

// Layer 3 — Multi-select (comma-separated, Livewire #[Url] limitation workaround)
#[Url(as: 'events', except: '')]
public string $eventTypesParam = '';    // "page_viewed,add_to_cart"

#[Url(as: 'device', except: '')]
public string $deviceParam = '';        // "mobile,desktop"

#[Url(as: 'utm', except: '')]
public string $utmSourceParam = '';     // "google,direct"

// Layer 4 — Complex AND/OR groups (base64 JSON, opaque)
#[Url(as: 'f', except: '')]
public string $filterGroupsParam = '';  // base64(json_encode($groups))
```

**Resulting URL examples:**
```
/admin/analityka?period=this_month&events=page_viewed,add_to_cart
/admin/analityka?from=2026-06-01&to=2026-06-16&device=mobile
/admin/analityka?period=this_week&utm=google,facebook
```

### New computed properties in AnalyticsOverview

```php
public function getEventTypes(): array
{
    return $this->eventTypesParam
        ? explode(',', $this->eventTypesParam)
        : [];
}

public function getDeviceTypes(): array
{
    return $this->deviceParam ? explode(',', $this->deviceParam) : [];
}

// Apply all filters to a base query builder
private function buildBaseQuery(string $table = 'analytics_events'): \Illuminate\Database\Query\Builder
{
    $tenant = TenantFeature::currentTenant();
    [$from, $to] = $this->resolvedDateRange();

    $query = DB::table($table)
        ->where('organization_id', $tenant->id)
        ->whereBetween('occurred_at', [$from, $to]);

    if ($this->getEventTypes()) {
        $query->whereIn('event', $this->getEventTypes());
    }
    if ($this->getDeviceTypes()) {
        $query->whereIn('device_type', $this->getDeviceTypes());
    }
    if ($this->utmSourceParam) {
        $query->whereIn('utm_source', explode(',', $this->utmSourceParam));
    }

    return $query;
}

// resolvedDateRange() uses $dateFrom/$dateTo if set, else falls back to $period
```

### New widgets / data methods

**Funnel conversion widget** — most important new widget:
```php
public function getFunnelData(): array
{
    // Steps: page_viewed → product_viewed → add_to_cart → checkout.started → order.completed
    // Count DISTINCT session_id for each step
    // Conversion % = step_n / step_1 * 100
}
```

**Cart abandonment summary:**
```php
public function getCartAbandonmentData(): array
{
    // cart.abandoned by 'step' property (pre_checkout|mid_form|payment)
    // cart.abandoned by 'last_field' property (phone|email|pesel|etc)
    // Recovery rate: cart.abandoned sessions that later had order.completed
}
```

**Traffic sources:**
```php
public function getTrafficSources(): array
{
    // GROUP BY utm_source, COUNT DISTINCT session_id
    // Include 'direct' for NULL utm_source
}
```

**Behavioral session quality:**
```php
public function getSessionQuality(): array
{
    // avg events per session, bounce rate (1 event sessions), 
    // avg scroll depth, rage_click count
}
```

### Filter UI components (Blade/Alpine)

**Filter bar** (horizontal, above charts):
- Period picker: button group (Dziś | Ten tydzień | Ten miesiąc | Poprzedni miesiąc | Własny zakres)
- Custom date range: appears when "Własny zakres" selected (daterangepicker or two date inputs)
- Event type multi-select: dropdown checkbox list, shows selected as pills
- Device filter: button group (Wszystkie | Desktop | Mobile | Tablet)
- UTM source: dropdown multi-select
- "Reset filters" button: clears all to defaults

**Active filter chips** — row of dismissible pills showing applied filters:
```
[Typ: page_viewed ×] [Urządzenie: mobile ×] [UTM: google ×]
```

**URL update mechanism:** Alpine `$watch` on each filter → `$wire.setX(value)` → Livewire updates `#[Url]` property → browser URL updates via pushState (Livewire handles this automatically with `#[Url]`).

### Quality gate — Phase D
1. `./vendor/bin/pint --test && php artisan test`
2. `code-reviewer` agent — reviews AnalyticsOverview + all queries
3. `frontend-ui-architect` reviews filter bar layout
4. `frontend-quality-auditor` — animation/a11y audit on filter components
5. Browser test: apply filters → verify URL updates → refresh page → verify filters restored
6. Browser test: share URL with filters → open in incognito → verify same filters applied
7. Performance: funnel query with 10k events < 2s

---

## Phase E — Saved Filter Presets (Phase 2)

**Where:** New DB table + Filament integration  
**Agent:** `laravel-senior-architect`  
**Branch:** `feature/analytics-saved-presets`

### Goal
Allow tenants to save named filter combinations, accessible via dropdown and shareable by URL slug.

### Schema

```php
Schema::create('analytics_saved_filters', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('organization_id');
    $table->unsignedBigInteger('user_id');    // created by
    $table->string('name', 100);
    $table->string('slug', 120)->unique();    // url-friendly
    $table->json('filter_state');             // full URL params as JSON
    $table->boolean('is_shared')->default(false);
    $table->timestamps();
    
    $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
    $table->index(['organization_id', 'slug']);
});
```

### URL pattern
```
/admin/analityka?preset=mobile-checkout-funnel
```
On mount: if `$preset` set → load filter_state from DB → apply to `#[Url]` properties.

### UI additions
- "Zapisz filtry" button → modal (name input) → save to DB
- "Moje filtry" dropdown in filter bar → list of saved presets → click to load
- Delete button per preset (own presets only)
- "Udostępnij" toggle → generates shareable URL

### Quality gate — Phase E
1. `./vendor/bin/pint --test && php artisan test`
2. `code-reviewer` agent
3. Browser test: save → reload → preset loads → share URL → open in incognito

---

## Quality Gate Process (ALL phases)

```
Implementation complete
       ↓
./vendor/bin/pint --test        ← formatting
php artisan test                ← full test suite (Docker)
       ↓
code-reviewer agent             ← architecture, security, patterns
       ↓
[IF frontend changed:]
frontend-quality-auditor        ← animation GPU, a11y, design tokens
       ↓
[IF DB migration:]
php artisan migrations:check-rollback  ← rollback safety (pre-commit hook)
       ↓
Browser test (manual or bot probe)
       ↓
gh pr create --base develop     ← PR with description of phase
```

---

## Implementation Order Recommendation

```
A (bots)  → standalone Python, no Laravel risk, generates test data
B (schema) → foundation for C/D queries, additive migrations only  
C (events) → tracker + backend, uses B's schema
D (filter UI) → uses C's events, extends existing AnalyticsOverview
E (presets) → optional enhancement of D
```

All phases independent enough to be parallelized except: B must precede C (generated columns needed for efficient filtering), C must precede D (events needed for meaningful filter data).
