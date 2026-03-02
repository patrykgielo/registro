<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;

/**
 * Reusable Filament form schemas for CMS block styling.
 *
 * Provides consistent background, layout, and advanced styling options
 * that can be embedded into any Filament Builder block schema.
 *
 * @example
 * Forms\Components\Builder\Block::make('custom_block')
 *     ->schema([
 *         // ... block-specific fields ...
 *         Fieldset::make('Tło')
 *             ->schema(BlockStyling::backgroundSchema('pages/backgrounds'))
 *             ->columns(2),
 *     ])
 */
class BlockStyling
{
    /**
     * Universal background options schema.
     *
     * Supports solid colors, gradients, and images with overlay options.
     *
     * @param  string  $directory  Storage directory for background images (e.g., 'pages/backgrounds')
     * @return array<Forms\Components\Component>
     */
    public static function backgroundSchema(string $directory = 'backgrounds'): array
    {
        return [
            Forms\Components\Select::make('background_type')
                ->label('Typ tła')
                ->options([
                    'none' => 'Brak',
                    'solid' => 'Kolor',
                    'gradient' => 'Gradient',
                    'image' => 'Obraz',
                ])
                ->default('none')
                ->selectablePlaceholder(false)
                ->live(),

            Forms\Components\ColorPicker::make('background_color')
                ->label('Kolor tła')
                ->default('#ffffff')
                ->visible(fn ($get) => $get('background_type') === 'solid'),

            Grid::make(3)
                ->schema([
                    Forms\Components\ColorPicker::make('gradient_from')
                        ->label('Kolor początkowy')
                        ->default('#0891b2'),

                    Forms\Components\ColorPicker::make('gradient_to')
                        ->label('Kolor końcowy')
                        ->default('#0e7490'),

                    Forms\Components\Select::make('gradient_direction')
                        ->label('Kierunek')
                        ->options([
                            'to-r' => 'W prawo →',
                            'to-l' => 'W lewo ←',
                            'to-t' => 'Do góry ↑',
                            'to-b' => 'W dół ↓',
                            'to-br' => 'Prawy dolny róg ↘',
                            'to-bl' => 'Lewy dolny róg ↙',
                            'to-tr' => 'Prawy górny róg ↗',
                            'to-tl' => 'Lewy górny róg ↖',
                        ])
                        ->default('to-r')
                        ->selectablePlaceholder(false),
                ])
                ->visible(fn ($get) => $get('background_type') === 'gradient'),

            Forms\Components\FileUpload::make('background_image')
                ->label('Obraz tła')
                ->image()
                ->directory($directory)
                ->maxSize(6144)
                ->imageEditor()
                ->imageEditorAspectRatios([
                    '16:9',
                    '4:3',
                    '1:1',
                ])
                ->visible(fn ($get) => $get('background_type') === 'image'),

            Forms\Components\Toggle::make('background_overlay')
                ->label('Nakładka na obraz')
                ->default(false)
                ->live()
                ->visible(fn ($get) => $get('background_type') === 'image'),

            Forms\Components\ColorPicker::make('overlay_color')
                ->label('Kolor nakładki')
                ->default('#000000')
                ->visible(fn ($get) => $get('background_type') === 'image' && $get('background_overlay')),

            Forms\Components\Select::make('overlay_opacity')
                ->label('Przezroczystość nakładki')
                ->options([
                    '10' => '10%',
                    '20' => '20%',
                    '30' => '30%',
                    '40' => '40%',
                    '50' => '50%',
                    '60' => '60%',
                    '70' => '70%',
                    '80' => '80%',
                    '90' => '90%',
                ])
                ->default('50')
                ->selectablePlaceholder(false)
                ->visible(fn ($get) => $get('background_type') === 'image' && $get('background_overlay')),
        ];
    }

    /**
     * Layout and spacing options schema.
     *
     * Controls container width, max-width constraints, and vertical padding.
     *
     * @return array<Forms\Components\Component>
     */
    public static function layoutSchema(): array
    {
        return [
            Forms\Components\Toggle::make('full_width')
                ->label('Pełna szerokość')
                ->default(false)
                ->helperText('Zawartość rozciąga się na całą szerokość ekranu')
                ->live(),

            Forms\Components\Select::make('container_max_width')
                ->label('Maksymalna szerokość kontenera')
                ->options([
                    'sm' => 'Wąski (640px)',
                    'md' => 'Średni (768px)',
                    'lg' => 'Duży (1024px)',
                    'xl' => 'Standardowy (1280px)',
                    '2xl' => 'Szeroki (1536px)',
                    '7xl' => 'Bardzo szeroki (1920px)',
                    'full' => 'Bez ograniczeń',
                ])
                ->default('xl')
                ->selectablePlaceholder(false)
                ->visible(fn ($get) => ! $get('full_width'))
                ->helperText('Maksymalna szerokość zawartości wewnątrz bloku'),

            Forms\Components\Select::make('vertical_padding')
                ->label('Odstęp pionowy')
                ->options([
                    'none' => 'Brak',
                    'sm' => 'Mały (2rem / 32px)',
                    'md' => 'Średni (4rem / 64px)',
                    'lg' => 'Duży (6rem / 96px)',
                    'xl' => 'Bardzo duży (8rem / 128px)',
                ])
                ->default('md')
                ->selectablePlaceholder(false)
                ->helperText('Odstęp nad i pod blokiem'),
        ];
    }

