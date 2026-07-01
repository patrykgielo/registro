<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EmailSuppressionResource\Pages;
use App\Models\EmailSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmailSuppressionResource extends BaseResource
{
    protected static ?string $model = EmailSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Wykluczenia Email';

    protected static ?string $modelLabel = 'Wykluczenie Email';

    protected static ?string $pluralModelLabel = 'Wykluczenia Email';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Szczegóły wykluczenia')
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Adres e-mail')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->placeholder('user@example.com')
                        ->helperText('Adres e-mail do wykluczenia z wysyłki')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('reason')
                        ->label('Powód wykluczenia')
                        ->required()
                        ->options([
                            'bounced' => 'Odrzucony (nieprawidłowy adres e-mail)',
                            'complained' => 'Zgłoszony jako spam',
                            'unsubscribed' => 'Wypisany (rezygnacja użytkownika)',
                            'manual' => 'Ręczne wykluczenie (decyzja administracyjna)',
                        ])
                        ->helperText('Powód wykluczenia tego adresu e-mail'),

                    Forms\Components\DateTimePicker::make('suppressed_at')
                        ->label('Data wykluczenia')
                        ->default(now())
                        ->required()
                        ->helperText('Kiedy ten adres e-mail został wykluczony'),
                ])
                ->columns(2),

            Section::make('Ostrzeżenie')
                ->schema([
                    Forms\Components\Placeholder::make('warning')
                        ->content('Wykluczone adresy NIE otrzymają żadnych automatycznych e-maili z systemu. Usuń z tej listy, aby ponownie włączyć wysyłkę.')
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
                Tables\Columns\TextColumn::make('email')
                    ->label('Adres e-mail')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Powód')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'bounced' => 'danger',
                        'complained' => 'warning',
                        'unsubscribed' => 'info',
                        'manual' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'bounced' => 'Odrzucony',
                        'complained' => 'Zgłoszony jako spam',
                        'unsubscribed' => 'Wypisany',
                        'manual' => 'Ręczne',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('suppressed_at')
                    ->label('Data wykluczenia')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Powód')
                    ->options([
                        'bounced' => 'Odrzucony',
                        'complained' => 'Zgłoszony jako spam',
                        'unsubscribed' => 'Wypisany',
                        'manual' => 'Ręczne',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('suppressed_at')
                    ->form([
                        Forms\Components\DatePicker::make('suppressed_from')
                            ->label('Wykluczono od'),
                        Forms\Components\DatePicker::make('suppressed_until')
                            ->label('Wykluczono do'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['suppressed_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('suppressed_at', '>=', $date),
                            )
                            ->when(
                                $data['suppressed_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('suppressed_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Actions\EditAction::make(),

                Actions\DeleteAction::make()
                    ->label('Usuń')
                    ->requiresConfirmation()
                    ->modalHeading('Usuń e-mail z listy wykluczeń')
                    ->modalDescription('Ten adres będzie mógł ponownie otrzymywać e-maile. Czy na pewno chcesz kontynuować?')
                    ->modalSubmitActionLabel('Tak, usuń z listy wykluczeń')
                    ->successNotificationTitle('Wykluczenie e-maila zostało usunięte')
                    ->action(function (EmailSuppression $record): void {
                        $record->delete();
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone wykluczenia')
                        ->requiresConfirmation()
                        ->modalHeading('Usuń zaznaczone z listy wykluczeń')
                        ->modalDescription('Zaznaczone adresy będą mogły ponownie otrzymywać e-maile. Czy na pewno?')
                        ->modalSubmitActionLabel('Tak, usuń wszystkie zaznaczone')
                        ->successNotificationTitle('Zaznaczone wykluczenia zostały usunięte'),

                    Actions\BulkAction::make('export')
                        ->label('Eksportuj zaznaczone')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            $filename = 'wykluczenia-email-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Email', 'Powód', 'Data wykluczenia', 'Utworzono']);

                                foreach ($records as $record) {
                                    fputcsv($file, [
                                        $record->id,
                                        $record->email,
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailSuppressions::route('/'),
            'create' => Pages\CreateEmailSuppression::route('/create'),
            'edit' => Pages\EditEmailSuppression::route('/{record}/edit'),
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
