<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SmsSuppressionResource\Pages;
use App\Models\SmsSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SmsSuppressionResource extends BaseResource
{
    protected static ?string $model = SmsSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-x-circle';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Wykluczenia SMS';

    protected static ?string $modelLabel = 'Wykluczenie SMS';

    protected static ?string $pluralModelLabel = 'Wykluczenia SMS';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Szczegóły wykluczenia')
                ->schema([
                    Forms\Components\TextInput::make('phone')
                        ->label('Numer telefonu')
                        ->tel()
                        ->required()
                        ->placeholder('+48501234567')
                        ->helperText('Format międzynarodowy (+48...)'),

                    Forms\Components\Select::make('reason')
                        ->label('Powód wykluczenia')
                        ->required()
                        ->options([
                            'invalid_number' => 'Nieprawidłowy numer',
                            'opted_out' => 'Rezygnacja użytkownika',
                            'failed_repeatedly' => 'Wielokrotny błąd wysyłki',
                            'manual' => 'Ręczne wykluczenie',
                        ])
                        ->helperText('Powód dodania do listy wykluczeń'),

                    Forms\Components\DateTimePicker::make('suppressed_at')
                        ->label('Data wykluczenia')
                        ->default(now())
                        ->required()
                        ->helperText('Kiedy ten numer został wykluczony'),
                ])
                ->columns(3),

            Section::make('Ostrzeżenie')
                ->schema([
                    Forms\Components\Placeholder::make('warning')
                        ->content('Wykluczone numery NIE otrzymają żadnych automatycznych SMS z systemu. Usuń z tej listy, aby ponownie włączyć wysyłkę.')
                        ->extraAttributes([
                            'class' => 'text-sm text-yellow-600 dark:text-yellow-400',
                        ]),
                ])
                ->collapsed(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Numer telefonu')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Powód')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'invalid_number' => 'danger',
                        'opted_out' => 'warning',
                        'failed_repeatedly' => 'gray',
                        'manual' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'invalid_number' => 'Nieprawidłowy numer',
                        'opted_out' => 'Rezygnacja użytkownika',
                        'failed_repeatedly' => 'Wielokrotny błąd',
                        'manual' => 'Ręczne',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('suppressed_at')
                    ->label('Data wykluczenia')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Powód')
                    ->options([
                        'invalid_number' => 'Nieprawidłowy numer',
                        'opted_out' => 'Rezygnacja użytkownika',
                        'failed_repeatedly' => 'Wielokrotny błąd',
                        'manual' => 'Ręczne',
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),

                Actions\DeleteAction::make()
                    ->label('Usuń')
                    ->requiresConfirmation()
                    ->modalHeading('Usuń numer z listy wykluczeń')
                    ->modalDescription('Ten numer będzie mógł ponownie otrzymywać SMS. Czy na pewno chcesz kontynuować?')
                    ->modalSubmitActionLabel('Tak, usuń z listy wykluczeń')
                    ->successNotificationTitle('Wykluczenie numeru zostało usunięte'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone wykluczenia')
                        ->requiresConfirmation()
                        ->modalHeading('Usuń zaznaczone z listy wykluczeń')
                        ->modalDescription('Zaznaczone numery będą mogły ponownie otrzymywać SMS. Czy na pewno?')
                        ->modalSubmitActionLabel('Tak, usuń wszystkie zaznaczone')
                        ->successNotificationTitle('Zaznaczone wykluczenia zostały usunięte'),

                    Actions\BulkAction::make('export')
                        ->label('Eksportuj zaznaczone')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            $filename = 'wykluczenia-sms-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Telefon', 'Powód', 'Data wykluczenia', 'Utworzono']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->phone,
                                        $record->reason,
                                        $record->suppressed_at->format('Y-m-d H:i:s'),
                                        $record->created_at->format('Y-m-d H:i:s'),
                                    ]);
                                }

                                fclose($file);
                            };

                            return response()->stream($callback, 200, $headers);
                        }),
                ]),
            ])
            ->defaultSort('suppressed_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsSuppressions::route('/'),
            'create' => Pages\CreateSmsSuppression::route('/create'),
            'edit' => Pages\EditSmsSuppression::route('/{record}/edit'),
        ];
    }

    /**
     * Restrict access to super-admins only (global model, not tenant-scoped).
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('communication.manage_suppressions') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('communication.manage_suppressions') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('communication.manage_suppressions') ?? false;
    }
}
