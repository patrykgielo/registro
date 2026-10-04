<x-ios.auth-card
    :title="__('account.setup.title')"
    :subtitle="__('account.setup.subtitle')"
>
    {{-- Info Alert --}}
    <x-ios.alert
        type="info"
        :message="__('account.setup.info')"
        class="mb-6"
    />

    {{-- Token Error Alert --}}
    @error('token')
        <x-ios.alert
            type="error"
            :title="__('account.setup.error_title')"
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
            :label="__('account.passwords.email_label')"
            placeholder="{{ $email }}"
            :value="$email"
            icon="envelope"
            :helpText="__('account.passwords.your_email')"
            disabled
            readonly
        />

        {{-- New Password --}}
        <x-ios.input
            type="password"
            name="password"
            :label="__('account.passwords.new_password')"
            :placeholder="__('account.passwords.min_chars')"
            icon="password"
            :helpText="__('account.passwords.min_chars')"
            required
            autofocus
            autocomplete="new-password"
        />

        {{-- Confirm Password --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            :label="__('account.fields.password_confirmation')"
            :placeholder="__('account.passwords.reenter_placeholder')"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Submit Button --}}
        <x-ios.button
            type="submit"
            variant="primary"
            :label="__('account.setup.submit')"
            icon="arrow-right"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
