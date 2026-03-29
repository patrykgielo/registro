<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Traits\HasGroupedSettings;
use App\Models\Page as PageModel;
use App\Services\Sms\SmsService;
use App\Support\Settings\SettingsManager;
use App\Support\TenantFeature;
use BackedEnum;
use enshrined\svgSanitize\Sanitizer;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * System Settings Page
 *
 * Filament admin page for managing application-wide settings.
 * Settings are grouped into tabs: Booking, Map, Contact, Marketing, Email.
 */
class SystemSettings extends Page implements HasForms
{
    use HasGroupedSettings;
    use InteractsWithForms;

    /**
     * Page view.
     */
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-8-tooth';

    /**
     * Navigation group.
     */
    protected static string|UnitEnum|null $navigationGroup = 'settings';

    /**
     * Navigation sort.
     */
    protected static ?int $navigationSort = 1;

    /**
     * Navigation label.
     */
    protected static ?string $navigationLabel = 'System Settings';

    /**
     * Page view.
     */
    protected string $view = 'filament.pages.system-settings';

    /**
     * Permission required to access this page.
     */
    protected static ?string $permission = 'settings.manage';

    /**
     * Restrict access to admins and super-admins only.
     * Overrides permission-based authorization for stricter control.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    /**
     * Maps settings tab keys to the module required for that tab to be visible.
     * Tabs without an entry are always visible (core settings).
     */
    private const TAB_MODULE_MAP = [
        'booking' => 'bookings',
        'booking_wizard' => 'bookings',
        'map' => 'website',
        'marketing' => 'website',
        'email' => 'communication',
        'sms' => 'communication',
        'cms' => 'website',
        'integrations' => 'website',
        'checkout' => 'rentals',
    ];

    /**
     * Determine if a settings tab should be visible for the current tenant.
     * Super-admins (no tenant context) always see all tabs.
     */
    private function isTabVisible(string $tab): bool
    {
        $module = self::TAB_MODULE_MAP[$tab] ?? null;

        if ($module === null) {
            return true;
        }

        $tenant = TenantFeature::currentTenant();

        if ($tenant === null) {
            return true;
        }

        return $tenant->hasModule($module);
    }

    /**
     * Form state data.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Mount the page and load settings.
     */
    public function mount(): void
    {
        $settingsManager = app(SettingsManager::class);
        $allSettings = $settingsManager->all();

        // Flatten settings for form
        $this->form->fill($allSettings);
    }

