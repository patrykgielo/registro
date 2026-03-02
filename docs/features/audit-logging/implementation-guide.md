# Audit Log Filament Resource - Implementation Guide

## Overview

This guide provides step-by-step instructions for creating a Filament admin resource to view audit logs.

**Estimated time:** 2-3 hours

---

## Requirements

- [x] `audit_logs` table exists
- [x] `AuditLog` model exists
- [x] `Auditable` trait implemented on User and Appointment models
- [x] `LogAuthenticationEvents` listener registered

---

## Step 1: Generate Filament Resource

```bash
docker compose exec app php artisan make:filament-resource AuditLog --generate
```

**Options:**
- `--generate`: Auto-generate form/table from model
- **Do NOT** use `--simple` (we need full CRUD capabilities for filters)
- **Do NOT** use `--view` (we want read-only, but need form for filters)

---

## Step 2: Configure Resource (AuditLogResource.php)

**File:** `app/Filament/Resources/AuditLogResource.php`

### 2.1 Navigation Setup

```php
protected static ?string $model = AuditLog::class;

protected static ?string $navigationIcon = 'heroicon-o-shield-check';

protected static ?string $navigationLabel = 'Dziennik Audytu';

protected static ?string $navigationGroup = 'Administracja';

protected static ?int $navigationSort = 99; // Last in group

public static function getModelLabel(): string
{
    return 'Wpis audytu';
}

public static function getPluralModelLabel(): string
{
    return 'Dziennik audytu';
}
```

---

### 2.2 Access Control (Admin-Only)

```php
public static function canCreate(): bool
{
    return false; // Audit logs are auto-generated only
}

public static function canEdit(Model $record): bool
{
    return false; // Audit logs are immutable
}

public static function canDelete(Model $record): bool
{
    return false; // Audit logs should never be deleted manually
}

public static function canDeleteAny(): bool
{
    return false;
}

public static function canViewAny(): bool
{
    return auth()->user()?->hasRole('admin');
}
```

**Reasoning:**
- Audit logs are **read-only** for compliance
- Only admins should view them
- No manual creation/editing/deletion
- Automatic cleanup via scheduled command only

---

### 2.3 Table Configuration

