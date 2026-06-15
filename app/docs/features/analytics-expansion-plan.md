# Analytics Expansion Plan — Registro

**Data:** 2026-06-12  
**Status:** Plan (do implementacji)  
**Autor:** Research: 3 agenty × Firecrawl (~80 źródeł)

---

## TL;DR — Rekomendacja Stack

| Warstwa | Narzędzie | Koszt | Priorytet |
|---------|-----------|-------|-----------|
| Heatmapy + session replay | **Microsoft Clarity** | Darmowy | Faza 1 |
| Funnel + cart abandonment | **Własna implementacja** (Laravel) | $0 | Faza 2 |
| JS event tracker | **Własny analytics.js** (35 linii) | $0 | Faza 2 |
| Product analytics + per-tenant | **PostHog Cloud** (free tier) | $0 do 1M eventów/mies. | Faza 3 |
| Panel statystyk — rozszerzenie | **Filament widgets** (nowe zakładki) | $0 | Faza 4 |

**Czego NIE używać:**
- Matomo — heatmapy to płatny plugin (€299/rok), zbędne nakładki
- OpenPanel — zbyt młody projekt (6 gwiazdek na GitHub), ryzyko porzucenia
- rrweb self-hosted — storage 1–5 MB/sesję, Clarity robi to samo za darmo
- PostHog self-hosted — wymaga 16 GB RAM, ClickHouse, Kafka, Zookeeper (6–8h/mies. ops)

---

## 1. Co Mamy (Obecny Stan)

### Warstwa danych (Phase 11/11b)
```
statistics_daily_snapshots:
  organization_id | date | source (orders/appointments/rentals) | revenue | count
```
3 wiersze/dzień/tenant. Odświeżane co godzinę. Odpowiada na: *ile zarobiliśmy i ile było transakcji*.

### Czego NIE wiemy (luki)
- Dlaczego klient dodał do koszyka ale nie złożył zamówienia
- Na którym kroku checkout opuścił formularz
- Które produkty są oglądane ale nie kupowane
- Jak daleko scrolluje stronę katalogu
- Czy klikał wielokrotnie w przycisk który nie działał (rage click)
- Skąd pochodzi (UTM: Google Ads / Instagram / direct)
- Jaki % wizyt na stronie produktu → koszyk → zamówienie (conversion funnel)
- Które pola formularza B2B/B2C są problematyczne (form abandonment)

---

## 2. Co Zbierać i Dlaczego

### 2.1 Taksonomia eventów (format: Object Action)

Oparta na **Segment E-Commerce Spec v2** + Mixpanel best practices 2025.

#### DISCOVERY (odkrycie)
| Event | Kiedy | Properties | Wartość biznesowa |
|-------|-------|------------|-------------------|
| `Page Viewed` | każda strona | `url`, `title`, `referrer`, `utm_*` | Skąd przychodzą klienci |
| `Catalog Viewed` | `/wypozyczalnia` | `filters_applied`, `items_shown` | Ile % wizyt idzie dalej |
| `Product Viewed` | strona usługi | `item_id`, `item_name`, `price`, `category` | Które produkty są popularne |
| `Product Searched` | wyszukiwanie | `query`, `results_count` | Czego szukają klienci |

#### CART (koszyk)
| Event | Kiedy | Properties | Wartość biznesowa |
|-------|-------|------------|-------------------|
| `Add to Cart` | klik "Dodaj do koszyka" | `item_id`, `price`, `quantity`, `rental_days`, `cart_id` | Punkt startu funnel |
| `Cart Viewed` | `/koszyk` | `cart_id`, `items[]`, `total`, `item_count` | Czy klient angażuje się |
| `Cart Updated` | zmiana ilości/usunięcie | `cart_id`, `action: add/remove/update`, `item_id` | Czy koryguje zamówienie |
| `Remove from Cart` | usunięcie pozycji | `item_id`, `price`, `cart_id` | Co odstraszało klientów |

