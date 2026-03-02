<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\Service;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;

/**
 * Centralized builder blocks for CMS content types.
 *
 * Provides reusable Filament Builder blocks for Pages, Posts, Promotions, Portfolio, and Services.
 * Blocks are organized into basic (text, media) and advanced (hero, grids, features).
 */
class BuilderBlocks
{
    /**
     * Basic content blocks: text, images, galleries, videos, quotes, CTA, columns.
     *
     * @param  string  $directory  Storage directory for uploaded files (e.g., 'pages', 'posts')
     * @return array<Forms\Components\Builder\Block>
     */
    public static function basicBlocks(string $directory = 'pages'): array
    {
        return [
            Forms\Components\Builder\Block::make('text_block')
                ->label('Blok tekstowy')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\RichEditor::make('content')
                                        ->label('Treść')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                            'orderedList', 'h2', 'h3', 'blockquote',
                                        ])
                                        ->extraInputAttributes(['style' => 'min-height: 20rem;']),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('image')
                ->label('Zdjęcie')
                ->icon('heroicon-o-photo')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\FileUpload::make('image')
                                        ->label('Zdjęcie')
                                        ->image()
                                        ->required()
                                        ->directory($directory.'/images')
                                        ->maxSize(6144),

                                    Forms\Components\TextInput::make('alt')
                                        ->label('Tekst alternatywny (ALT)')
                                        ->maxLength(255),

                                    Forms\Components\TextInput::make('caption')
                                        ->label('Podpis')
                                        ->maxLength(255),

                                    Forms\Components\Select::make('size')
                                        ->label('Rozmiar')
                                        ->options([
                                            'small' => 'Mały',
                                            'medium' => 'Średni',
                                            'large' => 'Duży',
                                            'full' => 'Pełna szerokość',
                                        ])
                                        ->default('large'),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('gallery')
                ->label('Galeria')
                ->icon('heroicon-o-photo')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\FileUpload::make('images')
                                        ->label('Zdjęcia')
                                        ->image()
                                        ->multiple()
                                        ->required()
                                        ->directory($directory.'/galleries')
                                        ->maxSize(6144)
                                        ->maxFiles(20)
                                        ->reorderable(),

                                    Forms\Components\Select::make('columns')
                                        ->label('Liczba kolumn')
                                        ->options([
                                            '2' => '2 kolumny',
                                            '3' => '3 kolumny',
                                            '4' => '4 kolumny',
                                        ])
                                        ->default('3'),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('video')
                ->label('Wideo')
                ->icon('heroicon-o-film')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\TextInput::make('url')
                                        ->label('URL YouTube lub Vimeo')
                                        ->url()
                                        ->required()
                                        ->helperText('np. https://www.youtube.com/watch?v=...'),

                                    Forms\Components\TextInput::make('caption')
                                        ->label('Podpis')
                                        ->maxLength(255),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('quote')
                ->label('Cytat')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\Textarea::make('quote')
                                        ->label('Cytat')
                                        ->required()
                                        ->rows(3)
                                        ->maxLength(500),

                                    Forms\Components\TextInput::make('author')
                                        ->label('Autor')
                                        ->maxLength(255),

                                    Forms\Components\TextInput::make('author_title')
                                        ->label('Tytuł autora')
                                        ->maxLength(255)
                                        ->helperText('np. CEO, Dyrektor'),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('cta')
                ->label('Call to Action')
                ->icon('heroicon-o-cursor-arrow-ripple')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\TextInput::make('heading')
                                        ->label('Nagłówek')
                                        ->required()
                                        ->maxLength(255),

                                    Forms\Components\Textarea::make('description')
                                        ->label('Opis')
                                        ->rows(3)
                                        ->maxLength(500),

                                    Forms\Components\TextInput::make('button_text')
                                        ->label('Tekst przycisku')
                                        ->default('Dowiedz się więcej')
                                        ->maxLength(100),

                                    Forms\Components\TextInput::make('button_url')
                                        ->label('Link przycisku')
                                        ->url(),

