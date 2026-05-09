<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Traits\HasGroupedSettings;
use App\Support\Settings\SettingsManager;
use App\Support\TenantFeature;
use BackedEnum;
use enshrined\svgSanitize\Sanitizer;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * Design Hub — tenant brand customisation page.
 *
 * Gated behind the `design` module. Only visible when the current tenant
 * has the `design` module explicitly enabled by a platform admin.
 *
 * Sections:
 *   A. Brand Identity — logos, color, name override
 *   B. Typography     — font family selection
 *   C. Email          — logo/color injection toggles
 */
class DesignHub extends Page implements HasForms
{
    use HasGroupedSettings;
    use InteractsWithForms;

    /**
     * Navigation icon.
     */
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    /**
     * Navigation group.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Design';

    /**
     * Navigation sort order.
     */
    protected static ?int $navigationSort = 50;

    /**
     * Navigation label.
     */
    protected static ?string $navigationLabel = 'Wygląd marki';

    /**
     * Blade view to render.
     */
    protected string $view = 'filament.pages.design-hub';

    /**
     * Form state data.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Only register navigation for tenants with the `design` module.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $tenant = TenantFeature::currentTenant();

        return $tenant?->hasModule('design') ?? false;
    }

    /**
     * Gate direct URL access — deny if module not active.
     */
    public static function canAccess(): bool
    {
        $tenant = TenantFeature::currentTenant();

        return $tenant?->hasModule('design') ?? false;
    }

    /**
     * Load current settings into form on mount.
     */
    public function mount(): void
    {
        $settingsManager = app(SettingsManager::class);
        $allSettings = $settingsManager->all();

        $this->form->fill($allSettings);
    }

    /**
     * Define settings groups for per-group validation.
     *
     * @return array<string, array{label: string, rules: array<string, string|array>}>
     */
    protected function getSettingsGroups(): array
    {
        return [
            'appearance' => [
                'label' => 'Ustawienia wyglądu zapisane',
                'rules' => [
                    'header_logo' => ['nullable'],
                    'footer_logo' => ['nullable'],
                    'logo_alt' => ['nullable', 'string', 'max:100'],
                ],
            ],
            'design' => [
                'label' => 'Ustawienia marki zapisane',
                'rules' => [
                    'brand_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
                    'font_family' => ['required', 'in:inter,system,roboto,poppins,montserrat'],
                    'brand_name_override' => ['nullable', 'string', 'max:100'],
                    'use_logo_in_emails' => ['nullable', 'boolean'],
                    'use_color_in_emails' => ['nullable', 'boolean'],
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
                $this->brandIdentitySection(),
                $this->typographySection(),
                $this->emailSection(),
            ])
            ->statePath('data');
    }

    /**
     * Section A — Brand Identity.
     * Logos, brand color, name override.
     */
    private function brandIdentitySection(): Section
    {
        return Section::make('Tożsamość marki')
            ->description('Logo, kolor i nazwa Twojej marki widoczne na publicznych stronach.')
            ->icon('heroicon-o-identification')
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
                    ->helperText('Logo dla nagłówka strony. SVG, PNG, WebP lub JPEG, max 1MB.')
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => $this->processLogoUpload($file)),

                FileUpload::make('appearance.footer_logo')
                    ->label('Logo stopki')
                    ->disk('public')
                    ->directory('settings/logos')
                    ->visibility('public')
                    ->image()
                    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'])
                    ->maxSize(1024)
                    ->imagePreviewHeight('80')
                    ->helperText('Logo dla stopki strony. SVG, PNG, WebP lub JPEG, max 1MB.')
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => $this->processLogoUpload($file)),

                TextInput::make('appearance.logo_alt')
                    ->label('Tekst alternatywny logo')
                    ->maxLength(100)
                    ->helperText('Dla dostępności (screen readers). Domyślnie: nazwa aplikacji.'),

                ColorPicker::make('design.brand_color')
                    ->label('Kolor marki')
                    ->hexColor()
                    ->default('#6366f1')
                    ->helperText('Główny kolor Twojej marki. System automatycznie dobierze wszystkie odcienie (50–900) używane na stronach klientów.'),