#### CHECKOUT FUNNEL (lejek płatności)
| Event | Kiedy | Properties | Wartość biznesowa |
|-------|-------|------------|-------------------|
| `Checkout Started` | klik "Przejdź do zamówienia" | `cart_id`, `total`, `item_count`, `step: 1` | % koszyków → checkout |
| `Customer Type Selected` | wybór B2C/B2B | `type: b2c/b2b` | Podział klientów |
| `Address Entered` | submit danych osobowych | `step: 2`, `is_b2b`, `has_pesel`, `city` | Gdzie się gubią w formularzu |
| `Consents Given` | zaznaczenie zgód | `consents: [terms, rodo, withdrawal]` | Czy zgody są barierą |
| `Payment Attempted` | redirect do P24 | `method: p24`, `order_id`, `step: 3` | % docierających do płatności |
| `Payment Failed` | powrót z błędem P24 | `error_code`, `order_id` | Ile % płatności nie przechodzi |
| `Order Completed` | OrderPaid event | `order_id`, `total`, `items[]`, `is_b2b` | Konwersja końcowa |

#### ABANDONMENT (generowane przez backend Job)
| Event | Kiedy | Properties |
|-------|-------|------------|
| `Cart Abandoned` | 30 min bez aktywności po AddToCart | `cart_id`, `total`, `item_count`, `minutes_elapsed` |
| `Checkout Abandoned` | 30 min bez aktywności po CheckoutStarted | `cart_id`, `last_step`, `minutes_elapsed` |

#### ENGAGEMENT (zaangażowanie UX)
| Event | Kiedy | Properties | Wartość biznesowa |
|-------|-------|------------|-------------------|
| `Scroll Depth` | 25/50/75/100% strony | `percent`, `page`, `time_to_reach_ms` | Czy klienci czytają opisy |
| `Time on Page` | opuszczenie strony | `seconds`, `page` | Jak długo analizują ofertę |
| `Rage Click` | 3+ kliki w 750ms w tym samym miejscu | `element`, `element_id`, `page` | Gdzie UI jest zepsute |
| `Form Abandoned` | opuszczenie formularza bez submit | `form_id`, `abandoned_fields[]`, `completed_fields[]` | Które pola są problematyczne |
| `Exit Intent` | kursor opuszcza stronę górą | `page`, `cart_value` | Moment rozważania porzucenia |

#### ATTRIBUTION (źródła)
Każdy event zawiera `utm_source`, `utm_medium`, `utm_campaign` — odczytywane z cookie na start sesji.

---

## 3. Architektura Techniczna

### 3.1 Własna tabela `analytics_events` (server-side)

```sql
CREATE TABLE analytics_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,   -- tenant (NULL = publiczna strona przed tenant resolvingiem)
    user_id         BIGINT UNSIGNED NULL,   -- NULL = anonimowy
    anonymous_id    CHAR(36)     NOT NULL,  -- cookie UUIDv4, zawsze obecny
    session_id      CHAR(36)     NOT NULL,  -- per-browser-session (sessionStorage)
    event           VARCHAR(100) NOT NULL,  -- "Add to Cart", "Checkout Started"
    properties      JSON         NULL,      -- payload zależny od event
    page_url        VARCHAR(2048) NOT NULL,
    referrer        VARCHAR(2048) NULL,
    utm_source      VARCHAR(255) NULL,
    utm_medium      VARCHAR(255) NULL,
    utm_campaign    VARCHAR(255) NULL,
    device_type     ENUM('desktop','mobile','tablet') NULL,
    country_code    CHAR(2)      NULL,
    ip_hash         CHAR(64)     NULL,      -- SHA-256(IP) — GDPR (nie przechowujemy IP)
    occurred_at     DATETIME(3)  NOT NULL,  -- czas zdarzenia (klient)
    ingested_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX idx_session        (session_id),
    INDEX idx_user           (user_id),
    INDEX idx_anon           (anonymous_id),
    INDEX idx_event_time     (event, occurred_at),
    INDEX idx_org_time       (organization_id, occurred_at),
    INDEX idx_funnel         (organization_id, event, session_id, occurred_at)
) ENGINE=InnoDB;
-- Partycjonowanie miesięczne dodać gdy tabela przekroczy ~10M wierszy
```

