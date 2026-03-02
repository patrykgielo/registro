<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EmailSuppressionResource\Pages;
use App\Models\EmailSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmailSuppressionResource extends Resource
{
    protected static ?string $model = EmailSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Email Suppressions';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Suppression Details')
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Email Address')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->placeholder('user@example.com')
                        ->helperText('Email address to suppress from sending')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('reason')
                        ->label('Suppression Reason')
                        ->required()
                        ->options([
                            'bounced' => 'Bounced (email address invalid)',
                            'complained' => 'Complained (marked as spam)',
                            'unsubscribed' => 'Unsubscribed (user opt-out)',
                            'manual' => 'Manual (administrative decision)',
                        ])
                        ->helperText('Reason for suppressing this email'),

                    Forms\Components\DateTimePicker::make('suppressed_at')
                        ->label('Suppressed At')
                        ->default(now())
                        ->required()
                        ->helperText('When this email was suppressed'),
                ])
                ->columns(2),

            Section::make('Warning')
                ->schema([
                    Forms\Components\Placeholder::make('warning')
                        ->content('Suppressed emails will NOT receive any automated emails from the system. Remove from this list to re-enable sending.')
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
                    ->label('Email Address')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
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
                        'bounced' => 'Bounced',
                        'complained' => 'Complained',
                        'unsubscribed' => 'Unsubscribed',
                        'manual' => 'Manual',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('suppressed_at')
                    ->label('Suppressed At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Reason')
                    ->options([
                        'bounced' => 'Bounced',
                        'complained' => 'Complained',
                        'unsubscribed' => 'Unsubscribed',
                        'manual' => 'Manual',
                    ])
                    ->multiple(),

                Tables\Filters\Filter::make('suppressed_at')
                    ->form([
                        Forms\Components\DatePicker::make('suppressed_from')
                            ->label('Suppressed From'),
                        Forms\Components\DatePicker::make('suppressed_until')
                            ->label('Suppressed Until'),
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
                    ->label('Remove')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Email from Suppression List')
                    ->modalDescription('This will allow emails to be sent to this address again. Are you sure you want to proceed?')
                    ->modalSubmitActionLabel('Yes, Remove from Suppression List')
                    ->successNotificationTitle('Email unsuppressed')
                    ->action(function (EmailSuppression $record): void {
                        $record->delete();
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Bulk Unsuppress')
                        ->requiresConfirmation()
                        ->modalHeading('Bulk Remove from Suppression List')
                        ->modalDescription('This will allow emails to be sent to the selected addresses again. Are you sure?')
                        ->modalSubmitActionLabel('Yes, Remove All Selected')
                        ->successNotificationTitle('Selected emails unsuppressed'),

                    Actions\BulkAction::make('export')
                        ->label('Export Selected')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($records) {
                            $filename = 'email-suppressions-'.now()->format('Y-m-d-His').'.csv';
                            $headers = [
                                'Content-Type' => 'text/csv',
                                'Content-Disposition' => "attachment; filename=\"$filename\"",
                            ];

                            $callback = function () use ($records) {
                                $file = fopen('php://output', 'w');
                                fputcsv($file, ['ID', 'Email', 'Reason', 'Suppressed At', 'Created At']);

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
     * Check if user can access this resource
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage suppressions') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('manage suppressions') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('manage suppressions') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('manage suppressions') ?? false;
    }
}
