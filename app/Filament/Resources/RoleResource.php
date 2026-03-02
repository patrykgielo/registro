<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|UnitEnum|null $navigationGroup = 'users';

    protected static ?string $modelLabel = 'Rola';

    protected static ?string $pluralModelLabel = 'Role';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informacje o roli')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nazwa roli')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('Np. admin, staff, customer'),
                    Forms\Components\TextInput::make('guard_name')
                        ->label('Guard')
                        ->default('web')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Zazwyczaj "web" dla standardowych użytkowników'),
                ])->columns(2),

            Section::make('Uprawnienia')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('Przypisz uprawnienia')
                        ->relationship('permissions', 'name')
                        ->options(Permission::all()->pluck('name', 'id'))
                        ->columns(3)
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Wybierz uprawnienia dla tej roli'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nazwa roli')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->colors([
                        'danger' => 'super-admin',
                        'warning' => 'admin',
                        'success' => 'staff',
                        'info' => 'customer',
                    ]),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Liczba uprawnień')
                    ->counts('permissions')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Liczba użytkowników')
                    ->counts('users')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data utworzenia')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Data aktualizacji')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('guard_name')
                    ->label('Guard')
                    ->options([
                        'web' => 'Web',
                        'api' => 'API',
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->label('Edytuj'),
                Actions\DeleteAction::make()
                    ->label('Usuń'),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->label('Usuń zaznaczone'),
                ]),
            ])
            ->emptyStateHeading('Brak ról')
            ->emptyStateDescription('Dodaj pierwszą rolę klikając przycisk poniżej.')
            ->emptyStateIcon('heroicon-o-shield-check');
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    /**
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
