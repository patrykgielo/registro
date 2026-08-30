<?php

declare(strict_types=1);

namespace App\Filament\Resources\PortfolioItems;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\PortfolioItems\Pages\CreatePortfolioItem;
use App\Filament\Resources\PortfolioItems\Pages\EditPortfolioItem;
use App\Filament\Resources\PortfolioItems\Pages\ListPortfolioItems;
use App\Filament\Support\BuilderBlocks;
use App\Filament\Support\TenantScopedUniqueRule;
use App\Models\Category;
use App\Models\PortfolioItem;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class PortfolioItemResource extends BaseResource
{
    protected static ?string $model = PortfolioItem::class;

    protected static ?string $module = 'website';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Realizacja';

    protected static ?string $pluralModelLabel = 'Portfolio';

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
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: TenantScopedUniqueRule::forCurrentTenant(),
                            )
                            ->helperText('Automatycznie generowany z tytułu'),

                        Forms\Components\Select::make('category_id')
                            ->label('Kategoria')
                            ->relationship('category', 'name', fn ($query) => $query->portfolioCategories())
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nazwa kategorii')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('description')
                                    ->label('Opis')
                                    ->rows(3),
                            ])
                            ->createOptionUsing(function (array $data) {
                                $data['type'] = 'portfolio';
                                $data['slug'] = Str::slug($data['name']);

                                return Category::create($data)->getKey();
                            }),

                        Forms\Components\DateTimePicker::make('published_at')
                            ->label('Data publikacji')
                            ->helperText('Pozostaw puste dla wersji roboczej'),
                    ])
                    ->columns(2),

                Section::make('Zdjęcia przed/po')
                    ->schema([
                        Forms\Components\FileUpload::make('before_image')
                            ->label('Zdjęcie PRZED')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['4:3'])
                            ->directory('portfolio/before')
                            ->maxSize(6144)
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Maksymalny rozmiar: 6MB'),

                        Forms\Components\FileUpload::make('after_image')
                            ->label('Zdjęcie PO')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['4:3'])
                            ->directory('portfolio/after')
                            ->maxSize(6144)
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Maksymalny rozmiar: 6MB'),

                        Forms\Components\FileUpload::make('gallery')
                            ->label('Dodatkowa galeria')
                            ->image()
                            ->multiple()
                            ->directory('portfolio/gallery')
                            ->maxSize(6144)
                            ->maxFiles(30)
                            ->reorderable()
                            ->helperText('Maksymalny rozmiar: 6MB na zdjęcie')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Opis projektu')
                    ->schema([
                        Forms\Components\RichEditor::make('body')
                            ->label('Opis realizacji')
                            ->toolbarButtons([
                                'bold', 'italic', 'underline',
                                'link', 'bulletList', 'orderedList',
                                'h2', 'h3', 'blockquote',
                                'undo', 'redo',
                            ])
                            ->columnSpanFull()
                            ->extraInputAttributes(['style' => 'min-height: 30rem;'])
                            ->helperText('Opisz szczegóły projektu, przebieg prac, użyte materiały'),
                    ]),

                Section::make('Dodatkowe bloki (opcjonalnie)')
                    ->schema([
                        Forms\Components\Builder::make('content')
                            ->label('Dodatkowe bloki')
                            ->blocks(BuilderBlocks::allBlocks('portfolio'))
                            ->collapsible()
                            ->collapsed()
                            ->blockNumbers(false)
                            ->reorderable()
                            ->addActionLabel('Dodaj blok')
                            ->columnSpanFull()
                            ->helperText('Opcjonalne: dodaj cytaty klientów, galerie, wideo, hero sections i więcej'),
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('before_image')
                    ->label('Przed')
                    ->circular()
                    ->defaultImageUrl(url('/images/placeholder.jpg')),

                Tables\Columns\ImageColumn::make('after_image')
                    ->label('Po')
                    ->circular()
                    ->defaultImageUrl(url('/images/placeholder.jpg')),

                Tables\Columns\TextColumn::make('title')
                    ->label('Tytuł')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Kategoria')
                    ->badge()
                    ->color('info')
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

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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

                Tables\Filters\SelectFilter::make('category')
                    ->label('Kategoria')
                    ->relationship('category', 'name', fn ($query) => $query->portfolioCategories()),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Podgląd')
                    ->icon('heroicon-o-eye')
                    ->url(fn (PortfolioItem $record) => route('portfolio.show', $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (PortfolioItem $record) => $record->published_at && $record->published_at->isPast()),
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
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPortfolioItems::route('/'),
            'create' => CreatePortfolioItem::route('/create'),
            'edit' => EditPortfolioItem::route('/{record}/edit'),
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
