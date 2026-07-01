<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Support\Settings\SettingsManager;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Platform-level settings page for super-admins.
 *
 * Persists global (organization_id = null) settings via SettingsManager.
 * Currently exposes: account.closure_request_email.
 *
 * Security: only reachable via /platform which is already gated by EnsureSuperAdmin,
 * but canAccess() adds an explicit in-code guard as defence-in-depth.
 */
class PlatformSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Ustawienia platformy';

    protected static ?string $title = 'Ustawienia platformy';

    protected static ?string $slug = 'ustawienia';

    protected string $view = 'filament.platform.pages.platform-settings';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }

    public function getTitle(): string
    {
        return 'Ustawienia platformy';
    }

    public function mount(): void
    {
        $manager = app(SettingsManager::class);
        $raw = $manager->getGlobal('account.closure_request_email');

        $this->form->fill([
            'closure_request_email' => is_string($raw) ? $raw : '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('E-mail dla wniosków zamknięcia konta')
                    ->description(
                        'Adres, na który tenanci wysyłają wniosek o zamknięcie konta. '
                        .'Wyświetlany w zakładce "Konto" ustawień tenanta.'
                    )
                    ->schema([
                        TextInput::make('closure_request_email')
                            ->label('Adres e-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('kontakt@registro.app'),

                        \Filament\Schemas\Components\Actions::make([
                            \Filament\Actions\Action::make('saveAccountSettings')
                                ->label('Zapisz')
                                ->color('primary')
                                ->icon('heroicon-o-check')
                                ->action('saveAccountSettings'),
                        ])->columnSpanFull(),
                    ]),
            ]);
    }

    public function saveAccountSettings(): void
    {
        $state = $this->form->getState();

        $email = trim($state['closure_request_email'] ?? '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::make()
                ->title('Nieprawidłowy adres e-mail')
                ->danger()
                ->send();

            return;
        }

        app(SettingsManager::class)->setGlobal('account.closure_request_email', $email);

        Notification::make()
            ->title('Ustawienia zapisane')
            ->success()
            ->send();
    }
}
