<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SmsSuppressionResource\Pages;
use App\Models\SmsSuppression;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class SmsSuppressionResource extends Resource
{
    protected static ?string $model = SmsSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-x-circle';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'SMS Suppression List';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Suppression Details')
                ->schema([
                    Forms\Components\TextInput::make('phone')
                        ->label('Phone Number')
                        ->tel()
                        ->required()
                        ->placeholder('+48501234567')
                        ->helperText('International format (+48...)'),

                    Forms\Components\Select::make('reason')
                        ->label('Suppression Reason')
                        ->required()
                        ->options([
                            'invalid_number' => 'Invalid Number',
                            'opted_out' => 'Opted Out',
                            'failed_repeatedly' => 'Failed Repeatedly',
                            'manual' => 'Manual Suppression',
                        ])
                        ->helperText('Reason for adding to suppression list'),

                    Forms\Components\DateTimePicker::make('suppressed_at')
                        ->label('Suppressed At')
                        ->default(now())
                        ->required()
                        ->helperText('When this number was suppressed'),
                ])
                ->columns(3),
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
                    ->label('Phone Number')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('reason')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'invalid_number' => 'danger',
                        'opted_out' => 'warning',
                        'failed_repeatedly' => 'gray',
                        'manual' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('suppressed_at')
                    ->label('Suppressed At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Suppression Reason')
                    ->options([
                        'invalid_number' => 'Invalid Number',
                        'opted_out' => 'Opted Out',
                        'failed_repeatedly' => 'Failed Repeatedly',
                        'manual' => 'Manual',
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->label('Unsuppress')
                    ->requiresConfirmation()
                    ->modalHeading('Remove from Suppression List')
                    ->modalDescription('This phone number will be able to receive SMS again.'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Unsuppress Selected')
                        ->requiresConfirmation(),
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
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
