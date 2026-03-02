<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Traits\CreatesAndRedirectsToEdit;
use App\Support\Settings\SettingsManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;

class CreatePage extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    protected static string $resource = PageResource::class;

    protected function afterCreate(): void
    {
        $this->handleHomepageSetting();
    }

    protected function handleHomepageSetting(): void
    {
        $isHomepage = $this->data['is_homepage'] ?? false;

        if ($isHomepage) {
            $settingsManager = app(SettingsManager::class);
            $settingsManager->set('cms.homepage_page_id', $this->record->id);

            // Clear relevant caches
            Cache::forget('settings:cms');

            Notification::make()
                ->title('Strona główna ustawiona')
                ->body("Strona \"{$this->record->title}\" została ustawiona jako strona główna.")
                ->success()
                ->send();
        }
    }
}
