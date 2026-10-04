<x-ios.auth-card
    :title="__('account.expired.title')"
    :subtitle="__('account.expired.subtitle')"
>
    {{-- Expired Link Alert --}}
    <x-ios.alert
        type="error"
        :title="__('account.expired.alert_title')"
        class="mb-6"
    >
        <p class="mb-3">
            {{ __('account.expired.body', ['hours' => trans_choice('account.expired.hours', \App\Models\User::PASSWORD_SETUP_TTL_HOURS)]) }}
        </p>
        <hr class="my-3 border-red-200">
        <p class="mb-0">
            <strong>{{ __('account.expired.what_now') }}</strong><br>
            {{ __('account.expired.contact_admin') }}
        </p>
    </x-ios.alert>

    {{-- Return to Login Button --}}
    <x-ios.button
        variant="secondary"
        href="{{ route('login') }}"
        :label="__('account.expired.back')"
        icon="arrow-left"
        iconPosition="left"
        fullWidth
    />
</x-ios.auth-card>
