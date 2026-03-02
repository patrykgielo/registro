<x-ios.auth-card
    title="Link wygasł"
    subtitle="Link do ustawienia hasła jest już nieważny"
>
    {{-- Expired Link Alert --}}
    <x-ios.alert
        type="error"
        title="Link do ustawienia hasła wygasł"
        class="mb-6"
    >
        <p class="mb-3">
            Link, którego użyłeś, wygasł lub jest nieprawidłowy. Linki do ustawienia hasła są ważne przez 30 minut ze względów bezpieczeństwa.
        </p>
        <hr class="my-3 border-red-200">
        <p class="mb-0">
            <strong>Co teraz?</strong><br>
            Skontaktuj się z administratorem, który utworzył Twoje konto, aby otrzymać nowy link do ustawienia hasła.
        </p>
    </x-ios.alert>

    {{-- Return to Login Button --}}
    <x-ios.button
        variant="secondary"
        href="{{ route('login') }}"
        label="Powrót do logowania"
        icon="arrow-left"
        iconPosition="left"
        fullWidth
    />
</x-ios.auth-card>
