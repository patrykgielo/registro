<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class CategoryResource extends BaseResource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Kategoria';

    protected static ?string $pluralModelLabel = 'Kategorie';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Nazwa')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', Str::slug($state))
                    ),

                Forms\Components\TextInput::make('slug')
                    ->label('Slug (URL)')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Automatycznie generowany z nazwy'),

                Forms\Components\Select::make('type')
                    ->label('Typ')
                    ->required()
                    ->options([
                        'post' => 'Aktualności',
                        'portfolio' => 'Portfolio',
                    ])
                    ->default('post')
                    ->helperText('Czy kategoria dla wpisów czy portfolio'),

                Forms\Components\Select::make('parent_id')
                    ->label('Kategoria nadrzędna')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Pozostaw puste dla kategorii głównej'),

                Forms\Components\Textarea::make('description')
                    ->label('Opis')
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
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

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Typ')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'post' => 'info',
                        'portfolio' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'post' => 'Aktualności',
                        'portfolio' => 'Portfolio',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Kategoria nadrzędna')
                    ->badge()
                    ->color('warning')
                    ->default('—'),

                Tables\Columns\TextColumn::make('posts_count')
                    ->label('Wpisów')
                    ->counts('posts')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('portfolio_items_count')
                    ->label('Portfolio')
                    ->counts('portfolioItems')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Typ')
                    ->options([
                        'post' => 'Aktualności',
                        'portfolio' => 'Portfolio',
                    ]),

                Tables\Filters\Filter::make('root_only')
                    ->label('Tylko główne kategorie')
                    ->query(fn ($query) => $query->whereNull('parent_id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Nowa kategoria'),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
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
