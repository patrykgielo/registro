<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\ReminderConfigResource\Pages;
use App\Models\ReminderConfig;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class ReminderConfigResource extends BaseResource
{
    protected static ?string $model = ReminderConfig::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Przypomnienia';

    protected static ?string $modelLabel = 'Przypomnienie';

    protected static ?string $pluralModelLabel = 'Przypomnienia';

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::enabled()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::enabled()->count() > 0 ? 'success' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Podstawowe ustawienia')
                ->description('Konfiguracja podstawowa przypomnienia')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nazwa')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('np. Przypomnienie SMS 24h przed wizytą')
                        ->helperText('Nazwa wyświetlana w panelu admina')
                        ->columnSpan(2),

                    Forms\Components\Select::make('channel')
                        ->label('Kanał')
                        ->required()
                        ->options([
                            'sms' => '📱 SMS',
                            'email' => '📧 Email',
                        ])
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('template_key', null))
                        ->helperText('Wybierz kanał wysyłki'),

                    Forms\Components\TextInput::make('priority')
                        ->label('Priorytet')
                        ->numeric()
                        ->default(0)
                        ->helperText('Niższy = wcześniejsze wykonanie'),

                    Forms\Components\Toggle::make('enabled')
                        ->label('Włączone')
                        ->default(true)
                        ->helperText('Wyłącz aby tymczasowo zatrzymać wysyłkę'),
                ])
                ->columns(3),

            Section::make('Timing')
                ->description('Kiedy wysłać przypomnienie')
                ->schema([
                    Forms\Components\Select::make('trigger_type')
                        ->label('Typ wyzwalacza')
                        ->required()
                        ->options([
                            'before' => '⏰ Przed wizytą (przypomnienie)',
                            'after' => '📝 Po wizycie (follow-up)',
                        ])
                        ->default('before')
                        ->native(false)
                        ->helperText('Przed = reminder, Po = follow-up'),

                    Forms\Components\TextInput::make('trigger_hours')
                        ->label('Godziny')
                        ->numeric()
                        ->required()
                        ->default(24)
                        ->minValue(0)
                        ->maxValue(168) // max 7 days
                        ->suffix('h')
                        ->helperText('Ile godzin przed/po wizycie'),

                    Forms\Components\TextInput::make('trigger_minutes')
                        ->label('Minuty')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(59)
                        ->suffix('min')
                        ->helperText('Dodatkowe minuty (opcjonalne)'),

                    Forms\Components\TextInput::make('window_buffer_minutes')
                        ->label('Bufor okna')
                        ->numeric()
                        ->default(60)
                        ->minValue(15)
                        ->maxValue(120)
                        ->suffix('min')
                        ->helperText('Bufor czasowy dla schedulera (default 60 min)'),
                ])
                ->columns(2),

            Section::make('Szablon')
                ->description('Wybierz szablon wiadomości')
                ->schema([
                    Forms\Components\Select::make('template_key')
                        ->label('Klucz szablonu')
                        ->required()
                        ->options(fn (callable $get) => TemplateKey::reminderOptions($get('channel') ?? 'sms'))
                        ->searchable()
                        ->helperText('Dostępne szablony zależą od wybranego kanału'),
                ])
                ->columns(1),

            Section::make('Ustawienia zaawansowane')
                ->description('Dodatkowa konfiguracja (opcjonalna)')
                ->schema([
                    Forms\Components\KeyValue::make('settings')
                        ->label('Ustawienia JSON')
                        ->keyLabel('Klucz')
                        ->valueLabel('Wartość')
                        ->addButtonLabel('Dodaj ustawienie')
                        ->helperText('Dodatkowe ustawienia jako klucz-wartość'),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('enabled')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Kanał')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sms' => 'success',
                        'email' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sms' => '📱 SMS',
                        'email' => '📧 Email',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->label('Typ')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'before' => 'warning',
                        'after' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'before' => 'Przed',
                        'after' => 'Po',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('timing')
                    ->label('Czas')
                    ->state(fn (ReminderConfig $record): string => $record->getTimingDescription())
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('template_key')
                    ->label('Szablon')
                    ->searchable()
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string|TemplateKey $state): string => $state instanceof TemplateKey ? $state->label() : (TemplateKey::tryFrom($state)?->label() ?? $state)),

                Tables\Columns\TextColumn::make('logs_count')
                    ->label('Wysłano')
                    ->counts('logs')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Aktualizacja')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Kanał')
                    ->options([
                        'sms' => 'SMS',
                        'email' => 'Email',
                    ]),

                Tables\Filters\SelectFilter::make('trigger_type')
                    ->label('Typ')
                    ->options([
                        'before' => 'Przed wizytą',
                        'after' => 'Po wizycie',
                    ]),

                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Status')
                    ->placeholder('Wszystkie')
                    ->trueLabel('Włączone')
                    ->falseLabel('Wyłączone'),
            ])
            ->recordActions([
                Actions\Action::make('toggle')
                    ->label(fn (ReminderConfig $record): string => $record->enabled ? 'Wyłącz' : 'Włącz')
                    ->icon(fn (ReminderConfig $record): string => $record->enabled ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (ReminderConfig $record): string => $record->enabled ? 'warning' : 'success')
                    ->action(function (ReminderConfig $record): void {
                        $record->update(['enabled' => ! $record->enabled]);
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (ReminderConfig $record): string => $record->enabled
                        ? 'Wyłączyć przypomnienie?'
                        : 'Włączyć przypomnienie?')
                    ->modalDescription(fn (ReminderConfig $record): string => $record->enabled
                        ? 'Przypomnienie przestanie być wysyłane.'
                        : 'Przypomnienie zacznie być wysyłane.'),

                Actions\EditAction::make(),

                Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('enable')
                        ->label('Włącz zaznaczone')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['enabled' => true]))
                        ->requiresConfirmation(),

                    Actions\BulkAction::make('disable')
                        ->label('Wyłącz zaznaczone')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->action(fn ($records) => $records->each->update(['enabled' => false]))
                        ->requiresConfirmation(),

                    Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('priority')
            ->reorderable('priority');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReminderConfigs::route('/'),
            'create' => Pages\CreateReminderConfig::route('/create'),
            'edit' => Pages\EditReminderConfig::route('/{record}/edit'),
        ];
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
