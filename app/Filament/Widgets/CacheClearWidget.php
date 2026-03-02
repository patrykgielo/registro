<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Cache Clear Widget
 *
 * Dashboard widget for quick cache clearing operations.
 * Provides buttons for clearing application cache and config cache.
 */
class CacheClearWidget extends Widget
{
    /**
     * Widget view.
     */
    protected string $view = 'filament.widgets.cache-clear';

    /**
     * Widget column span.
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Widget sort order.
     */
    protected static ?int $sort = 999;

    /**
     * Authorize widget access.
     */
    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    /**
     * Clear application cache (Laravel cache store).
     */
    public function clearApplicationCache(): void
    {
        try {
            Cache::flush();

            Notification::make()
                ->title('Application cache cleared')
                ->body('All cached data has been removed successfully.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Cache clear failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Clear config cache (Laravel config, routes, views).
     */
    public function clearConfigCache(): void
    {
        try {
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            Notification::make()
                ->title('Config cache cleared')
                ->body('Configuration, routes, and views cache cleared successfully.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Cache clear failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Clear all caches at once.
     */
    public function clearAllCaches(): void
    {
        try {
            // Clear application cache
            Cache::flush();

            // Clear config, routes, views
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            // Clear Filament cache
            Artisan::call('filament:clear-cached-components');

            Notification::make()
                ->title('All caches cleared')
                ->body('Application, config, routes, views, and Filament caches cleared successfully.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Cache clear failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
