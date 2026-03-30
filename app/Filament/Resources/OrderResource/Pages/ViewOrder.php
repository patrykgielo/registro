<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dane klienta')
                ->columns(2)
                ->schema([
                    TextEntry::make('customer_type')
                        ->label('Typ klienta')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'natural_person' => 'Osoba fizyczna',
                            'business' => 'Firma',
                            default => '—',
                        }),

                    TextEntry::make('customer_name')
                        ->label('Imię i nazwisko')
                        ->getStateUsing(fn (Order $record): string => trim("{$record->customer_first_name} {$record->customer_last_name}")),

                    TextEntry::make('customer_email')
                        ->label('Email')
                        ->copyable(),

                    TextEntry::make('customer_phone')
                        ->label('Telefon')
                        ->placeholder('—'),

                    TextEntry::make('customer_pesel')
                        ->label('PESEL')
                        ->placeholder('—')
                        ->visible(fn (Order $record): bool => $record->customer_type === 'natural_person'),
                ]),

            Section::make('Dane firmy')
                ->columns(2)
                ->visible(fn (Order $record): bool => $record->customer_type === 'business')
                ->schema([
                    TextEntry::make('invoice_company_name')
                        ->label('Nazwa firmy')
                        ->placeholder('—'),

                    TextEntry::make('invoice_nip')
                        ->label('NIP')
                        ->placeholder('—'),

                    TextEntry::make('company_regon')
                        ->label('REGON')
                        ->placeholder('—'),

                    TextEntry::make('company_krs')
                        ->label('KRS')
                        ->placeholder('—'),

                    TextEntry::make('company_contact_name')
                        ->label('Osoba podpisująca umowę')
                        ->placeholder('—'),

                    TextEntry::make('signatory_id_number')
                        ->label('PESEL / dowód podpisującego')
                        ->placeholder('—'),

                    TextEntry::make('pickup_person_name')
                        ->label('Osoba odbierająca sprzęt')
                        ->placeholder('Taka sama jak podpisująca')
                        ->columnSpanFull(),

                    TextEntry::make('pickup_person_id_number')
                        ->label('Dowód osoby odbierającej')
                        ->placeholder('—')
                        ->visible(fn (Order $record): bool => filled($record->pickup_person_name)),
                ]),

            Section::make('Adres')
                ->columns(2)
                ->schema([
                    TextEntry::make('street')
                        ->label('Ulica i numer')
                        ->getStateUsing(fn (Order $record): string => trim(
                            implode(' ', array_filter([
                                $record->customer_street,
                                $record->customer_building,
                                $record->customer_apartment ? "/{$record->customer_apartment}" : null,
                            ]))
                        ))
                        ->placeholder('—'),

                    TextEntry::make('customer_city')
                        ->label('Miasto')
                        ->placeholder('—'),

                    TextEntry::make('customer_postal_code')
                        ->label('Kod pocztowy')
                        ->placeholder('—'),
                ]),

            Section::make('Kaucja')
                ->columns(2)
                ->visible(fn (Order $record): bool => (float) $record->deposit_amount > 0)
                ->schema([
                    TextEntry::make('deposit_amount')
                        ->label('Kwota kaucji')
                        ->money('PLN'),

                    TextEntry::make('deposit_status')
                        ->label('Status kaucji')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'not_required' => 'gray',
                            'pending' => 'warning',
                            'collected' => 'success',
                            'returned' => 'gray',
                            'partial_return' => 'info',
                            'forfeited' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'not_required' => 'Nie wymagana',
                            'pending' => 'Oczekuje',
                            'collected' => 'Pobrana',
                            'returned' => 'Zwrócona',
                            'partial_return' => 'Zwrot częściowy',
                            'forfeited' => 'Przepadła',
                            default => $state,
                        }),

                    TextEntry::make('deposit_collected_at')
                        ->label('Pobrano dnia')
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('deposit_returned_at')
                        ->label('Zwrócono dnia')
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('deposit_notes')
                        ->label('Notatka dot. kaucji')
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),

            Section::make('Zgody i RODO')
                ->columns(2)
                ->schema([
                    TextEntry::make('rodo_accepted_at')
                        ->label('RODO zaakceptowane')
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('terms_accepted_at')
                        ->label('Regulamin zaakceptowany')
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),

                    TextEntry::make('withdrawal_exclusion_accepted_at')
                        ->label('Zrzeczenie prawa do odstąpienia')
                        ->dateTime('d.m.Y H:i')
                        ->placeholder('—'),
                ]),
        ]);
    }
}
