<div class="space-y-4">
    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Temat:</h3>
        <p class="text-base text-gray-900 dark:text-gray-100">{{ $template->subject }}</p>
    </div>

    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Podgląd HTML:</h3>
        <div class="bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 p-4 max-h-96 overflow-y-auto">
            {!! $rendered !!}
        </div>
    </div>

    @if($template->text_body)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Wersja tekstowa:</h3>
            <pre class="bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 p-4 max-h-48 overflow-y-auto text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $renderedText ?: 'Brak wersji tekstowej' }}</pre>
        </div>
    @endif

    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Informacje o szablonie:</h3>
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="text-gray-600 dark:text-gray-400">Klucz:</dt>
            <dd class="text-gray-900 dark:text-gray-100 font-mono">{{ $template->key }}</dd>

            <dt class="text-gray-600 dark:text-gray-400">Język:</dt>
            <dd class="text-gray-900 dark:text-gray-100">{{ strtoupper($template->language) }}</dd>

            <dt class="text-gray-600 dark:text-gray-400">Status:</dt>
            <dd class="text-gray-900 dark:text-gray-100">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $template->active ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : 'bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100' }}">
                    {{ $template->active ? 'Aktywny' : 'Nieaktywny' }}
                </span>
            </dd>

            @if(!empty($template->variables))
                <dt class="text-gray-600 dark:text-gray-400">Dostępne zmienne:</dt>
                <dd class="text-gray-900 dark:text-gray-100">
                    <div class="flex flex-wrap gap-1">
                        @foreach($template->variables as $var)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">
                                {{ $var }}
                            </span>
                        @endforeach
                    </div>
                </dd>
            @endif
        </dl>
    </div>

    <div class="rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 p-4">
        <p class="text-sm text-yellow-800 dark:text-yellow-200">
            <strong>Uwaga:</strong> Ten podgląd używa przykładowych danych. Rzeczywiste e-maile będą korzystać z prawdziwych danych aplikacji.
        </p>
    </div>
</div>
