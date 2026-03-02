<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Traits\StaysOnPageAfterSave;
use App\Support\Settings\SettingsManager;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditPage extends EditRecord
{
    use StaysOnPageAfterSave;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Usuń'),
        ];
    }

    protected function afterSave(): void
    {
        $this->handleHomepageSetting();
    }

    protected function handleHomepageSetting(): void
    {
        $isHomepage = $this->data['is_homepage'] ?? false;
        $settingsManager = app(SettingsManager::class);
        $currentHomepageId = $settingsManager->get('cms.homepage_page_id');

        $wasHomepage = $currentHomepageId !== null && (int) $currentHomepageId === $this->record->id;

        if ($isHomepage && ! $wasHomepage) {
            // Setting as new homepage
            $settingsManager->set('cms.homepage_page_id', $this->record->id);
            Cache::forget('settings:cms');

            Notification::make()
                ->title('Strona główna ustawiona')
                ->body("Strona \"{$this->record->title}\" została ustawiona jako strona główna.")
                ->success()
                ->send();
        } elseif (! $isHomepage && $wasHomepage) {
            // Removing homepage setting
            $settingsManager->set('cms.homepage_page_id', null);
            Cache::forget('settings:cms');

            Notification::make()
                ->title('Strona główna usunięta')
                ->body("Strona \"{$this->record->title}\" nie jest już stroną główną.")
                ->warning()
                ->send();
        }
    }
}
