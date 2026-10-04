<x-ios.auth-card
    :title="__('account.login.forgot')"
    :subtitle="__('account.forgot.subtitle')"
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
            :label="__('account.passwords.email_label')"
            placeholder="{{ __('account.login.email_placeholder') }}"
            :value="old('email')"
            icon="envelope"
            :helpText="__('account.forgot.email_help')"
            required
            autofocus
            autocomplete="email"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            :label="__('account.forgot.submit')"
            icon="paper-airplane"
            iconPosition="right"
            fullWidth
        />

        {{-- Back to Login Link --}}
        <div class="text-center mt-4">
            <x-ios.button
                variant="ghost"
                href="{{ route('login') }}"
                :label="__('account.forgot.back_to_login')"
                icon="arrow-left"
                iconPosition="left"
            />
        </div>
    </form>
</x-ios.auth-card>
