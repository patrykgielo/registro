<?php

namespace App\Providers\Filament;

use App\Filament\Pages\MaintenanceSettings;
use App\Filament\Pages\SystemSettings;
use App\Filament\Widgets\CacheClearWidget;
use App\Http\Responses\LoginResponse;
use App\Models\Organization;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Register services.
     *
     * Overrides LoginResponse to always redirect to admin panel after login,
     * preventing redirect to "/" during maintenance mode.
     */
    public function register(): void
    {
        parent::register();

        // Override LoginResponse to always redirect to admin panel
        // This fixes the issue where intended URL "/" causes redirect to maintenance page
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->tenant(Organization::class, slugAttribute: 'slug')

            // 🎨 COLOR SYSTEM - Medical Precision Turquoise
            ->colors([
                'primary' => [
                    50 => '#E6F4F6',
                    100 => '#CCE9ED',
                    200 => '#99D3DB',
                    300 => '#66BDC9',
                    400 => '#4AA5B0',
                    500 => '#3D8A94',  // Main brand color
                    600 => '#2F6A72',
                    700 => '#224A50',
                    800 => '#162A2E',
                    900 => '#0A0F10',
                ],
                'success' => Color::hex('#34C759'),
                'warning' => Color::hex('#FF9500'),
                'danger' => Color::hex('#FF3B30'),
                'info' => Color::hex('#4AA5B0'),
            ])

            // 🏷️ BRANDING
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->darkModeBrandLogo(fn () => view('filament.brand-logo-dark'))
            ->brandLogoHeight('2.5rem')
            ->brandName('Registro Admin')

            // 📐 LAYOUT
            ->sidebarCollapsibleOnDesktop(true)
            ->sidebarWidth('16rem')
            ->collapsedSidebarWidth('4rem')
            ->maxContentWidth('full')
            ->darkMode(true)

            // 🔤 TYPOGRAPHY
            ->font('system-ui')

            // 🧭 NAVIGATION - Logical Business Flow
            // Keys MUST match resource $navigationGroup values (lowercase English).
            // NavigationGroup labels display translated Polish names in the sidebar.
            ->navigationGroups([
                'appointments' => NavigationGroup::make(__('navigation.groups.appointments')),
                'content' => NavigationGroup::make(__('navigation.groups.content')),
                'rentals' => NavigationGroup::make(__('navigation.groups.rentals')),
                'vehicles' => NavigationGroup::make(__('navigation.groups.vehicles')),
                'staff' => NavigationGroup::make(__('navigation.groups.staff')),
                'users' => NavigationGroup::make(__('navigation.groups.users')),
                'communication' => NavigationGroup::make(__('navigation.groups.communication')),
                'settings' => NavigationGroup::make(__('navigation.groups.settings')),
                'system' => NavigationGroup::make(__('navigation.groups.system')),
            ])

            // 📄 PAGES & RESOURCES
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                SystemSettings::class,
                MaintenanceSettings::class,
            ])

            // 📊 WIDGETS
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                CacheClearWidget::class,
            ])

            // 🔒 MIDDLEWARE
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
                \App\Http\Middleware\AdminMaintenanceCheck::class, // Block non-super-admin during maintenance
            ])

            // 🎨 CUSTOM CSS - Hide image editor icon (premium feature)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString('
                    <style>
                        /* Hide image editor button - feature reserved for premium */
                        .no-edit-icon .filepond--action-edit-item {
                            display: none !important;
                        }
                    </style>
                ')
            );
    }
}