    /**
     * Advanced CSS and animation options schema.
     *
     * Provides CSS ID, custom classes, and entrance animations.
     *
     * @return array<Forms\Components\Component>
     */
    public static function advancedSchema(): array
    {
        return [
            Forms\Components\TextInput::make('css_id')
                ->label('ID CSS')
                ->prefix('#')
                ->maxLength(100)
                ->helperText('Unikalny identyfikator do kotwic (#sekcja) lub niestandardowego CSS')
                ->regex('/^[a-zA-Z][a-zA-Z0-9_-]*$/')
                ->validationAttribute('ID CSS'),

            Forms\Components\TextInput::make('css_classes')
                ->label('Klasy CSS')
                ->maxLength(255)
                ->helperText('Dodatkowe klasy Tailwind lub niestandardowe, oddzielone spacjami')
                ->placeholder('bg-gradient-to-r shadow-xl'),
        ];
    }

    /**
     * CTA Container background schema.
     *
     * Provides background options for the inner CTA container (card-like element).
     * Simpler than backgroundSchema - no image support, focused on solid/gradient.
     *
     * @return array<Forms\Components\Component>
     */
    public static function ctaContainerSchema(): array
    {
        return [
            Forms\Components\Select::make('cta_container_bg_type')
                ->label('Typ tła kontenera')
                ->options([
                    'none' => 'Brak',
                    'solid' => 'Kolor',
                    'gradient' => 'Gradient',
                ])
                ->default('none')
                ->selectablePlaceholder(false)
                ->live(),

            Forms\Components\ColorPicker::make('cta_container_color')
                ->label('Kolor tła')
                ->default('#0891b2')
                ->visible(fn ($get) => $get('cta_container_bg_type') === 'solid'),

            Grid::make(3)
                ->schema([
                    Forms\Components\ColorPicker::make('cta_container_gradient_from')
                        ->label('Kolor początkowy')
                        ->default('#0891b2'),

                    Forms\Components\ColorPicker::make('cta_container_gradient_to')
                        ->label('Kolor końcowy')
                        ->default('#0e7490'),

                    Forms\Components\Select::make('cta_container_gradient_direction')
                        ->label('Kierunek')
                        ->options([
                            'to-r' => 'W prawo →',
                            'to-l' => 'W lewo ←',
                            'to-t' => 'Do góry ↑',
                            'to-b' => 'W dół ↓',
                            'to-br' => 'Prawy dolny róg ↘',
                            'to-bl' => 'Lewy dolny róg ↙',
                            'to-tr' => 'Prawy górny róg ↗',
                            'to-tl' => 'Lewy górny róg ↖',
                        ])
                        ->default('to-r')
                        ->selectablePlaceholder(false),
                ])
                ->visible(fn ($get) => $get('cta_container_bg_type') === 'gradient'),

            Forms\Components\Select::make('cta_container_rounded')
                ->label('Zaokrąglenie rogów')
                ->options([
                    'none' => 'Brak',
                    'lg' => 'Małe (0.5rem)',
                    'xl' => 'Średnie (0.75rem)',
                    '2xl' => 'Duże (1rem)',
                    '3xl' => 'Bardzo duże (1.5rem)',
                ])
                ->default('3xl')
                ->selectablePlaceholder(false)
                ->visible(fn ($get) => in_array($get('cta_container_bg_type'), ['solid', 'gradient'])),

            Forms\Components\Select::make('cta_container_padding')
                ->label('Wewnętrzny padding')
                ->options([
                    'md' => 'Średni (2rem)',
                    'lg' => 'Duży (3rem)',
                    'xl' => 'Bardzo duży (4rem)',
                ])
                ->default('lg')
                ->selectablePlaceholder(false)
                ->visible(fn ($get) => in_array($get('cta_container_bg_type'), ['solid', 'gradient'])),
        ];
    }

    /**
     * Complete styling schema: background + layout + advanced.
     *
     * Combines all styling options into a single fieldset schema.
     * Use this for full-featured blocks that need all styling options.
     *
     * @param  string  $directory  Storage directory for background images (e.g., 'pages/backgrounds')
     * @return array<Forms\Components\Component>
     */
    public static function completeSchema(string $directory = 'backgrounds'): array
    {
        return [
            Fieldset::make('Tło')
                ->schema(self::backgroundSchema($directory))
                ->columns(2),

            Fieldset::make('Układ')
                ->schema(self::layoutSchema())
                ->columns(2),

            Fieldset::make('Zaawansowane')
                ->schema(self::advancedSchema())
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ];
    }
}
