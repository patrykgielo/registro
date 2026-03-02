<footer class="bg-section-dark mt-16">
    <div class="container mx-auto px-6 py-12">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            {{-- Left: Logo & Company Info --}}
            <div>
                @php
                    $settings = app(\App\Support\Settings\SettingsManager::class);
                @endphp
                <div class="mb-4">
                    <img
                        src="{{ $settings->footerLogo() }}"
                        alt="{{ $settings->logoAlt() }}"
                        class="h-10 w-auto"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >
                    {{-- Fallback badge if logo fails to load --}}
                    <div class="hidden w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 items-center justify-center">
                        <span class="text-white font-bold text-xl">P</span>
                    </div>
                </div>
                <p class="text-sm text-dark-muted mb-4">
                    Profesjonalne usługi detailingowe dla Twojego auta
                </p>
                <div class="flex gap-3">
                    <a href="tel:+48123456789"
                       class="w-9 h-9 rounded-full bg-white/10 hover:bg-[#0AB1EA] text-white hover:text-white flex items-center justify-center transition-all duration-200 ios-spring"
                       aria-label="Zadzwoń do nas">
                        <x-heroicon-s-phone class="w-4 h-4" />
                    </a>
                    <a href="mailto:kontakt@registro.local"
                       class="w-9 h-9 rounded-full bg-white/10 hover:bg-[#0AB1EA] text-white hover:text-white flex items-center justify-center transition-all duration-200 ios-spring"
                       aria-label="Napisz do nas">
                        <x-heroicon-s-envelope class="w-4 h-4" />
                    </a>
                </div>
            </div>

            {{-- Right: Link columns --}}
            <div class="flex flex-col sm:flex-row gap-8 md:justify-end md:text-right">
                {{-- Quick Access --}}
                <div>
                    <h3 class="font-semibold text-dark-primary mb-4">Szybki dostęp</h3>
                    <ul class="space-y-2">
                        <li>
                            <a href="{{ auth()->check() ? route('profile.personal') : route('login') }}"
                               class="text-base text-dark-muted hover:text-[#0AB1EA] transition-colors duration-200">
                                Moje konto
                            </a>
                        </li>
                        <li>
                            <a href="{{ auth()->check() ? route('appointments.index') : route('login') }}"
                               class="text-base text-dark-muted hover:text-[#0AB1EA] transition-colors duration-200">
                                Moje zamówienia
                            </a>
                        </li>
                    </ul>
                </div>

                {{-- Dynamic CMS Links --}}
                <div>
                    <h3 class="font-semibold text-dark-primary mb-4">{{ $settings->footerColumnTitle() }}</h3>
                    @inject('navigation', 'App\Services\NavigationService')
                    <ul class="space-y-2">
                        @foreach($navigation->getMenuItems('footer') as $item)
                        <li>
                            <a href="{{ $item['url'] }}"
                               class="text-base text-dark-muted hover:text-[#0AB1EA] transition-colors duration-200">
                                {{ $item['label'] }}
                            </a>
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Bottom Bar --}}
        <div class="pt-8 border-t border-white/10">
            <p class="text-center text-sm text-dark-muted">
                &copy; {{ date('Y') }} Registro. Wszelkie prawa zastrzeżone.
            </p>
        </div>
    </div>
</footer>
