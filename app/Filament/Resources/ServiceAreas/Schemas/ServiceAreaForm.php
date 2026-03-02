<?php

namespace App\Filament\Resources\ServiceAreas\Schemas;

use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Podstawowe informacje')
                    ->description('Wprowadź nazwę miasta i opcjonalny opis obszaru')
                    ->schema([
                        Forms\Components\TextInput::make('city_name')
                            ->label('Nazwa miasta')
                            ->required()
                            ->maxLength(100)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('Opis')
                            ->helperText('Opcjonalny opis obszaru (np. "Greater Warsaw Area")')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Lokalizacja na mapie')
                    ->description('Ustaw środek obszaru obsługi i promień zasięgu')
                    ->schema([
                        Forms\Components\ViewField::make('map_picker')
                            ->view('filament.components.google-maps-picker')
                            ->columnSpanFull()
                            ->dehydrated(false),

                        Forms\Components\Hidden::make('latitude')
                            ->required()
                            ->default(52.2297), // Default Warsaw

                        Forms\Components\Hidden::make('longitude')
                            ->required()
                            ->default(21.0122), // Default Warsaw

                        Forms\Components\Hidden::make('radius_km')
                            ->required()
                            ->default(50), // Default 50km
                    ])
                    ->columnSpanFull(),

                Section::make('Ustawienia wyświetlania')
                    ->description('Kolor, kolejność i widoczność obszaru')
                    ->schema([
                        Forms\Components\ColorPicker::make('color_hex')
                            ->label('Kolor na mapie')
                            ->default('#4CAF50')
                            ->helperText('Kolor koła na mapie w kreatorze rezerwacji'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Kolejność sortowania')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Niższe liczby = wyższa pozycja na liście'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywny')
                            ->default(true)
                            ->helperText('Czy obszar jest obecnie obsługiwany?'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