```php
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

public static function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('id')
                ->label('ID')
                ->sortable()
                ->searchable(),

            TextColumn::make('created_at')
                ->label('Data i czas')
                ->dateTime('Y-m-d H:i:s')
                ->sortable()
                ->searchable(),

            TextColumn::make('user.name')
                ->label('Użytkownik')
                ->searchable()
                ->sortable()
                ->description(fn (AuditLog $record) => $record->user?->email)
                ->default('System'),

            BadgeColumn::make('event')
                ->label('Zdarzenie')
                ->formatStateUsing(fn (string $state) => $state)
                ->color(fn (string $state): string => match ($state) {
                    AuditLog::EVENT_CREATED => 'success',
                    AuditLog::EVENT_UPDATED => 'info',
                    AuditLog::EVENT_DELETED => 'danger',
                    AuditLog::EVENT_LOGIN => 'success',
                    AuditLog::EVENT_LOGOUT => 'gray',
                    AuditLog::EVENT_LOGIN_FAILED => 'danger',
                    AuditLog::EVENT_CONSENT_GRANTED => 'success',
                    AuditLog::EVENT_CONSENT_WITHDRAWN => 'warning',
                    AuditLog::EVENT_EXPORTED => 'info',
                    default => 'gray',
                })
                ->icon(fn (string $state): string => match ($state) {
                    AuditLog::EVENT_CREATED => 'heroicon-o-plus-circle',
                    AuditLog::EVENT_UPDATED => 'heroicon-o-pencil',
                    AuditLog::EVENT_DELETED => 'heroicon-o-trash',
                    AuditLog::EVENT_LOGIN => 'heroicon-o-arrow-right-on-rectangle',
                    AuditLog::EVENT_LOGOUT => 'heroicon-o-arrow-left-on-rectangle',
                    AuditLog::EVENT_LOGIN_FAILED => 'heroicon-o-exclamation-triangle',
                    AuditLog::EVENT_CONSENT_GRANTED => 'heroicon-o-check-circle',
                    AuditLog::EVENT_CONSENT_WITHDRAWN => 'heroicon-o-x-circle',
                    AuditLog::EVENT_EXPORTED => 'heroicon-o-arrow-down-tray',
                    default => 'heroicon-o-information-circle',
                })
                ->sortable()
                ->searchable(),

            TextColumn::make('auditable_type')
                ->label('Typ obiektu')
                ->formatStateUsing(fn (string $state) => class_basename($state))
                ->sortable()
                ->searchable(),

            TextColumn::make('auditable_id')
                ->label('ID obiektu')
                ->sortable()
                ->searchable(),

            TextColumn::make('ip_address')
                ->label('Adres IP')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('url')
                ->label('URL')
                ->limit(50)
                ->tooltip(fn (AuditLog $record) => $record->url)
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->defaultSort('created_at', 'desc')
        ->filters([
            // Filter by event type
            SelectFilter::make('event')
                ->label('Typ zdarzenia')
                ->options([
                    AuditLog::EVENT_CREATED => 'Utworzono',
                    AuditLog::EVENT_UPDATED => 'Zaktualizowano',
                    AuditLog::EVENT_DELETED => 'Usunięto',
                    AuditLog::EVENT_LOGIN => 'Zalogowano',
                    AuditLog::EVENT_LOGOUT => 'Wylogowano',
                    AuditLog::EVENT_LOGIN_FAILED => 'Nieudane logowanie',
                    AuditLog::EVENT_CONSENT_GRANTED => 'Udzielono zgody',
                    AuditLog::EVENT_CONSENT_WITHDRAWN => 'Wycofano zgodę',
                    AuditLog::EVENT_EXPORTED => 'Wyeksportowano',
                    AuditLog::EVENT_PASSWORD_CHANGED => 'Zmieniono hasło',
                    AuditLog::EVENT_PASSWORD_RESET => 'Zresetowano hasło',
                    AuditLog::EVENT_ACCOUNT_ANONYMIZED => 'Zanonimizowano',
                ]),

            // Filter by model type
            SelectFilter::make('auditable_type')
                ->label('Typ obiektu')
                ->options([
                    'App\\Models\\User' => 'Użytkownik',
                    'App\\Models\\Appointment' => 'Rezerwacja',
                    'App\\Models\\UserAddress' => 'Adres',
                    'App\\Models\\UserVehicle' => 'Pojazd',
                ]),

            // Filter by user
            SelectFilter::make('user_id')
                ->label('Użytkownik')
                ->relationship('user', 'email')
                ->searchable()
                ->preload(),

            // Filter by date range
            Filter::make('created_at')
                ->form([
                    Forms\Components\DatePicker::make('created_from')
                        ->label('Data od'),
                    Forms\Components\DatePicker::make('created_until')
                        ->label('Data do'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when(
                            $data['created_from'],
                            fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['created_until'],
                            fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                        );
                }),
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
        ])
        ->bulkActions([
            // No bulk actions - audit logs should not be modified
        ]);
}
```

---

### 2.4 View Page (Show Detailed Record)

**File:** `app/Filament/Resources/AuditLogResource/Pages/ViewAuditLog.php`

