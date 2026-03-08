<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Filament\Support\BuilderBlocks;
use App\Models\Service;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class ServiceResource extends BaseResource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Usługa';

    protected static ?string $pluralModelLabel = 'Usługi';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // Sekcja 1: Podstawowe Informacje
                Section::make('Podstawowe informacje')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nazwa usługi')
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
                            ->unique(ignoreRecord: true)
                            ->helperText('Automatycznie generowany z nazwy'),

                        Forms\Components\Select::make('icon')
                            ->label('Ikona usługi')
                            ->options(fn () => self::getHeroiconOptions())
                            ->searchable()
                            ->default('sparkles')
                            ->helperText('Ikona wyświetlana na karcie usługi'),

                        Forms\Components\Textarea::make('excerpt')
                            ->label('Krótki opis (excerpt)')
                            ->maxLength(500)
                            ->rows(3)
                            ->helperText('Wyświetlany na liście usług (max 500 znaków)')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('duration_days')
                                    ->label('Dni')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(0)
                                    ->default(0)
                                    ->suffix('dni')
                                    ->helperText('Usługi wielodniowe nie są obsługiwane')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('duration_hours')
                                    ->label('Godziny')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(23)
                                    ->suffix('godz')
                                    ->live(onBlur: true)
                                    ->afterStateHydrated(function ($state, $set, $get, $record) {
                                        if ($record && $record->duration_minutes) {
                                            $set('duration_hours', floor($record->duration_minutes / 60));
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        $hours = (int) ($state ?? 0);
                                        $minutes = (int) ($get('duration_mins') ?? 0);
                                        $set('duration_minutes', ($hours * 60) + $minutes);
                                    })
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('duration_mins')
                                    ->label('Minuty')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(59)
                                    ->step(15)
                                    ->suffix('min')
                                    ->live(onBlur: true)
                                    ->afterStateHydrated(function ($state, $set, $get, $record) {
                                        if ($record && $record->duration_minutes) {
                                            $set('duration_mins', $record->duration_minutes % 60);
                                        }
                                    })
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        $hours = (int) ($get('duration_hours') ?? 0);
                                        $minutes = (int) ($state ?? 0);
                                        $set('duration_minutes', ($hours * 60) + $minutes);
                                    })
                                    ->dehydrated(false),
                            ])
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('duration_minutes')
                            ->default(60)
                            ->required(),

                        Forms\Components\TextInput::make('price')
                            ->label('Cena bazowa')
                            ->required()
                            ->numeric()
                            ->default(0.00)
                            ->prefix('PLN')
                            ->helperText('Podstawowa cena usługi'),

                        Forms\Components\TextInput::make('price_from')
                            ->label('Cena "od" (opcjonalnie)')
                            ->numeric()
                            ->prefix('PLN')
                            ->helperText('Jeśli cena jest zmienna, podaj cenę minimalną (np. od 150 PLN)'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktywna')
                            ->default(true)
                            ->required(),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Kolejność sortowania')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->helperText('Niższe wartości = wyżej na liście'),
                    ])
                    ->columns(2),

                // Sekcja 2: Zdjęcie wyróżniające
                Section::make('Zdjęcie wyróżniające')
                    ->schema([
                        Forms\Components\FileUpload::make('featured_image')
                            ->label('Zdjęcie wyróżniające')
                            ->disk('public')
                            ->directory('services/featured')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['16:9'])
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(6144)
                            ->imageResizeMode('cover')
                            ->imageResizeTargetWidth('1920')
                            ->imageResizeTargetHeight('1080')
                            ->imagePreviewHeight('200')
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Zalecany format: 16:9, max 6MB'),

                        Forms\Components\ColorPicker::make('hero_overlay_color')
                            ->label('Kolor nakładki hero')
                            ->default('#000000'),

                        Forms\Components\Select::make('hero_overlay_opacity')
                            ->label('Przezroczystość nakładki')
                            ->options([
                                '10' => '10%',
                                '20' => '20%',
                                '30' => '30%',
                                '40' => '40%',
                                '50' => '50%',
                                '60' => '60%',
                                '70' => '70%',
                                '80' => '80%',
                                '85' => '85%',
                                '90' => '90%',
                                '95' => '95%',
                            ])
                            ->default('85')
                            ->selectablePlaceholder(false),
                    ]),

                // Sekcja 3: Główna treść
                Section::make('Główna treść')
                    ->schema([
                        Forms\Components\RichEditor::make('body')
                            ->label('Opis usługi')
                            ->toolbarButtons([
                                'bold', 'italic', 'underline',
                                'link', 'bulletList', 'orderedList',
                                'h2', 'h3', 'blockquote',
                            ])
                            ->columnSpanFull()
                            ->extraInputAttributes(['style' => 'min-height: 30rem;'])
                            ->helperText('Szczegółowy opis usługi. Obrazki dodaj przez bloki poniżej.'),
                    ]),

                // Sekcja 4: Zaawansowane Bloki (collapsed)
                Section::make('Zaawansowane bloki (opcjonalnie)')
                    ->schema([
                        Forms\Components\Builder::make('content')
                            ->label('Dodatkowe bloki')
                            ->blocks([
                                ...BuilderBlocks::allBlocks('services'),
                                Forms\Components\Builder\Block::make('service_features')
                                    ->label('Cechy usługi (automatyczne)')
                                    ->icon('heroicon-o-check-badge')
                                    ->schema([
                                        Forms\Components\Placeholder::make('info')
                                            ->content('Automatycznie wyświetla listę "Co zawiera usługa" z danych tej usługi.'),

                                        Forms\Components\TextInput::make('heading')
                                            ->label('Nagłówek')
                                            ->default('Co zawiera usługa'),

                                        Forms\Components\Select::make('layout')
                                            ->label('Układ')
                                            ->options([
                                                'simple' => 'Lista',
                                                'grid' => 'Siatka 2 kolumny',
                                            ])
                                            ->default('simple'),
                                    ]),
                            ])
                            ->collapsible()
                            ->collapsed()
                            ->blockNumbers(false)
                            ->reorderable()
                            ->addActionLabel('Dodaj blok')
                            ->columnSpanFull()
                            ->helperText('Opcjonalne: dodaj galerie, wideo, przyciski CTA, hero sections i więcej'),
                    ])
                    ->collapsed(),

                // Sekcja 5: SEO i Publikacja (collapsed)
                Section::make('SEO i publikacja')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta tytuł')
                            ->maxLength(60)
                            ->helperText('Zalecane: do 60 znaków'),

                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta opis')
                            ->rows(3)
                            ->maxLength(160)
                            ->helperText('Zalecane: do 160 znaków'),

                        Forms\Components\TextInput::make('area_served')
                            ->label('Obszar obsługi (Local SEO)')
                            ->default('Poznań')
                            ->maxLength(255)
                            ->helperText('Miasto/region obsługi usługi'),

                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Data publikacji')
                            ->default(now())
                            ->helperText('Pozostaw puste dla wersji roboczej'),
                    ])
                    ->columns(2)
                    ->collapsed(),

                // Sekcja 6: Conversion Optimization
                Section::make('Optymalizacja konwersji')
                    ->description('Social proof, popularność i wskaźniki pilności dla zwiększenia konwersji')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('average_rating')
                                    ->label('Średnia ocena')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(5)
                                    ->step(0.1)
                                    ->suffix('★')
                                    ->default(0)
                                    ->helperText('Ocena 0-5 gwiazdek'),

                                Forms\Components\TextInput::make('total_reviews')
                                    ->label('Liczba opinii')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText('Całkowita liczba opinii'),

                                Forms\Components\Toggle::make('is_popular')
                                    ->label('Usługa popularna')
                                    ->helperText('Pokaż badge "Najpopularniejsze"')
                                    ->default(false),
                            ]),

                        Forms\Components\TextInput::make('booking_count_week')
                            ->label('Rezerwacje w tym tygodniu')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Dla wiadomości pilności: "Zarezerwowano X razy w tym tygodniu"')
                            ->default(0),

                        Forms\Components\Repeater::make('features')
                            ->label('Co zawiera usługa')
                            ->simple(
                                Forms\Components\TextInput::make('feature')
                                    ->label('Cecha')
                                    ->required()
                                    ->maxLength(100)
                            )
                            ->minItems(0)
                            ->maxItems(4)
                            ->helperText('3-4 punkty wyświetlane na karcie usługi z ikonami checkmark')
                            ->columnSpanFull()
                            ->defaultItems(0),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa usługi')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('formatted_duration')
                    ->label('Czas trwania')
                    ->sortable(query: function ($query, $direction) {
                        return $query->orderBy('duration_minutes', $direction);
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label('Cena')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    ->dateTime('Y-m-d H:i')
                    ->color(fn ($state) => $state && $state->isPast() ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => $state
                            ? ($state->isPast() ? 'Opublikowano' : 'Zaplanowano')
                            : 'Wersja robocza'
                    )
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktywna')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Kolejność')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Ostatnia aktualizacja')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('published')
                    ->label('Opublikowane')
                    ->query(fn ($query) => $query->published()),

                Tables\Filters\Filter::make('draft')
                    ->label('Wersje robocze')
                    ->query(fn ($query) => $query->draft()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktywne')
                    ->boolean(),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Podgląd')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Service $record) => route('service.show', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Service $record) => $record->published_at && $record->published_at->isPast()),
                Actions\EditAction::make()
                    ->label('Edytuj'),
                Actions\DeleteAction::make()
                    ->label('Usuń'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order', 'asc');
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
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
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
