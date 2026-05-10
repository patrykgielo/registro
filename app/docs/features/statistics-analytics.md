# Statistics & Analytics System

**Added:** 2026-05-10
**Branch:** `feature/statistics-analytics`

---

## Architecture

Pre-aggregated snapshot strategy:
- `statistics_daily_snapshots` table: 3 rows/day/tenant (orders, appointments, rentals)
- Hourly scheduled job recalculates today + yesterday for all tenants
- All UI reads from snapshots — never from raw tables
- Live fallback to raw tables only when today's snapshot is missing or >2 hours old

## Revenue Counting Rules

| Source | Status filter | Revenue field | Date field |
|--------|---------------|---------------|------------|
| orders | status = `paid` | `total_amount` | `paid_at` |
| appointments | status IN (`confirmed`, `completed`) | `service_price_at_booking` | `appointment_date` |
| rentals | status IN (`confirmed`, `active`, `returned`) | `total_price` | `start_date` |

## Files

### Migrations
- `2026_05_10_000001_add_statistics_indexes.php` — composite indexes: `(org_id, status, paid_at)`, `(org_id, status, appointment_date)`, `(org_id, status, start_date)`
- `2026_05_10_000002_create_statistics_daily_snapshots_table.php` — snapshots table with unique constraint on `(organization_id, date, source)`

### Models
- `app/Models/StatisticsSnapshot.php` — NO `BelongsToOrganization` trait (intentional — super-admin needs cross-tenant access)

### Services
- `app/Services/Statistics/StatisticsService.php`
  - `forTenant(Organization, Carbon $from, Carbon $to)` — tenant-scoped, with live fallback
  - `platformAggregate(Carbon, Carbon)` — all tenants
  - `perTenant(Carbon, Carbon)` — Collection grouped by org
  - `liveForDate(Organization, Carbon)` — raw table fallback
  - `periodToRange(string)` — converts `today|this_week|this_month|this_year|last_month|last_year` to [Carbon, Carbon]
- `app/Services/Statistics/StatisticsExportService.php`
  - `toCsv(array, string)` → `StreamedResponse` (UTF-8 BOM for Excel)
  - `toPdf(array, ?Organization, string)` → `Response` via barryvdh/laravel-dompdf

### Jobs
- `app/Jobs/RecalculateDailyStatisticsJob.php` — uses `DB::table()` to bypass global scopes; upserts 3 rows per org per day

### Console Commands
- `php artisan statistics:recalculate [--date=] [--from=] [--to=] [--org=]`
- `php artisan statistics:backfill --from= [--to=]` — chunks in 30-day windows

### Scheduler
`routes/console.php` — hourly `statistics-recalculate` that dispatches jobs for yesterday + today

### Filament — Admin Panel (`/admin`)
- `app/Filament/Widgets/TenantStatsOverviewWidget.php` — dashboard KPI cards (current month). Hides appointments/rentals card when module disabled.
- `app/Filament/Widgets/RevenueChartWidget.php` — last-30-days line chart on dashboard
- `app/Filament/Pages/Statistics.php` — full stats page at `/admin/statystyki`, navigation group: `reports`
  - Period selector (query string `?period=`)
  - 4 KPI cards
  - 30-day revenue chart
  - Top-10 services table (live query, bounded to 1 tenant)
  - Export CSV + Export PDF header actions

### Filament — Platform Panel (`/platform`)
- `app/Filament/Platform/Pages/Statistics.php` — `/platform/statystyki`
  - Cross-tenant aggregate KPI cards
  - 30-day chart
  - Per-tenant breakdown table
  - Export CSV action

### Views
- `resources/views/filament/pages/statistics.blade.php` — admin stats page
- `resources/views/filament/platform/pages/statistics.blade.php` — platform stats page
- `resources/views/statistics/pdf-report.blade.php` — DomPDF template (tables only, no charts)

## Backfilling Historical Data

```bash
# Backfill full year 2026
php artisan statistics:backfill --from=2026-01-01

# Backfill specific date range
php artisan statistics:backfill --from=2026-03-01 --to=2026-04-30

# Recalculate single day for one org
php artisan statistics:recalculate --date=2026-05-10 --org=1
```

## Dependencies Added

- `barryvdh/laravel-dompdf` ^3.1 — PDF generation
- Chart.js (served via Filament's bundled assets or CDN in Blade views)

## Known Constraints

- Charts in Blade pages use Chart.js loaded from the page's script stack — requires Chart.js to be available in the Filament panel bundle
- PDF reports contain tables only (no charts) — DomPDF does not support canvas/JS
- The `statistics_daily_snapshots.count` column name conflicts with PHP's reserved keywords in some ORM contexts — addressed by using raw DB queries in the Job
