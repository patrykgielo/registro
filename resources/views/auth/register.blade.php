<x-ios.auth-card
    title="Dołącz do nas"
    subtitle="Stwórz konto i rozpocznij przygodę"
>
    <form method="POST" action="{{ route('register') }}" class="space-y-6">
        @csrf

        {{-- First Name Input --}}
        <x-ios.input
            type="text"
            name="first_name"
            label="Imię"
            placeholder="Jan"
            icon="user"
            :value="old('first_name')"
            required
            autofocus
            autocomplete="given-name"
        />

        {{-- Last Name Input --}}
        <x-ios.input
            type="text"
            name="last_name"
            label="Nazwisko"
            placeholder="Kowalski"
            icon="user"
            :value="old('last_name')"
            required
            autocomplete="family-name"
        />

        {{-- Email Input --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres email"
            placeholder="jan.kowalski@example.com"
            icon="email"
            :value="old('email')"
            required
            autocomplete="email"
        />

        {{-- Password Input --}}
        <x-ios.input
            type="password"
            name="password"
            label="Hasło"
            placeholder="Minimum 8 znaków"
            icon="password"
            required
            autocomplete="new-password"
            help-text="Użyj co najmniej 8 znaków, w tym wielkich liter, cyfr i znaków specjalnych"
        />

        {{-- Password Confirmation Input --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            label="Potwierdź hasło"
            placeholder="Powtórz hasło"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Terms & Conditions --}}
        <div class="pt-2">
            <div class="flex items-start">
                <div class="flex items-center h-6">
                    <input
                        id="terms"
                        name="terms"
                        type="checkbox"
                        required
                        class="w-5 h-5 rounded-lg border-2 border-gray-300 text-primary focus:ring-4 focus:ring-primary/20 transition-all ios-spring"
                    >
                </div>
                <label for="terms" class="ml-3 text-sm text-gray-700">
                    Akceptuję
                    <a href="{{ route('page.show', 'regulamin') }}" target="_blank" class="text-primary font-semibold hover:text-primary/80 transition-colors ios-spring underline">
                        Regulamin
                    </a>
                    oraz
                    <a href="{{ route('page.show', 'polityka-prywatnosci') }}" target="_blank" class="text-primary font-semibold hover:text-primary/80 transition-colors ios-spring underline">
                        Politykę Prywatności
                    </a>
                </label>
            </div>
        </div>

        {{-- Register Button --}}
        <button type="submit"
                class="w-full bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 ios-spring focus:outline-none focus:ring-4 focus:ring-primary/30">
            <span class="flex items-center justify-center gap-2">
                Zarejestruj się
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </button>
    </form>

    {{-- Footer Slot: Login Link --}}
    <x-slot:footer>
        <p class="text-sm text-white/90">
            Masz już konto?
            <a href="{{ route('login') }}"
               class="font-semibold text-white hover:text-white/80 transition-colors ios-spring underline decoration-2 underline-offset-4">
                Zaloguj się
            </a>
        </p>
    </x-slot:footer>
</x-ios.auth-card>

<style>
    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .ios-spring {
            transition: none !important;
            transform: none !important;
        }
    }
</style>
