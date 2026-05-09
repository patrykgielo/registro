<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promotions;

use App\Enums\PageLayout;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Filament\Support\BuilderBlocks;
use App\Models\Promotion;
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

class PromotionResource extends BaseResource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $module = 'website';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Promocja';

    protected static ?string $pluralModelLabel = 'Promocje';

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
                            ->helperText('Automatycznie generowany z tytułu'),

                        Forms\Components\Toggle::make('active')
                            ->label('Aktywna')
                            ->default(true)
                            ->helperText('Wyłącz aby ukryć promocję'),

                        Forms\Components\Select::make('layout')
                            ->label('Layout')
                            ->options(PageLayout::optionsFor('promotion'))
                            ->default(PageLayout::DEFAULT->value)
                            ->required()
                            ->helperText('Wybierz układ prezentacji treści'),

                        Forms\Components\DateTimePicker::make('valid_from')
                            ->label('Ważna od')
                            ->helperText('Pozostaw puste jeśli bez ograniczeń'),

                        Forms\Components\DateTimePicker::make('valid_until')
                            ->label('Ważna do')
                            ->helperText('Pozostaw puste jeśli bez ograniczeń'),
                    ])
                    ->columns(2),

                Section::make('Zdjęcie wyróżniające')
                    ->schema([
                        Forms\Components\FileUpload::make('featured_image')
                            ->label('Zdjęcie wyróżniające')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['4:3'])
                            ->directory('promotions/featured')
                            ->maxSize(6144)
                            ->extraAttributes(['class' => 'no-edit-icon'])
                            ->helperText('Maksymalny rozmiar: 6MB'),
                    ]),

                Section::make('Treść promocji')
                    ->schema([
                        Forms\Components\RichEditor::make('body')
                            ->label('Opis promocji')
                            ->required()
                            ->toolbarButtons([
                                'bold', 'italic', 'underline', 'strike',
                                'link', 'bulletList', 'orderedList',
                                'h2', 'h3', 'blockquote',
                                'undo', 'redo',
                            ])
                            ->columnSpanFull()
                            ->extraInputAttributes(['style' => 'min-height: 30rem;'])
                            ->helperText('Główna treść promocji. Obrazki dodaj przez bloki poniżej.'),
                    ]),

                Section::make('Zaawansowane bloki (opcjonalnie)')
                    ->schema([
                        Forms\Components\Builder::make('content')
                            ->label('Dodatkowe bloki')
                            ->blocks(BuilderBlocks::allBlocks('promotions'))
                            ->collapsible()
                            ->collapsed()
                            ->blockNumbers(false)
                            ->reorderable()
                            ->addActionLabel('Dodaj blok')
                            ->columnSpanFull()
                            ->helperText('Opcjonalne: dodaj galerie zdjęć, wideo, przyciski CTA, hero sections i więcej'),
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
                Tables\Columns\TextColumn::make('title')
                    ->label('Tytuł')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Aktywna')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('layout')
                    ->label('Layout')
                    ->badge()
                    ->color(fn (PageLayout $state): string => match ($state) {
                        PageLayout::DEFAULT => 'info',
                        PageLayout::FULL_WIDTH => 'success',
                        PageLayout::MINIMAL => 'warning',
                        PageLayout::HOME => 'danger',
                    })
                    ->formatStateUsing(fn (PageLayout $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('valid_from')
                    ->label('Ważna od')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Ważna do')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (Promotion $record) => $record->isActiveAndValid() ? 'active' : 'inactive'
                    )
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Aktywna',
                        'inactive' => 'Nieaktywna',
                    }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Ostatnia aktualizacja')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('active')
                    ->label('Aktywne')
                    ->query(fn ($query) => $query->active()),

                Tables\Filters\Filter::make('valid')
                    ->label('Ważne (w okresie obowiązywania)')
                    ->query(fn ($query) => $query->valid()),

                Tables\Filters\Filter::make('active_and_valid')
                    ->label('Aktywne i ważne')
                    ->query(fn ($query) => $query->activeAndValid()),

                Tables\Filters\SelectFilter::make('layout')
                    ->label('Layout')
                    ->options(PageLayout::optionsFor('promotion')),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Podgląd')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Promotion $record) => route('promotion.show', $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (Promotion $record) => $record->isActiveAndValid()),
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
            'index' => ListPromotions::route('/'),
            'create' => CreatePromotion::route('/create'),
            'edit' => EditPromotion::route('/{record}/edit'),
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