    /**
     * Define all settings groups with their labels and validation rules.
     *
     * @return array<string, array{label: string, rules: array<string, string|array>}>
     */
    protected function getSettingsGroups(): array
    {
        return [
            'general' => [
                'label' => 'Ustawienia ogólne zapisane',
                'rules' => [
                    'app_name' => ['required', 'string', 'max:100'],
                ],
            ],
            'booking' => [
                'label' => 'Ustawienia rezerwacji zapisane',
                'rules' => [
                    'booking_enabled' => ['nullable', 'boolean'],
                    'business_hours_start' => ['required', 'date_format:H:i'],
                    'business_hours_end' => ['required', 'date_format:H:i'],
                    'slot_interval_minutes' => ['required', 'integer', 'min:15', 'max:120'],
                    'advance_booking_hours' => ['required', 'integer', 'min:1'],
                    'cancellation_hours' => ['required', 'integer', 'min:1'],
                    'max_service_duration_minutes' => ['required', 'integer', 'min:60'],
                ],
            ],
            'auth' => [
                'label' => 'Ustawienia autoryzacji zapisane',
                'rules' => [
                    'registration_enabled' => ['nullable', 'boolean'],
                ],
            ],
            'booking_wizard' => [
                'label' => 'Ustawienia systemu rezerwacji zapisane',
                'rules' => [
                    'before_visit_items' => ['nullable', 'array'],
                    'before_visit_items.*.item' => ['required_with:before_visit_items', 'string', 'max:500'],
                    'service_location_types' => ['nullable', 'array'],
                    'service_location_types.*.name' => ['required', 'string', 'max:100'],
                    'service_location_types.*.icon' => ['nullable', 'string', 'max:50'],
                    'service_location_types.*.description' => ['nullable', 'string', 'max:200'],
                ],
            ],
            'map' => [
                'label' => 'Ustawienia mapy zapisane',
                'rules' => [
                    'default_latitude' => ['required', 'numeric'],
                    'default_longitude' => ['required', 'numeric'],
                    'default_zoom' => ['required', 'integer', 'min:1', 'max:20'],
                    'country_code' => ['required', 'string', 'size:2'],
                    'map_id' => ['nullable', 'string', 'max:255'],
                    'debug_panel_enabled' => ['nullable', 'boolean'],
                ],
            ],
            'contact' => [
                'label' => 'Ustawienia kontaktowe zapisane',
                'rules' => [
                    'email' => ['required', 'email'],
                    'phone' => ['required', 'string'],
                    'address_line' => ['required', 'string'],
                    'city' => ['required', 'string'],
                    'postal_code' => ['required', 'string'],
                ],
            ],
            'appearance' => [
                'label' => 'Ustawienia wyglądu zapisane',
                'rules' => [
                    'header_logo' => ['nullable'],
                    'footer_logo' => ['nullable'],
                    'logo_alt' => ['nullable', 'string', 'max:100'],
                ],
            ],
            'marketing' => [
                'label' => 'Ustawienia marketingowe zapisane',
                'rules' => [
                    'hero_title' => ['required', 'string', 'max:100'],
                    'hero_subtitle' => ['required', 'string', 'max:200'],
                    'services_heading' => ['required', 'string'],
                    'services_subheading' => ['required', 'string'],
                    'features_heading' => ['required', 'string'],
                    'features_subheading' => ['required', 'string'],
                    'features' => ['nullable', 'array'],
                    'features.*.title' => ['required_with:features', 'string'],
                    'features.*.description' => ['required_with:features', 'string'],
                    'cta_heading' => ['required', 'string'],
                    'cta_subheading' => ['required', 'string'],
                    'important_info_heading' => ['required', 'string'],
                    'important_info_points' => ['nullable', 'array'],
                    'important_info_points.*.point' => ['required_with:important_info_points', 'string'],
                ],
            ],
            'email' => [
                'label' => 'Ustawienia email zapisane',
                'rules' => [
                    'smtp_host' => ['required', 'string'],
                    'smtp_port' => ['required', 'integer'],
                    'smtp_encryption' => ['required', 'in:tls,ssl'],
                    'smtp_username' => ['nullable', 'string'],
                    'smtp_password' => ['nullable', 'string'],
                    'from_name' => ['required', 'string'],
                    'from_address' => ['required', 'email'],
                    'retry_attempts' => ['required', 'integer', 'min:1', 'max:5'],
                    'backoff_seconds' => ['required', 'integer', 'min:30'],
                    'admin_digest_enabled' => ['nullable', 'boolean'],
                ],
            ],
            'sms' => [
                'label' => 'Ustawienia SMS zapisane',
                'rules' => [
                    'enabled' => ['nullable', 'boolean'],
                    'service' => ['required', 'in:pl,com'],
                    'sender_name' => ['required', 'string', 'max:11'],
                    'test_mode' => ['nullable', 'boolean'],
                    'send_booking_confirmation' => ['nullable', 'boolean'],
                    'send_admin_confirmation' => ['nullable', 'boolean'],
                    'daily_limit' => ['required', 'integer', 'min:1', 'max:100000'],
                    'monthly_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
                    'alert_threshold' => ['required', 'integer', 'min:1', 'max:100'],
                    'alert_email' => ['required', 'email'],
                ],
            ],
            'cms' => [
                'label' => 'Ustawienia CMS zapisane',
                'rules' => [
                    'homepage_page_id' => ['required', 'integer', 'exists:pages,id'],
                    'footer_column_title' => ['nullable', 'string', 'max:50'],
                ],
            ],
            'checkout' => [
                'label' => 'Ustawienia checkout zapisane',
                'rules' => [
                    'terms_label' => ['nullable', 'string', 'max:5000'],
                    'rodo_label' => ['nullable', 'string', 'max:5000'],
                    'withdrawal_label' => ['nullable', 'string', 'max:5000'],
                    'deposit_policy_note' => ['nullable', 'string', 'max:2000'],
                ],
            ],
            'integrations' => [
                'label' => 'Ustawienia integracji zapisane',
                'rules' => [
                    'gtm_enabled' => ['nullable', 'boolean'],
                    'gtm_container_id' => ['nullable', 'string', 'max:20', 'regex:/^GTM-[A-Z0-9]+$/'],
                ],
            ],
        ];
    }

