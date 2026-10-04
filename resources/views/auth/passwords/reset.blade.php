<x-ios.auth-card
    :title="__('account.reset.title')"
    :subtitle="__('account.reset.subtitle')"
>
    <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Email Address (disabled, readonly) --}}
        <x-ios.input
            type="email"
            name="email"
            :label="__('account.passwords.email_label')"
            placeholder="{{ $email ?? old('email') }}"
            :value="$email ?? old('email')"
            icon="envelope"
            :helpText="__('account.passwords.your_email')"
            disabled
            readonly
            required
            autocomplete="email"
        />

        {{-- New Password --}}
        <x-ios.input
            type="password"
            name="password"
            :label="__('account.passwords.new_password')"
            :placeholder="__('account.passwords.min_chars')"
            icon="lock-closed"
            :helpText="__('account.passwords.min_chars')"
            required
            autofocus
            autocomplete="new-password"
        />

        {{-- Confirm New Password --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            :label="__('account.passwords.confirm_new_password')"
            :placeholder="__('account.passwords.reenter_placeholder')"
            icon="lock-closed"
            required
            autocomplete="new-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            :label="__('account.reset.submit')"
            icon="check-circle"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
