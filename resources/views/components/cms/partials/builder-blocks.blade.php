{{--
    Builder Blocks Renderer

    Renders Filament Builder blocks for all content types.
    Centralizes block rendering logic to eliminate duplication.

    @props
    - blocks: array of builder blocks
    - fullWidth: boolean (for full-width layout)
    - minimal: boolean (for minimal layout)
--}}

@props([
    'blocks',
    'fullWidth' => false,
    'minimal' => false,
])

@foreach($blocks as $block)
    <div data-track-section="{{ $block['type'] }}-{{ $loop->index }}"
         data-track-block="{{ $block['type'] }}"
         data-track-position="{{ $loop->index }}">
    @if($block['type'] === 'image')
        <div class="mb-8 @if($block['data']['size'] === 'full') w-full @elseif($block['data']['size'] === 'large') max-w-3xl mx-auto @elseif($block['data']['size'] === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
            <img src="{{ Storage::url($block['data']['image']) }}"
                 alt="{{ $block['data']['alt'] ?? '' }}"
                 class="w-full rounded-lg">
            @if(!empty($block['data']['caption']))
                <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
            @endif
        </div>

    @elseif($block['type'] === 'gallery')
        <div class="mb-8">
            <div class="grid grid-cols-{{ $block['data']['columns'] ?? 3 }} gap-4">
                @foreach($block['data']['images'] as $image)
                    <img src="{{ Storage::url($image) }}"
                         alt=""
                         class="w-full h-64 object-cover rounded-lg">
                @endforeach
            </div>
        </div>

    @elseif($block['type'] === 'video')
        <div class="mb-8">
            <div class="aspect-w-16 aspect-h-9">
                <iframe src="{{ $block['data']['url'] }}"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        class="w-full h-96 rounded-lg"></iframe>
            </div>
            @if(!empty($block['data']['caption']))
                <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
            @endif
        </div>

    @elseif($block['type'] === 'cta')
        <div class="mb-8 p-8 rounded-lg @if($block['data']['style'] === 'primary') bg-primary-50 @elseif($block['data']['style'] === 'accent') bg-green-50 @else bg-gray-50 @endif">
            <h3 class="text-2xl font-bold mb-4">{{ $block['data']['heading'] }}</h3>
            @if(!empty($block['data']['description']))
                <p class="text-gray-700 mb-6">{{ $block['data']['description'] }}</p>
            @endif
            @if(!empty($block['data']['button_url']))
                <a href="{{ $block['data']['button_url'] }}"
                   class="inline-block min-h-11 px-6 py-3 rounded-lg font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2
                          @if($block['data']['style'] === 'primary') bg-primary-500 text-white hover:bg-primary-600 focus:ring-primary-400
                          @elseif($block['data']['style'] === 'accent') bg-green-600 text-white hover:bg-green-700 focus:ring-green-400
                          @else bg-gray-600 text-white hover:bg-gray-700 focus:ring-gray-400 @endif">
                    {{ $block['data']['button_text'] ?? 'Dowiedz się więcej' }}
                </a>
            @endif
        </div>

    @elseif($block['type'] === 'two_columns')
        <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="prose prose-registro max-w-none">{!! $block['data']['left_column'] !!}</div>
            <div class="prose prose-registro max-w-none">{!! $block['data']['right_column'] !!}</div>
        </div>

    @elseif($block['type'] === 'three_columns')
        <div class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="prose prose-registro max-w-none">{!! $block['data']['column_1'] !!}</div>
            <div class="prose prose-registro max-w-none">{!! $block['data']['column_2'] !!}</div>
            <div class="prose prose-registro max-w-none">{!! $block['data']['column_3'] !!}</div>
        </div>

    @elseif($block['type'] === 'quote')
        <blockquote class="mb-8 border-l-4 border-primary-400 pl-6 py-4 bg-gray-50 rounded-r-lg">
            <p class="text-xl text-gray-700 italic mb-4">{{ $block['data']['quote'] }}</p>
            @if(!empty($block['data']['author']))
                <footer class="text-gray-600">
                    <strong>{{ $block['data']['author'] }}</strong>
                    @if(!empty($block['data']['author_title']))
                        <span class="text-gray-500"> - {{ $block['data']['author_title'] }}</span>
                    @endif
                </footer>
            @endif
        </blockquote>

    {{-- Advanced blocks (use existing components) --}}
    @elseif($block['type'] === 'hero')
        <x-content-blocks.hero :data="$block['data']" />

    @elseif($block['type'] === 'content_grid')
        <x-content-blocks.content-grid :data="$block['data']" />

    @elseif($block['type'] === 'feature_list')
        <x-content-blocks.feature-list :data="$block['data']" />

    @elseif($block['type'] === 'cta_banner')
        <x-content-blocks.cta-banner :data="$block['data']" />

    @elseif($block['type'] === 'text_block')
        <x-content-blocks.text-block :data="$block['data']" />

    @elseif($block['type'] === 'custom_html')
        <x-content-blocks.custom-html :data="$block['data']" />
    @endif
    </div>
@endforeach