**Dlaczego własna tabela (nie tylko PostHog):**  
PostHog i Clarity nie widzą: walidacji NIP/PESEL po stronie serwera, statusu płatności P24, podziału B2C/B2B, wartości kaucji, dat wypożyczenia. Te dane ma tylko Laravel — więc funnel musi być własny.

### 3.2 Rozszerzenie tabeli `carts`

```sql
ALTER TABLE carts
    ADD COLUMN checkout_started_at TIMESTAMP NULL,        -- kiedy otworzył checkout
    ADD COLUMN last_checkout_step  VARCHAR(50) NULL,       -- 'cart'|'personal'|'payment'
    ADD COLUMN email_captured_at   TIMESTAMP NULL,         -- kiedy wpisał email (recovery)
    ADD COLUMN customer_email      VARCHAR(255) NULL,      -- email z formularza (pre-submit!)
    ADD COLUMN utm_source          VARCHAR(255) NULL,      -- skąd przyszedł do koszyka
    ADD COLUMN utm_campaign        VARCHAR(255) NULL;
```

**Problem:** Obecny `CleanupAbandonedCarts` usuwa koszyki ze statusem `abandoned`, ale **nic nie ustawia** tego statusu. To luka.

**Fix — nowy Job `MarkCartsAbandonedJob`:**
```php
// Co 5 minut w schedulerze
Cart::active()
    ->where('updated_at', '<', now()->subMinutes(30))
    ->update(['status' => 'abandoned']);
```

### 3.3 JS Tracker (`resources/js/analytics/tracker.js`)

```javascript
// ~50 linii, vanilla JS, bez dependencies
class RegistroTracker {
    constructor() {
        this.anonId = this._getOrCreate('_registro_anon', 365); // cookie 1 rok
        this.sessionId = this._getOrCreateSession();            // sessionStorage
        this.userId = window.__userId || null;
        this.orgId = window.__orgId || null;
        this.utmParams = this._parseUtm();
        this._trackPageView();
        this._trackScrollDepth();
        this._trackTimeOnPage();
        this._trackRageClicks();
        this._trackExitIntent();
    }

    track(event, properties = {}) {
        const payload = {
            event,
            properties: { ...this.utmParams, ...properties },
            anonymous_id: this.anonId,
            session_id: this.sessionId,
            user_id: this.userId,
            org_id: this.orgId,
            page_url: location.href,
            referrer: document.referrer,
            occurred_at: new Date().toISOString(),
        };
        // sendBeacon działa nawet przy zamknięciu karty
        navigator.sendBeacon('/api/analytics', JSON.stringify(payload));
    }

    // _trackScrollDepth, _trackTimeOnPage, _trackRageClicks, _trackExitIntent — patrz Faza 2
}
window.tracker = new RegistroTracker();
```

**Wbudowane w layout Blade:**
```blade
@push('scripts')
<script>
    window.__userId = {{ auth()->id() ?? 'null' }};
    window.__orgId = {{ $tenant?->id ?? 'null' }};
</script>
<script src="{{ Vite::asset('resources/js/analytics/tracker.js') }}" defer></script>
@endpush
```

### 3.4 Ingestion Endpoint

```php
// POST /api/analytics (no auth, rate-limited 120/min)
class AnalyticsIngestionController extends Controller
{
    public function store(Request $request): Response
    {
        // Walidacja minimalna — szybkość ważniejsza niż doskonałość
        $data = $request->validate([
            'event'        => 'required|string|max:100',
            'anonymous_id' => 'required|uuid',
            'session_id'   => 'required|uuid',
            'occurred_at'  => 'required|date',
            'page_url'     => 'required|url|max:2048',
            'properties'   => 'nullable|array',
        ]);

        AnalyticsEvent::create([
            ...$data,
            'organization_id' => TenantFeature::currentTenant()?->id,
            'user_id'         => auth()->id(),
            'ip_hash'         => hash('sha256', $request->ip()),
            'device_type'     => $this->detectDevice($request->userAgent()),
            'country_code'    => $this->detectCountry($request->ip()),
            'ingested_at'     => now(),
        ]);

        return response('', 204); // No Content — szybko!
    }
}
```

