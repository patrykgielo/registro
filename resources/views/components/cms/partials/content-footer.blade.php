{{--
    Content Footer Partial

    Renders publication date, share buttons, back links.
--}}

@props([
    'model',    // Page|Post|Promotion instance
    'type',     // 'page'|'post'|'promotion'
    'minimal' => false,
])

<footer class="mt-8 pt-6 border-t border-gray-200">
    {{-- Publication Date (Pages) --}}
    @if($type === 'page' && $model->published_at)
        <p class="text-sm text-gray-600 mb-4">
            Opublikowano: {{ $model->published_at->format('d.m.Y H:i') }}
        </p>
    @endif

    {{-- CTA for Promotions --}}
    @if($type === 'promotion' && !$minimal)
        <div class="bg-green-50 -mx-8 -mb-8 p-8 rounded-b-lg">
            <p class="text-lg font-semibold text-green-800 mb-4">
                Skorzystaj z tej promocji już dziś!
            </p>
            <a href="{{ route('home') }}"
               class="inline-block px-6 py-3 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 transition-colors">
                Umów wizytę
            </a>
        </div>
    @endif

</footer>
