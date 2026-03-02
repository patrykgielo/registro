{{-- Content Block Renderer: Centralized rendering for all block types --}}

@php
    $blockType = $block['type'] ?? null;
    $blockData = $block['data'] ?? [];
@endphp

@switch($blockType)
    {{-- Filament Builder Blocks (Components) --}}
    @case('hero')
        <x-content-blocks.hero :data="$blockData" />
        @break

    @case('content_grid')
        <x-content-blocks.content-grid :data="$blockData" />
        @break

    @case('feature_list')
        <x-content-blocks.feature-list :data="$blockData" />
        @break

    @case('cta_banner')
        <x-content-blocks.cta-banner :data="$blockData" />
        @break

    @case('text_block')
        <x-content-blocks.text-block :data="$blockData" />
        @break

    @case('custom_html')
        <x-content-blocks.custom-html :data="$blockData" />
        @break

    {{-- Legacy Block Types (Inline rendering) --}}
    @case('image')
        <div class="mb-8 @if($blockData['size'] ?? 'medium' === 'full') w-full @elseif($blockData['size'] ?? 'medium' === 'large') max-w-3xl mx-auto @elseif($blockData['size'] ?? 'medium' === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
            <img
                src="{{ Storage::url($blockData['image']) }}"
                alt="{{ $blockData['alt'] ?? '' }}"
                class="w-full rounded-lg shadow-md"
                loading="lazy"
            >
            @if(!empty($blockData['caption']))
                <p class="text-sm text-gray-600 text-center mt-3">{{ $blockData['caption'] }}</p>
            @endif
        </div>
        @break

    @case('gallery')
        <div class="mb-8">
            <div class="grid grid-cols-{{ $blockData['columns'] ?? 3 }} gap-4">
                @foreach($blockData['images'] ?? [] as $image)
                    <img
                        src="{{ Storage::url($image) }}"
                        alt=""
                        class="w-full h-64 object-cover rounded-lg shadow-md"
                        loading="lazy"
                    >
                @endforeach
            </div>
        </div>
        @break

    @case('video')
        <div class="mb-8">
            <div class="aspect-w-16 aspect-h-9 rounded-lg overflow-hidden shadow-md">
                <iframe
                    src="{{ $blockData['url'] }}"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    class="w-full h-96"
                ></iframe>
            </div>
            @if(!empty($blockData['caption']))
                <p class="text-sm text-gray-600 text-center mt-3">{{ $blockData['caption'] }}</p>
            @endif
        </div>
        @break

    @case('cta')
        <div class="mb-8 p-8 rounded-xl shadow-md @if($blockData['style'] ?? 'default' === 'primary') bg-gradient-to-br from-blue-50 to-blue-100 @elseif($blockData['style'] ?? 'default' === 'accent') bg-gradient-to-br from-green-50 to-green-100 @else bg-gray-50 @endif">
            <h3 class="text-2xl font-bold mb-4 text-gray-900">{{ $blockData['heading'] }}</h3>
            @if(!empty($blockData['description']))
                <p class="text-gray-700 mb-6 leading-relaxed">{{ $blockData['description'] }}</p>
            @endif
            @if(!empty($blockData['button_url']))
                <a
                    href="{{ $blockData['button_url'] }}"
                    class="inline-flex items-center gap-2 px-6 py-3 rounded-full font-semibold transition-all hover:scale-105 active:scale-95 shadow-md @if($blockData['style'] ?? 'default' === 'primary') bg-blue-600 text-white hover:bg-blue-700 @elseif($blockData['style'] ?? 'default' === 'accent') bg-green-600 text-white hover:bg-green-700 @else bg-gray-600 text-white hover:bg-gray-700 @endif"
                >
                    {{ $blockData['button_text'] ?? 'Dowiedz się więcej' }}
                    <x-heroicon-o-arrow-right class="w-4 h-4" />
                </a>
            @endif
        </div>
        @break

    @case('two_columns')
        <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="prose prose-gray max-w-none">
                {!! $blockData['left_column'] ?? '' !!}
            </div>
            <div class="prose prose-gray max-w-none">
                {!! $blockData['right_column'] ?? '' !!}
            </div>
        </div>
        @break

    @case('three_columns')
        <div class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="prose prose-gray max-w-none">
                {!! $blockData['column_1'] ?? '' !!}
            </div>
            <div class="prose prose-gray max-w-none">
                {!! $blockData['column_2'] ?? '' !!}
            </div>
            <div class="prose prose-gray max-w-none">
                {!! $blockData['column_3'] ?? '' !!}
            </div>
        </div>
        @break

    @case('quote')
        <blockquote class="mb-8 border-l-4 border-cyan-500 pl-6 py-6 bg-gray-50 rounded-r-xl shadow-sm">
            <p class="text-xl text-gray-700 italic mb-4 leading-relaxed">
                "{{ $blockData['quote'] }}"
            </p>
            @if(!empty($blockData['author']))
                <footer class="text-gray-600">
                    <strong class="text-gray-900">{{ $blockData['author'] }}</strong>
                    @if(!empty($blockData['author_title']))
                        <span class="text-gray-500"> — {{ $blockData['author_title'] }}</span>
                    @endif
                </footer>
            @endif
        </blockquote>
        @break

    @default
        {{-- Unknown block type - log warning in dev --}}
        @if(config('app.debug'))
            <div class="mb-8 p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
                <strong>Debug:</strong> Unknown block type "{{ $blockType }}"
            </div>
        @endif
@endswitch
