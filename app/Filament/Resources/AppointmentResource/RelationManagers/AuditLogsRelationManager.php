<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppointmentResource\RelationManagers;

use App\Enums\AppointmentStatus;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Historia zmian';

    protected static ?string $modelLabel = 'Zmiana';

    protected static ?string $pluralModelLabel = 'Zmiany';

    private const FIELD_LABELS = [
        'customer_id' => 'Klient',
        'staff_id' => 'Pracownik',
        'service_id' => 'Usługa',
        'appointment_date' => 'Data wizyty',
        'start_time' => 'Czas rozpoczęcia',
        'end_time' => 'Czas zakończenia',
        'status' => 'Status',
        'notes' => 'Notatki',
        'cancellation_reason' => 'Powód anulowania',
        'location_address' => 'Adres',
        'service_location_type' => 'Typ lokalizacji',
        'completed_at' => 'Zakończono',
        'cancelled_at' => 'Anulowano',
    ];

    /** @deprecated Use AppointmentStatus enum instead. Kept for reference only. */
    private const STATUS_LABELS = [
        'pending' => 'Oczekująca',
        'confirmed' => 'Potwierdzona',
        'cancelled' => 'Anulowana',
        'completed' => 'Zakończona',
    ];

    /**
     * AuditLog is BelongsToOrganization-scoped and reached only through an already
     * tenant-scoped Appointment ($ownerRecord), so opening this to tenant admins
     * carries no cross-tenant exposure — mirrors AuditLogResource's own reasoning.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasRole(['super-admin', 'admin']) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('event')
                    ->label('Zdarzenie')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AuditLog::EVENT_CREATED => 'Utworzono',
                        AuditLog::EVENT_UPDATED => 'Zaktualizowano',
                        AuditLog::EVENT_DELETED => 'Usunięto',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        AuditLog::EVENT_CREATED => 'success',
                        AuditLog::EVENT_UPDATED => 'info',
                        AuditLog::EVENT_DELETED => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        AuditLog::EVENT_CREATED => 'heroicon-o-plus-circle',
                        AuditLog::EVENT_UPDATED => 'heroicon-o-pencil-square',
                        AuditLog::EVENT_DELETED => 'heroicon-o-trash',
                        default => 'heroicon-o-information-circle',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Użytkownik')
                    ->placeholder('System'),

                Tables\Columns\TextColumn::make('summary')
                    ->label('Podsumowanie')
                    ->html()
                    ->getStateUsing(fn (AuditLog $record): string => self::generateSummary($record))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Typ zdarzenia')
                    ->options([
                        AuditLog::EVENT_CREATED => 'Utworzono',
                        AuditLog::EVENT_UPDATED => 'Zaktualizowano',
                        AuditLog::EVENT_DELETED => 'Usunięto',
                    ]),
            ])
            ->recordActions([
                Actions\Action::make('details')
                    ->label('Szczegóły')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Szczegóły zmiany')
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Zamknij')
                    ->schema(function (AuditLog $record): array {
                        return self::getDetailsSchema($record);
                    }),
            ])
            ->emptyStateHeading('Brak historii zmian')
            ->emptyStateDescription('Nie zarejestrowano jeszcze żadnych zmian dla tej wizyty.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Generate concise summary for table display
     */
    private static function generateSummary(AuditLog $record): string
    {
        $filtered = self::filterMeaningfulFields($record->new_values ?? []);

        return match ($record->event) {
            AuditLog::EVENT_CREATED => self::generateCreatedSummary($filtered),
            AuditLog::EVENT_UPDATED => self::generateUpdatedSummary($record),
            AuditLog::EVENT_DELETED => '<span class="text-gray-600 dark:text-gray-400">Usunięto wizytę</span>',
            default => '—',
        };
    }

    private static function generateCreatedSummary(array $values): string
    {
        $parts = [];

        if (isset($values['service_id'])) {
            $service = Service::find($values['service_id']);
            $parts[] = '<strong>'.htmlspecialchars($service?->name ?? 'Usługa #'.$values['service_id']).'</strong>';
        }

        if (isset($values['appointment_date'])) {
            $parts[] = 'na <strong>'.self::formatDate($values['appointment_date']).'</strong>';
        }

        if (isset($values['start_time'])) {
            $parts[] = 'o <strong>'.self::formatTime($values['start_time']).'</strong>';
        }

        return ! empty($parts)
            ? 'Utworzono wizytę: '.implode(' ', $parts)
            : 'Utworzono wizytę';
    }

    private static function generateUpdatedSummary(AuditLog $record): string
    {
        $changes = [];
        $oldValues = $record->old_values ?? [];
        $newValues = $record->new_values ?? [];

        foreach ($newValues as $field => $newValue) {
            if (! isset(self::FIELD_LABELS[$field])) {
                continue;
            }

            $oldValue = $oldValues[$field] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $label = self::FIELD_LABELS[$field];
            $formattedOld = self::formatFieldValue($field, $oldValue, short: true);
            $formattedNew = self::formatFieldValue($field, $newValue, short: true);

            $changes[] = "<span class=\"text-gray-500\">{$label}:</span> {$formattedOld} → <strong>{$formattedNew}</strong>";
        }

        if (empty($changes)) {
            return '<span class="text-gray-400">Brak istotnych zmian</span>';
        }

        $summary = implode('<br>', array_slice($changes, 0, 2));

        if (count($changes) > 2) {
            $remaining = count($changes) - 2;
            $summary .= '<br><span class="text-xs text-gray-400">+ '.$remaining.' więcej...</span>';
        }

        return $summary;
    }

    /**
     * Build schema for detail modal
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    private static function getDetailsSchema(AuditLog $record): array
    {
        $sections = [
            Section::make('Informacje podstawowe')
                ->schema([
                    TextEntry::make('created_at')
                        ->label('Data zdarzenia')
                        ->dateTime('d.m.Y H:i:s'),

                    TextEntry::make('event')
                        ->label('Typ zdarzenia')
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            AuditLog::EVENT_CREATED => 'Utworzono',
                            AuditLog::EVENT_UPDATED => 'Zaktualizowano',
                            AuditLog::EVENT_DELETED => 'Usunięto',
                            default => ucfirst($state),
                        })
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            AuditLog::EVENT_CREATED => 'success',
                            AuditLog::EVENT_UPDATED => 'info',
                            AuditLog::EVENT_DELETED => 'danger',
                            default => 'gray',
                        }),

                    TextEntry::make('user.name')
                        ->label('Wykonujący')
                        ->placeholder('System'),

                    TextEntry::make('ip_address')
                        ->label('Adres IP')
                        ->placeholder('N/A'),
                ])
                ->columns(2),
        ];

        if ($record->event === AuditLog::EVENT_CREATED) {
            $sections[] = self::getCreatedSection($record);
        } elseif ($record->event === AuditLog::EVENT_UPDATED) {
            $sections[] = self::getUpdatedSection($record);
        } elseif ($record->event === AuditLog::EVENT_DELETED) {
            $sections[] = self::getDeletedSection($record);
        }

        return $sections;
    }

    private static function getCreatedSection(AuditLog $record): Section
    {
        $values = self::filterMeaningfulFields($record->new_values ?? []);
        $entries = [];

        foreach ($values as $field => $value) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            $formatted = self::formatFieldValue($field, $value, short: false);

            $entries[] = TextEntry::make("new_values.{$field}")
                ->label($label)
                ->state($formatted)
                ->html();
        }

        return Section::make('Utworzone wartości')
            ->schema($entries)
            ->columns(2);
    }

    private static function getUpdatedSection(AuditLog $record): Section
    {
        $oldValues = $record->old_values ?? [];
        $newValues = $record->new_values ?? [];
        $entries = [];

        foreach ($newValues as $field => $newValue) {
            if (! isset(self::FIELD_LABELS[$field])) {
                continue;
            }

            $oldValue = $oldValues[$field] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $label = self::FIELD_LABELS[$field];
            $formattedOld = self::formatFieldValue($field, $oldValue, short: false);
            $formattedNew = self::formatFieldValue($field, $newValue, short: false);

            $entries[] = TextEntry::make("change.{$field}")
                ->label($label)
                ->state("<span class=\"text-gray-500\">{$formattedOld}</span> → <strong>{$formattedNew}</strong>")
                ->html();
        }

        if (empty($entries)) {
            $entries[] = TextEntry::make('no_changes')
                ->label('')
                ->state('Brak istotnych zmian');
        }

        return Section::make('Zmiany')
            ->schema($entries)
            ->columns(1);
    }

    private static function getDeletedSection(AuditLog $record): Section
    {
        $values = self::filterMeaningfulFields($record->old_values ?? []);
        $entries = [];

        foreach ($values as $field => $value) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            $formatted = self::formatFieldValue($field, $value, short: false);

            $entries[] = TextEntry::make("old_values.{$field}")
                ->label($label)
                ->state($formatted)
                ->html();
        }

        return Section::make('Usunięte wartości')
            ->schema($entries)
            ->columns(2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function filterMeaningfulFields(?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $allowedFields = array_keys(self::FIELD_LABELS);

        return array_filter(
            $values,
            fn ($key) => in_array($key, $allowedFields),
            ARRAY_FILTER_USE_KEY
        );
    }

    private static function formatFieldValue(string $key, mixed $value, bool $short = false): string
    {
        if ($value === null) {
            return '<span class="text-gray-400">—</span>';
        }

        return match ($key) {
            'customer_id', 'staff_id' => self::formatUserId((int) $value),
            'service_id' => self::formatServiceId((int) $value),
            'appointment_date' => self::formatDate($value),
            'start_time', 'end_time' => self::formatTime($value),
            'completed_at', 'cancelled_at' => self::formatDateTime($value),
            'status' => self::formatStatus($value),
            'service_location_type' => self::formatServiceLocationType($value),
            'notes', 'cancellation_reason', 'location_address' => self::formatText($value, $short),
            default => htmlspecialchars((string) $value),
        };
    }

    private static function formatUserId(int $userId): string
    {
        $user = User::find($userId);

        return $user
            ? htmlspecialchars($user->name)
            : "<span class=\"text-gray-400\">Użytkownik #{$userId}</span>";
    }

    private static function formatServiceId(int $serviceId): string
    {
        $service = Service::find($serviceId);

        return $service
            ? htmlspecialchars($service->name)
            : "<span class=\"text-gray-400\">Usługa #{$serviceId}</span>";
    }

    private static function formatDate(mixed $value): string
    {
        if (empty($value)) {
            return '<span class="text-gray-400">—</span>';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y');
        } catch (\Exception) {
            return htmlspecialchars((string) $value);
        }
    }

    private static function formatTime(mixed $value): string
    {
        if (empty($value)) {
            return '<span class="text-gray-400">—</span>';
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Exception) {
            return htmlspecialchars((string) $value);
        }
    }

    private static function formatDateTime(mixed $value): string
    {
        if (empty($value)) {
            return '<span class="text-gray-400">—</span>';
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (\Exception) {
            return htmlspecialchars((string) $value);
        }
    }

    private static function formatStatus(string $value): string
    {
        return AppointmentStatus::tryFrom($value)?->label() ?? htmlspecialchars($value);
    }

    private static function formatServiceLocationType(mixed $value): string
    {
        if (empty($value)) {
            return '<span class="text-gray-400">—</span>';
        }

        try {
            $types = app(SettingsManager::class)->get('booking_wizard.service_location_types', []);

            foreach ($types as $type) {
                if (isset($type['name']) && $type['name'] === $value) {
                    $name = htmlspecialchars($type['name']);
                    $desc = isset($type['description']) ? ' <span class="text-xs text-gray-500">('.htmlspecialchars($type['description']).')</span>' : '';

                    return $name.$desc;
                }
            }

            return htmlspecialchars($value);
        } catch (\Exception) {
            return htmlspecialchars((string) $value);
        }
    }

    private static function formatText(mixed $value, bool $short = false): string
    {
        if (empty($value)) {
            return '<span class="text-gray-400">—</span>';
        }

        $text = htmlspecialchars((string) $value);

        if ($short && mb_strlen($text) > 50) {
            return mb_substr($text, 0, 50).'...';
        }

        return $text;
    }
}