```php
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\KeyValueEntry;

public function infolist(Infolist $infolist): Infolist
{
    return $infolist
        ->schema([
            Section::make('Szczegóły zdarzenia')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextEntry::make('id')
                                ->label('ID'),

                            TextEntry::make('created_at')
                                ->label('Data i czas')
                                ->dateTime('Y-m-d H:i:s'),

                            TextEntry::make('user.name')
                                ->label('Użytkownik')
                                ->default('System'),

                            TextEntry::make('user.email')
                                ->label('Email użytkownika')
                                ->default('—'),

                            TextEntry::make('event')
                                ->label('Typ zdarzenia')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    AuditLog::EVENT_CREATED => 'success',
                                    AuditLog::EVENT_UPDATED => 'info',
                                    AuditLog::EVENT_DELETED => 'danger',
                                    default => 'gray',
                                }),

                            TextEntry::make('auditable_type')
                                ->label('Typ obiektu')
                                ->formatStateUsing(fn (string $state) => class_basename($state)),

                            TextEntry::make('auditable_id')
                                ->label('ID obiektu'),

                            TextEntry::make('ip_address')
                                ->label('Adres IP')
                                ->default('—'),

                            TextEntry::make('url')
                                ->label('URL')
                                ->columnSpanFull()
                                ->default('—'),

                            TextEntry::make('user_agent')
                                ->label('User Agent')
                                ->columnSpanFull()
                                ->default('—'),
                        ]),
                ]),

            Section::make('Zmiany')
                ->schema([
                    KeyValueEntry::make('old_values')
                        ->label('Wartości przed zmianą')
                        ->columnSpanFull()
                        ->hidden(fn (AuditLog $record) => empty($record->old_values)),

                    KeyValueEntry::make('new_values')
                        ->label('Wartości po zmianie')
                        ->columnSpanFull()
                        ->hidden(fn (AuditLog $record) => empty($record->new_values)),
                ])
                ->hidden(fn (AuditLog $record) => empty($record->old_values) && empty($record->new_values)),

            Section::make('Dodatkowe informacje')
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->label('Metadane')
                        ->columnSpanFull(),
                ])
                ->hidden(fn (AuditLog $record) => empty($record->metadata)),
        ]);
}
```

---

### 2.5 List Page Configuration

**File:** `app/Filament/Resources/AuditLogResource/Pages/ListAuditLogs.php`

```php
protected function getHeaderActions(): array
{
    return [
        // Export action
        Actions\Action::make('export')
            ->label('Eksportuj do CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(function () {
                $filename = 'audit-log-' . now()->format('Y-m-d-His') . '.csv';

                return response()->streamDownload(function () {
                    $handle = fopen('php://output', 'w');

                    // CSV header
                    fputcsv($handle, [
                        'ID',
                        'Data i czas',
                        'Użytkownik',
                        'Email',
                        'Zdarzenie',
                        'Typ obiektu',
                        'ID obiektu',
                        'Adres IP',
                        'URL',
                    ]);

                    // Get filtered records
                    $query = $this->getFilteredTableQuery();

                    $query->chunk(100, function ($logs) use ($handle) {
                        foreach ($logs as $log) {
                            fputcsv($handle, [
                                $log->id,
                                $log->created_at->format('Y-m-d H:i:s'),
                                $log->user?->name ?? 'System',
                                $log->user?->email ?? '—',
                                $log->event_label,
                                class_basename($log->auditable_type),
                                $log->auditable_id,
                                $log->ip_address ?? '—',
                                $log->url ?? '—',
                            ]);
                        }
                    });

                    fclose($handle);
                }, $filename);
            }),
    ];
}
```

---

## Step 3: Add Polish Translations

