@props([
    'contactPhone' => null,
    'bookingEnabled' => false,
    'rentalEnabled' => false,
    'registrationEnabled' => true,
])

@php
    $isTenantDomain = !is_null(request()->attributes->get('tenant'));
@endphp

<div x-data="{
    mobileOpen: false,
    scrolled: false,
}" x-init="window.addEventListener('scroll', () => { scrolled = window.scrollY > 20 })">

    {{-- ─── Desktop + Mobile Header ─── --}}
    <header
        :class="scrolled ? 'bg-surface-raised/95 backdrop-blur-lg shadow-sm border-b border-border' : 'bg-surface-raised'"
        class="fixed top-0 inset-x-0 z-[var(--z-sticky)] transition-all duration-300"
    >
        <x-layout.container>
            <div class="flex items-center justify-between h-16">

                {{-- Logo --}}
                @php
                    $__headerLogo = app(\App\Support\Settings\SettingsManager::class)->headerLogo();
                    $__logoAlt = app(\App\Support\Settings\SettingsManager::class)->logoAlt();
                @endphp
                <a href="{{ route('home') }}" class="flex items-center shrink-0">
                    @if($__headerLogo)
                        <img src="{{ $__headerLogo }}" alt="{{ $__logoAlt }}" class="h-9 w-auto max-w-[160px] object-contain">
                    @else
                        <span class="text-lg font-semibold text-text-primary tracking-tight">{{ config('app.name') }}</span>
                    @endif
                </a>

                {{-- Desktop Nav --}}
                <nav class="hidden md:flex items-center gap-8" aria-label="Main navigation">
                    <x-navigation.menu-items location="header" />
                </nav>

                {{-- Desktop Actions --}}
                <div class="hidden md:flex items-center gap-3">
                    @auth
                        @php
                            $cartCount = 0;
                            if ($rentalEnabled) {
                                $cartOrg = \App\Support\TenantFeature::currentTenant();
                                if ($cartOrg) {
                                    $cartModel = \App\Models\Cart::active()
                                        ->forUser(Auth::user())
                                        ->where('organization_id', $cartOrg->id)
                                        ->first();
                                    $cartCount = $cartModel ? $cartModel->items()->count() : 0;
                                }
                            }
                        @endphp

                        {{-- Cart Icon --}}
                        @if($rentalEnabled)
                        <a
                            href="{{ route('cart.show') }}"
                            class="relative flex items-center justify-center min-h-11 min-w-11 rounded-lg text-text-secondary hover:text-text-primary hover:bg-surface-sunken transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            aria-label="{{ $cartCount > 0 ? 'Twój koszyk (' . $cartCount . ' ' . ($cartCount === 1 ? 'pozycja' : ($cartCount < 5 ? 'pozycje' : 'pozycji')) . ')' : 'Twój koszyk' }}"
                        >
                            <x-heroicon-m-shopping-cart class="h-5 w-5" />
                            @if($cartCount > 0)
                                <span
                                    class="absolute -top-1 -right-1 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-brand text-text-inverse text-[10px] font-semibold leading-none"
                                    aria-hidden="true"
                                >{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                            @endif
                        </a>
                        @endif

                        <x-interactive.dropdown align="right">
                            <x-slot:trigger>
                                <button class="flex items-center gap-2 text-sm text-text-secondary hover:text-text-primary transition-colors py-2">
                                    <x-ui.avatar size="sm" :alt="Auth::user()->name" />
                                    <span>{{ Auth::user()->first_name }}</span>
                                    <x-heroicon-m-chevron-down class="h-4 w-4" />
                                </button>
                            </x-slot:trigger>

                            <a href="{{ route('profile.index') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken transition-colors" role="menuitem">
                                <x-heroicon-m-user class="h-4 w-4" /> Moje konto
                            </a>
                            @if($bookingEnabled)
                            <a href="{{ route('appointments.index') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken transition-colors" role="menuitem">
                                <x-heroicon-m-calendar class="h-4 w-4" /> Moje rezerwacje
                            </a>
                            @endif
                            @if($rentalEnabled)
                            <a href="{{ route('orders.index') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken transition-colors" role="menuitem">
                                <x-heroicon-m-shopping-bag class="h-4 w-4" /> Moje zamówienia
                            </a>
                            @endif
                            @if(Auth::user()->hasAnyRole(['admin', 'super-admin', 'staff']))
                                <a href="/admin" class="flex items-center gap-2 px-4 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken transition-colors" role="menuitem">
                                    <x-heroicon-m-cog-6-tooth class="h-4 w-4" /> Panel admina
                                </a>
                            @endif
                            <x-ui.separator class="my-1" />
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex items-center gap-2 w-full px-4 py-2.5 text-sm text-error hover:bg-error/5 transition-colors" role="menuitem">
                                    <x-heroicon-m-arrow-right-on-rectangle class="h-4 w-4" /> Wyloguj
                                </button>
                            </form>
                        </x-interactive.dropdown>

                        @if($bookingEnabled)
                            <x-ui.button href="{{ route('booking.step', ['step' => 1]) }}" icon-right="arrow-right">
                                Zarezerwuj
                            </x-ui.button>
                        @endif
                    @else
                        <x-ui.button variant="ghost" href="{{ route('login') }}">Zaloguj</x-ui.button>
                        @if($isTenantDomain)
                            @if($registrationEnabled)
                                <x-ui.button href="{{ route('customer.register') }}">Zarejestruj się</x-ui.button>
                            @endif
                        @else
                            {{-- No tenant resolved: there is no public self-serve
                                 sign-up anymore (see routes/web.php), so this is a
                                 sales contact point, not a registration link. --}}
                            <x-ui.button href="mailto:{{ $contactEmail }}">Kontakt</x-ui.button>
                        @endif
                    @endauth
                </div>

                {{-- Mobile Hamburger --}}
                <button
                    @click="mobileOpen = true"
                    class="md:hidden flex items-center justify-center min-h-11 min-w-11 text-text-secondary hover:text-text-primary transition-colors rounded-lg"
                    aria-label="Otwórz menu"
                >
                    <x-heroicon-m-bars-3 class="h-6 w-6" />
                </button>
            </div>
        </x-layout.container>
    </header>

    {{-- Spacer --}}
    <div class="h-16"></div>

    {{-- ─── Mobile Drawer ─── --}}
    <x-interactive.drawer name="mobile-nav" title="Menu" side="right">
        <x-slot:name>mobile-nav</x-slot:name>

        {{-- Override drawer to use local state --}}
    </x-interactive.drawer>

    {{-- Mobile Drawer (inline — simpler for navigation) --}}
    <template x-teleport="body">
        {{-- Backdrop --}}
        <div
            x-show="mobileOpen"
            x-transition.opacity.duration.200ms
            @click="mobileOpen = false"
            class="fixed inset-0 z-[var(--z-modal-backdrop)] bg-surface-overlay"
            x-cloak
        ></div>

        {{-- Panel --}}
        <div
            x-show="mobileOpen"
            x-transition:enter="duration-300 ease-out"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="duration-200 ease-in"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            x-trap.inert.noscroll="mobileOpen"
            @keydown.escape.window="mobileOpen = false"
            class="fixed inset-y-0 right-0 z-[var(--z-modal)] w-full max-w-sm bg-surface-raised shadow-xl"
            role="dialog"
            aria-modal="true"
            aria-label="Menu nawigacji"
            x-cloak
        >
            <div class="flex h-full flex-col">
                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-border px-6 py-4">
                    <span class="text-lg font-semibold text-text-primary">Menu</span>
                    <button @click="mobileOpen = false" class="text-text-muted hover:text-text-primary rounded-lg p-1">
                        <x-heroicon-m-x-mark class="h-5 w-5" />
                    </button>
                </div>

                {{-- Content --}}
                <div class="flex-1 overflow-y-auto px-6 py-6">
                    @auth
                        <div class="flex items-center gap-3 mb-6 pb-6 border-b border-border">
                            <x-ui.avatar size="md" :alt="Auth::user()->name" />
                            <div>
                                <p class="font-medium text-text-primary">{{ Auth::user()->name }}</p>
                                <p class="text-sm text-text-muted">{{ Auth::user()->email }}</p>
                            </div>
                        </div>
                    @endauth

                    <nav class="space-y-1">
                        <x-navigation.menu-items location="header" />

                        @auth
                            <x-ui.separator class="my-4" />
                            <a href="{{ route('profile.index') }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                <x-heroicon-m-user class="h-5 w-5" /> Moje konto
                            </a>
                            @if($bookingEnabled)
                            <a href="{{ route('appointments.index') }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                <x-heroicon-m-calendar class="h-5 w-5" /> Moje rezerwacje
                            </a>
                            @endif
                            @if($rentalEnabled)
                            <a href="{{ route('orders.index') }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                <x-heroicon-m-shopping-bag class="h-5 w-5" /> Moje zamówienia
                            </a>
                            <a
                                href="{{ route('cart.show') }}"
                                class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                                aria-label="{{ isset($cartCount) && $cartCount > 0 ? 'Twój koszyk (' . $cartCount . ' ' . ($cartCount === 1 ? 'pozycja' : ($cartCount < 5 ? 'pozycje' : 'pozycji')) . ')' : 'Twój koszyk' }}"
                            >
                                <span class="relative flex-shrink-0">
                                    <x-heroicon-m-shopping-cart class="h-5 w-5" />
                                    @if(isset($cartCount) && $cartCount > 0)
                                        <span
                                            class="absolute -top-1.5 -right-1.5 flex items-center justify-center min-w-[16px] h-[16px] px-0.5 rounded-full bg-brand text-text-inverse text-[9px] font-semibold leading-none"
                                            aria-hidden="true"
                                        >{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                                    @endif
                                </span>
                                Koszyk
                            </a>
                            @endif
                        @else
                            <x-ui.separator class="my-4" />
                            <a href="{{ route('login') }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                <x-heroicon-m-arrow-right-on-rectangle class="h-5 w-5" /> Zaloguj się
                            </a>
                            @if($isTenantDomain)
                                @if($registrationEnabled)
                                    <a href="{{ route('customer.register') }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                        <x-heroicon-m-user-plus class="h-5 w-5" /> Zarejestruj się
                                    </a>
                                @endif
                            @else
                                <a href="mailto:{{ $contactEmail }}" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-secondary hover:text-text-primary hover:bg-surface-sunken rounded-lg transition-colors">
                                    <x-heroicon-m-envelope class="h-5 w-5" /> Kontakt
                                </a>
                            @endif
                        @endauth
                    </nav>

                    @auth
                        <div class="mt-6">
                            @if($bookingEnabled)
                                <x-ui.button href="{{ route('booking.step', ['step' => 1]) }}" class="w-full" icon-right="arrow-right">
                                    Zarezerwuj
                                </x-ui.button>
                            @endif
                        </div>
                    @endauth
                </div>

                @auth
                    <div class="border-t border-border px-6 py-4">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex items-center gap-2 text-sm text-error hover:text-error/80 transition-colors">
                                <x-heroicon-m-arrow-right-on-rectangle class="h-4 w-4" /> Wyloguj
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </div>
    </template>
</div>
