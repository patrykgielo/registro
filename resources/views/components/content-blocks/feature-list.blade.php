@props(['data' => []])

@php
    use Illuminate\Support\Facades\Storage;

    $features = $data['features'] ?? [];
    $layout = $data['layout'] ?? 'grid';
    $columns = $data['columns'] ?? '3';
    $image = $data['image'] ?? null;
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
        }
    }

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
                    <h2 class="text-5xl md:text-6xl font-light tracking-tight text-gray-900 mb-4"
                        style="letter-spacing: -0.02em;">
                        {{ $heading }}
                    </h2>
                @endif

                @if($subheading)
                    <p class="text-xl md:text-2xl text-gray-600 max-w-3xl mx-auto font-light">
                        {{ $subheading }}
                    </p>
                @endif
            </div>
        @endif

        @if(empty($features))
            <div class="max-w-2xl mx-auto bg-yellow-50 border border-yellow-200 rounded-2xl p-6">
                <div class="flex items-start gap-3">
                    <x-heroicon-s-exclamation-triangle class="w-6 h-6 text-yellow-600 flex-shrink-0" />
                    <div>
                        <p class="font-bold text-yellow-900">Brak funkcji</p>
                        <p class="mt-1 text-yellow-800">Dodaj funkcje w panelu administracyjnym.</p>
                    </div>
                </div>
            </div>
        @else
            @if($layout === 'split')
                {{-- Split layout with image --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    @if($image)
                        <div>
                            <img src="{{ Storage::url($image) }}"
                                 alt="{{ $heading }}"
                                 class="w-full rounded-2xl shadow-2xl">
                        </div>
                    @endif

                    <div class="space-y-8">
                        @foreach($features as $feature)
                            <div class="flex gap-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center">
                                        <x-dynamic-component :component="'heroicon-o-' . strtolower($feature['icon'] ?? 'star')" class="w-6 h-6 text-primary-600" />
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $feature['title'] ?? '' }}</h3>
                                    <p class="text-gray-600">{{ $feature['description'] ?? '' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- Grid layout --}}
                <div class="grid {{ $gridClass }} gap-8">
                    @foreach($features as $feature)
                        <div class="text-center">
                            <div class="w-16 h-16 bg-primary-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                <x-dynamic-component :component="'heroicon-o-' . strtolower($feature['icon'] ?? 'star')" class="w-8 h-8 text-primary-600" />
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $feature['title'] ?? '' }}</h3>
                            <p class="text-gray-600">{{ $feature['description'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
</x-blocks.partials.section-wrapper>
