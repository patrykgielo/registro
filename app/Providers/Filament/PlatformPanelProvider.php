<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureSuperAdmin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login()

            ->colors([
                'primary' => Color::Indigo,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])

            ->brandName('Registro Platform')

            ->sidebarCollapsibleOnDesktop(true)
            ->sidebarWidth('14rem')
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->darkMode(true)

            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\\Filament\\Platform\\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\\Filament\\Platform\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])

            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\\Filament\\Platform\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureSuperAdmin::class,
            ])

            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<link rel="stylesheet" href="'.\Illuminate\Support\Facades\Vite::asset('resources/css/filament/admin.css').'">'
                    .'<script type="module" src="'.\Illuminate\Support\Facades\Vite::asset('resources/js/filament-admin.js').'"></script>'
                    // Loaded AFTER admin.css — platform-only chrome overrides (sidebar density).
                    // admin.css is shared with the tenant /admin panel and must never be edited here.
                    .'<link rel="stylesheet" href="'.\Illuminate\Support\Facades\Vite::asset('resources/css/filament/platform.css').'">'
                )
            );
    }
}
