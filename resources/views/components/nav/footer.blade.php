@props([
    'contact' => [],
])

@php
    $phone = $contact['phone'] ?? null;
    $email = $contact['email'] ?? null;
    $address = $contact['address'] ?? null;
@endphp

<footer class="bg-dark-bg text-dark-text mt-auto">
    <x-layout.container>
        <div class="py-16">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12">

                {{-- Brand --}}
                <div class="md:col-span-2">
                    <h3 class="text-lg font-semibold mb-4">{{ config('app.name') }}</h3>
                    <p class="text-dark-text-muted text-sm leading-relaxed max-w-md">
                        Profesjonalna platforma do zarządzania rezerwacjami i wypożyczeniami.
                    </p>
                </div>

                {{-- Navigation --}}
                <div>
                    <h4 class="text-sm font-semibold uppercase tracking-wider text-dark-text-muted mb-4">Nawigacja</h4>
                    <nav class="space-y-3">
                        <x-navigation.menu-items location="footer" />
                    </nav>
                </div>

                {{-- Contact --}}
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
            </div>
        </div>

        {{-- Bottom bar --}}
        <div class="border-t border-white/10 py-6 flex flex-col md:flex-row items-center justify-between gap-4">
            <p class="text-sm text-dark-text-muted">
                &copy; {{ date('Y') }} {{ config('app.name') }}. Wszelkie prawa zastrzeżone.
            </p>
            <div class="flex items-center gap-6 text-sm">
                <a href="#" class="text-dark-text-muted hover:text-dark-text transition-colors">Polityka prywatności</a>
                <a href="#" class="text-dark-text-muted hover:text-dark-text transition-colors">Regulamin</a>
            </div>
        </div>
    </x-layout.container>
</footer>
