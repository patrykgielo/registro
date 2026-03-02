<x-ios.auth-card
    title="Witaj ponownie"
    subtitle="Zaloguj się do swojego konta"
>
    <form method="POST" action="{{ route('login') }}" class="space-y-6">
        @csrf

        {{-- Email Input --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres email"
            placeholder="twoj@email.pl"
            icon="email"
            :value="old('email')"
            required
            autofocus
            autocomplete="email"
        />

        {{-- Password Input --}}
        <x-ios.input
            type="password"
            name="password"
            label="Hasło"
            placeholder="Wprowadź hasło"
            icon="password"
            required
            autocomplete="current-password"
        />

        {{-- Remember Me & Forgot Password Row --}}
        <div class="flex items-center justify-between">
            <x-ios.checkbox
                name="remember"
                label="Zapamiętaj mnie"
                style="checkbox"
                :checked="old('remember')"
            />

            @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}"
               class="text-sm font-medium text-primary hover:text-primary/80 transition-colors ios-spring">
                Zapomniałeś hasła?
            </a>
            @endif
        </div>

        {{-- Login Button --}}
        <button type="submit"
                class="w-full bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 ios-spring focus:outline-none focus:ring-4 focus:ring-primary/30">
            <span class="flex items-center justify-center gap-2">
                Zaloguj się
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </button>
    </form>

    {{-- Footer Slot: Register Link --}}
    @if($registrationEnabled)
        <x-slot:footer>
            <p class="text-sm text-white/90">
                Nie masz konta?
                <a href="{{ route('register') }}"
                   class="font-semibold text-white hover:text-white/80 transition-colors ios-spring underline decoration-2 underline-offset-4">
                    Zarejestruj się
                </a>
            </p>
        </x-slot:footer>
    @endif
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