### 3.5 Identyfikacja anonimowych użytkowników (Identity Merge)

```php
// Po AuthenticatedSessionController@store (login):
DB::table('analytics_events')
    ->where('anonymous_id', cookie('_registro_anon'))
    ->whereNull('user_id')
    ->update(['user_id' => auth()->id()]);
```

Dzięki temu sesja sprzed zalogowania łączy się z kontem — wiemy że ten sam człowiek przeglądał katalog anonimowo, dodał do koszyka, a dopiero przy checkout się zalogował.

---

## 4. Zewnętrzne Narzędzia

### 4.1 Microsoft Clarity — heatmapy + session replay (FAZA 1)

**Co daje:** Heatmapy (click, scroll, area), nagrania sesji, rage clicks, dead clicks. Automatyczne — zero konfiguracji per strona.

**Koszt:** Całkowicie darmowy. 100k sesji/dzień. 13 miesięcy retencji.

**GDPR (Polska):** Od 31.10.2025 wymaga explicit consent od użytkowników EEA. Dane wysyłane do Microsoft USA. **Wymagany consent banner.**

**Laravel package:** `abr4xas/clarity-laravel` (aktywny)

```bash
composer require abr4xas/clarity-laravel
```

```php
// config/clarity.php
'enabled'          => env('CLARITY_ENABLED', false),
'id'               => env('CLARITY_ID'),
'consent_version'  => 2,  // Consent V2 — wymagany od 31.10.2025
```

```blade
{{-- W layout.blade.php (po uzyskaniu zgody) --}}
@if($user->hasAnalyticsConsent())
    @clarity
@endif
```

```javascript
// Po kliknięciu "Akceptuj" w cookie banner:
window.clarity('consent');
window.clarity('identify', '{{ auth()->id() }}');
window.clarity('set', 'org_id', '{{ $tenant?->id }}');
window.clarity('set', 'plan', '{{ $tenant?->subscription_status }}');

// Custom eventy (max 20 per projekt):
window.clarity('event', 'AddToCart');
window.clarity('event', 'CheckoutStarted');
window.clarity('event', 'OrderCompleted');

// Upgrade (priorytetowe nagrywanie dla ważnych sesji):
window.clarity('upgrade', 'checkout_abandonment');
```

**Co z tego wyciągamy:**
- Heatmapa strony `/wypozyczalnia` — gdzie klikają, gdzie nie docierają
- Nagrania sesji osób które opuściły checkout
- Rage clicks = znalezione zepsute elementy UI
- Scroll depth = czy czytają opisy produktów

### 4.2 PostHog Cloud — group analytics per-tenant (FAZA 3)

**Co daje:** Funnele (per org), retencja, cohorts, session replay (5k/mies. darmowe), feature flags, A/B testy. **Group Analytics = metryki per tenant** (Daily Active Tenants, funnel per organizacja).

**Koszt:** Darmowy do 1M eventów/mies. + 5k recordingów. Group Analytics w Cloud = płatny add-on, ale podstawowe eventy i funnele są darmowe.

**WAŻNE:** `qodenl/laravel-posthog` wymaga PHP 8.4+. Testy w Docker (PHP 8.3) mogą wymagać warunkowej instalacji lub mocków. Sprawdzić czy pakiet ma fallback dla 8.3.

```bash
composer require qodenl/laravel-posthog
```

```php
// .env
POSTHOG_KEY=phc_xxx
POSTHOG_HOST=https://eu.i.posthog.com  // EU hosting — GDPR lepsiej

// W AppServiceProvider::boot():
PosthogFacade::groupIdentify('organization', $org->id, [
    'name'    => $org->name,
    'plan'    => $org->subscription_status,
    'modules' => $org->modules,
]);

// W event handlerach:
PosthogFacade::capture('order_completed', [
    'order_id'    => $order->id,
    'total'       => $order->total_amount,
    'is_b2b'      => $order->customer_type === 'b2b',
    '$groups'     => ['organization' => $org->id],
]);
```

