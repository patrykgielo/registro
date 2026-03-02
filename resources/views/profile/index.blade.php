@extends('layouts.app')

@section('title', 'Moje Konto')

@section('content')
<div class="bg-gray-50 min-h-screen pb-24 md:pb-8">
    <div class="max-w-2xl mx-auto">
        {{-- Profile Header --}}
        <div class="bg-white p-6 mb-6 flex items-center gap-4 md:rounded-lg shadow-sm">
            <div class="w-20 h-20 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold text-2xl flex-shrink-0">
                {{ strtoupper(substr(Auth::user()->first_name, 0, 1)) }}
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold text-gray-900 truncate">{{ Auth::user()->name }}</h1>
                <p class="text-sm text-gray-500 truncate">{{ Auth::user()->email }}</p>
            </div>
        </div>

        {{-- Section 1: Account Data --}}
        <section class="mb-6">
            <h2 class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                Dane konta
            </h2>
            <ul class="bg-white divide-y divide-gray-200 md:rounded-lg shadow-sm">
                {{-- Personal Info --}}
                <li>
                    <a href="{{ route('profile.personal') }}"
                       class="flex items-center justify-between px-4 py-4 active:bg-gray-100 transition-colors min-h-[44px]">
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-gray-900">Dane osobowe</div>
                            <div class="text-sm text-gray-500 truncate mt-0.5">
                                {{ Auth::user()->name }}
                            </div>
                        </div>
                        @include('profile.partials.icons.chevron-right')
                    </a>
                </li>

                {{-- Vehicle --}}
                <li>
                    <a href="{{ route('profile.vehicle') }}"
                       class="flex items-center justify-between px-4 py-4 active:bg-gray-100 transition-colors min-h-[44px]">
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-gray-900">Pojazd</div>
                            <div class="text-sm text-gray-500 truncate mt-0.5">
                                @if($vehicle)
                                    {{ $vehicle->car_brand->name ?? '' }} {{ $vehicle->car_model->name ?? '' }} {{ $vehicle->year ?? '' }}
                                @else
                                    Nie dodano pojazdu
                                @endif
                            </div>
                        </div>
                        @include('profile.partials.icons.chevron-right')
                    </a>
                </li>

                {{-- Address --}}
                <li>
                    <a href="{{ route('profile.address') }}"
                       class="flex items-center justify-between px-4 py-4 active:bg-gray-100 transition-colors min-h-[44px]">
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-gray-900">Adres</div>
                            <div class="text-sm text-gray-500 truncate mt-0.5">
                                @if($address)
                                    {{ $address->street }}, {{ $address->city }}
                                @else
                                    Nie dodano adresu
                                @endif
                            </div>
                        </div>
                        @include('profile.partials.icons.chevron-right')
                    </a>
                </li>
            </ul>
        </section>

        {{-- Section 2: Preferences --}}
        <section class="mb-6">
            <h2 class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                Preferencje
            </h2>
            <ul class="bg-white divide-y divide-gray-200 md:rounded-lg shadow-sm">
                {{-- Notifications --}}
                <li>
                    <a href="{{ route('profile.notifications') }}"
                       class="flex items-center justify-between px-4 py-4 active:bg-gray-100 transition-colors min-h-[44px]">
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-gray-900">Powiadomienia</div>
                            <div class="text-sm text-gray-500 truncate mt-0.5">
                                @php
                                    $enabled = [];
                                    if(Auth::user()->email_notifications_enabled) $enabled[] = 'Email';
                                    if(Auth::user()->sms_notifications_enabled) $enabled[] = 'SMS';
                                @endphp
                                {{ implode(', ', $enabled) ?: 'Wyłączone' }}
                            </div>
                        </div>
                        @include('profile.partials.icons.chevron-right')
                    </a>
                </li>
            </ul>
        </section>

        {{-- Section 3: Security & Privacy --}}
        <section class="mb-6">
            <h2 class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                Bezpieczeństwo i prywatność
            </h2>
            <ul class="bg-white divide-y divide-gray-200 md:rounded-lg shadow-sm">
                {{-- Security Page Link --}}
                <li>
                    <a href="{{ route('profile.security') }}"
                       class="flex items-center justify-between px-4 py-4 active:bg-gray-100 transition-colors min-h-[44px]">
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-gray-900">Bezpieczeństwo</div>
                            <div class="text-sm text-gray-500 truncate mt-0.5">
                                Hasło, email, usuwanie konta
                            </div>
                        </div>
                        @include('profile.partials.icons.chevron-right')
                    </a>
                </li>
            </ul>
        </section>

        {{-- Logout Section --}}
        <section class="mb-6">
            <div class="bg-white md:rounded-lg shadow-sm">
                <button
                    onclick="confirmLogout()"
                    class="flex items-center w-full px-4 py-4 text-red-600 hover:bg-red-50 active:bg-red-100 transition-colors min-h-[44px]">
                    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="font-medium">Wyloguj się</span>
                </button>
            </div>
        </section>
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
