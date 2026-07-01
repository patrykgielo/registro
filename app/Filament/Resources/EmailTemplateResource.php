<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\TemplateKey;
use App\Filament\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use App\Services\Email\EmailService;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmailTemplateResource extends BaseResource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $module = 'communication';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Szablony Email';

    protected static ?string $modelLabel = 'Szablon Email';

    protected static ?string $pluralModelLabel = 'Szablony Email';

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
                        ->options(TemplateKey::optionsForChannel('email'))
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

                    Forms\Components\Toggle::make('active')
                        ->label('Aktywny')
                        ->default(true)
                        ->helperText('Włącz/wyłącz ten szablon'),
                ]),

            Section::make('Treść e-maila')
                ->schema([
                    Forms\Components\TextInput::make('subject')
                        ->label('Temat wiadomości')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Witaj w {{app_name}}, {{user_name}}!')
                        ->helperText('Użyj składni {{zmienna}} dla placeholderów'),

                    Forms\Components\Textarea::make('html_body')
                        ->label('Treść HTML')
                        ->required()
                        ->rows(15)
                        ->placeholder('<h1>Witaj {{user_name}}</h1>')
                        ->helperText('Szablon HTML z placeholderami {{zmienna}}. Obsługuje składnię Blade.'),

                    Forms\Components\Textarea::make('text_body')
                        ->label('Treść tekstowa (opcjonalnie)')
                        ->rows(10)
                        ->placeholder('Witaj {{user_name}}...')
                        ->helperText('Wersja tekstowa dla klientów pocztowych bez obsługi HTML'),
                ]),

            Section::make('Dostępne zmienne')
                ->schema([
                    Forms\Components\Placeholder::make('variable_legend')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => self::getVariableLegendForKey($get('key')))
                        ->helperText('Skopiuj nazwy zmiennych do szablonu używając składni {{nazwa_zmiennej}}'),
                ])
                ->description('Zmienne dostępne w temacie, treści HTML i treści tekstowej')
                ->collapsible(),

            Section::make('Ustawienia zaawansowane')
                ->schema([
                    Forms\Components\TextInput::make('blade_path')
                        ->label('Ścieżka Blade (zapasowa)')
                        ->placeholder('emails.user-registered')
                        ->helperText('Zapasowa ścieżka widoku Blade, gdy szablon z bazy zawiedzie'),

                    Forms\Components\TagsInput::make('variables')
                        ->label('Dostępne zmienne')
                        ->placeholder('user_name, app_name, itp.')
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

                Tables\Columns\TextColumn::make('subject')
                    ->label('Temat')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (EmailTemplate $record): string {
                        return $record->subject;
                    }),

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
                    ->options(TemplateKey::optionsForChannel('email')),

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
                        Forms\Components\TextInput::make('email')
                            ->label('Adres e-mail odbiorcy')
                            ->email()
                            ->required()
                            ->placeholder('test@example.com')
                            ->helperText('E-mail zostanie wysłany na ten adres z przykładowymi danymi'),
                    ])
                    ->action(function (EmailTemplate $record, array $data): void {
                        try {
                            $emailService = app(EmailService::class);

                            // Send test email with example data
                            $result = $emailService->sendFromTemplate(
                                templateKey: $record->key,
                                language: $record->language,
                                recipient: $data['email'],
                                data: self::getExampleData($record),
                                metadata: []
                            );

                            if ($result) {
                                Notification::make()
                                    ->success()
                                    ->title('Wysłano testowy e-mail!')
                                    ->body("Wysłano e-mail na adres {$data['email']}")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Nie udało się wysłać testowego e-maila')
                                    ->body('Sprawdź logi e-maili, aby uzyskać szczegóły')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Błąd podczas wysyłania testowego e-maila')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Wyślij testowy e-mail')
                    ->modalDescription('Wyśle testowy e-mail z przykładowymi danymi, aby zweryfikować renderowanie szablonu.'),

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
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * Get example data for template preview/testing
     */
    protected static function getExampleData(EmailTemplate $template): array
    {
        // Common variables
        $data = [
            'app_name' => app(\App\Support\Settings\SettingsManager::class)->appName(),
            'app_url' => config('app.url', 'https://registro.local'),
            'user_name' => 'Jan Kowalski',
            'user_email' => 'jan.kowalski@example.com',
            'current_year' => date('Y'),
        ];

        // Template-specific variables
        $specificData = match ($template->key) {
            TemplateKey::USER_REGISTERED->value => [
                'verification_url' => url('/email/verify'),
            ],
            TemplateKey::PASSWORD_RESET->value => [
                'reset_url' => url('/reset-password/token123'),
                'expires_in' => '60 minut',
            ],
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'appointment_date' => now()->addDays(2)->format('Y-m-d'),
                'appointment_time' => '14:00',
                'service_name' => 'Detailing Premium',
                'location_address' => 'ul. Przykładowa 123, Warszawa',
            ],
            TemplateKey::APPOINTMENT_CANCELLED->value => [
                'appointment_date' => now()->format('Y-m-d'),
                'appointment_time' => '14:00',
                'service_name' => 'Detailing Premium',
                'cancellation_reason' => 'Prośba klienta',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'appointment_date' => now()->subDays(3)->format('Y-m-d'),
                'service_name' => 'Detailing Premium',
                'feedback_url' => url('/feedback/123'),
            ],
            TemplateKey::ADMIN_DAILY_DIGEST->value => [
                'date' => now()->format('Y-m-d'),
                'total_appointments' => 12,
                'pending_appointments' => 3,
                'completed_appointments' => 9,
            ],
            default => [],
        };

        return array_merge($data, $specificData);
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

    /**
     * Get variable legend HTML for a specific template key.
     *
     * Displays available variables that can be used in the email template,
     * including both common variables (app_name, user_name, etc.) and
     * template-specific variables (appointment_date, verification_url, etc.).
     *
     * @param  string|null  $key  Template key (e.g., 'user-registered', 'appointment-created')
     * @return \Illuminate\Support\HtmlString HTML content showing variable list
     */
    protected static function getVariableLegendForKey(?string $key): HtmlString
    {
        if (! $key) {
            return new HtmlString('<p class="text-sm text-gray-500">Wybierz klucz szablonu, aby zobaczyć dostępne zmienne</p>');
        }

        // Common variables available in ALL templates
        $commonVariables = [
            'app_name' => 'Nazwa aplikacji (z konfiguracji)',
            'app_url' => 'Adres URL aplikacji',
            'user_name' => 'Imię i nazwisko użytkownika (first_name + last_name)',
            'user_email' => 'Adres e-mail użytkownika',
            'customer_name' => 'Imię i nazwisko klienta (alias dla user_name)',
            'current_year' => 'Bieżący rok (np. 2026)',
            'contact_email' => 'Adres e-mail wsparcia',
            'contact_phone' => 'Numer telefonu wsparcia',
        ];

        // Template-specific variables
        $specificVariables = match ($key) {
            TemplateKey::USER_REGISTERED->value => [
                'verification_url' => 'Link weryfikacji adresu e-mail',
            ],
            TemplateKey::PASSWORD_RESET->value => [
                'reset_url' => 'Link resetowania hasła',
                'expires_in' => 'Czas wygaśnięcia linku (np. "60 minut")',
            ],
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'appointment_date' => 'Data wizyty (format Y-m-d)',
                'appointment_time' => 'Godzina wizyty (format H:i)',
                'service_name' => 'Nazwa usługi',
                'location_address' => 'Adres miejsca świadczenia usługi',
            ],
            TemplateKey::APPOINTMENT_CANCELLED->value => [
                'appointment_date' => 'Data wizyty',
                'appointment_time' => 'Godzina wizyty',
                'service_name' => 'Nazwa usługi',
                'cancellation_reason' => 'Powód anulowania',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'appointment_date' => 'Data wizyty',
                'service_name' => 'Nazwa usługi',
                'feedback_url' => 'Link do formularza opinii',
            ],
            TemplateKey::ADMIN_DAILY_DIGEST->value => [
                'date' => 'Data raportu',
                'total_appointments' => 'Łączna liczba wizyt',
                'pending_appointments' => 'Liczba oczekujących wizyt',
                'completed_appointments' => 'Liczba zakończonych wizyt',
            ],
            default => [],
        };

        // Build HTML output
        $html = '<div class="space-y-4">';

        // Common variables section
        $html .= '<div>';
        $html .= '<h4 class="text-sm font-semibold text-gray-700 mb-2">Zmienne wspólne (dostępne we wszystkich szablonach)</h4>';
        $html .= '<div class="bg-gray-50 rounded-lg p-3 space-y-1">';
        foreach ($commonVariables as $var => $description) {
            $html .= sprintf(
                '<div class="flex items-start"><code class="text-xs bg-gray-200 px-2 py-1 rounded font-mono text-blue-600 mr-2">{{%s}}</code><span class="text-xs text-gray-600">%s</span></div>',
                $var,
                $description
            );
        }
        $html .= '</div>';
        $html .= '</div>';

        // Template-specific variables section
        if (! empty($specificVariables)) {
            $html .= '<div>';
            $html .= '<h4 class="text-sm font-semibold text-gray-700 mb-2">Zmienne specyficzne dla szablonu</h4>';
            $html .= '<div class="bg-blue-50 rounded-lg p-3 space-y-1">';
            foreach ($specificVariables as $var => $description) {
                $html .= sprintf(
                    '<div class="flex items-start"><code class="text-xs bg-blue-200 px-2 py-1 rounded font-mono text-blue-700 mr-2">{{%s}}</code><span class="text-xs text-gray-600">%s</span></div>',
                    $var,
                    $description
                );
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }
}