**Co z tego wyciągamy:**
- Funnel: Catalog Viewed → Add to Cart → Checkout Started → Order Completed (per organizacja)
- Retencja: które organizacje są aktywne po 7/14/30 dniach?
- Cohort: organizacje na planie X mają wyższy conversion rate?
- Session replay dla wybranych flow

---

## 5. Rozszerzenie Panelu Statystyk

### 5.1 Nowe zakładki w `/admin/statystyki`

**Obecne:** KPI cards (przychód, liczba) + ApexCharts area chart + Top usługi + eksport CSV/PDF

**Do dodania:**

#### Zakładka: "Lejek Konwersji"
```
Wizyt na katalogu:     1,247  (100%)
↓ Produkty odwiedzone:   834  (66.9%)
↓ Dodań do koszyka:      312  (37.4%)
↓ Checkout rozpoczęty:   187  (59.9%)
↓ Płatność podjęta:      143  (76.5%)
↓ Zamówień złożonych:    128  (89.5%)

Konwersja całkowita: 10.3%
```

Widget `FunnelConversionWidget` — dane z `analytics_events` + `orders`.

#### Zakładka: "Porzucone Koszyki"
```
Aktywne koszyki:        47
Porzucone (30 dni):    234
  ↳ Na kroku: koszyk           34%
  ↳ Na kroku: dane osobowe     41%
  ↳ Na kroku: płatność         25%
Wartość porzuconego:  18,420 PLN

Wskaźnik porzuceń:    64.7%
Śr. wartość porzuconego: 78.72 PLN
```

Widget `CartAbandonmentWidget` — dane z tabeli `carts` (rozszerzonej).

#### Zakładka: "Zachowanie Klientów"
```
Śr. czas na stronie katalogu: 2min 34s
Śr. scroll depth:              67%
Rage clicks (30 dni):          14 sesji
Top 3 strony wyjścia:
  1. /koszyk/zamowienie    (41%)
  2. /wypozyczalnia        (28%)
  3. /moje-konto           (12%)
```

Widget `CustomerBehaviorWidget` — dane z `analytics_events`.

#### Zakładka: "Źródła Ruchu"
```
Bezpośredni:          45%
Google (organic):     23%
Instagram:            18%
Google Ads:           11%
Inne:                  3%
```

Widget `TrafficSourcesWidget` — dane z `analytics_events.utm_*`.

---

## 6. Fazy Implementacji

### FAZA 1 — Microsoft Clarity (1–2 dni)
**Cel:** Natychmiastowe heatmapy i session replay bez żadnej infrastruktury.

1. `composer require abr4xas/clarity-laravel`
2. Konfiguracja `.env` + `config/clarity.php`
3. Dodanie `@clarity` do layout + cookie consent gate
4. Custom events: AddToCart, CheckoutStarted, OrderCompleted
5. Custom tags: org_id, plan

**Wynik:** Działające heatmapy i nagrania sesji od razu.

---

### FAZA 2 — Własny event tracker + cart abandonment (1–2 sprinty)

#### 2a. Fundament danych
1. Migracja: `create_analytics_events_table`
2. Model `AnalyticsEvent` (no BelongsToOrganization — dostęp cross-tenant dla super-admina)
3. Endpoint: `POST /api/analytics` z throttle 120/min
4. Dodanie kolumn do `carts`: `checkout_started_at`, `last_checkout_step`, `customer_email`, `utm_*`

#### 2b. Backend event hooks
```php
// Istniejące events → dodaj AnalyticsEvent:
OrderPaid     → track 'Order Completed' + org group
OrderCancelled → track 'Order Cancelled'
// Nowe:
CartService::addItem()        → track 'Add to Cart'
CartService::convertToOrder() → track 'Checkout Completed'
CheckoutController::show()    → update cart.checkout_started_at
```