                TextInput::make('design.brand_name_override')
                    ->label('Nazwa marki (wyświetlana)')
                    ->maxLength(100)
                    ->placeholder('Zostaw puste aby użyć nazwy organizacji')
                    ->helperText('Opcjonalna nazwa wyświetlana w interfejsie klienta. Domyślnie: nazwa Twojej organizacji.'),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveBrandIdentity')
                        ->label('Zapisz tożsamość marki')
                        ->action('saveBrandIdentitySettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Section B — Typography.
     */
    private function typographySection(): Section
    {
        return Section::make('Typografia')
            ->description('Czcionka używana na wszystkich publicznych stronach klienta.')
            ->icon('heroicon-o-language')
            ->schema([
                Select::make('design.font_family')
                    ->label('Czcionka')
                    ->options([
                        'inter' => 'Inter (domyślna Registro)',
                        'system' => 'System (czcionka przeglądarki — najszybsza)',
                        'roboto' => 'Roboto',
                        'poppins' => 'Poppins',
                        'montserrat' => 'Montserrat',
                    ])
                    ->default('inter')
                    ->required()
                    ->helperText('Zmiana czcionki wymaga odświeżenia strony aby zobaczyć efekt. Czcionki ładowane przez Bunny Fonts (GDPR-safe).'),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveTypography')
                        ->label('Zapisz typografię')
                        ->action('saveTypographySettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    /**
     * Section C — Email branding.
     */
    private function emailSection(): Section
    {
        return Section::make('Branding email')
            ->description('Kontroluj jak Twoja marka pojawia się w transakcyjnych emailach wysyłanych do klientów.')
            ->icon('heroicon-o-envelope')
            ->schema([
                Toggle::make('design.use_logo_in_emails')
                    ->label('Użyj logo w emailach')
                    ->helperText('Wyświetla logo nagłówka w każdym emailu transakcyjnym (potwierdzenia, przypomnienia).')
                    ->default(true),

                Toggle::make('design.use_color_in_emails')
                    ->label('Użyj koloru marki w emailach')
                    ->helperText('Przyciski akcji w emailach będą miały kolor Twojej marki.')
                    ->default(true),

                Placeholder::make('email_info')
                    ->label('')
                    ->content('Zmiany w branding emailach zobaczysz w kolejnym wysłanym emailu. Konfiguracja serwera SMTP → System Settings → Email.')
                    ->columnSpanFull(),

                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('saveEmail')
                        ->label('Zapisz ustawienia email')
                        ->action('saveEmailBrandingSettings')
                        ->color('primary')
                        ->icon('heroicon-o-check'),
                ])->columnSpanFull(),
            ]);
    }

    // ========================================================================
    // Save actions
    // ========================================================================

    /**
     * Save brand identity (appearance logos + design color/name).
     */
    public function saveBrandIdentitySettings(): void
    {
        $this->saveSettingsGroup('appearance');
        $this->saveSettingsGroup('design');
    }

    /**
     * Save typography settings.
     */
    public function saveTypographySettings(): void
    {
        $this->saveSettingsGroup('design');
    }

    /**
     * Save email branding toggles.
     */
    public function saveEmailBrandingSettings(): void
    {
        $this->saveSettingsGroup('design');
    }

    /**
     * No global form actions — all save buttons are per-section.
     */
    protected function getFormActions(): array
    {
        return [];
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Process and store a logo upload with magic bytes validation and SVG sanitization.
     *
     * Follows the same pattern as SystemSettings::appearanceTab() (see filament-settings-pages.md).
     */
    private function processLogoUpload(TemporaryUploadedFile $file): string
    {
        $mimeType = $file->getMimeType();

        // Validate magic bytes for raster images
        if ($mimeType !== 'image/svg+xml') {
            $magicBytes = file_get_contents($file->getRealPath(), false, null, 0, 8);
            $validSignatures = [
                "\x89PNG\x0D\x0A\x1A\x0A", // PNG
                'RIFF',                     // WebP
                "\xFF\xD8\xFF",             // JPEG
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
    }
}
