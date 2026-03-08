<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RentalItemResource\Pages;
use App\Models\RentalItem;
use App\Support\TenantFeature;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class RentalItemResource extends BaseResource
{
    protected static ?string $model = RentalItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|UnitEnum|null $navigationGroup = 'rentals';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Przedmiot';

    protected static ?string $pluralModelLabel = 'Przedmioty do wypożyczenia';

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = TenantFeature::currentTenant();

        return $tenant?->supportsRentals() ?? false;
    }

    public static function form(Schema $schema): Schema
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
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Automatycznie generowany z nazwy'),

                        Forms\Components\Select::make('rental_category_id')
                            ->label('Kategoria')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Opcjonalne — możesz nie przypisywać kategorii'),

                        Forms\Components\Textarea::make('description')
                            ->label('Opis')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('quantity_total')
                            ->label('Ilość dostępna')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->helperText('Łączna liczba sztuk w magazynie'),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Kolejność')
                            ->required()
                            ->numeric()
                            ->default(0),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywny')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Cennik')
                    ->schema([
                        Forms\Components\TextInput::make('price_per_day')
                            ->label('Cena za dzień')
                            ->required()
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0),

                        Forms\Components\TextInput::make('price_per_hour')
                            ->label('Cena za godzinę')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->helperText('Opcjonalne'),

                        Forms\Components\TextInput::make('price_per_week')
                            ->label('Cena za tydzień')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->helperText('Opcjonalne'),

                        Forms\Components\TextInput::make('deposit_amount')
                            ->label('Kaucja')
                            ->numeric()
                            ->prefix('PLN')
                            ->minValue(0)
                            ->helperText('Opcjonalne'),
                    ])
                    ->columns(2),

                Section::make('Zdjęcie')
                    ->schema([
                        Forms\Components\FileUpload::make('featured_image')
                            ->label('Zdjęcie przedmiotu')
                            ->disk('public')
                            ->directory('rentals/items')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(4096)
                            ->imagePreviewHeight('200')
                            ->extraAttributes(['class' => 'no-edit-icon']),
                    ])
                    ->collapsed(),

                Section::make('Specyfikacja techniczna')
                    ->schema([
                        Forms\Components\KeyValue::make('specifications')
                            ->label('Parametry')
                            ->keyLabel('Parametr')
                            ->valueLabel('Wartość')
                            ->addActionLabel('Dodaj parametr')
                            ->columnSpanFull()
                            ->helperText('np. Moc: 1200W, Waga: 5kg'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategoria')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quantity_total')
                    ->label('Ilość')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('price_per_day')
                    ->label('Cena/dzień')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywny')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Aktualizacja')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('rental_category_id')
                    ->label('Kategoria')
                    ->relationship('category', 'name'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktywny'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentalItems::route('/'),
            'create' => Pages\CreateRentalItem::route('/create'),
            'edit' => Pages\EditRentalItem::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
