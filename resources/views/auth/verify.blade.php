<x-ios.auth-card
    :title="__('account.verify.title')"
    :subtitle="__('account.verify.subtitle')"
>
    {{-- Success Alert (resent) --}}
    @if (session('resent'))
        <x-ios.alert
            type="success"
            :message="__('account.verify.resent')"
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
            {{ __('account.verify.instructions') }}
        </p>
    </x-ios.alert>

    {{-- Resend Verification Link Form --}}
    <form method="POST" action="{{ route('verification.resend') }}" class="space-y-6">
        @csrf

        <x-ios.button
            type="submit"
            variant="primary"
            :label="__('account.verify.resend')"
            icon="paper-airplane"
            iconPosition="right"
            fullWidth
        />
    </form>
</x-ios.auth-card>
