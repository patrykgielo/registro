<x-ios.auth-card
    title="Witaj w Registro!"
    subtitle="Ustaw hasło aby aktywować swoje konto"
>
    {{-- Info Alert --}}
    <x-ios.alert
        type="info"
        message="Administrator utworzył dla Ciebie konto. Aby się zalogować, ustaw swoje hasło."
        class="mb-6"
    />

    {{-- Token Error Alert --}}
    @error('token')
        <x-ios.alert
            type="error"
            title="Błąd"
            :message="$message"
            dismissible
            class="mb-6"
        />
    @enderror

    <form method="POST" action="{{ route('password.setup.store') }}" class="space-y-6">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Email (disabled, readonly) --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres e-mail"
            placeholder="{{ $email }}"
            :value="$email"
            icon="envelope"
            helpText="Twój adres e-mail"
            disabled
            readonly
        />

        {{-- New Password --}}
        <x-ios.input
            type="password"
            name="password"
            label="Nowe hasło"
            placeholder="Minimum 8 znaków"
            icon="password"
            helpText="Minimum 8 znaków"
            required
            autofocus
            autocomplete="new-password"
        />

        {{-- Confirm Password --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            label="Potwierdź hasło"
            placeholder="Wprowadź hasło ponownie"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            label="Ustaw hasło i zaloguj się"
            icon="arrow-right"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
