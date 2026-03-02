<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages;

use App\Enums\MenuLocation;
use App\Enums\PageLayout;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Support\BuilderBlocks;
use App\Models\Page;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Strona';

    protected static ?string $pluralModelLabel = 'Strony';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Podstawowe informacje')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Tytuł')
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
                            ->helperText('Automatycznie generowany z tytułu')
                            ->rules([
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                    // Block reserved slugs that conflict with routes
                                    if (in_array(strtolower($value), Page::RESERVED_SLUGS, true)) {
                                        $fail('Ten slug jest zarezerwowany przez system. Wybierz inny.');
                                    }
                                    // Disallow "/" - use is_homepage toggle instead
                                    if ($value === '/') {
                                        $fail('Użyj przełącznika "Strona główna" zamiast sluga "/".');
                                    }
                                },
                            ]),

                        Forms\Components\Toggle::make('is_homepage')
                            ->label('Strona główna')
                            ->helperText('Ustaw tę stronę jako stronę główną witryny')
                            ->default(false)
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Page $record) {
                                if ($record) {
                                    $component->state($record->is_homepage);
                                }
                            }),

                        Forms\Components\Select::make('layout')
                            ->label('Layout')
                            ->options(PageLayout::optionsFor('page'))
                            ->default(PageLayout::DEFAULT->value)
                            ->required()
                            ->helperText('Wybierz układ prezentacji treści'),

                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Data publikacji')
                            ->helperText('Pozostaw puste dla wersji roboczej'),
                    ])
                    ->columns(2),

                Section::make('Zdjęcie wyróżniające')
                    ->schema([
                        Forms\Components\FileUpload::make('featured_image')
                            ->label('Zdjęcie wyróżniające')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['16:9'])
                            ->directory('pages/featured')
                            ->maxSize(6144)
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Maksymalny rozmiar: 6MB'),
                    ]),

                Section::make('Główna treść')
                    ->schema([
                        Forms\Components\RichEditor::make('body')
                            ->label('Treść strony')
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'link', 'bulletList', 'orderedList',
                                'h2', 'h3', 'blockquote',
                                'table',
                                'undo', 'redo',
                            ])
                            ->columnSpanFull()
                            ->extraInputAttributes(['style' => 'min-height: 20rem;'])
                            ->helperText('Opcjonalne. Dla landing pages z blokami Hero możesz zostawić puste.'),
                    ]),

                Section::make('Zaawansowane bloki (opcjonalnie)')
                    ->schema([
                        Forms\Components\Builder::make('content')
                            ->label('Dodatkowe bloki')
                            ->blocks(BuilderBlocks::allBlocks('pages'))
                            ->collapsible()
                            ->collapsed(false)
                            ->blockNumbers(false)
                            ->reorderable()
                            ->addActionLabel('Dodaj blok')
                            ->columnSpanFull()
                            ->helperText('Opcjonalne: dodaj galerie, wideo, przyciski CTA'),
                    ])
                    ->collapsed(),

                Section::make('SEO')
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
                    ])
                    ->collapsed(),

                Section::make('Menu')
                    ->description('Konfiguracja wyświetlania w menu nawigacyjnym')
                    ->icon('heroicon-o-bars-3')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('show_in_menu')
                            ->label('Pokaż w menu')
                            ->helperText('Strona pojawi się w nawigacji')
                            ->live()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('menu_order')
                            ->label('Kolejność')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(999)
                            ->helperText('Mniejsza liczba = wyżej w menu')
                            ->visible(fn ($get) => $get('show_in_menu')),

                        Forms\Components\TextInput::make('menu_label')
                            ->label('Etykieta menu')
                            ->placeholder('Domyślnie: tytuł strony')
                            ->helperText('Alternatywna nazwa w menu')
                            ->visible(fn ($get) => $get('show_in_menu')),

                        Forms\Components\Select::make('menu_location')
                            ->label('Lokalizacja')
                            ->options(MenuLocation::options())
                            ->default(MenuLocation::HEADER->value)
                            ->visible(fn ($get) => $get('show_in_menu')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tytuł')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\IconColumn::make('is_homepage')
                    ->label('Homepage')
                    ->boolean()
                    ->getStateUsing(function (Page $record): bool {
                        $settingsManager = app(\App\Support\Settings\SettingsManager::class);
                        $homepageId = $settingsManager->get('cms.homepage_page_id');

                        return $homepageId == $record->id;
                    })
                    ->trueIcon('heroicon-o-home')
                    ->falseIcon('')
                    ->alignCenter()
                    ->tooltip(fn (bool $state): string => $state ? 'This is the homepage' : ''),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('layout')
                    ->label('Layout')
                    ->badge()
                    ->color(fn (PageLayout $state): string => match ($state) {
                        PageLayout::DEFAULT => 'info',
                        PageLayout::FULL_WIDTH => 'success',
                        PageLayout::MINIMAL => 'warning',
                        PageLayout::HOME => 'danger',
                    })
                    ->formatStateUsing(fn (PageLayout $state): string => $state->label()),

                Tables\Columns\IconColumn::make('show_in_menu')
                    ->label('Menu')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('menu_order')
                    ->label('Kolejność')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

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

                Tables\Filters\SelectFilter::make('layout')
                    ->label('Layout')
                    ->options(PageLayout::options()),

                Tables\Filters\TernaryFilter::make('show_in_menu')
                    ->label('W menu')
                    ->trueLabel('Tylko w menu')
                    ->falseLabel('Nie w menu'),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Podgląd')
                    ->icon('heroicon-o-eye')
                    ->url(function (Page $record) {
                        $settingsManager = app(\App\Support\Settings\SettingsManager::class);
                        $homepageId = $settingsManager->get('cms.homepage_page_id');

                        if ($homepageId == $record->id) {
                            return route('home');
                        }

                        return route('page.show', $record->slug);
                    })
                    ->openUrlInNewTab()
                    ->visible(fn (Page $record) => $record->published_at && $record->published_at->isPast()),
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
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
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
