<div class="space-y-4">
    {{-- Basic Info --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <span class="text-sm font-medium text-gray-500">ID</span>
            <p class="mt-1 text-sm text-gray-900">{{ $record->id }}</p>
        </div>
        <div>
            <span class="text-sm font-medium text-gray-500">Data</span>
            <p class="mt-1 text-sm text-gray-900">{{ $record->created_at->format('Y-m-d H:i:s') }}</p>
        </div>
    </div>

    {{-- Event --}}
    <div>
        <span class="text-sm font-medium text-gray-500">Zdarzenie</span>
        <p class="mt-1">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                @switch($record->event)
                    @case('created') bg-green-100 text-green-800 @break
                    @case('updated') bg-blue-100 text-blue-800 @break
                    @case('deleted') bg-red-100 text-red-800 @break
                    @case('login') bg-green-100 text-green-800 @break
                    @case('logout') bg-gray-100 text-gray-800 @break
                    @case('login_failed') bg-yellow-100 text-yellow-800 @break
                    @default bg-gray-100 text-gray-800
                @endswitch
            ">
                {{ $record->event_label }}
            </span>
        </p>
    </div>

    {{-- User --}}
    <div>
        <span class="text-sm font-medium text-gray-500">Wykonujący</span>
        <p class="mt-1 text-sm text-gray-900">
            @if($record->user)
                {{ $record->user->name }} (ID: {{ $record->user_id }})
            @else
                <span class="text-gray-400">Brak (system)</span>
            @endif
        </p>
    </div>

    {{-- Auditable Object --}}
    <div>
        <span class="text-sm font-medium text-gray-500">Obiekt</span>
        <p class="mt-1 text-sm text-gray-900">
            {{ class_basename($record->auditable_type) }} #{{ $record->auditable_id }}
        </p>
    </div>

    {{-- IP Address --}}
    <div>
        <span class="text-sm font-medium text-gray-500">Adres IP</span>
        <p class="mt-1 text-sm text-gray-900 font-mono">
            {{ $record->ip_address ?? 'N/A' }}
        </p>
    </div>

    {{-- URL --}}
    @if($record->url)
    <div>
        <span class="text-sm font-medium text-gray-500">URL</span>
        <p class="mt-1 text-sm text-gray-900 font-mono break-all">
            {{ $record->url }}
        </p>
    </div>
    @endif

    {{-- Old Values --}}
    @if($record->old_values)
    <div>
        <span class="text-sm font-medium text-gray-500">Poprzednie wartości</span>
        <pre class="mt-1 p-3 bg-gray-50 rounded-lg text-xs text-gray-800 overflow-x-auto">{{ json_encode($record->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- New Values --}}
    @if($record->new_values)
    <div>
        <span class="text-sm font-medium text-gray-500">Nowe wartości</span>
        <pre class="mt-1 p-3 bg-gray-50 rounded-lg text-xs text-gray-800 overflow-x-auto">{{ json_encode($record->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- Metadata --}}
    @if($record->metadata)
    <div>
        <span class="text-sm font-medium text-gray-500">Metadane</span>
        <pre class="mt-1 p-3 bg-gray-50 rounded-lg text-xs text-gray-800 overflow-x-auto">{{ json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- User Agent --}}
    @if($record->user_agent)
    <div>
        <span class="text-sm font-medium text-gray-500">User Agent</span>
        <p class="mt-1 text-xs text-gray-600 font-mono break-all">
            {{ $record->user_agent }}
        </p>
    </div>
    @endif
</div>
