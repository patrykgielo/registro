<x-ios.auth-card
    title="Zresetuj hasło"
    subtitle="Wprowadź nowe hasło dla swojego konta"
>
    <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Email Address (disabled, readonly) --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres e-mail"
            placeholder="{{ $email ?? old('email') }}"
            :value="$email ?? old('email')"
            icon="envelope"
            helpText="Twój adres e-mail"
            disabled
            readonly
            required
            autocomplete="email"
        />

        {{-- New Password --}}
        <x-ios.input
            type="password"
            name="password"
            label="Nowe hasło"
            placeholder="Minimum 8 znaków"
            icon="lock-closed"
            helpText="Minimum 8 znaków"
            required
            autofocus
            autocomplete="new-password"
        />

        {{-- Confirm New Password --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            label="Potwierdź nowe hasło"
            placeholder="Wprowadź hasło ponownie"
            icon="lock-closed"
            required
            autocomplete="new-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            label="Zresetuj hasło"
            icon="check-circle"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
