{{--
    Content Header Partial

    Renders title, metadata, featured image for Pages/Posts/Promotions.
    Adapts styling based on layout type (minimal vs default).
--}}

@props([
    'model',    // Page|Post|Promotion instance
    'type',     // 'page'|'post'|'promotion'
    'minimal' => false,  // Minimal layout styling
])

<header class="mb-8">
    {{-- Category Badge (Posts only) --}}
    @if($type === 'post' && $model->category)
        <span class="inline-block px-3 py-1 bg-primary-50 text-primary-600 text-sm font-semibold rounded-full mb-4">
            {{ $model->category->name }}
        </span>
    @endif

    {{-- Promotion Badge --}}
    @if($type === 'promotion')
        <div class="flex items-center justify-between mb-4">
            <span class="inline-block px-4 py-2 bg-green-100 text-green-800 text-sm font-bold rounded-full">
                🎉 PROMOCJA
            </span>

            @if($model->valid_from || $model->valid_until)
                <span class="text-sm text-gray-600">
                    @if($model->valid_from && $model->valid_until)
                        Ważna: {{ $model->valid_from->format('d.m.Y') }} - {{ $model->valid_until->format('d.m.Y') }}
                    @elseif($model->valid_from)
                        Ważna od: {{ $model->valid_from->format('d.m.Y') }}
                    @elseif($model->valid_until)
                        Ważna do: {{ $model->valid_until->format('d.m.Y') }}
                    @endif
                </span>
            @endif
        </div>
    @endif

    {{-- Title --}}
    <h1 class="{{ $minimal ? 'text-5xl' : 'text-4xl' }} font-bold text-gray-900 mb-4 leading-tight">
        {{ $model->title }}
    </h1>

    {{-- Publication Date (Posts) --}}
    @if($type === 'post' && $model->published_at)
        <div class="flex items-center text-gray-600 text-sm mb-6">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            {{ $model->published_at->format('d.m.Y H:i') }}
        </div>
    @endif

    {{-- Featured Image --}}
    @if($model->featured_image)
        <img src="{{ Storage::url($model->featured_image) }}"
             alt="{{ $model->title }}"
             class="w-full {{ $minimal ? 'h-auto' : 'h-96' }} object-cover rounded-lg mb-6">
    @endif

    {{-- Excerpt (Posts) --}}
    @if($type === 'post' && $model->excerpt)
        <p class="text-xl text-gray-600 mb-6 leading-relaxed">{{ $model->excerpt }}</p>
    @endif
</header>
