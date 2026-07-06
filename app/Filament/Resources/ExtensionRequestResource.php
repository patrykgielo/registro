<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ExtensionRequestStatus;
use App\Exceptions\RentalUnavailableException;
use App\Filament\Resources\ExtensionRequestResource\Pages;
use App\Models\OrderItemExtensionRequest;
use App\Services\RentalExtensionService;
use App\Support\TenantFeature;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExtensionRequestResource extends BaseResource
{
    protected static ?string $model = OrderItemExtensionRequest::class;

    protected static ?string $module = 'rentals';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Wnioski o przedłużenie';

    protected static ?string $modelLabel = 'Wniosek o przedłużenie';

    protected static ?string $pluralModelLabel = 'Wnioski o przedłużenie';

    protected static string|UnitEnum|null $navigationGroup = 'rentals';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $tenant = TenantFeature::currentTenant();

        if ($tenant === null) {
            return null;
        }

        $count = OrderItemExtensionRequest::where('organization_id', $tenant->id)
            ->pending()
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                $tenant = TenantFeature::currentTenant();
                if ($tenant) {
                    $query->where('organization_id', $tenant->id);
                }

                return $query->with(['orderItem.service', 'order', 'requestedBy']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('order.order_number')
                    ->label('Nr zamówienia')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('orderItem.service_name')
                    ->label('Pozycja')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('requestedBy.first_name')
                    ->label('Klient')
                    ->formatStateUsing(fn ($state, OrderItemExtensionRequest $record) => trim(
                        ($record->requestedBy?->first_name ?? '').' '.($record->requestedBy?->last_name ?? '')
                    ))
                    ->searchable(['requested_by_user_id']),

                Tables\Columns\TextColumn::make('original_end_date')
                    ->label('Aktualna data końca')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('requested_end_date')
                    ->label('Żądana data końca')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('additional_days')
                    ->label('Dni')
                    ->suffix(' dni')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('additional_amount')
                    ->label('Kwota')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ExtensionRequestStatus $state) => $state->label())
                    ->color(fn (ExtensionRequestStatus $state) => $state->badgeColor()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data wniosku')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ExtensionRequestStatus::cases())
                        ->mapWithKeys(fn (ExtensionRequestStatus $s) => [$s->value => $s->label()])
                        ->toArray())
                    ->default(ExtensionRequestStatus::Pending->value),
            ])
            ->recordActions([
                Actions\Action::make('approve')
                    ->label('Zatwierdź')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Zatwierdź przedłużenie')
                    ->modalDescription(fn (OrderItemExtensionRequest $record) => sprintf(
                        'Czy na pewno zatwierdzić przedłużenie pozycji "%s" do %s (+%d dni, +%s zł)?',
                        $record->orderItem?->service_name,
                        $record->requested_end_date->format('d.m.Y'),
                        $record->additional_days,
                        number_format((float) $record->additional_amount, 2, ',', ' ')
                    ))
                    ->visible(fn (OrderItemExtensionRequest $record) => $record->status === ExtensionRequestStatus::Pending)
                    ->action(function (OrderItemExtensionRequest $record) {
                        try {
                            app(RentalExtensionService::class)->approve($record, auth()->user());

                            Notification::make()
                                ->title('Przedłużenie zatwierdzone')
                                ->success()
                                ->send();
                        } catch (RentalUnavailableException|\RuntimeException $e) {
                            Notification::make()
                                ->title('Nie można zatwierdzić')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Actions\Action::make('reject')
                    ->label('Odrzuć')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OrderItemExtensionRequest $record) => $record->status === ExtensionRequestStatus::Pending)
                    ->form([
                        Textarea::make('rejection_reason')
                            ->label('Powód odrzucenia')
                            ->required()
                            ->minLength(5)
                            ->rows(3),
                    ])
                    ->action(function (OrderItemExtensionRequest $record, array $data) {
                        try {
                            app(RentalExtensionService::class)->reject(
                                $record,
                                auth()->user(),
                                $data['rejection_reason']
                            );

                            Notification::make()
                                ->title('Wniosek odrzucony')
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Nie można odrzucić')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Brak wniosków o przedłużenie')
            ->emptyStateDescription('Wnioski od klientów pojawią się tutaj.')
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExtensionRequests::route('/'),
        ];
    }
}