#### 2c. JS Tracker
- `resources/js/analytics/tracker.js` (~50 linii vanilla JS)
- Auto-track: page views, scroll depth (25/50/75/100%), time on page (visibilitychange), rage clicks (3+ kliki 750ms), exit intent
- Form abandonment na `checkout/show.blade.php`
- Include w layout Blade

#### 2d. MarkCartsAbandonedJob
```php
// Scheduler: co 5 minut
Cart::active()
    ->where('updated_at', '<', now()->subMinutes(30))
    ->each(function (Cart $cart) {
        $cart->update(['status' => 'abandoned']);
        AnalyticsEvent::create([/* Cart Abandoned event */]);
    });
```

**Wynik:** Pełne dane o lejku + porzuconych koszykach w własnej bazie.

---

### FAZA 3 — PostHog Cloud (0.5 sprintu)

1. `composer require qodenl/laravel-posthog` (sprawdzić PHP 8.4 requirement)
2. Konfiguracja (EU hosting: `eu.i.posthog.com`)
3. Middleware do `groupIdentify` per request
4. Event hooks: OrderPaid, OrderCancelled, CartAbandoned
5. JS snippet w layout (po cookie consent)
6. Funnel report w PostHog (per-tenant dzięki group analytics)

**Wynik:** Zewnętrzna platforma do funneli, retencji, session replay — bez konieczności budowania UI.

---

### FAZA 4 — Rozszerzenie panelu statystyk Filament (1 sprint)

1. `FunnelConversionWidget` — Livewire, dane z analytics_events
2. `CartAbandonmentWidget` — dane z carts (rozszerzone kolumny)
3. `CustomerBehaviorWidget` — scroll depth, time on page, rage clicks
4. `TrafficSourcesWidget` — utm_source breakdown
5. Nowe zakładki w `/admin/statystyki` — tab navigation
6. Export CSV dla każdego widgetu

---

### FAZA 5 — Odzyskiwanie porzuconych koszyków (opcjonalna)

1. Przechwytywanie emaila na checkout step 1 (przed submitem)
   ```javascript
   document.getElementById('email').addEventListener('blur', () => {
       tracker.track('Email Captured', { email_hash: sha256(this.value) });
       // Wyślij do backendu — zapisz w cart.customer_email
   });
   ```
2. `SendAbandonedCartEmailJob` — wyślij po 1h od porzucenia (jeśli email znany)
3. Notification: `AbandonedCartNotification` — z linkiem do koszyka

**Szacowany odzysk:** Branżowo 5–10% porzuconych koszyków wraca po emailu recovery.

---

## 7. Co To Daje — Business Value

| Metryka | Wartość biznesowa |
|---------|------------------|
| **Funnel conversion rate** | Wiesz ile % odwiedzających kupuje — benchmark branżowy e-commerce = 2–4%. Poniżej? Problem z UX lub cenami. |
| **Checkout step abandonment** | Jeśli 40% odpada na "Dane osobowe" → za dużo pól lub PESEL/REGON odstrasza |
| **Cart abandonment rate** | Branżowo 70–80%. Mierzysz swój. Wysyłasz email recovery. |
| **Rage clicks heatmapa** | Natychmiast widać zepsute elementy UI (np. przycisk który wydaje się klikalny ale nie jest) |
| **Scroll depth katalogu** | Jeśli 80% nie scrolluje poniżej 3. produktu → zmień kolejność lub layout |
| **Exit intent** | Gdzie tracisz klientów → pop-up z rabatem / "Masz pytania?" |
| **UTM attribution** | Który kanał (Instagram / Google Ads / organic) przynosi konwertujących klientów |
| **Time on product page** | < 30s = klient nie czyta opisów (za mało informacji) |
| **Per-tenant funnel** | Które organizacje mają problem z konwersją (onboarding, UX, ceny) |
| **B2C vs B2B abandonment** | Czy formularz B2B (NIP, REGON, KRS) odstrasza |

---

## 8. GDPR / Prawo Polskie

