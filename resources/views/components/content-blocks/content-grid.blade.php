@props(['data' => []])

@php
    use App\Models\Service;
    use App\Models\Post;
    use App\Models\Promotion;
    use App\Models\PortfolioItem;
    use Illuminate\Support\Facades\Storage;

    $contentType = $data['content_type'] ?? 'services';
    $contentItemIds = $data['content_items'] ?? [];
    $columns = (string) ($data['columns'] ?? '3');
    $heading = $data['heading'] ?? '';
    $subheading = $data['subheading'] ?? '';

    // Backwards compatibility: old background_color was a string key
    $backgroundType = $data['background_type'] ?? null;
    if (!$backgroundType && isset($data['background_color'])) {
        $oldBgColor = $data['background_color'];
        if (in_array($oldBgColor, ['white', 'neutral-50', 'primary-50', 'dark'])) {
            $data['background_type'] = 'solid';
            $data['background_color'] = match($oldBgColor) {
                'neutral-50' => '#f9fafb',
                'primary-50' => '#f0f9ff',
                'dark' => '#1f2937',
                default => '#ffffff',
            };
        } else {
            // Services default to dark theme
            $defaultBg = $contentType === 'services' ? 'dark' : 'white';
            $backgroundColor = $data['background_color'] ?? $defaultBg;
            if ($backgroundColor === 'dark') {
                $data['background_type'] = 'solid';
                $data['background_color'] = '#1f2937';
            }
        }
    }

    // Fetch content items in specified order (FIELD preserves order)
    $items = collect([]);
    if (!empty($contentItemIds)) {
        $items = match($contentType) {
            'services' => Service::whereIn('id', $contentItemIds)
                ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $contentItemIds)) . ')')
                ->get(),
            'posts' => Post::whereIn('id', $contentItemIds)
                ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $contentItemIds)) . ')')
                ->get(),
            'promotions' => Promotion::whereIn('id', $contentItemIds)
                ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $contentItemIds)) . ')')
                ->get(),
            'portfolio' => PortfolioItem::whereIn('id', $contentItemIds)
                ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $contentItemIds)) . ')')
                ->get(),
            default => collect([]),
        };
    }

    // Detect if background is dark (luminance calculation per WCAG)
    $isColorDark = function (string $hex): bool {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance < 0.5;
    };

    $isDark = ($data['background_type'] ?? 'none') === 'solid'
        && isset($data['background_color'])
        && $isColorDark($data['background_color']);

    // Service card variant: user choice or auto-detect from background
    $serviceCardVariant = match($data['service_card_variant'] ?? 'auto') {
        'dark' => 'dark',
        'default' => 'default',
        default => $isDark ? 'dark' : 'default',
    };

    // Text classes based on dark variant
    $headingClasses = $isDark ? 'text-white' : 'text-gray-900';
    $subheadingClasses = $isDark ? 'text-white/80' : 'text-gray-600';

    $gridClass = match($columns) {
        '2' => 'grid-cols-1 md:grid-cols-2',
        '4' => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
        default => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
    };
@endphp

<x-blocks.partials.section-wrapper :data="$data">
    @if($heading || $subheading)
            <div class="text-center mb-16">
                @if($heading)
                    <h2 class="text-5xl md:text-6xl font-light tracking-tight {{ $headingClasses }} mb-4"
                        style="letter-spacing: -0.02em;">
                        {{ $heading }}
                    </h2>
                @endif

                @if($subheading)
                    <p class="text-xl md:text-2xl {{ $subheadingClasses }} max-w-3xl mx-auto font-light">
                        {{ $subheading }}
                    </p>
                @endif
            </div>
        @endif

        @if($items->isEmpty())
            <div class="max-w-2xl mx-auto {{ $isDark ? 'bg-white/10 border-white/20' : 'bg-yellow-50 border-yellow-200' }} border rounded-2xl p-6">
                <div class="flex items-start gap-3">
                    <x-heroicon-s-exclamation-triangle class="w-6 h-6 {{ $isDark ? 'text-[#0AB1EA]' : 'text-yellow-600' }} flex-shrink-0" />
                    <div>
                        <p class="font-bold {{ $isDark ? 'text-white' : 'text-yellow-900' }}">Brak elementów</p>
                        <p class="mt-1 {{ $isDark ? 'text-white/70' : 'text-yellow-800' }}">Wybrane elementy nie istnieją lub zostały usunięte.</p>
                    </div>
                </div>
            </div>
        @else
            <div class="grid {{ $gridClass }} gap-8">
                @foreach($items as $item)
                    @if($contentType === 'services')
                        <x-ios.service-card :service="$item" :variant="$serviceCardVariant" />
                    @else
                        {{-- CMS Content Card for posts, promotions, portfolio --}}
                        @php
                            $itemUrl = ($item->slug ?? false) ? route(match($contentType) {
                                'posts' => 'post.show',
                                'promotions' => 'promotion.show',
                                'portfolio' => 'portfolio.show',
                                default => 'home'
                            }, $item->slug) : '#';
                        @endphp
                        <article class="cms-content-card {{ $isDark
                            ? 'service-card-dark shadow-dark-glow hover:shadow-dark-glow-hover'
                            : 'bg-white shadow-md hover:shadow-xl' }}">

                            @if($item->featured_image ?? false)
                                <div class="overflow-hidden">
                                    <img src="{{ Storage::url($item->featured_image) }}"
                                         alt="{{ $item->title ?? $item->name }}"
                                         class="cms-card-image"
                                         loading="lazy">
                                </div>
                            @endif

                            <div class="p-6 flex flex-col flex-1">
                                <h3 class="text-xl font-bold mb-2 {{ $isDark ? 'text-white' : 'text-gray-900' }}">
                                    {{ $item->title ?? $item->name }}
                                </h3>

                                <p class="text-sm mb-4 flex-1 {{ $isDark ? 'text-white/70' : 'text-gray-600' }}">
                                    {{ Str::limit($item->excerpt ?? $item->body ?? '', 120) }}
                                </p>

                                @if($item->slug ?? false)
                                    <a href="{{ $itemUrl }}"
                                       class="inline-flex items-center gap-2 font-semibold text-sm {{ $isDark ? 'text-[#0AB1EA] hover:text-[#0AB1EA]/80' : 'text-primary-600 hover:text-primary-700' }} transition-colors">
                                        Zobacz szczegóły
                                        <x-heroicon-m-arrow-right class="w-4 h-4" />
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endif
                @endforeach
            </div>
        @endif
</x-blocks.partials.section-wrapper>
