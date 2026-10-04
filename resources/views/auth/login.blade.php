<x-ios.auth-card
    :title="__('account.login.title')"
    :subtitle="__('account.login.subtitle')"
>
    <form method="POST" action="{{ route('login') }}" class="space-y-6">
        @csrf

        {{-- Email Input --}}
        <x-ios.input
            type="email"
            name="email"
            :label="__('account.fields.email')"
            placeholder="{{ __('account.login.email_placeholder') }}"
            icon="email"
            :value="old('email')"
            required
            autofocus
            autocomplete="email"
        />

        {{-- Password Input --}}
        <x-ios.input
            type="password"
            name="password"
            :label="__('account.fields.password')"
            :placeholder="__('account.login.password_placeholder')"
            icon="password"
            required
            autocomplete="current-password"
        />

        {{-- Remember Me & Forgot Password Row --}}
        <div class="flex items-center justify-between">
            <x-ios.checkbox
                name="remember"
                :label="__('account.login.remember')"
                style="checkbox"
                :checked="old('remember')"
            />

            @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}"
               class="text-sm font-medium text-brand hover:text-brand/80 transition-colors ios-spring">
                {{ __('account.login.forgot') }}
            </a>
            @endif
        </div>

        {{-- Login Button --}}
        <button type="submit"
                class="w-full bg-brand text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 ios-spring focus:outline-none focus:ring-4 focus:ring-brand/30">
            <span class="flex items-center justify-center gap-2">
                {{ __('account.login.submit') }}
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </button>
    </form>

    {{-- Footer Slot: Register Link --}}
    {{-- Solid text-white throughout (not /90, /70): see auth-card.blade.php's subtitle
         comment for why translucent white text on bg-brand fails WCAG AA contrast. --}}
    <x-slot:footer>
        @if($registrationEnabled)
            <p class="text-sm text-white">
                {{ __('account.login.no_account') }}
                <a href="{{ route('customer.register') }}"
                   class="font-semibold text-white hover:text-white/80 transition-colors ios-spring underline decoration-2 underline-offset-4">
                    {{ __('account.login.register_link') }}
                </a>
            </p>
        @endif
        <p class="text-sm text-white mt-2">
            {{ __('account.login.company_account') }}
            <a href="mailto:{{ $contactEmail }}" class="font-semibold text-white underline decoration-2 underline-offset-4 hover:text-white/80 transition-colors">
                {{ __('account.login.contact_us') }}
            </a>
        </p>
    </x-slot:footer>
</x-ios.auth-card>

<style>
    /* iOS Spring Animation */
    .ios-spring {
        transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
    }

    /* Accessibility: Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .ios-spring {
            transition: none !important;
            transform: none !important;
        }
    }
</style>
