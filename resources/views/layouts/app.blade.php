<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} - System Rezerwacji</title>

    {{-- Google Tag Manager (Head) - Loaded before other scripts for Consent Mode --}}
    <x-gtm-head />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    {{-- Google Tag Manager (Body noscript) - Must be immediately after <body> --}}
    <x-gtm-body />

    {{-- Skip Link for Accessibility (WCAG 2.2 AA) --}}
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-primary-600 focus:text-white focus:rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-400">
        Przejdź do treści głównej
    </a>

    @php
        $contact = app(\App\Support\Settings\SettingsManager::class)->contactInformation();

        // Calculate badge count for tab bar (upcoming appointments)
        $upcomingAppointmentsCount = 0;
        if (Auth::check()) {
            $upcomingAppointmentsCount = Auth::user()->customerAppointments()
                ->whereIn('status', [\App\Enums\AppointmentStatus::Pending, \App\Enums\AppointmentStatus::Confirmed])
                ->where('appointment_date', '>=', now()->toDateString())
                ->count();
        }
    @endphp

    {{-- Alpine.js State Management for Navigation --}}
    <div x-data="{
        mobileMenuOpen: false,
        userMenuOpen: false,
        scrolled: false,
        lastScroll: 0,
        headerVisible: true
    }"
    x-init="
        window.addEventListener('scroll', () => {
            let currentScroll = window.pageYOffset;
            scrolled = currentScroll > 50;
            if (currentScroll > 200) {
                headerVisible = currentScroll < lastScroll;
            } else {
                headerVisible = true;
            }
            lastScroll = currentScroll;
        });
    ">

    {{-- Smart Sticky Header (Dark Theme) --}}
    <nav
        :class="{
            'shadow-lg shadow-black/20': scrolled,
            '-translate-y-full': !headerVisible
        }"
        class="bg-black backdrop-blur-xl fixed top-0 left-0 right-0 z-50 transition-all duration-300 ease-in-out"
        style="transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);"
    >
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center h-16 lg:h-20">

                {{-- Logo (Left) - White variant for dark header --}}
                <x-ios.nav-logo />

                {{-- Desktop Navigation (Center) - Fully Dynamic from CMS --}}
                <div class="hidden md:flex items-center space-x-6">
                    <x-navigation.menu-items location="header" :dark="true" />
                </div>

                {{-- Desktop Right Section --}}
                <div class="hidden md:flex items-center space-x-4">
                    @auth
                        {{-- User Dropdown (Alpine.js) --}}
                        <div class="relative" @click.away="userMenuOpen = false">
                            <button
                                @click="userMenuOpen = !userMenuOpen"
                                class="flex items-center space-x-2 text-white hover:text-[#0AB1EA] transition-colors"
                                :aria-expanded="userMenuOpen"
                            >
                                <span>{{ Auth::user()->first_name }}</span>
                                <svg class="w-4 h-4 transition-transform"
                                     :class="{ 'rotate-180': userMenuOpen }"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            {{-- Dropdown Menu --}}
                            <div
                                x-show="userMenuOpen"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-xl border border-gray-100 py-2 z-60"
                                style="display: none;"
                            >
                                <a href="{{ route('profile.index') }}" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-cyan-500 transition-colors">
                                    <div class="flex items-center space-x-2">
                                        <x-heroicon-o-user class="w-5 h-5" />
                                        <span>Moje Konto</span>
                                    </div>
                                </a>
                                <a href="{{ route('appointments.index') }}" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-cyan-500 transition-colors">
                                    <div class="flex items-center space-x-2">
                                        <x-heroicon-o-calendar class="w-5 h-5" />
                                        <span>Moje Wizyty</span>
                                    </div>
                                </a>
                                @if(Auth::user()->hasAnyRole(['admin', 'super-admin', 'staff']))
                                    <a href="/admin" class="block px-4 py-2 text-gray-700 hover:bg-blue-50 hover:text-cyan-500 transition-colors">
                                        <div class="flex items-center space-x-2">
                                            <x-heroicon-o-cog-6-tooth class="w-5 h-5" />
                                            <span>Panel Admina</span>
                                        </div>
                                    </a>
                                @endif
                                <div class="border-t border-gray-100 my-2"></div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 transition-colors">
                                        <div class="flex items-center space-x-2">
                                            <x-heroicon-o-arrow-right-on-rectangle class="w-5 h-5" />
                                            <span>Wyloguj</span>
                                        </div>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- CTA Button (Authenticated) --}}
                        @if($bookingEnabled)
                            <x-ios.button
                                variant="primary"
                                href="{{ route('booking.step', ['step' => 1]) }}"
                                label="Zarezerwuj Termin"
                                icon="calendar"
                                iconPosition="right"
                            />
                        @else
                            <x-ios.button
                                variant="primary"
                                href="tel:{{ $contactPhone }}"
                                label="Skontaktuj się z nami"
                                icon="phone"
                                iconPosition="right"
                            />
                        @endif
                    @else
                        {{-- Guest Links --}}
                        <x-ios.button
                            variant="ghost"
                            href="{{ route('login') }}"
                            label="Zaloguj się"
                        />
                        @if($registrationEnabled)
                            <x-ios.button
                                variant="primary"
                                href="{{ route('register') }}"
                                label="Zarejestruj się"
                            />
                        @endif
                    @endauth
                </div>

                {{-- Mobile Hamburger (Right) --}}
                <button
                    @click="mobileMenuOpen = true"
                    class="md:hidden flex items-center justify-center w-11 h-11 text-white hover:text-[#0AB1EA] transition-colors"
                    aria-label="Open menu"
                >
                    {{-- Hamburger Icon --}}
                    <div class="flex flex-col justify-center items-center w-11 h-11 space-y-1.5">
                        <span :class="{ 'rotate-45 translate-y-2': mobileMenuOpen }" class="block w-6 h-0.5 bg-white transition-all duration-300 ease-in-out"></span>
                        <span :class="{ 'opacity-0': mobileMenuOpen }" class="block w-6 h-0.5 bg-white transition-all duration-300 ease-in-out"></span>
                        <span :class="{ '-rotate-45 -translate-y-2': mobileMenuOpen }" class="block w-6 h-0.5 bg-white transition-all duration-300 ease-in-out"></span>
                    </div>
                </button>

            </div>
        </div>
    </nav>

    {{-- Mobile Drawer --}}
    <div
        x-show="mobileMenuOpen"
        x-transition:enter="transform transition ease-in-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition ease-in-out duration-300"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        @keydown.escape.window="mobileMenuOpen = false"
        role="dialog"
        aria-modal="true"
        aria-label="Mobile navigation menu"
        class="fixed top-0 right-0 bottom-0 w-80 max-w-[80vw] bg-white shadow-2xl z-50 flex flex-col"
        style="display: none;"
    >
        {{-- Header with close button --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="text-lg font-semibold text-gray-900">Menu</h2>
            <button
                @click="mobileMenuOpen = false"
                type="button"
                class="flex items-center justify-center w-10 h-10 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                aria-label="Close menu"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Scrollable content area --}}
        <div class="flex-1 overflow-y-auto">
            {{-- User Info (if authenticated) --}}
            @auth
                <div class="px-6 py-4 bg-gradient-to-r from-blue-50 to-purple-50 border-b border-gray-100">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-lg">
                            {{ strtoupper(substr(Auth::user()->first_name, 0, 1)) }}
                        </div>
                        <div>
                            <div class="font-semibold text-gray-900">{{ Auth::user()->name }}</div>
                            <div class="text-sm text-gray-600">{{ Auth::user()->email }}</div>
                        </div>
                    </div>
                </div>
            @endauth

            {{-- Navigation Links - Fully Dynamic from CMS --}}
            <div class="px-4 py-6 space-y-2">
                <x-navigation.menu-items location="header" />

                @auth
                    <div class="border-t border-gray-200 my-4"></div>
                    <x-ios.nav-item
                        href="{{ route('profile.index') }}"
                        label="Moje Konto"
                        routePattern="profile"
                        icon="user"
                    />
                    <x-ios.nav-item
                        href="{{ route('appointments.index') }}"
                        label="Moje Wizyty"
                        routePattern="appointments"
                        icon="calendar"
                    />
                    @if(Auth::user()->hasAnyRole(['admin', 'super-admin', 'staff']))
                        <x-ios.nav-item
                            href="/admin"
                            label="Panel Admina"
                            icon="cog-6-tooth"
                        />
                    @endif
                @else
                    <div class="border-t border-gray-200 my-4"></div>
                    <x-ios.nav-item
                        href="{{ route('login') }}"
                        label="Zaloguj się"
                        icon="arrow-right-on-rectangle"
                    />
                    @if($registrationEnabled)
                        <x-ios.nav-item
                            href="{{ route('register') }}"
                            label="Zarejestruj się"
                            icon="user-plus"
                        />
                    @endif
                @endauth
            </div>

            {{-- CTA Footer (Mobile) --}}
            @auth
                <div class="px-4 pb-6">
                    @if($bookingEnabled)
                        <x-ios.button
                            variant="primary"
                            href="{{ route('booking.step', ['step' => 1]) }}"
                            label="Zarezerwuj Termin"
                            icon="calendar"
                            iconPosition="right"
                            fullWidth
                        />
                    @else
                        <x-ios.button
                            variant="primary"
                            href="tel:{{ $contactPhone }}"
                            label="Skontaktuj się z nami"
                            icon="phone"
                            iconPosition="right"
                            fullWidth
                        />
                    @endif
                </div>
            @endauth
        </div>
    </div>

    {{-- Overlay --}}
    <div
        x-show="mobileMenuOpen"
        x-transition:enter="transition-opacity ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="mobileMenuOpen = false"
        class="fixed inset-0 bg-black/50 z-40"
        style="display: none;"
        aria-hidden="true"
    ></div>

    </div> {{-- End Alpine.js wrapper --}}

    {{-- Spacer (prevents content from hiding under fixed header) --}}
    <div class="h-16 lg:h-20"></div>

    <!-- Main Content (Full Width - sections control their own containers) -->
    <main class="flex-1 pb-20 md:pb-0">
        {{-- Flash Messages Container (only rendered when messages exist) --}}
        @if (session('success') || session('error') || session('info') || $errors->any())
            <div class="container mx-auto px-4 py-8">
                @if (session('success'))
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
                        {{ session('success') }}
                    </div>
                @endif

                @if (session('info'))
                    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4 rounded">
                        {{ session('info') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                        {{ session('error') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Footer (iOS Component) -->
    <x-ios.footer />

    {{-- iOS Bottom Tab Bar (Mobile Only - Auth-specific items only) --}}
    <x-ios.tab-bar>
        @auth
            {{-- Rezerwacje Tab (authenticated only, with badge notification) --}}
            <x-ios.tab-item
                href="{{ route('appointments.index') }}"
                label="Rezerwacje"
                icon="calendar"
                routePattern="appointments"
                :badge="$upcomingAppointmentsCount"
            />

            {{-- Profil Tab (authenticated only) --}}
            <x-ios.tab-item
                href="{{ route('profile.index') }}"
                label="Profil"
                icon="user"
                routePattern="profile"
            />
        @else
            {{-- Zaloguj się Tab (guest only) --}}
            <x-ios.tab-item
                href="{{ route('login') }}"
                label="Zaloguj się"
                icon="arrow-right-on-rectangle"
                routePattern="login"
            />

            {{-- Rejestracja Tab (guest only) --}}
            @if($registrationEnabled)
                <x-ios.tab-item
                    href="{{ route('register') }}"
                    label="Rejestracja"
                    icon="user-plus"
                    routePattern="register"
                />
            @endif
        @endauth
    </x-ios.tab-bar>

    {{-- Back to Top Button (Fixed, visible after scrolling) --}}
    <x-ios.back-to-top />

    @stack('scripts')
</body>
</html>
