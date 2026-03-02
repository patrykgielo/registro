<x-ios.auth-card
    title="Zapomniałeś hasła?"
    subtitle="Wprowadź adres e-mail, a my wyślemy link resetujący"
>
    {{-- Success Alert --}}
    @if (session('status'))
        <x-ios.alert
            type="success"
            :message="session('status')"
            dismissible
            class="mb-6"
        />
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
        @csrf

        {{-- Email Address --}}
        <x-ios.input
            type="email"
            name="email"
            label="Adres e-mail"
            placeholder="twoj@email.pl"
            :value="old('email')"
            icon="envelope"
            helpText="Wprowadź adres e-mail powiązany z Twoim kontem"
            required
            autofocus
            autocomplete="email"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            label="Wyślij link resetujący"
            icon="paper-airplane"
            iconPosition="right"
            fullWidth
        />

        {{-- Back to Login Link --}}
        <div class="text-center mt-4">
            <x-ios.button
                variant="ghost"
                href="{{ route('login') }}"
                label="Pamiętasz hasło? Zaloguj się"
                icon="arrow-left"
                iconPosition="left"
            />
        </div>
    </form>
</x-ios.auth-card>