**File:** `lang/pl/filament.php` (or create if doesn't exist)

```php
return [
    'audit_log' => [
        'navigation_label' => 'Dziennik Audytu',
        'model_label' => 'Wpis audytu',
        'plural_model_label' => 'Dziennik audytu',

        'columns' => [
            'id' => 'ID',
            'created_at' => 'Data i czas',
            'user' => 'Użytkownik',
            'event' => 'Zdarzenie',
            'auditable_type' => 'Typ obiektu',
            'auditable_id' => 'ID obiektu',
            'ip_address' => 'Adres IP',
            'url' => 'URL',
        ],

        'filters' => [
            'event' => 'Typ zdarzenia',
            'auditable_type' => 'Typ obiektu',
            'user_id' => 'Użytkownik',
            'created_from' => 'Data od',
            'created_until' => 'Data do',
        ],

        'events' => [
            'created' => 'Utworzono',
            'updated' => 'Zaktualizowano',
            'deleted' => 'Usunięto',
            'login' => 'Zalogowano',
            'logout' => 'Wylogowano',
            'login_failed' => 'Nieudane logowanie',
            'consent_granted' => 'Udzielono zgody',
            'consent_withdrawn' => 'Wycofano zgodę',
            'exported' => 'Wyeksportowano',
            'password_changed' => 'Zmieniono hasło',
            'password_reset' => 'Zresetowano hasło',
            'account_anonymized' => 'Zanonimizowano',
        ],
    ],
];
```

---

## Step 4: Testing

### 4.1 Verify Access Control

```bash
# Login as admin
# Navigate to /admin/audit-logs
# Should see: ✅ Audit logs visible

# Login as staff
# Navigate to /admin/audit-logs
# Should see: ❌ 403 Forbidden
```

### 4.2 Verify Data Display

1. **Create test data:**
```bash
docker compose exec app php artisan tinker
```

```php
// Create a test user
$user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User']);

// Update user (triggers audit log)
$user->update(['first_name' => 'Updated']);

// Login as admin
auth()->login(User::where('email', 'admin@registro.com')->first());

// Check audit logs
AuditLog::latest()->take(5)->get();
```

2. **Check admin panel:**
   - Navigate to `/admin/audit-logs`
   - Should see recent `created` and `updated` events
   - Click "View" on an `updated` event
   - Should see old/new values (first_name: Test → Updated)

### 4.3 Verify Filters

1. Filter by event type: "Zaktualizowano"
2. Filter by user: admin@registro.com
3. Filter by date range: Last 7 days
4. Should see filtered results

### 4.4 Verify Export

1. Click "Eksportuj do CSV"
2. Should download `audit-log-YYYY-MM-DD-HHMMSS.csv`
3. Open in Excel/LibreOffice
4. Should contain all columns with correct data

---

## Step 5: Performance Optimization

### 5.1 Add Index for Common Queries

Already exists in migration, but verify:

```bash
docker compose exec mysql mysql -u registro -ppassword registro -e "SHOW INDEX FROM audit_logs;"
```

Should see indexes on:
- `user_id`
- `auditable_type, auditable_id`
- `event`
- `created_at`

### 5.2 Eager Loading

In `AuditLogResource.php`:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with('user') // Eager load user relationship
        ->latest();    // Default sort by newest first
}
```

---

## Step 6: Optional Enhancements

### 6.1 Dashboard Widget: Recent Activity

**File:** `app/Filament/Widgets/RecentAuditActivity.php`

```bash
docker compose exec app php artisan make:filament-widget RecentAuditActivity
```

```php
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentAuditActivity extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AuditLog::query()
                    ->with('user')
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Czas')
                    ->dateTime('H:i:s')
                    ->since(),

                TextColumn::make('user.name')
                    ->label('Użytkownik')
                    ->default('System'),

                TextColumn::make('event')
                    ->label('Zdarzenie')
                    ->badge(),

                TextColumn::make('auditable_type')
                    ->label('Obiekt')
                    ->formatStateUsing(fn (string $state) => class_basename($state)),
            ]);
    }
}
```

Register in `app/Filament/Pages/Dashboard.php`:

```php
public function getWidgets(): array
{
    return [
        // ... other widgets
        \App\Filament\Widgets\RecentAuditActivity::class,
    ];
}
```

---

### 6.2 Global Search Integration

In `AuditLogResource.php`:

```php
public static function getGloballySearchableAttributes(): array
{
    return ['user.email', 'user.name', 'ip_address', 'auditable_id'];
}

public static function getGlobalSearchResultTitle(Model $record): string
{
    return "{$record->event_label} - {$record->user?->name ?? 'System'}";
}

