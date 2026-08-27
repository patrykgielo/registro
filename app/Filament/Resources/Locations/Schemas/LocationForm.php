<?php

declare(strict_types=1);

namespace App\Filament\Resources\Locations\Schemas;

use App\Support\TenantFeature;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Podstawowe informacje')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nazwa')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $state, callable $set) {
                                $set('slug', Str::slug($state));
                            })
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('slug')
                            ->label('Slug (URL)')
                            ->required()
                            ->maxLength(255)
                            // `locations` has UNIQUE(organization_id, slug), not a global unique
                            // on slug alone — the rule has to match. `-1` never matches a real
                            // organization_id, so a null tenant (should not happen inside this
                            // tenant-scoped panel, but the fallback must not throw or silently
                            // wave through same-tenant duplicates) makes the check a no-op; the
                            // DB constraint is still the real backstop in that case.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule->where(
                                    'organization_id',
                                    TenantFeature::currentTenant()?->id ?? -1
                                ),
                            )
                            ->helperText('Automatycznie generowany z nazwy'),

                        Forms\Components\TextInput::make('code')
                            ->label('Symbol')
                            ->maxLength(20)
                            ->helperText('Krótki skrót, np. „WAW"'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Kolejność sortowania')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Niższe liczby = wyższa pozycja na liście'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywna')
                            ->default(true)
                            ->helperText('Czy lokalizacja jest obecnie czynna?'),
                    ])
                    ->columns(2),

                Section::make('Adres i kontakt')
                    ->schema([
                        Forms\Components\TextInput::make('street')
                            ->label('Ulica')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('building')
                            ->label('Numer budynku / lokalu')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('postal_code')
                            ->label('Kod pocztowy')
                            ->maxLength(255)
                            ->placeholder('00-000'),

                        Forms\Components\TextInput::make('city')
                            ->label('Miasto')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Telefon')
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Godziny otwarcia')
                    ->description('Opcjonalnie — wyświetlane na stronie przy tej lokalizacji')
                    ->schema([
                        Forms\Components\Repeater::make('opening_hours')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label('Dni')
                                    ->required()
                                    ->maxLength(50)
                                    ->placeholder('np. Pon–Pt'),

                                Forms\Components\TextInput::make('hours')
                                    ->label('Godziny')
                                    ->required()
                                    ->maxLength(50)
                                    ->placeholder('np. 7:00–17:00 lub „Zamknięte"'),
                            ])
                            ->columns(2)
                            ->addActionLabel('Dodaj wiersz')
                            ->reorderable()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),

                Section::make('Zdjęcie siedziby i galeria')
                    ->schema([
                        Forms\Components\FileUpload::make('photo')
                            ->label('Zdjęcie siedziby')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['4:3'])
                            ->directory(fn () => 'locations/'.(TenantFeature::currentTenant()?->id ?? 'shared'))
                            ->maxSize(6144)
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Maksymalny rozmiar: 6MB'),

                        Forms\Components\FileUpload::make('gallery')
                            ->label('Galeria')
                            ->image()
                            ->multiple()
                            ->directory(fn () => 'locations/'.(TenantFeature::currentTenant()?->id ?? 'shared'))
                            ->maxSize(6144)
                            ->maxFiles(30)
                            ->reorderable()
                            ->helperText('Maksymalny rozmiar: 6MB na zdjęcie')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Lokalizacja na mapie')
                    ->description('Kliknij na mapie lub przeciągnij marker, aby ustawić dokładną pozycję')
                    ->schema([
                        Forms\Components\ViewField::make('map_picker')
                            ->view('filament.components.location-map-picker')
                            ->columnSpanFull()
                            ->dehydrated(false),

                        Forms\Components\Hidden::make('latitude'),

                        Forms\Components\Hidden::make('longitude'),
                    ])
                    ->columnSpanFull(),

                Section::make('Opis')
                    ->schema([
                        Forms\Components\Textarea::make('description')
                            ->label('Opis')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}
