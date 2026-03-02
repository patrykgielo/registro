<x-ios.auth-card
    title="Potwierdź hasło"
    subtitle="Ze względów bezpieczeństwa potwierdź swoje hasło"
>
    {{-- Warning Alert --}}
    <x-ios.alert
        type="warning"
        message="Ta akcja wymaga potwierdzenia hasła"
        class="mb-6"
    />

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-6">
        @csrf

        {{-- Password --}}
        <x-ios.input
            type="password"
            name="password"
            label="Hasło"
            placeholder="Wprowadź swoje hasło"
            icon="lock-closed"
            required
            autofocus
            autocomplete="current-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            label="Potwierdź"
            icon="shield-check"
            iconPosition="right"
            fullWidth
        />

        {{-- Forgot Password Link --}}
        @if (Route::has('password.request'))
            <div class="text-center mt-4">
                <x-ios.button
                    variant="ghost"
                    href="{{ route('password.request') }}"
                    label="Zapomniałeś hasła?"
                    icon="question-mark-circle"
                    iconPosition="left"
                />
            </div>
        @endif
    </form>
</x-ios.auth-card>