public static function getGlobalSearchResultDetails(Model $record): array
{
    return [
        'Obiekt' => class_basename($record->auditable_type) . " #{$record->auditable_id}",
        'Data' => $record->created_at->format('Y-m-d H:i'),
        'IP' => $record->ip_address ?? '—',
    ];
}
```

---

### 6.3 Alerts for Suspicious Activity

**File:** `app/Services/AuditAlertService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Notifications\SuspiciousActivityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AuditAlertService
{
    /**
     * Check for suspicious activity and alert admins
     */
    public function checkFailedLogins(string $email): void
    {
        $cacheKey = "failed_logins:{$email}";
        $attempts = Cache::increment($cacheKey);
        Cache::put($cacheKey, $attempts, now()->addHour());

        // Alert on 5+ failed attempts in 1 hour
        if ($attempts >= 5) {
            $this->alertAdmins(
                "5+ failed login attempts for {$email}",
                AuditLog::where('event', AuditLog::EVENT_LOGIN_FAILED)
                    ->where('created_at', '>=', now()->subHour())
                    ->get()
            );
        }
    }

    /**
     * Alert admins of suspicious activity
     */
    protected function alertAdmins(string $message, $auditLogs): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            $admin->notify(new SuspiciousActivityAlert($message, $auditLogs));
        }
    }
}
```

Call in `LogAuthenticationEvents::handleFailed()`:

```php
use App\Services\AuditAlertService;

public function handleFailed(Failed $event): void
{
    // ... existing code ...

    // Check for suspicious activity
    app(AuditAlertService::class)->checkFailedLogins(
        $event->credentials['email'] ?? 'unknown'
    );
}
```

---

## Step 7: Documentation

Update project documentation:

1. **Add to CHANGELOG.md:**
```markdown
## [Unreleased]

### Added
- Audit log admin panel (Filament resource)
- CSV export for audit logs
- Admin-only access control
- Filters: event type, model type, user, date range
```

2. **Update README.md:**
```markdown
## Admin Features

### Audit Logging
View complete audit trail of all system activities.

**Access:** Admin only
**Location:** `/admin/audit-logs`

**Features:**
- View all data changes, logins, consents
- Filter by user, event type, date range
- Export to CSV for compliance reports
- Detailed view of old/new values
```

---

## Step 8: Deployment Checklist

- [ ] Run migration on production: `php artisan migrate --force`
- [ ] Clear cache: `php artisan optimize:clear`
- [ ] Verify admin role exists: `php artisan tinker` → `User::role('admin')->count()`
- [ ] Test access as admin: Navigate to `/admin/audit-logs`
- [ ] Test access as staff: Should see 403 Forbidden
- [ ] Verify performance with large dataset (10,000+ logs)
- [ ] Schedule cleanup command (if implemented)

---

## Common Issues

### Issue 1: "Class 'AuditLog' not found"

**Solution:**
```bash
composer dump-autoload
php artisan optimize:clear
```

---

### Issue 2: Navigation item not showing

**Solution:**
Check `AuditLogResource.php`:
```php
protected static ?string $navigationGroup = 'Administracja';
```

Ensure you have other resources in the same group, or remove grouping.

---

### Issue 3: Filters not working

**Solution:**
Ensure you're using `SelectFilter::make()` with correct relationship:
```php
SelectFilter::make('user_id')
    ->relationship('user', 'email') // NOT 'name' - User model has first_name/last_name
    ->searchable()
    ->preload();
```

---

### Issue 4: Export downloads empty CSV

**Solution:**
Use `$this->getFilteredTableQuery()` instead of `AuditLog::query()` to respect active filters.

---

## Summary

You now have a complete, production-ready audit log admin panel with:

- ✅ Read-only access (no editing/deleting)
- ✅ Admin-only access control
- ✅ Advanced filtering (event, model, user, date)
- ✅ CSV export for compliance reports
- ✅ Detailed view with old/new value comparison
- ✅ Performance optimized (eager loading, indexed queries)
- ✅ Polish translations
- ✅ GDPR-compliant (immutable logs)

**Next steps:**
- Customer-facing "My Activity" page
- Automated cleanup command
- Dashboard widget for recent activity
- Email alerts for suspicious activity
