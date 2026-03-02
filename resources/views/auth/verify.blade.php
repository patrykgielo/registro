<x-ios.auth-card
    title="Zweryfikuj adres e-mail"
    subtitle="Link weryfikacyjny został wysłany na Twój adres"
>
    {{-- Success Alert (resent) --}}
    @if (session('resent'))
        <x-ios.alert
            type="success"
            message="Link wysłany ponownie! Sprawdź swoją skrzynkę e-mail."
            dismissible
            class="mb-6"
        />
    @endif

    {{-- Info Alert --}}
    <x-ios.alert
        type="info"
        class="mb-6"
    >
        <p class="mb-0">
            Sprawdź swoją skrzynkę e-mail i kliknij link weryfikacyjny.
            Nie dostałeś wiadomości? Wyślij link ponownie, klikając poniższy przycisk.
        </p>
    </x-ios.alert>

    {{-- Resend Verification Link Form --}}
    <form method="POST" action="{{ route('verification.resend') }}" class="space-y-6">
        @csrf

        <x-ios.button
            type="submit"
            variant="primary"
            label="Wyślij link ponownie"
            icon="paper-airplane"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
