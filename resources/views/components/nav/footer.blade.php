@props([
    'contact' => [],
])

@inject('navigation', 'App\Services\NavigationService')

@php
    $isTenantDomain = !is_null(request()->attributes->get('tenant'));
    $brandName = app(\App\Support\Settings\SettingsManager::class)->brandName();

    // contact.address was never a real setting key (Contact tab writes
    // address_line/city/postal_code — see SystemSettings.php) so it never
    // rendered even for a tenant who filled the form in. Assemble it here
    // from the keys that actually exist.
    $phone = $contact['phone'] ?? null;
    $email = $contact['email'] ?? null;
    $cityLine = trim(collect([$contact['postal_code'] ?? null, $contact['city'] ?? null])->filter()->implode(' '));
    $address = collect([$contact['address_line'] ?? null, $cityLine ?: null])->filter()->implode(', ') ?: null;
    $hasContact = (bool) ($phone || $email || $address);

    $footerMenuItems = $navigation->getMenuItems('footer');
    $hasFooterNav = $footerMenuItems->isNotEmpty();

    // A column with an empty body under a heading reads as broken, not
    // minimal — render only the columns that have content, and resize the
    // grid so the remaining ones don't leave a lopsided gap.
    $extraColumns = ($hasFooterNav ? 1 : 0) + ($hasContact ? 1 : 0);
    $gridColsClass = match ($extraColumns) {
        2 => 'md:grid-cols-4',
        1 => 'md:grid-cols-2',
        default => 'md:grid-cols-1',
    };
    $brandColSpanClass = $extraColumns === 2 ? 'md:col-span-2' : '';
@endphp

<footer class="bg-dark-bg text-dark-text mt-auto">
    <x-layout.container>
        <div class="py-16">
            <div class="grid grid-cols-1 {{ $gridColsClass }} gap-12">

                {{-- Brand --}}
                <div class="{{ $brandColSpanClass }}">
                    <h3 class="text-lg font-semibold mb-4">{{ $brandName }}</h3>
                    @unless($isTenantDomain)
                        <p class="text-dark-text-muted text-sm leading-relaxed max-w-md">
                            Profesjonalna platforma do zarządzania rezerwacjami i wypożyczeniami.
                        </p>
                    @endunless
                </div>

                {{-- Navigation --}}
                @if($hasFooterNav)
                <div>
                    <h4 class="text-sm font-semibold uppercase tracking-wider text-dark-text-muted mb-4">Nawigacja</h4>
                    <nav class="space-y-3">
                        <x-navigation.menu-items location="footer" />
                    </nav>
                </div>
                @endif

                {{-- Contact --}}
                @if($hasContact)
                <div>
                    <h4 class="text-sm font-semibold uppercase tracking-wider text-dark-text-muted mb-4">Kontakt</h4>
                    <div class="space-y-3 text-sm">
                        @if($phone)
                            <a href="tel:{{ $phone }}" class="flex items-center gap-2 text-dark-text-muted hover:text-dark-text transition-colors">
                                <x-heroicon-m-phone class="h-4 w-4 shrink-0" />
                                {{ $phone }}
                            </a>
                        @endif
                        @if($email)
                            <a href="mailto:{{ $email }}" class="flex items-center gap-2 text-dark-text-muted hover:text-dark-text transition-colors">
                                <x-heroicon-m-envelope class="h-4 w-4 shrink-0" />
                                {{ $email }}
                            </a>
                        @endif
                        @if($address)
                            <p class="flex items-start gap-2 text-dark-text-muted">
                                <x-heroicon-m-map-pin class="h-4 w-4 shrink-0 mt-0.5" />
                                {{ $address }}
                            </p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- Bottom bar --}}
        <div class="border-t border-white/10 py-6 flex flex-col md:flex-row items-center justify-between gap-4">
            <p class="text-sm text-dark-text-muted">
                &copy; {{ date('Y') }} {{ $brandName }}. Wszelkie prawa zastrzeżone.
            </p>
            <div class="flex items-center gap-6 text-sm">
                <a href="#" class="text-dark-text-muted hover:text-dark-text transition-colors">Polityka prywatności</a>
                <a href="#" class="text-dark-text-muted hover:text-dark-text transition-colors">Regulamin</a>
            </div>
        </div>
    </x-layout.container>
</footer>
