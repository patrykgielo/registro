<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\MaintenanceType;
use App\Services\MaintenanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Maintenance Mode Settings Page
 *
 * Filament admin page for managing application maintenance mode.
 * Allows enabling/disabling maintenance with different types and configurations.
 */
class MaintenanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.maintenance-settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?string $navigationLabel = 'Maintenance Mode';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Maintenance Mode';

    /**
     * Permission required to access this page.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasAnyRole(['super-admin', 'admin']) ?? false;
    }

    /**
     * Form state data.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Current maintenance status.
     */
    public bool $isActive = false;

    public ?MaintenanceType $currentType = null;

    public ?array $currentConfig = [];

    public ?string $secretToken = null;

    /**
     * Mount the page and load current status.
     */
    public function mount(): void
    {
        $service = app(MaintenanceService::class);

        $this->isActive = $service->isActive();
        $this->currentType = $service->getType();
        $this->currentConfig = $service->getConfig();
        $this->secretToken = $service->getSecretToken();

        // Pre-fill form with current config if active
        if ($this->isActive && $this->currentType) {
            $formData = [
                'type' => $this->currentType->value,
                'message' => $this->currentConfig['message'] ?? null,
                'estimated_duration' => $this->currentConfig['estimated_duration'] ?? null,
                'launch_date' => $this->currentConfig['launch_date'] ?? null,
                'image_url' => $this->currentConfig['image_url'] ?? null,
            ];

            // Add pre-launch specific fields if type is PRELAUNCH
            if ($this->currentType === MaintenanceType::PRELAUNCH) {
                $formData = array_merge($formData, [
                    'page_title' => $this->currentConfig['page_title'] ?? null,
                    'main_heading' => $this->currentConfig['main_heading'] ?? null,
                    'tagline' => $this->currentConfig['tagline'] ?? null,
                    'launch_date_label' => $this->currentConfig['launch_date_label'] ?? null,
                    'description_part1' => $this->currentConfig['description_part1'] ?? null,
                    'description_part2' => $this->currentConfig['description_part2'] ?? null,
                    'contact_heading' => $this->currentConfig['contact_heading'] ?? null,
                    'copyright_text' => $this->currentConfig['copyright_text'] ?? null,
                    'html_lang' => $this->currentConfig['html_lang'] ?? 'pl',
                    'background_image' => $this->currentConfig['background_image'] ?? null,
                ]);
            }

            $this->form->fill($formData);
        } else {
            // Load persisted config from Settings table (survives disable/enable cycles)
            $persistedConfig = $service->getPersistedConfig();

            $this->form->fill([
                'type' => MaintenanceType::PRELAUNCH->value, // Default to PRELAUNCH since it's most common
                'message' => null,
                'estimated_duration' => null,
                'launch_date' => $persistedConfig['launch_date'] ?? null,
                'image_url' => null,
                // Pre-launch fields from persisted Settings
                'page_title' => $persistedConfig['page_title'] ?? null,
                'main_heading' => $persistedConfig['main_heading'] ?? null,
                'tagline' => $persistedConfig['tagline'] ?? null,
                'launch_date_label' => $persistedConfig['launch_date_label'] ?? null,
                'description_part1' => $persistedConfig['description_part1'] ?? null,
                'description_part2' => $persistedConfig['description_part2'] ?? null,
                'contact_heading' => $persistedConfig['contact_heading'] ?? null,
                'copyright_text' => $persistedConfig['copyright_text'] ?? null,
                'html_lang' => $persistedConfig['html_lang'] ?? 'pl',
                'background_image' => $persistedConfig['background_image'] ?? null,
            ]);
        }
    }

    /**
     * Define the form schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maintenance Configuration')
                    ->description('Configure maintenance mode settings')
                    ->schema([
                        Select::make('type')
                            ->label('Maintenance Type')
                            ->options([
                                MaintenanceType::DEPLOYMENT->value => MaintenanceType::DEPLOYMENT->label().' - Admins can bypass',
                                MaintenanceType::SCHEDULED->value => MaintenanceType::SCHEDULED->label().' - Admins can bypass',
                                MaintenanceType::EMERGENCY->value => MaintenanceType::EMERGENCY->label().' - Admins can bypass',
                                MaintenanceType::PRELAUNCH->value => MaintenanceType::PRELAUNCH->label().' - NO bypass allowed',
                            ])
                            ->required()
                            ->reactive()
                            ->helperText('PRELAUNCH: Complete lockdown, no one can bypass (not even admins)')
                            ->columnSpanFull(),

                        Textarea::make('message')
                            ->label('Custom Message')
                            ->rows(3)
                            ->placeholder('Optional message to display to users')
                            ->helperText('Leave empty for default message')
                            ->columnSpanFull(),

                        TextInput::make('estimated_duration')
                            ->label('Estimated Duration')
                            ->placeholder('e.g., 15 minutes, 1 hour')
                            ->helperText('Display estimated downtime (DEPLOYMENT/SCHEDULED/EMERGENCY only)')
                            ->visible(fn ($get) => $get('type') !== MaintenanceType::PRELAUNCH->value)
                            ->columnSpanFull(),
                    ]),

                Section::make('Pre-Launch Page Content')
                    ->description('Customize all text content for pre-launch page. Leave empty to use default values from Settings.')
                    ->visible(fn ($get) => $get('type') === MaintenanceType::PRELAUNCH->value)
                    ->collapsible()
                    ->schema([
                        TextInput::make('page_title')
                            ->label('Tytuł strony (HTML <title>)')
                            ->placeholder('Domyślnie: Wkrótce startujemy - Registro')
                            ->maxLength(60)
                            ->helperText('Wyświetlany w zakładce przeglądarki (SEO: max 60 znaków)'),

                        TextInput::make('main_heading')
                            ->label('Główny nagłówek (H1)')
                            ->placeholder('Domyślnie: Wkrótce Ruszamy!')
                            ->maxLength(100),

                        Textarea::make('tagline')
                            ->label('Podtytuł / Tagline')
                            ->placeholder('Domyślnie: Registro polega na tym, że to my przyjeżdżamy do Ciebie...')
                            ->rows(2)
                            ->maxLength(200)
                            ->columnSpanFull(),

                        TextInput::make('launch_date_label')
                            ->label('Etykieta daty startu')
                            ->placeholder('Domyślnie: Data startu')
                            ->maxLength(50),

                        DatePicker::make('launch_date')
                            ->label('Data startu (wartość)')
                            ->displayFormat('d.m.Y')
                            ->helperText('Data rozpoczęcia działalności'),

                        Textarea::make('description_part1')
                            ->label('Opis - część 1')
                            ->placeholder('Domyślnie: Wprowadzamy autorski system rezerwacji...')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),

                        Textarea::make('description_part2')
                            ->label('Opis - część 2')
                            ->placeholder('Domyślnie: Świadczymy usługi we wskazanej przez Ciebie lokalizacji...')
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull(),

                        TextInput::make('contact_heading')
                            ->label('Nagłówek sekcji kontaktowej')
                            ->placeholder('Domyślnie: Masz pytania?')
                            ->maxLength(100),

                        TextInput::make('copyright_text')
                            ->label('Tekst copyright (stopka)')
                            ->placeholder('Domyślnie: Registro. Wszelkie prawa zastrzeżone.')
                            ->maxLength(100),

                        Select::make('html_lang')
                            ->label('Język strony (HTML lang)')
                            ->options([
                                'pl' => 'Polski (pl)',
                                'en' => 'English (en)',
                            ])
                            ->default('pl'),

                        FileUpload::make('background_image')
                            ->label('Tło strony (Background Image)')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->directory('maintenance/backgrounds')
                            ->maxSize(5120)
                            ->imagePreviewHeight('200px')
                            ->helperText('Dozwolone: JPG, PNG, WebP (max 5MB)')
                            ->columnSpanFull(),

                        Placeholder::make('contact_info')
                            ->label('Email i telefon')
                            ->content('⚙️ Zarządzane w Ustawieniach systemowych → Kontakt')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Get header actions.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('enable')
                ->label($this->isActive ? 'Update Configuration' : 'Enable Maintenance')
                ->color($this->isActive ? 'warning' : 'danger')
                ->icon($this->isActive ? 'heroicon-o-wrench-screwdriver' : 'heroicon-o-lock-closed')
                ->requiresConfirmation()
                ->modalHeading($this->isActive ? 'Update Maintenance Configuration?' : 'Enable Maintenance Mode?')
                ->modalDescription(fn () => $this->isActive
                    ? 'This will update the current maintenance configuration. Users will see the new settings immediately.'
                    : 'This will put the application into maintenance mode. Only authorized users will be able to access the site.'
                )
                ->modalSubmitActionLabel($this->isActive ? 'Update' : 'Enable')
                ->action('enableMaintenance'),

            Action::make('disable')
                ->label('Disable Maintenance')
                ->color('success')
                ->icon('heroicon-o-lock-open')
                ->requiresConfirmation()
                ->modalHeading('Disable Maintenance Mode?')
                ->modalDescription('This will restore normal site access for all users.')
                ->modalSubmitActionLabel('Disable')
                ->visible($this->isActive)
                ->action('disableMaintenance'),

            Action::make('regenerate_token')
                ->label('Regenerate Secret Token')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Regenerate Secret Token?')
                ->modalDescription('This will invalidate the old token. Users with the old token will lose bypass access.')
                ->modalSubmitActionLabel('Regenerate')
                ->visible($this->isActive && $this->currentType !== MaintenanceType::PRELAUNCH)
                ->action('regenerateToken'),

            Action::make('view_logs')
                ->label('View Event Log')
                ->color('gray')
                ->icon('heroicon-o-document-text')
                ->url(route('filament.admin.resources.maintenance-events.index'))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Enable or update maintenance mode.
     */
    public function enableMaintenance(): void
    {
        $data = $this->form->getState();
        $service = app(MaintenanceService::class);

        try {
            $type = MaintenanceType::from($data['type']);

            $config = [
                'message' => $data['message'] ?? null,
            ];

            // Add type-specific config
            if ($type === MaintenanceType::PRELAUNCH) {
                // Existing launch_date and image_url fields
                if (! empty($data['launch_date'])) {
                    $config['launch_date'] = $data['launch_date'];
                }
                if (! empty($data['image_url'])) {
                    $config['image_url'] = $data['image_url'];
                }

                // NEW: Add all pre-launch content fields
                $config['page_title'] = $data['page_title'] ?? null;
                $config['main_heading'] = $data['main_heading'] ?? null;
                $config['tagline'] = $data['tagline'] ?? null;
                $config['launch_date_label'] = $data['launch_date_label'] ?? null;
                $config['description_part1'] = $data['description_part1'] ?? null;
                $config['description_part2'] = $data['description_part2'] ?? null;
                $config['contact_heading'] = $data['contact_heading'] ?? null;
                $config['copyright_text'] = $data['copyright_text'] ?? null;
                $config['html_lang'] = $data['html_lang'] ?? 'pl';
                $config['background_image'] = $data['background_image'] ?? null;
            } else {
                if (! empty($data['estimated_duration'])) {
                    $config['estimated_duration'] = $data['estimated_duration'];
                }
            }

            $service->enable(
                type: $type,
                user: Auth::user(),
                config: $config
            );

            $this->refreshStatus();

            Notification::make()
                ->title($this->isActive ? 'Maintenance mode updated' : 'Maintenance mode enabled')
                ->body("Type: {$type->label()}")
                ->success()
                ->send();

            // Show secret token notification (except for PRELAUNCH)
            if ($type !== MaintenanceType::PRELAUNCH && $this->secretToken) {
                Notification::make()
                    ->title('Secret Bypass Token Generated')
                    ->body("Token: {$this->secretToken}")
                    ->success()
                    ->persistent()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to enable maintenance mode')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Disable maintenance mode.
     */
    public function disableMaintenance(): void
    {
        $service = app(MaintenanceService::class);

        try {
            $service->disable(user: Auth::user());

            $this->refreshStatus();

            Notification::make()
                ->title('Maintenance mode disabled')
                ->body('Site is now accessible to all users')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to disable maintenance mode')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Regenerate secret bypass token.
     */
    public function regenerateToken(): void
    {
        $service = app(MaintenanceService::class);

        try {
            $newToken = $service->regenerateSecretToken(user: Auth::user());

            $this->refreshStatus();

            Notification::make()
                ->title('Secret token regenerated')
                ->body("New token: {$newToken}")
                ->success()
                ->persistent()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to regenerate token')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Refresh current status from service.
     */
    private function refreshStatus(): void
    {
        $service = app(MaintenanceService::class);

        $this->isActive = $service->isActive();
        $this->currentType = $service->getType();
        $this->currentConfig = $service->getConfig();
        $this->secretToken = $service->getSecretToken();
    }
}
