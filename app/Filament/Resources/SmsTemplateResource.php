<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\SmsTemplateResource\Pages;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class SmsTemplateResource extends BaseResource
{
    protected static ?string $model = SmsTemplate::class;

    protected static ?string $module = 'communication';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Szablony SMS';

    protected static ?string $modelLabel = 'Szablon SMS';

    protected static ?string $pluralModelLabel = 'Szablony SMS';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() === 0 ? 'Brak' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::count() === 0 ? 'danger' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Szczegóły szablonu')
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label('Klucz szablonu')
                        ->required()
                        ->options(TemplateKey::optionsForChannel('sms'))
                        ->searchable()
                        ->helperText('Unikalny identyfikator tego szablonu'),

                    Forms\Components\Select::make('language')
                        ->label('Język')
                        ->required()
                        ->options([
                            'pl' => 'Polski (PL)',
                            'en' => 'English (EN)',
                        ])
                        ->default('pl')
                        ->helperText('Język szablonu'),

                    Forms\Components\TextInput::make('max_length')
                        ->label('Maksymalna długość')
                        ->numeric()
                        ->default(160)
                        ->required()
                        ->minValue(70)
                        ->maxValue(500)
                        ->helperText('160 dla GSM, 70 dla Unicode'),

                    Forms\Components\Toggle::make('active')
                        ->label('Aktywny')
                        ->default(true)
                        ->helperText('Włącz/wyłącz ten szablon'),
                ]),

            Section::make('Treść SMS')
                ->schema([
                    Forms\Components\Textarea::make('message_body')
                        ->label('Treść wiadomości')
                        ->required()
                        ->rows(6)
                        ->maxLength(500)
                        ->placeholder('Witaj {{customer_name}}! Przypominamy o wizycie {{appointment_date}} o {{appointment_time}}.')
                        ->helperText('Użyj składni {{zmienna}} dla placeholderów. Zachowaj zwięzłość!')
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $length = mb_strlen($state ?? '');
                            $set('character_count', $length);
                        }),

                    Forms\Components\Placeholder::make('character_count')
                        ->label('Liczba znaków')
                        ->content(fn (Get $get): string => mb_strlen($get('message_body') ?? '').' znaków'),
                ]),

            Section::make('Dostępne zmienne')
                ->schema([
                    Forms\Components\Placeholder::make('variable_legend')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => self::getVariableLegendForKey($get('key')))
                        ->helperText('Skopiuj nazwy zmiennych do wiadomości używając składni {{nazwa_zmiennej}}'),
                ])
                ->description('Zmienne dostępne w treści wiadomości')
                ->collapsible(),

            Section::make('Ustawienia zaawansowane')
                ->schema([
                    Forms\Components\TagsInput::make('variables')
                        ->label('Dostępne zmienne')
                        ->placeholder('customer_name, appointment_date, itp.')
                        ->helperText('Lista zmiennych dostępnych dla tego szablonu (tylko informacyjnie)'),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Klucz')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('language')
                    ->label('Język')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pl' => 'success',
                        'en' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('message_body')
                    ->label('Podgląd wiadomości')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(function (SmsTemplate $record): string {
                        return $record->message_body;
                    }),

                Tables\Columns\TextColumn::make('max_length')
                    ->label('Maks. długość')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Aktywny')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Aktualizacja')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('language')
                    ->label('Język')
                    ->options([
                        'pl' => 'Polski',
                        'en' => 'English',
                    ]),

                Tables\Filters\SelectFilter::make('key')
                    ->label('Klucz szablonu')
                    ->options(TemplateKey::optionsForChannel('sms')),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Status')
                    ->placeholder('Wszystkie szablony')
                    ->trueLabel('Tylko aktywne')
                    ->falseLabel('Tylko nieaktywne'),
            ])
            ->recordActions([
                Actions\Action::make('testSend')
                    ->label('Wyślij testowo')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('phone')
                            ->label('Numer telefonu odbiorcy')
                            ->tel()
                            ->required()
                            ->placeholder('+48501234567')
                            ->helperText('SMS zostanie wysłany na ten numer z przykładowymi danymi'),
                    ])
                    ->action(function (SmsTemplate $record, array $data): void {
                        try {
                            $smsService = app(SmsService::class);

                            // Send test SMS with example data
                            $result = $smsService->sendFromTemplate(
                                templateKey: $record->key,
                                language: $record->language,
                                recipient: $data['phone'],
                                data: self::getExampleData($record),
                                metadata: ['type' => 'test']
                            );

                            if ($result) {
                                Notification::make()
                                    ->success()
                                    ->title('Wysłano testowy SMS!')
                                    ->body("Wysłano SMS na numer {$data['phone']}")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Nie udało się wysłać testowego SMS')
                                    ->body('Sprawdź logi SMS, aby uzyskać szczegóły')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Błąd podczas wysyłania testowego SMS')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Wyślij testowy SMS')
                    ->modalDescription('Wyśle testowy SMS z przykładowymi danymi, aby zweryfikować renderowanie szablonu.'),

                Actions\EditAction::make(),

                Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
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
            'index' => Pages\ListSmsTemplates::route('/'),
            'create' => Pages\CreateSmsTemplate::route('/create'),
            'edit' => Pages\EditSmsTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * Get example data for template testing
     */
    protected static function getExampleData(SmsTemplate $template): array
    {
        // Common variables
        $data = [
            'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
            'customer_name' => 'Jan Kowalski',
            'contact_phone' => '+48123456789',
        ];

        // Template-specific variables
        switch ($template->key) {
            case TemplateKey::APPOINTMENT_CREATED->value:
            case TemplateKey::APPOINTMENT_CONFIRMED->value:
            case TemplateKey::APPOINTMENT_REMINDER_24H->value:
            case TemplateKey::APPOINTMENT_REMINDER_2H->value:
                $data['appointment_date'] = '2026-12-15';
                $data['appointment_time'] = '14:00';
                $data['service_name'] = 'Detailing Premium';
                $data['location_address'] = 'ul. Przykładowa 123, Warszawa';
                break;

            case TemplateKey::APPOINTMENT_FOLLOWUP->value:
                $data['service_name'] = 'Detailing Premium';
                break;
        }

        return $data;
    }

    /**
     * Get variable legend HTML for specific template key
     */
    protected static function getVariableLegendForKey(?string $key): HtmlString
    {
        if (! $key) {
            return new HtmlString('<p class="text-sm text-gray-500">Wybierz klucz szablonu, aby zobaczyć dostępne zmienne</p>');
        }

        $variables = match ($key) {
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_CONFIRMED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_CANCELLED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'customer_name' => 'Imię i nazwisko klienta',
                'appointment_date' => 'Data wizyty (RRRR-MM-DD)',
                'appointment_time' => 'Godzina wizyty (GG:MM)',
                'service_name' => 'Nazwa usługi',
                'location_address' => 'Adres miejsca świadczenia usługi',
                'app_name' => 'Nazwa aplikacji',
                'contact_phone' => 'Numer telefonu kontaktowego',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'customer_name' => 'Imię i nazwisko klienta',
                'service_name' => 'Nazwa usługi',
                'app_name' => 'Nazwa aplikacji',
                'contact_phone' => 'Numer telefonu kontaktowego',
            ],
            default => [],
        };

        if (empty($variables)) {
            return new HtmlString('<p class="text-sm text-gray-500">Brak zdefiniowanych zmiennych dla tego szablonu</p>');
        }

        $html = '<div class="space-y-2">';
        foreach ($variables as $var => $description) {
            $html .= '<div class="flex items-start gap-2">';
            $html .= '<code class="text-xs bg-gray-100 px-2 py-1 rounded">{{'.$var.'}}</code>';
            $html .= '<span class="text-sm text-gray-600">'.$description.'</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * Check if user can access this resource
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('communication.manage_templates') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('communication.manage_templates') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('communication.manage_templates') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('communication.manage_templates') ?? false;
    }
}
