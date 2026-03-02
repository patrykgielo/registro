@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex flex-col lg:flex-row gap-6">
        {{-- Sidebar Navigation --}}
        <aside class="lg:w-64 flex-shrink-0">
            {{-- Mobile: Vertical stack navigation (iOS pattern) --}}
            <nav class="lg:hidden bg-white rounded-lg shadow-md mb-4">
                <ul class="divide-y divide-gray-200">
                    @foreach([
                        ['route' => 'profile.personal', 'icon' => 'user', 'label' => 'Dane osobowe'],
                        ['route' => 'profile.vehicle', 'icon' => 'car', 'label' => 'Pojazd'],
                        ['route' => 'profile.address', 'icon' => 'map-pin', 'label' => 'Adres'],
                        ['route' => 'profile.notifications', 'icon' => 'bell', 'label' => 'Powiadomienia'],
                        ['route' => 'profile.security', 'icon' => 'shield', 'label' => 'Bezpieczeństwo'],
                    ] as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center px-4 py-3 transition-colors min-h-[44px]
                                      {{ request()->routeIs($item['route'])
                                         ? 'bg-primary-50 text-primary-700 font-medium'
                                         : 'text-gray-700 hover:bg-gray-50' }}">
                                @include('profile.partials.icons.' . $item['icon'], ['class' => 'w-5 h-5 mr-3'])
                                <span class="flex-1">{{ __($item['label']) }}</span>
                                @if(request()->routeIs($item['route']))
                                    <svg class="w-5 h-5 text-primary-700" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>

                {{-- Logout Button (Mobile) --}}
                <div class="border-t border-gray-200">
                    <button
                        onclick="confirmLogout()"
                        class="flex items-center w-full px-4 py-3 text-red-600 hover:bg-red-50 transition-colors min-h-[44px]">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span class="font-medium">{{ __('Wyloguj się') }}</span>
                    </button>
                </div>
            </nav>

            {{-- Desktop: Vertical sidebar --}}
            <nav class="hidden lg:block bg-white rounded-lg shadow-md p-4 sticky top-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Moje konto') }}</h2>
                <ul class="space-y-1">
                    @foreach([
                        ['route' => 'profile.personal', 'icon' => 'user', 'label' => 'Dane osobowe'],
                        ['route' => 'profile.vehicle', 'icon' => 'car', 'label' => 'Mój pojazd'],
                        ['route' => 'profile.address', 'icon' => 'map-pin', 'label' => 'Mój adres'],
                        ['route' => 'profile.notifications', 'icon' => 'bell', 'label' => 'Powiadomienia'],
                        ['route' => 'profile.security', 'icon' => 'shield', 'label' => 'Bezpieczeństwo'],
                    ] as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center px-3 py-2 rounded-lg transition-colors
                                      {{ request()->routeIs($item['route'])
                                         ? 'bg-primary-50 text-primary-700 font-medium'
                                         : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                @include('profile.partials.icons.' . $item['icon'], ['class' => 'w-5 h-5'])
                                <span class="ml-3">{{ __($item['label']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                {{-- Logout Button (Desktop) --}}
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <button
                        onclick="confirmLogout()"
                        class="flex items-center w-full px-3 py-2 rounded-lg transition-colors text-red-600 hover:bg-red-50">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span class="ml-3 font-medium">{{ __('Wyloguj się') }}</span>
                    </button>
                </div>
            </nav>
        </aside>

        {{-- Main Content --}}
        <main class="flex-1 min-w-0">
            {{-- Flash Messages --}}
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex">
                        <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="ml-3 text-sm text-green-700">{{ session('success') }}</p>
                    </div>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="ml-3 text-sm text-red-700">{{ session('error') }}</p>
                    </div>
                </div>
            @endif

            @yield('profile-content')
        </main>
    </div>
</div>

{{-- Hidden Logout Form --}}
<form id="logout-form" method="POST" action="{{ route('logout') }}" style="display: none;">
    @csrf
</form>

{{-- Logout Confirmation JavaScript --}}
@push('scripts')
<script>
function confirmLogout() {
    if (confirm('Czy na pewno chcesz się wylogować?')) {
        document.getElementById('logout-form').submit();
    }
}
</script>
@endpush
@endsection