                                    Forms\Components\Select::make('style')
                                        ->label('Styl')
                                        ->options([
                                            'primary' => 'Podstawowy (niebieski)',
                                            'secondary' => 'Drugorzędny (szary)',
                                            'accent' => 'Akcentowy (zielony)',
                                        ])
                                        ->default('primary'),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('two_columns')
                ->label('Dwie kolumny')
                ->icon('heroicon-o-view-columns')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\RichEditor::make('left_column')
                                        ->label('Lewa kolumna')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                            'orderedList', 'h3', 'blockquote',
                                        ]),

                                    Forms\Components\RichEditor::make('right_column')
                                        ->label('Prawa kolumna')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                            'orderedList', 'h3', 'blockquote',
                                        ]),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('three_columns')
                ->label('Trzy kolumny')
                ->icon('heroicon-o-squares-2x2')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\RichEditor::make('column_1')
                                        ->label('Kolumna 1')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                        ]),

                                    Forms\Components\RichEditor::make('column_2')
                                        ->label('Kolumna 2')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                        ]),

                                    Forms\Components\RichEditor::make('column_3')
                                        ->label('Kolumna 3')
                                        ->required()
                                        ->toolbarButtons([
                                            'bold', 'italic', 'link', 'bulletList',
                                        ]),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),
        ];
    }

    /**
     * Advanced content blocks: hero, content grids, feature lists, CTA banners.
     *
     * @param  string  $directory  Storage directory for uploaded files (e.g., 'pages', 'posts')
     * @return array<Forms\Components\Builder\Block>
     */
    public static function advancedBlocks(string $directory = 'pages'): array
    {
        return [
            Forms\Components\Builder\Block::make('hero')
                ->label('Hero Section')
                ->icon('heroicon-o-photo')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\Select::make('background_type')
                                        ->label('Typ tła')
                                        ->options([
                                            'gradient' => 'Gradient',
                                            'solid' => 'Kolor',
                                            'image' => 'Obraz',
                                        ])
                                        ->default('gradient')
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set) {
                                            if ($state !== 'image') {
                                                $set('background_image', null);
                                            }
                                            if ($state !== 'solid') {
                                                $set('background_color', null);
                                            }
                                        }),

                                    Forms\Components\FileUpload::make('background_image')
                                        ->label('Obraz tła')
                                        ->image()
                                        ->directory($directory.'/hero')
                                        ->maxSize(6144)
                                        ->visible(fn ($get) => $get('background_type') === 'image'),

                                    Forms\Components\ColorPicker::make('background_color')
                                        ->label('Kolor tła')
                                        ->visible(fn ($get) => $get('background_type') === 'solid'),

                                    Forms\Components\TextInput::make('title')
                                        ->label('Tytuł')
                                        ->required()
                                        ->maxLength(100),

                                    Forms\Components\Textarea::make('subtitle')
                                        ->label('Podtytuł')
                                        ->maxLength(200)
                                        ->rows(2),

                                    Forms\Components\Repeater::make('cta_buttons')
                                        ->label('Przyciski CTA')
                                        ->schema([
                                            Forms\Components\TextInput::make('text')
                                                ->label('Tekst')
                                                ->required()
                                                ->maxLength(50),

                                            Forms\Components\TextInput::make('url')
                                                ->label('URL')
                                                ->required()
                                                ->url(),

                                            Forms\Components\Select::make('style')
                                                ->label('Styl')
                                                ->options([
                                                    'primary' => 'Primary',
                                                    'secondary' => 'Secondary',
                                                    'accent' => 'Accent',
                                                ])
                                                ->default('primary')
                                                ->required(),
                                        ])
                                        ->defaultItems(1)
                                        ->maxItems(3),

                                    Forms\Components\Slider::make('overlay_opacity')
                                        ->label('Przezroczystość nakładki')
                                        ->default(50)
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->step(10),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('content_grid')
                ->label('Siatka treści')
                ->icon('heroicon-o-squares-2x2')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\Select::make('content_type')
                                        ->label('Typ treści')
                                        ->options([
                                            'services' => 'Usługi',
                                            'posts' => 'Posty',
                                            'promotions' => 'Promocje',
                                            'portfolio' => 'Portfolio',
                                        ])
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn ($set) => $set('content_items', [])),

                                    Forms\Components\Select::make('content_items')
                                        ->label('Wybierz elementy')
                                        ->options(function ($get) {
                                            return match ($get('content_type')) {
                                                'services' => Service::where('is_active', true)->pluck('name', 'id'),
                                                'posts' => Post::whereNotNull('published_at')->pluck('title', 'id'),
                                                'promotions' => Promotion::where('active', true)->pluck('title', 'id'),
                                                'portfolio' => PortfolioItem::whereNotNull('published_at')->pluck('title', 'id'),
                                                default => [],
                                            };
                                        })
                                        ->multiple()
                                        ->searchable()
                                        ->required(),

                                    Forms\Components\Select::make('service_card_variant')
                                        ->label('Styl kart usług')
                                        ->options([
                                            'auto' => 'Automatyczny (z tła)',
                                            'default' => 'Jasny',
                                            'dark' => 'Ciemny',
                                        ])
                                        ->default('auto')
                                        ->selectablePlaceholder(false)
                                        ->helperText('Wybierz styl kart usług lub pozostaw automatyczny')
                                        ->visible(fn ($get) => $get('content_type') === 'services'),

                                    Forms\Components\Select::make('columns')
                                        ->label('Kolumny')
                                        ->options([
                                            '2' => '2',
                                            '3' => '3',
                                            '4' => '4',
                                        ])
                                        ->default('3')
                                        ->required(),

                                    Forms\Components\TextInput::make('heading')
                                        ->label('Nagłówek')
                                        ->maxLength(100),

                                    Forms\Components\Textarea::make('subheading')
                                        ->label('Podtytuł')
                                        ->maxLength(200)
                                        ->rows(2),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('feature_list')
                ->label('Lista funkcji')
                ->icon('heroicon-o-star')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\Repeater::make('features')
                                        ->label('Funkcje')
                                        ->schema([
                                            Forms\Components\Select::make('icon')
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
                                                ->required()
                                                ->default('star')
                                                ->hintAction(
                                                    Actions\Action::make('browseIcons')
                                                        ->label('Przeglądaj ikony')
                                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                                        ->url('https://heroicons.com', shouldOpenInNewTab: true)
                                                )
                                                ->helperText('Wyszukaj po nazwie (np. "clock" lub "shield-check") • Używaj ikon "Outline"'),

                                            Forms\Components\TextInput::make('title')
                                                ->label('Tytuł')
                                                ->required()
                                                ->maxLength(100),

                                            Forms\Components\Textarea::make('description')
                                                ->label('Opis')
                                                ->required()
                                                ->maxLength(200)
                                                ->rows(2),
                                        ])
                                        ->defaultItems(3)
                                        ->maxItems(8)
                                        ->collapsible(),

                                    Forms\Components\Select::make('layout')
                                        ->label('Układ')
                                        ->options([
                                            'grid' => 'Siatka',
                                            'split' => 'Podzielony (z obrazem)',
                                        ])
                                        ->default('grid')
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set) {
                                            if ($state === 'grid') {
                                                $set('image', null);
                                            }
                                        }),

                                    Forms\Components\Select::make('columns')
                                        ->label('Kolumny (tylko siatka)')
                                        ->options([
                                            '2' => '2',
                                            '3' => '3',
                                            '4' => '4',
                                        ])
                                        ->default('3')
                                        ->visible(fn ($get) => $get('layout') === 'grid'),

                                    Forms\Components\FileUpload::make('image')
                                        ->label('Obraz (tylko podzielony)')
                                        ->image()
                                        ->directory($directory.'/features')
                                        ->maxSize(6144)
                                        ->visible(fn ($get) => $get('layout') === 'split'),

                                    Forms\Components\TextInput::make('heading')
                                        ->label('Nagłówek')
                                        ->maxLength(100),

                                    Forms\Components\Textarea::make('subheading')
                                        ->label('Podtytuł')
                                        ->maxLength(200)
                                        ->rows(2),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('cta_banner')
                ->label('CTA Banner')
                ->icon('heroicon-o-cursor-arrow-ripple')
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\TextInput::make('heading')
                                        ->label('Nagłówek')
                                        ->required()
                                        ->maxLength(100),

                                    Forms\Components\Textarea::make('subheading')
                                        ->label('Podtytuł')
                                        ->maxLength(200)
                                        ->rows(2),

                                    Forms\Components\Repeater::make('cta_buttons')
                                        ->label('Przyciski CTA')
                                        ->schema([
                                            Forms\Components\TextInput::make('text')
                                                ->label('Tekst')
                                                ->required()
                                                ->maxLength(50),

                                            Forms\Components\TextInput::make('url')
                                                ->label('URL')
                                                ->required()
                                                ->url(),

                                            Forms\Components\Select::make('style')
                                                ->label('Styl')
                                                ->options([
                                                    'primary' => 'Primary',
                                                    'secondary' => 'Secondary',
                                                ])
                                                ->default('primary')
                                                ->required(),
                                        ])
                                        ->defaultItems(1)
                                        ->maxItems(2),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->description('Tło całej sekcji (zewnętrzne)')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Tło kontenera CTA')
                                        ->description('Tło wewnętrznego kontenera z zaokrągleniami')
                                        ->schema(BlockStyling::ctaContainerSchema())
                                        ->collapsible(),
                                    Section::make('Efekty')
                                        ->schema([
                                            Forms\Components\Toggle::make('background_orbs')
                                                ->label('Animowane tło (orbs)')
                                                ->default(true)
                                                ->helperText('Dekoracyjne animowane kule. Zalecane dla jednolitego koloru lub gradientu.')
                                                ->visible(fn ($get) => in_array($get('background_type'), ['solid', 'gradient', null, 'none', ''])),
                                        ])
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),

            Forms\Components\Builder\Block::make('custom_html')
                ->label('Własny HTML')
                ->icon('heroicon-o-code-bracket')
                ->visible(fn () => auth()->user()?->hasRole('super-admin') ?? false)
                ->schema([
                    Tabs::make('block_settings')
                        ->tabs([
                            Tabs\Tab::make('Treść')
                                ->icon('heroicon-o-document-text')
                                ->schema([
                                    Forms\Components\Textarea::make('html')
                                        ->label('Kod HTML')
                                        ->required()
                                        ->rows(10)
                                        ->helperText('⚠️ Używaj ostrożnie. Tylko zaufany kod HTML.'),

                                    Forms\Components\Toggle::make('container_wrapper')
                                        ->label('Opakowanie kontenera')
                                        ->default(true)
                                        ->helperText('Dodaje kontener max-width wokół HTML'),
                                ]),

                            Tabs\Tab::make('Wygląd')
                                ->icon('heroicon-o-paint-brush')
                                ->schema([
                                    Section::make('Tło sekcji')
                                        ->schema(BlockStyling::backgroundSchema($directory.'/backgrounds'))
                                        ->collapsible(),
                                    Section::make('Układ')
                                        ->schema(BlockStyling::layoutSchema())
                                        ->collapsible(),
                                ]),

                            Tabs\Tab::make('Zaawansowane')
                                ->icon('heroicon-o-cog-6-tooth')
                                ->schema(BlockStyling::advancedSchema()),
                        ])
                        ->columnSpanFull()
                        ->persistTab(),
                ]),
        ];
    }

    /**
     * All blocks: basic + advanced.
     *
     * @param  string  $directory  Storage directory for uploaded files (e.g., 'pages', 'posts')
     * @return array<Forms\Components\Builder\Block>
     */
    public static function allBlocks(string $directory = 'pages'): array
    {
        return array_merge(
            self::basicBlocks($directory),
            self::advancedBlocks($directory)
        );
    }

    /**
     * Get available Heroicon outline icons for selection.
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
            // Fallback to common icons if scan fails
            return [
                'star' => 'Star',
                'sparkles' => 'Sparkles',
                'shield-check' => 'Shield Check',
                'clock' => 'Clock',
                'check-circle' => 'Check Circle',
                'arrow-right' => 'Arrow Right',
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
