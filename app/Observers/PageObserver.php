<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Page;
use App\Support\Settings\SettingsManager;

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

    // NOTE: an `updated()` hook that called Cache::forget('home.page') used to live here.
    // Nothing in the codebase ever writes a cache entry under that key — homepage rendering
    // is not cached anywhere (grepped 2026-08-30) — so it was a permanent no-op that looked
    // like working cache invalidation. Removed rather than kept as a misleading stub; Page's
    // own navigation-cache invalidation (see Page::booted()) covers the only real cache this
    // model's saves need to clear.
}
