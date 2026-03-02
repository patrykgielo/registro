<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Page;
use App\Support\Settings\SettingsManager;
use Illuminate\Support\Facades\Cache;

class PageObserver
{
    /**
     * Handle the Page "deleting" event.
     *
     * Prevent deletion of homepage page.
     */
    public function deleting(Page $page): bool
    {
        $settingsManager = app(SettingsManager::class);
        $homepageId = $settingsManager->get('cms.homepage_page_id');

        if ($homepageId && $homepageId == $page->id) {
            throw new \Exception(
                "Cannot delete page \"{$page->title}\" because it is set as homepage. ".
                'Please select a different homepage in Settings → CMS first.'
            );
        }

        return true;
    }

    /**
     * Handle the Page "updated" event.
     *
     * Clear homepage cache when homepage page is updated.
     */
    public function updated(Page $page): void
    {
        $settingsManager = app(SettingsManager::class);
        $homepageId = $settingsManager->get('cms.homepage_page_id');

        if ($homepageId && $homepageId == $page->id) {
            Cache::forget('home.page');
        }
    }
}