### Co można zbierać bez consent banneru
- Własna tabela `analytics_events` z danymi serwerowymi (IP zahashowane) — **prawdopodobnie nie wymaga zgody** (dane agregowane, nie w cookie użytkownika, podobnie jak logi serwera)
- `utm_*` z URL — bez cookies
- Czas zamówień, wartości koszyków — dane wewnętrzne systemu

### Co wymaga consent banneru
- **Microsoft Clarity** — od 31.10.2025 wymagany explicit opt-in dla EEA (w tym Polska)
- **PostHog** z cookies / session replay — wymaga zgody
- **PostHog bez cookies** (cookieless mode) — szara strefa, brak wytycznych UODO

### Rekomendacja implementacji consent
```javascript
// Clarity i PostHog ładowane warunkowo:
if (getCookie('analytics_consent') === 'granted') {
    loadClarity();
    loadPostHog();
}
// Własny tracker → zawsze (dane serwerowe, no cookies wymagane)
window.tracker = new RegistroTracker(); // uruchamia się zawsze
```

---

## 9. Szacunek Zasobów

| Komponent | Storage | CPU/RAM overhead |
|-----------|---------|-----------------|
| `analytics_events` (10k eventów/dzień) | ~50 MB/mies. | Negligible (async inserts) |
| Rozszerzone `carts` | ~1 MB/mies. | $0 |
| Microsoft Clarity | $0 (Microsoft) | $0 |
| PostHog Cloud | $0 (do 1M eventów) | $0 |
| JS tracker (`analytics.js`) | ~3 KB gzip | $0 |

**PostHog self-hosted (dla porównania — nie rekomendowane):**  
Hetzner CAX31 (8 vCPU, 16 GB RAM) = €18/mies. + 6–8h/mies. ops.

---

## 10. Kolejność Implementacji (priorytet)

```
Tydzień 1:  Faza 1 — Clarity (2 dni) → natychmiastowe heatmapy
Tydzień 2:  Faza 2a — analytics_events schema + endpoint
Tydzień 3:  Faza 2b — backend hooks (Cart + Checkout events)
Tydzień 4:  Faza 2c — JS tracker
Tydzień 5:  Faza 2d — MarkCartsAbandonedJob + rozszerzenie carts
Tydzień 6:  Faza 3 — PostHog integration
Tydzień 7-8: Faza 4 — Filament widgets (Funnel, CartAbandonment, Behavior)
Opcjonalnie: Faza 5 — email recovery (po zebraniu ~1 mies. danych)
```

---

## Appendix A — Pakiety

| Pakiet | Cel | Instalacje |
|--------|-----|------------|
| `abr4xas/clarity-laravel` | Clarity integration z Consent V2 | aktywny |
| `qodenl/laravel-posthog` | PostHog PHP SDK wrapper | ~95k |
| `directorytree/metrics` | Simple in-DB metric tracking | dojrzały |

## Appendix B — Źródła

- PostHog Group Analytics: posthog.com/docs/product-analytics/group-analytics
- PostHog PHP SDK: posthog.com/docs/libraries/php  
- Segment E-Commerce Spec v2: segment.com/docs/connections/spec/ecommerce/v2/
- Microsoft Clarity API: learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-api
- Microsoft Clarity GDPR 2025: pandectes.io/blog/microsoft-clarity-and-gdpr-what-you-need-to-know-in-2025/
- Clarity Laravel package: github.com/abr4xas/clarity-laravel
- rrweb library: github.com/rrweb-io/rrweb
- Cart abandonment stats: hulkapps.com/blogs/ecommerce-hub/2024-cart-abandonment-statistics
- Rage click detection: inspectlet.com/guides/rage-clicks
- Mixpanel event taxonomy: mixpanel.com/blog/best-practices-updated/
- DirectoryTree Metrics: masteryoflaravel.medium.com/tracking-and-analyzing-metrics-in-laravel-with-the-directorytree-metrics-package
- Self-hosted analytics comparison 2026: openpanel.dev/articles/self-hosted-web-analytics
- visibilitychange vs beforeunload: developer.mozilla.org/en-US/docs/Web/API/Document/visibilitychange_event
