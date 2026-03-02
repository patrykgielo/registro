<?php

namespace App\Filament\Resources\ServiceAreaWaitlists\Schemas;

use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceAreaWaitlistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dane kontaktowe')
                    ->description('Informacje podane przez klienta')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('name')
                            ->label('Imię i nazwisko')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('phone')
                            ->label('Telefon')
                            ->tel()
                            ->maxLength(20),
                    ])
                    ->columns(2),

                Section::make('Żądana lokalizacja')
                    ->description('Adres spoza obszaru obsługi')
                    ->schema([
                        Forms\Components\Textarea::make('requested_address')
                            ->label('Adres')
                            ->required()
                            ->disabled()
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('requested_latitude')
                            ->label('Szerokość geograficzna')
                            ->required()
                            ->numeric()
                            ->disabled(),

                        Forms\Components\TextInput::make('requested_longitude')
                            ->label('Długość geograficzna')
                            ->required()
                            ->numeric()
                            ->disabled(),

                        Forms\Components\TextInput::make('requested_place_id')
                            ->label('Google Place ID')
                            ->disabled()
                            ->columnSpanFull()
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Section::make('Analiza lokalizacji')
                    ->description('Automatycznie wyliczona najbliższa strefa')
                    ->schema([
                        Forms\Components\TextInput::make('nearest_area_city')
                            ->label('Najbliższe miasto')
                            ->disabled()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('distance_to_nearest_area_km')
                            ->label('Odległość od najbliższej strefy')
                            ->numeric()
                            ->suffix('km')
                            ->disabled(),
                    ])
                    ->columns(2),

                Section::make('Zarządzanie statusem')
                    ->description('Obsługa zgłoszenia')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Oczekujące',
                                'contacted' => 'Skontaktowano',
                                'area_added' => 'Strefa dodana',
                                'declined' => 'Odrzucone',
                            ])
                            ->default('pending')
                            ->required()
                            ->native(false),

                        Forms\Components\DateTimePicker::make('notified_at')
                            ->label('Data powiadomienia')
                            ->native(false)
                            ->seconds(false),

                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Notatki admina')
                            ->helperText('Wewnętrzne uwagi do zgłoszenia')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
