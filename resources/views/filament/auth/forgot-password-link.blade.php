{{-- Entry into the EXISTING password-reset flow (routes/web.php password.*) from the panel
     login screens. Not Filament's own passwordReset(): that sends Filament's mail, bypassing
     our EmailTemplate pipeline, branded layout and localisation. route() is evaluated while
     the request is rendered, so on a tenant host it resolves to that tenant's host. --}}
<div style="text-align: center; margin-top: 1rem;">
    <x-filament::link :href="route('password.request')" size="sm">
        {{ __('account.login.forgot') }}
    </x-filament::link>
</div>
