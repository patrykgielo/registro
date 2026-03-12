<x-ios.auth-card
    title="Twoje konto"
    subtitle="Krok 2 z 2 — Dane właściciela"
>
    {{-- Business info summary --}}
    <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-primary-100 rounded-lg flex items-center justify-center">
                <x-heroicon-m-building-office class="w-5 h-5 text-primary-600" />
            </div>
            <div>
                <p class="font-semibold text-gray-900">{{ $step1['org_name'] }}</p>
                <p class="text-sm text-gray-500">{{ $step1['slug'] }}.registro.app</p>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('register.step2.store') }}" class="space-y-6">
        @csrf

        {{-- First Name --}}
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

        {{-- Last Name --}}
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

        {{-- Email --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres email"
            placeholder="jan@example.com"
            icon="email"
            :value="old('email')"
            required
            autocomplete="email"
        />

        {{-- Password --}}
        <x-ios.input
            type="password"
            name="password"
            label="Hasło"
            placeholder="Minimum 8 znaków"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Password Confirmation --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            label="Potwierdź hasło"
            placeholder="Powtórz hasło"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Terms --}}
        <div class="pt-2">
            <div class="flex items-start">
                <div class="flex items-center h-6">
                    <input
                        id="terms"
                        name="terms"
                        type="checkbox"
                        required
                        {{ old('terms') ? 'checked' : '' }}
                        class="w-5 h-5 rounded-lg border-2 border-gray-300 text-primary focus:ring-4 focus:ring-primary/20 transition-all"
                    >
                </div>
                <label for="terms" class="ml-3 text-sm text-gray-700">
                    Akceptuję
                    <a href="{{ route('page.show', 'regulamin') }}" target="_blank" class="text-primary font-semibold hover:text-primary/80 underline">
                        Regulamin
                    </a>
                    oraz
                    <a href="{{ route('page.show', 'polityka-prywatnosci') }}" target="_blank" class="text-primary font-semibold hover:text-primary/80 underline">
                        Politykę Prywatności
                    </a>
                </label>
            </div>
            @error('terms')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Buttons --}}
        <div class="flex gap-3">
            <a href="{{ route('register') }}"
               class="flex-1 bg-gray-100 text-gray-700 font-semibold py-4 rounded-lg text-center hover:bg-gray-200 transition-all duration-200">
                <span class="flex items-center justify-center gap-2">
                    <x-heroicon-m-arrow-left class="w-5 h-5" />
                    Wstecz
                </span>
            </a>
            <button type="submit"
                    class="flex-[2] bg-primary-500 text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-primary/30">
                <span class="flex items-center justify-center gap-2">
                    Utwórz konto
                    <x-heroicon-m-rocket-launch class="w-5 h-5" />
                </span>
            </button>
        </div>
    </form>

    <x-slot:footer>
        <p class="text-sm text-white/90">
            Masz już konto?
            <a href="{{ route('login') }}"
               class="font-semibold text-white hover:text-white/80 transition-colors underline decoration-2 underline-offset-4">
                Zaloguj się
            </a>
        </p>
    </x-slot:footer>
</x-ios.auth-card>