    /**
     * Define the form schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Settings')
                    ->tabs([
                        $this->generalTab(),
                        $this->bookingTab(),
                        $this->bookingWizardTab(),
                        $this->mapTab(),
                        $this->contactTab(),
                        $this->appearanceTab(),
                        $this->marketingTab(),
                        $this->emailTab(),
                        $this->smsTab(),
                        $this->cmsTab(),
                        $this->integrationsTab(),
                        $this->checkoutTab(),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    /**
     * General settings tab.
     */
    private function generalTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Ogólne')
            ->icon('heroicon-o-building-storefront')
            ->schema([
                Section::make('Nazwa aplikacji')
                    ->description('Nazwa wyświetlana w powiadomieniach SMS/Email oraz w szablonach')
                    ->schema([
                        TextInput::make('general.app_name')
                            ->label('Nazwa aplikacji')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Registro')
                            ->helperText('Nazwa używana w szablonach wiadomości jako {{app_name}}'),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveGeneral')
                        ->label('Zapisz ustawienia')
                        ->action('saveGeneralSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Booking settings tab.
     */
    private function bookingTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Booking')
            ->visible(fn () => $this->isTabVisible('booking'))
            ->schema([
                Section::make('Dostępność systemu')
                    ->description('Włącz/wyłącz rezerwację online i rejestrację użytkowników')
                    ->schema([
                        Toggle::make('booking.booking_enabled')
                            ->label('Rezerwacja online aktywna')
                            ->helperText('Wyłączenie zamieni przyciski "Zarezerwuj" na "Skontaktuj się z nami" z numerem telefonu. Bezpośredni dostęp do rezerwacji będzie przekierowywał na stronę główną.')
                            ->default(true),
                        Toggle::make('auth.registration_enabled')
                            ->label('Rejestracja nowych użytkowników')
                            ->helperText('Wyłączenie ukryje formularz rejestracji i zablokuje dostęp do /register. Logowanie istniejących kont nadal działa.')
                            ->default(true),
                    ])
                    ->icon('heroicon-o-power'),

                Section::make('Business Hours')
                    ->description('Configure your business operating hours')
                    ->schema([
                        TextInput::make('booking.business_hours_start')
                            ->label('Business Hours Start')
                            ->type('time')
                            ->required()
                            ->helperText('Opening time (HH:MM format)'),

                        TextInput::make('booking.business_hours_end')
                            ->label('Business Hours End')
                            ->type('time')
                            ->required()
                            ->helperText('Closing time (HH:MM format)'),
                    ])
                    ->columns(2),

                Section::make('Booking Rules')
                    ->description('Configure booking and cancellation policies')
                    ->schema([
                        TextInput::make('booking.slot_interval_minutes')
                            ->label('Slot Interval (minutes)')
                            ->numeric()
                            ->required()
                            ->minValue(15)
                            ->maxValue(120)
                            ->helperText('Time interval between available slots'),

                        TextInput::make('booking.advance_booking_hours')
                            ->label('Advance Booking (hours)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->helperText('Minimum hours in advance for booking'),

                        TextInput::make('booking.cancellation_hours')
                            ->label('Cancellation Window (hours)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->helperText('Hours before appointment for free cancellation'),

                        TextInput::make('booking.max_service_duration_minutes')
                            ->label('Max Service Duration (minutes)')
                            ->numeric()
                            ->required()
                            ->minValue(60)
                            ->helperText('Maximum duration for a single service'),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveBooking')
                        ->label('Zapisz ustawienia')
                        ->action('saveBookingSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Booking wizard settings tab.
     */
    private function bookingWizardTab(): Tabs\Tab
    {
        return Tabs\Tab::make('System rezerwacji')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn () => $this->isTabVisible('booking_wizard'))
            ->schema([
                Section::make('Przed wizytą')
                    ->description('Lista informacji wyświetlanych klientowi po potwierdzeniu rezerwacji')
                    ->schema([
                        Repeater::make('booking_wizard.before_visit_items')
                            ->label('Punkty listy "Przed wizytą"')
                            ->simple(
                                TextInput::make('item')
                                    ->label('Punkt listy')
                                    ->required()
                            )
                            ->defaultItems(4)
                            ->addActionLabel('Dodaj punkt')
                            ->helperText('Te informacje będą wyświetlane na ekranie potwierdzenia rezerwacji'),
                    ]),

                Section::make('Typy lokalizacji serwisu')
                    ->description('Opcje miejsca postoju pojazdu podczas realizacji usługi (np. rodzaj parkingu)')
                    ->schema([
                        Repeater::make('booking_wizard.service_location_types')
                            ->label('Typy lokalizacji')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nazwa')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('np. Parking podziemny'),

                                Select::make('icon')
                                    ->label('Ikona Heroicon')
                                    ->options(fn () => self::getHeroiconOptions())
                                    ->getSearchResultsUsing(function (string $search): array {
                                        $allOptions = self::getHeroiconOptions();
                                        $search = strtolower($search);

                                        return collect($allOptions)
                                            ->filter(fn ($label, $value) => str_contains(strtolower($value), $search)
                                                    || str_contains(strtolower($label), $search)
                                            )
                                            ->take(50)
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->default('map-pin')
                                    ->hintAction(
                                        \Filament\Actions\Action::make('browseIcons')
                                            ->label('Przeglądaj ikony')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->url('https://heroicons.com', shouldOpenInNewTab: true)
                                    )
                                    ->helperText('Wyszukaj po nazwie (np. "building" lub "shield") • Używaj ikon "Outline"'),

                                Textarea::make('description')
                                    ->label('Opis (opcjonalny)')
                                    ->maxLength(200)
                                    ->rows(2)
                                    ->placeholder('np. Wymagany kod dostępu do garażu'),
                            ])
                            ->columns(1)
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Nowa opcja')
                            ->collapsible()
                            ->collapsed()
                            ->reorderable()
                            ->addActionLabel('Dodaj typ lokalizacji')
                            ->defaultItems(0)
                            ->helperText('Klient wybierze jeden z tych typów w kroku 3 rezerwacji (po zatwierdzeniu lokalizacji)'),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveBookingWizard')
                        ->label('Zapisz ustawienia')
                        ->action('saveBookingWizardSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Map settings tab.
     */
    private function mapTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Map')
            ->visible(fn () => $this->isTabVisible('map'))
            ->schema([
                Section::make('Google Maps Configuration')
                    ->description('Configure Google Maps integration')
                    ->schema([
                        TextInput::make('map.default_latitude')
                            ->label('Default Latitude')
                            ->numeric()
                            ->required()
                            ->helperText('Default map center latitude'),

                        TextInput::make('map.default_longitude')
                            ->label('Default Longitude')
                            ->numeric()
                            ->required()
                            ->helperText('Default map center longitude'),

                        TextInput::make('map.default_zoom')
                            ->label('Default Zoom Level')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(20)
                            ->helperText('Default map zoom (1-20)'),

                        TextInput::make('map.country_code')
                            ->label('Country Code')
                            ->maxLength(2)
                            ->required()
                            ->helperText('Two-letter country code (e.g., "pl")'),

                        TextInput::make('map.map_id')
                            ->label('Map ID')
                            ->maxLength(255)
                            ->helperText('Google Cloud Map ID (optional)'),

                        Toggle::make('map.debug_panel_enabled')
                            ->label('Debug Panel Enabled')
                            ->helperText('Show debug panel in booking wizard'),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveMap')
                        ->label('Zapisz ustawienia')
                        ->action('saveMapSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Contact settings tab.
     */
    private function contactTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Contact')
            ->schema([
                Section::make('Business Contact Information')
                    ->description('Your business contact details')
                    ->schema([
                        TextInput::make('contact.email')
                            ->label('Contact Email')
                            ->email()
                            ->required()
                            ->helperText('Public contact email'),

                        TextInput::make('contact.phone')
                            ->label('Phone Number')
                            ->tel()
                            ->required()
                            ->helperText('Contact phone number'),

                        TextInput::make('contact.address_line')
                            ->label('Address Line')
                            ->required()
                            ->helperText('Street address'),

                        TextInput::make('contact.city')
                            ->label('City')
                            ->required(),

                        TextInput::make('contact.postal_code')
                            ->label('Postal Code')
                            ->required(),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveContact')
                        ->label('Zapisz ustawienia')
                        ->action('saveContactSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Appearance settings tab.
     */
    private function appearanceTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Wygląd')
            ->icon('heroicon-o-swatch')
            ->schema([
                Section::make('Header')
                    ->description('Logo wyświetlane w nawigacji')
                    ->schema([
                        FileUpload::make('appearance.header_logo')
                            ->label('Logo nagłówka')
                            ->disk('public')
                            ->directory('settings/logos')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'])
                            ->maxSize(1024)
                            ->imagePreviewHeight('80')
                            ->helperText('Logo dla nagłówka. SVG, PNG, WebP lub JPEG, max 1MB.')
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
                                $mimeType = $file->getMimeType();

                                // Validate magic bytes for raster images
                                if ($mimeType !== 'image/svg+xml') {
                                    $magicBytes = file_get_contents($file->getRealPath(), false, null, 0, 8);
                                    $validSignatures = [
                                        "\x89PNG\x0D\x0A\x1A\x0A",  // PNG
                                        'RIFF',                      // WebP
                                        "\xFF\xD8\xFF",              // JPEG
                                    ];

                                    $isValid = false;
                                    foreach ($validSignatures as $signature) {
                                        if (str_starts_with($magicBytes, $signature)) {
                                            $isValid = true;
                                            break;
                                        }
                                    }

                                    if (! $isValid) {
                                        throw new \Exception('Invalid image format');
                                    }
                                }

                                // Store file
                                $path = $file->storePublicly('settings/logos', 'public');

                                // Sanitize SVG to prevent XSS
                                if ($mimeType === 'image/svg+xml') {
                                    $storage = Storage::disk('public');
                                    $content = $storage->get($path);

                                    $sanitizer = new Sanitizer;
                                    $sanitizer->removeRemoteReferences(true);
                                    $cleanSvg = $sanitizer->sanitize($content);

                                    if ($cleanSvg === false) {
                                        $storage->delete($path);
                                        throw new \Exception('SVG contains dangerous content');
                                    }

                                    $storage->put($path, $cleanSvg);
                                }

                                return $path;
                            }),
                    ]),

                Section::make('Footer')
                    ->description('Logo wyświetlane w stopce')
                    ->schema([
                        FileUpload::make('appearance.footer_logo')
                            ->label('Logo stopki')
                            ->disk('public')
                            ->directory('settings/logos')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'])
                            ->maxSize(1024)
                            ->imagePreviewHeight('80')
                            ->helperText('Logo dla stopki. SVG, PNG, WebP lub JPEG, max 1MB.')
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
                                $mimeType = $file->getMimeType();

                                // Validate magic bytes for raster images
                                if ($mimeType !== 'image/svg+xml') {
                                    $magicBytes = file_get_contents($file->getRealPath(), false, null, 0, 8);
                                    $validSignatures = [
                                        "\x89PNG\x0D\x0A\x1A\x0A",  // PNG
                                        'RIFF',                      // WebP
                                        "\xFF\xD8\xFF",              // JPEG
                                    ];

                                    $isValid = false;
                                    foreach ($validSignatures as $signature) {
                                        if (str_starts_with($magicBytes, $signature)) {
                                            $isValid = true;
                                            break;
                                        }
                                    }

                                    if (! $isValid) {
                                        throw new \Exception('Invalid image format');
                                    }
                                }

                                // Store file
                                $path = $file->storePublicly('settings/logos', 'public');

                                // Sanitize SVG to prevent XSS
                                if ($mimeType === 'image/svg+xml') {
                                    $storage = Storage::disk('public');
                                    $content = $storage->get($path);

                                    $sanitizer = new Sanitizer;
                                    $sanitizer->removeRemoteReferences(true);
                                    $cleanSvg = $sanitizer->sanitize($content);

                                    if ($cleanSvg === false) {
                                        $storage->delete($path);
                                        throw new \Exception('SVG contains dangerous content');
                                    }

                                    $storage->put($path, $cleanSvg);
                                }

                                return $path;
                            }),
                    ]),

                Section::make('Tekst alternatywny')
                    ->schema([
                        TextInput::make('appearance.logo_alt')
                            ->label('Alt text logo')
                            ->maxLength(100)
                            ->helperText('Dla dostępności. Domyślnie: nazwa aplikacji.'),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveAppearance')
                        ->label('Zapisz ustawienia')
                        ->action('saveAppearanceSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Marketing settings tab.
     */
    private function marketingTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Marketing')
            ->visible(fn () => $this->isTabVisible('marketing'))
            ->schema([
                Section::make('Hero Section')
                    ->description('Homepage hero section content')
                    ->schema([
                        TextInput::make('marketing.hero_title')
                            ->label('Hero Title')
                            ->required()
                            ->maxLength(100),

                        Textarea::make('marketing.hero_subtitle')
                            ->label('Hero Subtitle')
                            ->required()
                            ->maxLength(200)
                            ->rows(2),
                    ]),

                Section::make('Services Section')
                    ->description('Services section headings')
                    ->schema([
                        TextInput::make('marketing.services_heading')
                            ->label('Services Heading')
                            ->required(),

                        TextInput::make('marketing.services_subheading')
                            ->label('Services Subheading')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Features Section')
                    ->description('Features section content')
                    ->schema([
                        TextInput::make('marketing.features_heading')
                            ->label('Features Heading')
                            ->required(),

                        TextInput::make('marketing.features_subheading')
                            ->label('Features Subheading')
                            ->required(),

                        Repeater::make('marketing.features')
                            ->label('Features')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Feature Title')
                                    ->required(),

                                Textarea::make('description')
                                    ->label('Feature Description')
                                    ->required()
                                    ->rows(2),
                            ])
                            ->columns(2)
                            ->defaultItems(3)
                            ->addActionLabel('Add Feature')
                            ->collapsible(),
                    ]),

                Section::make('Call to Action')
                    ->description('CTA section content')
                    ->schema([
                        TextInput::make('marketing.cta_heading')
                            ->label('CTA Heading')
                            ->required(),

                        TextInput::make('marketing.cta_subheading')
                            ->label('CTA Subheading')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Important Information')
                    ->description('Important info section')
                    ->schema([
                        TextInput::make('marketing.important_info_heading')
                            ->label('Important Info Heading')
                            ->required(),

                        Repeater::make('marketing.important_info_points')
                            ->label('Info Points')
                            ->simple(
                                TextInput::make('point')
                                    ->label('Info Point')
                                    ->required()
                            )
                            ->defaultItems(3)
                            ->addActionLabel('Add Point'),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveMarketing')
                        ->label('Zapisz ustawienia')
                        ->action('saveMarketingSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Email settings tab.
     */
    private function emailTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Email')
            ->visible(fn () => $this->isTabVisible('email'))
            ->schema([
                Section::make('SMTP Configuration')
                    ->description('Configure SMTP server for sending emails')
                    ->schema([
                        TextInput::make('email.smtp_host')
                            ->label('SMTP Host')
                            ->required()
                            ->helperText('SMTP server hostname (e.g., smtp.gmail.com)'),

                        TextInput::make('email.smtp_port')
                            ->label('SMTP Port')
                            ->numeric()
                            ->required()
                            ->helperText('SMTP port (587 for TLS, 465 for SSL)'),

                        Select::make('email.smtp_encryption')
                            ->label('Encryption')
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                            ])
                            ->required()
                            ->helperText('Encryption protocol'),

                        TextInput::make('email.smtp_username')
                            ->label('SMTP Username')
                            ->helperText('SMTP authentication username (usually email)'),

                        TextInput::make('email.smtp_password')
                            ->label('SMTP Password')
                            ->password()
                            ->revealable()
                            ->helperText('SMTP authentication password'),
                    ])
                    ->columns(2),

                Section::make('Email Settings')
                    ->description('Configure sender information and retry behavior')
                    ->schema([
                        TextInput::make('email.from_name')
                            ->label('From Name')
                            ->required()
                            ->helperText('Display name for outgoing emails'),

                        TextInput::make('email.from_address')
                            ->label('From Address')
                            ->email()
                            ->required()
                            ->helperText('Email address for outgoing emails'),

                        TextInput::make('email.retry_attempts')
                            ->label('Retry Attempts')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(5)
                            ->helperText('Number of retry attempts for failed emails'),

                        TextInput::make('email.backoff_seconds')
                            ->label('Backoff Seconds')
                            ->numeric()
                            ->required()
                            ->minValue(30)
                            ->helperText('Seconds to wait between retry attempts'),
                    ])
                    ->columns(2),

                Section::make('Notification Settings')
                    ->description('Reminder settings are managed in Reminder Config panel')
                    ->schema([
                        Toggle::make('email.admin_digest_enabled')
                            ->label('Admin Digest Enabled')
                            ->helperText('Send daily digest to admins'),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveEmail')
                        ->label('Zapisz ustawienia')
                        ->action('saveEmailSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),

                    \Filament\Actions\Action::make('testEmail')
                        ->label('Testuj połączenie')
                        ->color('gray')
                        ->icon('heroicon-o-paper-airplane')
                        ->action('testEmailConnection')
                        ->requiresConfirmation()
                        ->modalHeading('Test połączenia email')
                        ->modalDescription('Wyślemy testowy email na Twój adres. Kontynuować?')
                        ->modalSubmitActionLabel('Wyślij testowy email'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * SMS settings tab.
     */
    private function smsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('SMS')
            ->visible(fn () => $this->isTabVisible('sms'))
            ->schema([
                Section::make('SMSAPI Configuration')
                    ->description('Configure SMSAPI.pl integration for sending SMS')
                    ->schema([
                        Toggle::make('sms.enabled')
                            ->label('SMS Enabled')
                            ->helperText('Enable or disable SMS notifications globally'),

                        Placeholder::make('api_token_info')
                            ->label('API Token')
                            ->content('⚙️ Configure SMSAPI_TOKEN in .env file for security')
                            ->helperText('API token is no longer stored in database for security reasons. Set SMSAPI_TOKEN in your .env file.'),

                        Select::make('sms.service')
                            ->label('Service')
                            ->options([
                                'pl' => 'SMSAPI.pl (Poland)',
                                'com' => 'SMSAPI.com (International)',
                            ])
                            ->required()
                            ->helperText('SMSAPI service endpoint'),

                        TextInput::make('sms.sender_name')
                            ->label('Sender Name')
                            ->maxLength(11)
                            ->required()
                            ->helperText('Max 11 characters, alphanumeric'),

                        Toggle::make('sms.test_mode')
                            ->label('Test Mode (Sandbox)')
                            ->helperText('Send SMS in test mode (no actual delivery)'),
                    ])
                    ->columns(2),

                Section::make('SMS Notification Settings')
                    ->description('Event-driven SMS. Reminders are managed in Reminder Config panel.')
                    ->schema([
                        Toggle::make('sms.send_booking_confirmation')
                            ->label('Booking Confirmation')
                            ->helperText('Send SMS when customer creates appointment'),

                        Toggle::make('sms.send_admin_confirmation')
                            ->label('Admin Confirmation')
                            ->helperText('Send SMS when admin confirms appointment'),
                    ])
                    ->columns(2),

                Section::make('SMS Cost Control')
                    ->description('Spending limits and alerts to control SMS costs')
                    ->schema([
                        TextInput::make('sms.daily_limit')
                            ->label('Daily SMS Limit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->default(500)
                            ->required()
                            ->helperText('Maximum SMS messages per day (also configurable via SMS_DAILY_LIMIT in .env)'),

                        TextInput::make('sms.monthly_limit')
                            ->label('Monthly SMS Limit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1000000)
                            ->default(10000)
                            ->required()
                            ->helperText('Maximum SMS messages per month (also configurable via SMS_MONTHLY_LIMIT in .env)'),

                        TextInput::make('sms.alert_threshold')
                            ->label('Alert Threshold (%)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(80)
                            ->suffix('%')
                            ->required()
                            ->helperText('Send alert email when reaching this percentage of daily/monthly limit'),

                        TextInput::make('sms.alert_email')
                            ->label('Alert Email')
                            ->email()
                            ->default('admin@example.com')
                            ->required()
                            ->helperText('Email address for cost alerts (also configurable via SMS_ALERT_EMAIL in .env)'),
                    ])
                    ->columns(2),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveSms')
                        ->label('Zapisz ustawienia')
                        ->action('saveSmsSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),

                    \Filament\Actions\Action::make('testSms')
                        ->label('Testuj połączenie')
                        ->color('gray')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->action('testSmsConnection')
                        ->requiresConfirmation()
                        ->modalHeading('Test połączenia SMS')
                        ->modalDescription('Wyślemy testowy SMS na Twój numer telefonu. Kontynuować?')
                        ->modalSubmitActionLabel('Wyślij testowy SMS'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * CMS settings tab.
     */
    private function cmsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('CMS')
            ->visible(fn () => $this->isTabVisible('cms'))
            ->schema([
                Section::make('Homepage Settings')
                    ->description('Configure which page displays as homepage')
                    ->schema([
                        Select::make('cms.homepage_page_id')
                            ->label('Homepage')
                            ->options(PageModel::published()->pluck('title', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('Select which page displays at / (root URL). Page must have slug="/"'),

                        Placeholder::make('homepage_info')
                            ->label('Current Homepage')
                            ->content(function ($get) {
                                $pageId = $get('cms.homepage_page_id');
                                if (! $pageId) {
                                    return 'No homepage set';
                                }
                                $page = PageModel::find($pageId);

                                return $page ? "/{$page->slug} → /" : 'Page not found';
                            }),
                    ]),

                Section::make('Footer')
                    ->description('Konfiguracja stopki strony')
                    ->schema([
                        TextInput::make('cms.footer_column_title')
                            ->label('Tytuł kolumny linków w stopce')
                            ->placeholder('Nawigacja')
                            ->maxLength(50)
                            ->helperText('Nagłówek kolumny z linkami w stopce. Domyślnie: "Nawigacja"'),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveCms')
                        ->label('Zapisz ustawienia')
                        ->action('saveCmsSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Integrations settings tab (GTM, Analytics, etc.).
     */
    private function integrationsTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Integrations')
            ->icon('heroicon-o-puzzle-piece')
            ->visible(fn () => $this->isTabVisible('integrations'))
            ->schema([
                Section::make('Google Tag Manager')
                    ->description('Configure GTM for analytics, marketing pixels, and cookie consent')
                    ->schema([
                        Toggle::make('integrations.gtm_enabled')
                            ->label('Enable GTM')
                            ->helperText('Enable Google Tag Manager on frontend pages'),

                        TextInput::make('integrations.gtm_container_id')
                            ->label('Container ID')
                            ->placeholder('GTM-XXXXXX')
                            ->maxLength(20)
                            ->regex('/^GTM-[A-Z0-9]+$/')
                            ->helperText('Your GTM Container ID (format: GTM-XXXXXX)')
                            ->requiredWith('integrations.gtm_enabled'),

                        Placeholder::make('gtm_info')
                            ->label('Setup Instructions')
                            ->content('
                                1. Create GTM account at tagmanager.google.com
                                2. Create a new container for your website
                                3. Copy the Container ID (GTM-XXXXXX) and paste above
                                4. In GTM, add CookieYes Community Template for GDPR cookie consent
                                5. Configure Consent Mode v2 for analytics/marketing tags
                            ')
                            ->columnSpanFull(),

                        Placeholder::make('gtm_status')
                            ->label('Status')
                            ->content(function ($get) {
                                $enabled = $get('integrations.gtm_enabled');
                                $containerId = $get('integrations.gtm_container_id');

                                if (! $enabled) {
                                    return '⚪ GTM is disabled';
                                }

                                if (empty($containerId)) {
                                    return '🟡 GTM enabled but no Container ID set';
                                }

                                if (! preg_match('/^GTM-[A-Z0-9]+$/', $containerId)) {
                                    return '🔴 Invalid Container ID format';
                                }

                                return '🟢 GTM is active with container: '.$containerId;
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Cookie Consent (via GTM)')
                    ->description('Cookie consent is managed through GTM using CookieYes')
                    ->schema([
                        Placeholder::make('cookie_consent_info')
                            ->content('
                                Cookie consent banner is configured in Google Tag Manager:

                                1. In GTM, go to Templates → Search Gallery
                                2. Search for "CookieYes" and add the template
                                3. Create a new tag using CookieYes template
                                4. Set trigger to "All Pages"
                                5. Configure Consent Mode v2 default state (denied)
                                6. Publish your GTM container

                                Benefits of GTM-based consent:
                                • Centralized management of all tracking scripts
                                • Native Consent Mode v2 integration
                                • No additional npm packages needed
                                • Easy updates without code deployment
                            ')
                            ->columnSpanFull(),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveIntegrations')
                        ->label('Zapisz ustawienia')
                        ->action('saveIntegrationsSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Save general settings.
     */
    public function saveGeneralSettings(): void
    {
        $this->saveSettingsGroup('general');
    }

    /**
     * Save booking settings (including auth toggle from "Dostępność systemu" section).
     */
    public function saveBookingSettings(): void
    {
        $this->saveSettingsGroup('booking');
        $this->saveSettingsGroup('auth');
    }

    /**
     * Save booking wizard settings.
     */
    public function saveBookingWizardSettings(): void
    {
        $this->saveSettingsGroup('booking_wizard');
    }

    /**
     * Save map settings.
     */
    public function saveMapSettings(): void
    {
        $this->saveSettingsGroup('map');
    }

    /**
     * Save contact settings.
     */
    public function saveContactSettings(): void
    {
        $this->saveSettingsGroup('contact');
    }

    /**
     * Save appearance settings.
     */
    public function saveAppearanceSettings(): void
    {
        $this->saveSettingsGroup('appearance');
    }

    /**
     * Save marketing settings.
     */
    public function saveMarketingSettings(): void
    {
        $this->saveSettingsGroup('marketing');
    }

    /**
     * Save email settings.
     */
    public function saveEmailSettings(): void
    {
        $this->saveSettingsGroup('email');
    }

    /**
     * Save SMS settings.
     */
    public function saveSmsSettings(): void
    {
        $this->saveSettingsGroup('sms');
    }

    /**
     * Save CMS settings.
     */
    public function saveCmsSettings(): void
    {
        $this->saveSettingsGroup('cms');
    }

    /**
     * Save integrations settings.
     */
    public function saveIntegrationsSettings(): void
    {
        $this->saveSettingsGroup('integrations');
    }

    /**
     * Checkout settings tab.
     */
    private function checkoutTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Checkout')
            ->icon('heroicon-o-clipboard-document-list')
            ->schema([
                Section::make('Treści zgód')
                    ->description('Teksty wyświetlane przy checkboxach zgód w formularzu zamówienia. Używaj edytora aby osadzić linki bezpośrednio w treści zgody (zaznacz słowo → kliknij ikonę linku). Możesz użyć {org_name} w treści zgody RODO jako zmiennej dla nazwy Twojej firmy.')
                    ->schema([
                        RichEditor::make('checkout.terms_label')
                            ->label('Tekst zgody na Regulamin')
                            ->toolbarButtons(['bold', 'italic', 'link'])
                            ->disableToolbarButtons(['attachFiles'])
                            ->helperText('Treść checkboxa "Akceptuję Regulamin". Zaznacz słowo i kliknij ikonę linku aby wstawić link do regulaminu bezpośrednio w tekście.')
                            ->columnSpanFull(),

                        RichEditor::make('checkout.rodo_label')
                            ->label('Tekst zgody RODO')
                            ->toolbarButtons(['bold', 'italic', 'link'])
                            ->disableToolbarButtons(['attachFiles'])
                            ->helperText('Treść zgody na przetwarzanie danych osobowych (Art. 13 RODO). Użyj {org_name} jako zmiennej dla nazwy Twojej firmy. Możesz wstawić link do polityki prywatności bezpośrednio w tekście.')
                            ->columnSpanFull(),

                        RichEditor::make('checkout.withdrawal_label')
                            ->label('Tekst wyłączenia prawa odstąpienia')
                            ->toolbarButtons(['bold', 'italic', 'link'])
                            ->disableToolbarButtons(['attachFiles'])
                            ->helperText('Informacja o braku prawa odstąpienia od umowy (Art. 38(1)(12) UoPK). Nie zmieniaj bez konsultacji prawnej.')
                            ->columnSpanFull(),

                        RichEditor::make('checkout.deposit_policy_note')
                            ->label('Notatka o kaucji')
                            ->toolbarButtons(['bold', 'italic', 'link'])
                            ->disableToolbarButtons(['attachFiles'])
                            ->helperText('Opcjonalny tekst wyjaśniający zasady kaucji. Wyświetlany tylko gdy zamówienie wymaga kaucji.')
                            ->columnSpanFull(),
                    ]),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveCheckout')
                        ->label('Zapisz ustawienia checkout')
                        ->action('saveCheckoutSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Save checkout settings.
     */
    public function saveCheckoutSettings(): void
    {
        $this->saveSettingsGroup('checkout');
    }

    /**
     * Test email connection.
     */
    public function testEmailConnection(): void
    {
        try {
            $user = auth()->user();

            if (! $user || ! $user->email) {
                Notification::make()
                    ->title('No user email found')
                    ->danger()
                    ->send();

                return;
            }

            // Send test email
            Mail::raw('This is a test email from Registro system settings.', function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Test Email - Registro');
            });

            Notification::make()
                ->title('Test email sent successfully')
                ->body("Check your inbox at {$user->email}")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Email test failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Test SMS connection.
     */
    public function testSmsConnection(): void
    {
        try {
            $user = auth()->user();

            if (! $user || ! $user->phone_e164) {
                Notification::make()
                    ->title('No phone number found')
                    ->body('Your user account does not have a phone number (phone_e164). Please add one to test SMS.')
                    ->danger()
                    ->send();

                return;
            }

            // Get SMS service
            $smsService = app(SmsService::class);

            // Send test SMS
            $result = $smsService->sendTestSms(
                $user->phone_e164,
                app()->getLocale()
            );

            Notification::make()
                ->title('Test SMS sent successfully')
                ->body("Check your phone at {$user->phone_e164}")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('SMS test failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Get form actions.
     *
     * Note: Tab-specific actions (like test buttons) are defined within their
     * respective tab methods (emailTab, smsTab) to keep them contextual.
     * This method returns empty to avoid global actions appearing on all tabs.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Generate icon options from Heroicons package (outline icons).
     *
     * Dynamically scans blade-heroicons package for o-* (outline) icons.
     * Auto-updates when package is updated.
     *
     * @return array<string, string> Icon name => Human-readable label
     */
    protected static function getHeroiconOptions(): array
    {
        $iconPath = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');
        $files = glob($iconPath.'/o-*.svg');

        if (empty($files)) {
            // Fallback to common icons for location types
            return [
                'map-pin' => 'Map Pin',
                'building-office' => 'Building Office',
                'sun' => 'Sun',
                'shield-check' => 'Shield Check',
                'home' => 'Home',
                'squares-plus' => 'Squares Plus',
                'building-library' => 'Building Library',
                'square-3-stack-3d' => 'Square 3 Stack 3D',
            ];
        }

        $icons = [];
        foreach ($files as $file) {
            $name = str_replace('.svg', '', basename($file));
            $name = str_replace('o-', '', $name);

            // Format: "arrow-down" => "Arrow Down"
            $icons[$name] = ucwords(str_replace('-', ' ', $name));
        }

        asort($icons);

        return $icons;
    }
}
