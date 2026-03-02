<x-filament-panels::page>
    {{-- Current Status Card --}}
    <div class="mb-6 rounded-xl {{ $isActive ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' }} p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="flex-shrink-0">
                    @if($isActive)
                        <div style="width: 48px; height: 48px;" class="flex items-center justify-center rounded-full bg-red-100">
                            <svg style="width: 24px; height: 24px;" class="text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                    @else
                        <div style="width: 48px; height: 48px;" class="flex items-center justify-center rounded-full bg-green-100">
                            <svg style="width: 24px; height: 24px;" class="text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    @endif
                </div>

                <div>
                    <h3 class="text-lg font-semibold {{ $isActive ? 'text-red-900' : 'text-green-900' }}">
                        @if($isActive)
                            Maintenance Mode Active
                        @else
                            Site Operational
                        @endif
                    </h3>
                    <p class="text-sm {{ $isActive ? 'text-red-700' : 'text-green-700' }}">
                        @if($isActive && $currentType)
                            Type: {{ $currentType->label() }}
                            @if($currentType === \App\Enums\MaintenanceType::PRELAUNCH)
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                    NO BYPASS
                                </span>
                            @else
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                    Admin Bypass Enabled
                                </span>
                            @endif
                        @else
                            All users can access the site normally
                        @endif
                    </p>
                </div>
            </div>

            @if($isActive)
                <div class="flex-shrink-0">
                    <span class="relative flex h-3 w-3">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                    </span>
                </div>
            @endif
        </div>

        {{-- Secret Token Display --}}
        @if($isActive && $secretToken)
            <div class="mt-4 pt-4 border-t border-red-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-red-900">Secret Bypass Token:</p>
                        <code class="text-xs text-red-700 bg-red-100 px-2 py-1 rounded mt-1 inline-block font-mono">
                            {{ $secretToken }}
                        </code>
                    </div>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText('{{ $secretToken }}').then(() => {
                            new FilamentNotification()
                                .title('Token copied to clipboard')
                                .success()
                                .send()
                        })"
                        class="text-sm text-red-600 hover:text-red-700 underline"
                    >
                        Copy
                    </button>
                </div>
                <p class="text-xs text-red-600 mt-2">
                    Share this token with authorized users: <code class="bg-red-100 px-1 rounded">?maintenance_token={{ $secretToken }}</code>
                </p>
            </div>
        @endif

        {{-- Current Config Display --}}
        @if($isActive && !empty($currentConfig))
            <div class="mt-4 pt-4 border-t {{ $isActive ? 'border-red-200' : 'border-green-200' }}">
                <p class="text-sm font-medium {{ $isActive ? 'text-red-900' : 'text-green-900' }} mb-2">Current Configuration:</p>
                <div class="grid grid-cols-2 gap-2 text-xs {{ $isActive ? 'text-red-700' : 'text-green-700' }}">
                    @foreach($currentConfig as $key => $value)
                        @if($value)
                            <div>
                                <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span>
                                <span>{{ $value }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Configuration Form --}}
    <form wire:submit="submit">
        {{ $this->form }}
    </form>

    {{-- Info Cards --}}
    <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0 mr-3">
                    <svg style="width: 48px; height: 48px;" class="text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-blue-900 mb-1">Bypass Methods</h4>
                    <ul class="text-xs text-blue-700 space-y-1">
                        <li>• Role-based: Super-admin & Admin roles</li>
                        <li>• Token-based: Secret token in URL or session</li>
                        <li>• Pre-launch: NO bypass allowed</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0 mr-3">
                    <svg style="width: 48px; height: 48px;" class="text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-purple-900 mb-1">Maintenance Types</h4>
                    <ul class="text-xs text-purple-700 space-y-1">
                        <li>• <strong>Deployment:</strong> Code updates (60s retry)</li>
                        <li>• <strong>Scheduled:</strong> Planned maintenance (300s retry)</li>
                        <li>• <strong>Emergency:</strong> Urgent fixes (120s retry)</li>
                        <li>• <strong>Pre-launch:</strong> Complete lockdown (3600s retry)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
