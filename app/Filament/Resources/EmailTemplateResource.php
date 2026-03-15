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
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmailTemplateResource extends BaseResource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $module = 'communication';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|UnitEnum|null $navigationGroup = 'communication';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Email Templates';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template Details')
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label('Template Key')
                        ->required()
                        ->options(TemplateKey::optionsForChannel('email'))
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

                    Forms\Components\Toggle::make('active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Enable/disable this template'),
                ]),

            Section::make('Email Content')
                ->schema([
                    Forms\Components\TextInput::make('subject')
                        ->label('Subject Line')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Welcome to {{app_name}}, {{user_name}}!')
                        ->helperText('Use {{variable}} syntax for placeholders'),

                    Forms\Components\Textarea::make('html_body')
                        ->label('HTML Body')
                        ->required()
                        ->rows(15)
                        ->placeholder('<h1>Hello {{user_name}}</h1>')
                        ->helperText('HTML template with {{variable}} placeholders. Supports Blade syntax.'),

                    Forms\Components\Textarea::make('text_body')
                        ->label('Plain Text Body (Optional)')
                        ->rows(10)
                        ->placeholder('Hello {{user_name}}...')
                        ->helperText('Plain text version for email clients that don\'t support HTML'),
                ]),

            Section::make('Available Variables')
                ->schema([
                    Forms\Components\Placeholder::make('variable_legend')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => self::getVariableLegendForKey($get('key')))
                        ->helperText('Copy these variable names into your template using {{variable_name}} syntax'),
                ])
                ->description('Variables you can use in the subject, HTML body, and text body')
                ->collapsible(),

            Section::make('Advanced Settings')
                ->schema([
                    Forms\Components\TextInput::make('blade_path')
                        ->label('Blade Path (Fallback)')
                        ->placeholder('emails.user-registered')
                        ->helperText('Fallback Blade view path if database template fails'),

                    Forms\Components\TagsInput::make('variables')
                        ->label('Available Variables')
                        ->placeholder('user_name, app_name, etc.')
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

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (EmailTemplate $record): string {
                        return $record->subject;
                    }),

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
                    ->options(TemplateKey::optionsForChannel('email')),

                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active Status')
                    ->placeholder('All templates')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                // Actions\Action::make('preview')
                //     ->label('Preview')
                //     ->icon('heroicon-o-eye')
                //     ->color('info')
                //     ->modalHeading(fn (EmailTemplate $record): string => "Preview: {$record->key}")
                //     ->modalContent(fn (EmailTemplate $record) => new HtmlString(
                //         view('filament.resources.email-template.preview', [
                //             'template' => $record,
                //             'rendered' => $record->render(self::getExampleData($record)),
                //             'renderedText' => $record->renderText(self::getExampleData($record)) ?? '',
                //         ])->render()
                //     ))
                //     ->modalWidth('4xl')
                //     ->modalSubmitAction(false)
                //     ->modalCancelActionLabel('Close'),

                Actions\Action::make('testSend')
                    ->label('Test Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('Recipient Email')
                            ->email()
                            ->required()
                            ->placeholder('test@example.com')
                            ->helperText('Email will be sent to this address with example data'),
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
                                    ->title('Test email sent!')
                                    ->body("Email sent to {$data['email']}")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Failed to send test email')
                                    ->body('Check the email logs for details')
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error sending test email')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Send Test Email')
                    ->modalDescription('This will send a test email with example data to verify the template rendering.'),

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
                'expires_in' => '60 minutes',
            ],
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'appointment_date' => now()->addDays(2)->format('Y-m-d'),
                'appointment_time' => '14:00',
                'service_name' => 'Full Car Detailing',
                'location_address' => 'ul. Przykładowa 123, Warszawa',
            ],
            TemplateKey::APPOINTMENT_CANCELLED->value => [
                'appointment_date' => now()->format('Y-m-d'),
                'appointment_time' => '14:00',
                'service_name' => 'Full Car Detailing',
                'cancellation_reason' => 'Customer request',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'appointment_date' => now()->subDays(3)->format('Y-m-d'),
                'service_name' => 'Full Car Detailing',
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
            return new HtmlString('<p class="text-sm text-gray-500">Select a template key to see available variables</p>');
        }

        // Common variables available in ALL templates
        $commonVariables = [
            'app_name' => 'Application name (from config)',
            'app_url' => 'Application URL',
            'user_name' => 'User\'s full name (first_name + last_name)',
            'user_email' => 'User\'s email address',
            'customer_name' => 'Customer\'s full name (alias for user_name)',
            'current_year' => 'Current year (e.g., 2025)',
            'contact_email' => 'Support email address',
            'contact_phone' => 'Support phone number',
        ];

        // Template-specific variables
        $specificVariables = match ($key) {
            TemplateKey::USER_REGISTERED->value => [
                'verification_url' => 'Email verification link',
            ],
            TemplateKey::PASSWORD_RESET->value => [
                'reset_url' => 'Password reset link',
                'expires_in' => 'Link expiration time (e.g., "60 minutes")',
            ],
            TemplateKey::APPOINTMENT_CREATED->value, TemplateKey::APPOINTMENT_RESCHEDULED->value, TemplateKey::APPOINTMENT_REMINDER_24H->value, TemplateKey::APPOINTMENT_REMINDER_2H->value => [
                'appointment_date' => 'Appointment date (Y-m-d format)',
                'appointment_time' => 'Appointment time (H:i format)',
                'service_name' => 'Service name',
                'location_address' => 'Service location address',
            ],
            TemplateKey::APPOINTMENT_CANCELLED->value => [
                'appointment_date' => 'Appointment date',
                'appointment_time' => 'Appointment time',
                'service_name' => 'Service name',
                'cancellation_reason' => 'Reason for cancellation',
            ],
            TemplateKey::APPOINTMENT_FOLLOWUP->value => [
                'appointment_date' => 'Appointment date',
                'service_name' => 'Service name',
                'feedback_url' => 'Feedback form link',
            ],
            TemplateKey::ADMIN_DAILY_DIGEST->value => [
                'date' => 'Report date',
                'total_appointments' => 'Total appointments',
                'pending_appointments' => 'Pending appointments count',
                'completed_appointments' => 'Completed appointments count',
            ],
            default => [],
        };

        // Build HTML output
        $html = '<div class="space-y-4">';

        // Common variables section
        $html .= '<div>';
        $html .= '<h4 class="text-sm font-semibold text-gray-700 mb-2">Common Variables (Available in all templates)</h4>';
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
            $html .= '<h4 class="text-sm font-semibold text-gray-700 mb-2">Template-Specific Variables</h4>';
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
