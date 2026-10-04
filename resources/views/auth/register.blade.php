<x-ios.auth-card
    :title="__('account.register.title')"
    :subtitle="__('account.register.subtitle')"
>
    <form method="POST" action="{{ route('customer.register') }}" class="space-y-6">
        @csrf

        {{-- First Name Input --}}
        <x-ios.input
            type="text"
            name="first_name"
            :label="__('account.fields.first_name')"
            placeholder="{{ __('account.register.first_name_placeholder') }}"
            icon="user"
            :value="old('first_name')"
            required
            autofocus
            autocomplete="given-name"
        />

        {{-- Last Name Input --}}
        <x-ios.input
            type="text"
            name="last_name"
            :label="__('account.fields.last_name')"
            placeholder="{{ __('account.register.last_name_placeholder') }}"
            icon="user"
            :value="old('last_name')"
            required
            autocomplete="family-name"
        />

        {{-- Email Input --}}
        <x-ios.input
            type="email"
            name="email"
            :label="__('account.fields.email')"
            placeholder="jan.kowalski@example.com"
            icon="email"
            :value="old('email')"
            required
            autocomplete="email"
        />

        {{-- Password Input --}}
        <x-ios.input
            type="password"
            name="password"
            :label="__('account.fields.password')"
            :placeholder="__('account.register.password_placeholder')"
            icon="password"
            required
            autocomplete="new-password"
            :help-text="__('account.register.password_help')"
        />

        {{-- Password Confirmation Input --}}
        <x-ios.input
            type="password"
            name="password_confirmation"
            :label="__('account.fields.password_confirmation')"
            :placeholder="__('account.register.password_confirmation_placeholder')"
            icon="password"
            required
            autocomplete="new-password"
        />

        {{-- Terms & Conditions --}}
        <div class="pt-2">
            <div class="flex items-start">
                <div class="flex items-center h-6">
                    <input
                        id="terms"
                        name="terms"
                        type="checkbox"
                        required
                        class="w-5 h-5 rounded-lg border-2 border-gray-300 text-brand focus:ring-4 focus:ring-brand/20 transition-all ios-spring"
                    >
                </div>
                <label for="terms" class="ml-3 text-sm text-gray-700">
                    @php
                        $linkClass = 'text-brand font-semibold hover:text-brand/80 transition-colors ios-spring underline';
                    @endphp
                    {!! __('account.register.terms', [
                        'terms' => '<a href="'.e(route('page.show', 'regulamin')).'" target="_blank" class="'.$linkClass.'">'.e(__('account.register.terms_link')).'</a>',
                        'privacy' => '<a href="'.e(route('page.show', 'polityka-prywatnosci')).'" target="_blank" class="'.$linkClass.'">'.e(__('account.register.privacy_link')).'</a>',
                    ]) !!}
                </label>
            </div>
        </div>

        {{-- Register Button --}}
        <button type="submit"
                class="w-full bg-brand text-white font-semibold py-4 rounded-lg shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98] transition-all duration-300 ios-spring focus:outline-none focus:ring-4 focus:ring-brand/30">
            <span class="flex items-center justify-center gap-2">
                {{ __('account.register.submit') }}
                <x-heroicon-m-arrow-right class="w-5 h-5" />
            </span>
        </button>
    </form>

    {{-- Footer Slot: Login Link --}}
    {{-- Solid text-white, not /90: see auth-card.blade.php's subtitle comment. --}}
    <x-slot:footer>
        <p class="text-sm text-white">
            {{ __('account.register.have_account') }}
            <a href="{{ route('login') }}"
               class="font-semibold text-white hover:text-white/80 transition-colors ios-spring underline decoration-2 underline-offset-4">
                {{ __('account.login.submit') }}
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
