# Analytics Panel — Admin

Panel analityki (`/admin/analityka`) prezentuje dane zbierane przez `analytics_events`.

## Dostęp

Widoczny tylko gdy istnieje kontekst tenanta (`TenantFeature::currentTenant()`). Na root domain panel jest ukryty.

## Komponenty

### KPI Cards (4 metryki)

| Metryka | Opis | Porównanie |
|---------|------|------------|
| Odsłony | Suma zdarzeń `page.view` | vs poprzedni okres |
| Wizyty | Unikalne session_id | vs poprzedni okres |
| Użytkownicy | Unikalne visitor_id | vs poprzedni okres |
| Śr. czas na stronie | Z `page.time_spent` events (MySQL only) | bez porównania |

Porównanie: poprzedni okres = ten sam czas przesunięty wstecz o `$periodDays`.

### Wykres "Odsłony w czasie"

- ApexCharts bar chart, 14 dni domyślnie
- `analyticsPageviewChart` Alpine component (`resources/js/charts/analytics-pageview-chart.js`)
- ResizeObserver aktualizuje szerokość SVG gdy sidebar Filament się rozszerza (fix dla niewidocznych słupków)
- Usunięto `tickAmount` (bug ApexCharts v5 — powoduje bars o wysokości 0)
- Etykiety co 2 daty (`formatter: (val, i) => i % 2 === 0 ? val : ''`)

### Najpopularniejsze strony (top 10)

5 kolumn: Strona | Wizyty | Śr. czas | Scroll | Bounce

- **Strona**: path (np. `/wypozyczalnia`) + link zewnętrzny
- **Wizyty**: unikalne session_id per URL
- **Śr. czas**: z `page.time_spent` events (nullable, MySQL only)
- **Scroll**: max głębokość scrollowania per sesja per URL, uśredniona; color badge (≥70% zielony, 40-69% żółty, <40% czerwony)
- **Bounce**: odsetek sesji z 1 eventem; null jeśli <3 sesji; kolor odwrócony (niski = zielony)

### Inne sekcje

- **Funnel konwersji**: kroki checkout
- **Porzucenia koszyka**: top pola gdzie użytkownicy rezygnują
- **Źródła ruchu**: direct/google/facebook/instagram/organic

## Filtry

- Predefiniowane okresy: ostatnie 7/14/30/90 dni
- Własny zakres dat (datepicker)
- Default: `last_14_days`

## Klasy CSS (badge'y)

Dynamiczne klasy generowane przez PHP closures — zdefiniowane bezpośrednio w `resources/css/filament/admin.css` (nie przez Tailwind JIT):

```css
.analytics-badge-green   /* scroll ≥ 70% */
.analytics-badge-yellow  /* scroll 40-69% */
.analytics-badge-red     /* scroll < 40% */
.analytics-text-green    /* bounce < 40% */
.analytics-text-yellow   /* bounce 40-60% */
.analytics-text-red      /* bounce > 60% */
```

## Pliki

| Plik | Rola |
|------|------|
| `app/Filament/Pages/AnalyticsOverview.php` | Logika, queries, mount/filter |
| `resources/views/filament/pages/analytics-overview.blade.php` | Widok, KPI cards, tabele |
| `resources/js/charts/analytics-pageview-chart.js` | ApexCharts Alpine component |
| `resources/css/filament/admin.css` | Badge klasy + @theme tokens |
