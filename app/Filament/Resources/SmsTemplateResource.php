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

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'SMS Templates';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count() === 0 ? 'Empty' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::count() === 0 ? 'danger' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template Details')
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label('Template Key')
                        ->required()
                        ->options(TemplateKey::optionsForChannel('sms'))
                        ->searchable()
                        ->helperText('Unique identifier for this template'),

                    Forms\Components\Select::make('language')
                        ->label('Language')
                        ->required()
                        ->options([
                            'pl' => 'Polski (PL)',
                            'en' => 'English (EN)',
                        ])
                        ->default('pl')
                        ->helperText('Template language'),

                    Forms\Components\TextInput::make('max_length')
                        ->label('Max Length')
                        ->numeric()
                        ->default(160)
                        ->required()
                        ->minValue(70)
                        ->maxValue(500)
                        ->helperText('160 for GSM, 70 for Unicode'),

                    Forms\Components\Toggle::make('active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Enable/disable this template'),
                ]),

            Section::make('SMS Content')
                ->schema([
                    Forms\Components\Textarea::make('message_body')
                        ->label('Message Body')
                        ->required()
                        ->rows(6)
                        ->maxLength(500)
                        ->placeholder('Witaj {{customer_name}}! Przypominamy o wizycie {{appointment_date}} o {{appointment_time}}.')
                        ->helperText('Use {{variable}} syntax for placeholders. Keep it short!')
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $length = mb_strlen($state ?? '');
                            $set('character_count', $length);
                        }),

                    Forms\Components\Placeholder::make('character_count')
                        ->label('Character Count')
                        ->content(fn (Get $get): string => mb_strlen($get('message_body') ?? '').' characters'),
                ]),

            Section::make('Available Variables')
                ->schema([
                    Forms\Components\Placeholder::make('variable_legend')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => self::getVariableLegendForKey($get('key')))
                        ->helperText('Copy these variable names into your message using {{variable_name}} syntax'),
                ])
                ->description('Variables you can use in the message body')
                ->collapsible(),

            Section::make('Advanced Settings')
                ->schema([
                    Forms\Components\TagsInput::make('variables')
                        ->label('Available Variables')
                        ->placeholder('customer_name, appointment_date, etc.')
                        ->helperText('List of variables available for this template (for reference only)'),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string $state): string => TemplateKey::tryFrom($state)?->label() ?? $state),

                Tables\Columns\TextColumn::make('language')
                    ->label('Language')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pl' => 'success',
                        'en' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('message_body')
                    ->label('Message Preview')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(function (SmsTemplate $record): string {
                        return $record->message_body;
                    }),

                Tables\Columns\TextColumn::make('max_length')
                    ->label('Max Length')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('language')
                    ->label('Language')
                    ->options([
                        'pl' => 'Polski',
                        'en' => 'English',
                    ]),

                Tables\Filters\SelectFilter::make('key')
                    ->label('Template Key')
                    ->options(TemplateKey::optionsForChannel('sms')),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active Status')
                    ->placeholder('All templates')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                Actions\Action::make('testSend')
                    ->label('Test Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('phone')
                            ->label('Recipient Phone')
                            ->tel()
                            ->required()
                            ->placeholder('+48501234567')
                            ->helperText('SMS will be sent to this number with example data'),
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
                                    ->title('Test SMS sent!')
                                    ->body("SMS sent to {$data['phone']}")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Failed to send test SMS')
                                    ->body('Check the SMS logs for details')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error sending test SMS')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Send Test SMS')
                    ->modalDescription('This will send a test SMS with example data to verify the template rendering.'),

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
                $data['appointment_date'] = '2025-12-15';
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
            return new HtmlString('<p class="text-sm text-gray-500">Select a template key to see available variables</p>');
        }

        $variables = match ($key) {
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_CONFIRMED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_CANCELLED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'customer_name' => 'Customer full name',
                'appointment_date' => 'Appointment date (YYYY-MM-DD)',
                'appointment_time' => 'Appointment time (HH:MM)',
                'service_name' => 'Service name',
                'location_address' => 'Service location address',
                'app_name' => 'Application name',
                'contact_phone' => 'Contact phone number',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'customer_name' => 'Customer full name',
                'service_name' => 'Service name',
                'app_name' => 'Application name',
                'contact_phone' => 'Contact phone number',
            ],
            default => [],
        };

        if (empty($variables)) {
            return new HtmlString('<p class="text-sm text-gray-500">No variables defined for this template</p>');
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
     * Restrict access to admins and super-admins only.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }
}
