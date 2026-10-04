<x-ios.auth-card
    :title="__('account.confirm.title')"
    :subtitle="__('account.confirm.subtitle')"
>
    {{-- Warning Alert --}}
    <x-ios.alert
        type="warning"
        :message="__('account.confirm.alert')"
        class="mb-6"
    />

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-6">
        @csrf

        {{-- Password --}}
        <x-ios.input
            type="password"
            name="password"
            :label="__('account.fields.password')"
            :placeholder="__('account.confirm.password_placeholder')"
            icon="lock-closed"
            required
            autofocus
            autocomplete="current-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            :label="__('account.confirm.submit')"
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
                    :label="__('account.login.forgot')"
                    icon="question-mark-circle"
                    iconPosition="left"
                />
            </div>
        @endif
    </form>
</x-ios.auth-card>
