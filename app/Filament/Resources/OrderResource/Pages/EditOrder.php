<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use App\Services\Order\OrderProtocolPdfService;
use App\Services\Order\OrderService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('confirm')
                ->label('Potwierdź')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === 'paid')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $this->record->status()->transitionTo('confirmed');
                        Notification::make()->success()->title('Zamówienie potwierdzone')->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()->title('Nie można potwierdzić zamówienia')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('mark_in_progress')
                ->label('Wydano klientowi')
                ->icon('heroicon-o-truck')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === 'confirmed')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $this->record->status()->transitionTo('in_progress');
                        Notification::make()->success()->title('Status zaktualizowany')->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()->title('Nie można zmienić statusu')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('complete')
                ->label('Sprzęt zwrócony')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === 'in_progress')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $this->record->status()->transitionTo('completed');
                        Notification::make()->success()->title('Zamówienie zakończone')->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()->title('Nie można zakończyć zamówienia')->body($e->getMessage())->send();
                    }
                }),

            // ->url()->openUrlInNewTab(), NOT ->action(fn () => $pdf->download(...)) — see
            // OrderResource.php's own comment on the same two actions for why.
            Actions\Action::make('handover_protocol')
                ->label('Protokół wydania (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => app(OrderProtocolPdfService::class)->canDownloadHandoverProtocol($this->record))
                ->url(fn (): string => route('orders.protocol.handover', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('return_protocol')
                ->label('Protokół zwrotu (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => app(OrderProtocolPdfService::class)->canDownloadReturnProtocol($this->record))
                ->url(fn (): string => route('orders.protocol.return', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('cancel')
                ->label('Anuluj')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, ['pending_payment', 'paid', 'confirmed']))
                ->form([
                    Textarea::make('reason')
                        ->label('Powód anulowania')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        app(OrderService::class)->cancel($this->record, $data['reason']);
                        Notification::make()->success()->title('Zamówienie anulowane')->send();
                    } catch (\Exception $e) {
                        Notification::make()->danger()->title('Nie można anulować zamówienia')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('collect_deposit')
                ->label('Pobrano kaucję')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => $this->record->deposit_status === 'pending')
                ->form([
                    Textarea::make('deposit_notes')
                        ->label('Notatka (opcjonalnie)')
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'deposit_status' => 'collected',
                        'deposit_collected_at' => now(),
                        'deposit_notes' => $data['deposit_notes'] ?? null,
                    ]);
                    Notification::make()->success()->title('Kaucja pobrana')->send();
                }),

            Actions\Action::make('return_deposit')
                ->label('Zwrócono kaucję')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('info')
                ->visible(fn (): bool => $this->record->deposit_status === 'collected')
                ->form([
                    Textarea::make('deposit_notes')
                        ->label('Notatka (opcjonalnie)')
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'deposit_status' => 'returned',
                        'deposit_returned_at' => now(),
                        'deposit_notes' => $data['deposit_notes'] ?? null,
                    ]);
                    Notification::make()->success()->title('Kaucja zwrócona')->send();
                }),

            Actions\Action::make('forfeit_deposit')
                ->label('Kaucja przepadła')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->deposit_status === 'collected')
                ->requiresConfirmation()
                ->modalHeading('Kaucja przepada')
                ->modalDescription('Czy na pewno chcesz oznaczyć, że kaucja przepadła? Ta akcja jest nieodwracalna.')
                ->form([
                    Textarea::make('deposit_notes')
                        ->label('Powód przepadku')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'deposit_status' => 'forfeited',
                        'deposit_notes' => $data['deposit_notes'],
                    ]);
                    Notification::make()->warning()->title('Kaucja przepadła')->send();
                }),

            Actions\ViewAction::make()
                ->label('Podgląd'),
        ];
    }
}
