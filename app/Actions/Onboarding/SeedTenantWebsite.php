<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\MenuLocation;
use App\Enums\PageLayout;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds a universal, tenant-agnostic public website (homepage + minimal menu) for a
 * freshly-provisioned organization. Everything user-facing (company name, industry,
 * products) is read from the Organization/Service tables at run time — nothing is
 * hardcoded to a specific tenant or vertical.
 *
 * Called from `onboarding:seed-website`. Deliberately NOT folded into
 * SeedEquipmentRental/vertical seeders — the website is a presentation-layer concern
 * shared across every industry, while vertical seeders own the product catalogue only
 * (see onboarding.md).
 */
class SeedTenantWebsite
{
    private const HOMEPAGE_SLUG = 'strona-glowna';

    private const ABOUT_SLUG = 'o-nas';

    private const RENTAL_MENU_SLUG = 'wypozyczalnia';

    /**
     * Whether the organization already has any CMS pages — the guard the console
     * command uses to require --force before overwriting anything.
     */
    public function hasExistingPages(Organization $org): bool
    {
        return Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->exists();
    }

    public function existingPageCount(Organization $org): int
    {
        return Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->count();
    }

    /**
     * Removes every CMS page owned by the organization, plus the homepage setting.
     *
     * Order matters: PageObserver::deleting() throws if the page being deleted is
     * currently set as the homepage, so the setting MUST be cleared first.
     */
    public function purge(Organization $org): void
    {
        $this->clearHomepageSetting($org);

        Page::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get()
            ->each(fn (Page $page) => $page->delete());
    }

    /**
     * Creates the homepage + a minimal working menu, and points
     * `cms.homepage_page_id` at the new homepage. Returns the homepage Page.
     */
    public function seed(Organization $org): Page
    {
        $homepage = $this->createHomepage($org);
        $this->createAboutPage($org);

        if ($org->supportsRentals()) {
            $this->createRentalMenuPage($org);
        }

        $this->setHomepageSetting($org, $homepage);

        return $homepage;
    }

    private function createHomepage(Organization $org): Page
    {
        return Page::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'title' => $org->name,
            'slug' => self::HOMEPAGE_SLUG,
            'body' => null,
            'content' => $this->homepageBlocks($org),
            'layout' => PageLayout::FULL_WIDTH,
            'published_at' => now()->subMinute(),
            'show_in_menu' => false,
        ]);
    }

    private function createAboutPage(Organization $org): void
    {
        Page::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'title' => 'O nas',
            'slug' => self::ABOUT_SLUG,
            'body' => null,
            'content' => [$this->aboutTextBlock($org)],
            'layout' => PageLayout::FULL_WIDTH,
            'published_at' => now()->subMinute(),
            'show_in_menu' => true,
            'menu_order' => 20,
            'menu_label' => 'O nas',
            'menu_location' => MenuLocation::HEADER,
        ]);
    }

    /**
     * A menu-only stand-in page. Its `content`/`body` are never rendered: the public
     * route `/wypozyczalnia` is registered ahead of the CMS catch-all `page.show`
     * route, so a click on this menu item always resolves to RentalController, not
     * this page. It exists purely so NavigationService has something to point at
     * `/wypozyczalnia` — the slug itself is NOT in Page::RESERVED_SLUGS.
     */
    private function createRentalMenuPage(Organization $org): void
    {
        Page::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'title' => 'Wypożyczalnia',
            'slug' => self::RENTAL_MENU_SLUG,
            'body' => null,
            'content' => [],
            'layout' => PageLayout::DEFAULT,
            'published_at' => now()->subMinute(),
            'show_in_menu' => true,
            'menu_order' => 10,
            'menu_label' => 'Wypożyczalnia',
            'menu_location' => MenuLocation::HEADER,
        ]);
    }

    /**
     * WARNING: writes directly to the Setting table (organization-scoped), bypassing
     * SettingsManager::set(). SettingsManager::set() targets
     * TenantFeature::currentTenant() ?? null — in a console command that is always
     * null, which would silently write a GLOBAL row shared by every tenant instead of
     * this one. See SettingsManager::set()'s own docblock and
     * SeedOrganizationDefaults::seedSettings() for the same pattern.
     */
    private function setHomepageSetting(Organization $org, Page $page): void
    {
        Setting::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $org->id, 'group' => 'cms', 'key' => 'homepage_page_id'],
            ['value' => [$page->id]]
        );

        $this->forgetHomepageSettingCache($org);
    }

    private function clearHomepageSetting(Organization $org): void
    {
        Setting::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('group', 'cms')
            ->where('key', 'homepage_page_id')
            ->delete();

        $this->forgetHomepageSettingCache($org);
    }

    /**
     * Mirrors SettingsManager's private cache key format — it has no public cache
     * invalidation method for a single tenant+key, so we replicate the two keys it
     * would clear (see SettingsManager::getCacheKey()/clearCache()).
     */
    private function forgetHomepageSettingCache(Organization $org): void
    {
        Cache::forget("settings:tenant:{$org->id}:cms:homepage_page_id");
        Cache::forget("settings:tenant:{$org->id}:cms");
    }

    /**
     * @return array<int, int>
     */
    private function activeServiceIds(Organization $org): array
    {
        return Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();
    }

    /**
     * The single CTA target used across the homepage's hero and CTA banner —
     * whichever catalogue the tenant actually has (rentals take priority over
     * appointments for a 'both' tenant; the industry-neutral fallback is the About
     * page, which every tenant always has).
     *
     * @return array{text: string, url: string, style: string}
     */
    private function primaryCtaButton(Organization $org): array
    {
        return match (true) {
            $org->supportsRentals() => ['text' => 'Przeglądaj katalog', 'url' => '/'.self::RENTAL_MENU_SLUG, 'style' => 'primary'],
            $org->supportsAppointments() => ['text' => 'Zobacz usługi', 'url' => '/uslugi', 'style' => 'primary'],
            default => ['text' => 'Dowiedz się więcej', 'url' => '/'.self::ABOUT_SLUG, 'style' => 'primary'],
        };
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function homepageBlocks(Organization $org): array
    {
        $blocks = [
            $this->heroBlock($org),
            $this->aboutTextBlock($org),
        ];

        $serviceIds = $this->activeServiceIds($org);

        // Deliberately omitted (not left with empty content_items) when the tenant has
        // no active products yet — an empty content_grid renders a visible "Brak
        // elementów" warning box to every public visitor.
        if ($serviceIds !== []) {
            $blocks[] = $this->contentGridBlock($serviceIds);
        }

        $blocks[] = $this->featureListBlock();
        $blocks[] = $this->ctaBannerBlock($org);

        return $blocks;
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function heroBlock(Organization $org): array
    {
        return [
            'type' => 'hero',
            'data' => [
                'background_type' => 'gradient',
                'background_image' => null,
                'background_color' => null,
                'title' => $org->name,
                'subtitle' => 'Sprawdź naszą ofertę i skontaktuj się z nami już dziś.',
                'cta_buttons' => [$this->primaryCtaButton($org)],
                'overlay_opacity' => 40,
                'full_width' => true,
                'container_max_width' => '7xl',
                'vertical_padding' => 'lg',
                'css_id' => null,
                'css_classes' => '',
            ],
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function aboutTextBlock(Organization $org): array
    {
        $name = e($org->name);

        return [
            'type' => 'text_block',
            'data' => [
                'content' => "<p><strong>{$name}</strong> zaprasza do zapoznania się z naszą ofertą. Dbamy o jakość obsługi i wygodę każdego klienta.</p>",
                'background_type' => 'none',
                'background_color' => null,
                'gradient_from' => null,
                'gradient_to' => null,
                'gradient_direction' => null,
                'background_image' => null,
                'background_overlay' => false,
                'overlay_color' => null,
                'overlay_opacity' => '50',
                'full_width' => false,
                'container_max_width' => 'lg',
                'vertical_padding' => 'md',
                'css_id' => null,
                'css_classes' => '',
            ],
        ];
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return array{type: string, data: array<string, mixed>}
     */
    private function contentGridBlock(array $serviceIds): array
    {
        return [
            'type' => 'content_grid',
            'data' => [
                'content_type' => 'services',
                'content_items' => $serviceIds,
                'service_card_variant' => 'auto',
                'columns' => '3',
                'heading' => 'Nasza oferta',
                'subheading' => '',
                'background_type' => 'none',
                'background_color' => null,
                'gradient_from' => null,
                'gradient_to' => null,
                'gradient_direction' => null,
                'background_image' => null,
                'background_overlay' => false,
                'overlay_color' => null,
                'overlay_opacity' => '50',
                'full_width' => false,
                'container_max_width' => '7xl',
                'vertical_padding' => 'md',
                'css_id' => null,
                'css_classes' => '',
            ],
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function featureListBlock(): array
    {
        return [
            'type' => 'feature_list',
            'data' => [
                'features' => [
                    ['icon' => 'shield-check', 'title' => 'Sprawdzona jakość', 'description' => 'Dbamy o najwyższą jakość obsługi i oferowanych produktów.'],
                    ['icon' => 'clock', 'title' => 'Szybka realizacja', 'description' => 'Sprawnie realizujemy zamówienia i odpowiadamy na zapytania.'],
                    ['icon' => 'star', 'title' => 'Zadowoleni klienci', 'description' => 'Stawiamy na długofalowe relacje i indywidualne podejście.'],
                ],
                'layout' => 'grid',
                'columns' => '3',
                'image' => null,
                'heading' => 'Dlaczego my',
                'subheading' => '',
                'background_type' => 'none',
                'background_color' => null,
                'gradient_from' => null,
                'gradient_to' => null,
                'gradient_direction' => null,
                'background_image' => null,
                'background_overlay' => false,
                'overlay_color' => null,
                'overlay_opacity' => '50',
                'full_width' => false,
                'container_max_width' => 'xl',
                'vertical_padding' => 'md',
                'css_id' => null,
                'css_classes' => '',
            ],
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function ctaBannerBlock(Organization $org): array
    {
        return [
            'type' => 'cta_banner',
            'data' => [
                'heading' => 'Gotowi na współpracę?',
                'subheading' => 'Skontaktuj się z nami lub sprawdź naszą pełną ofertę.',
                'cta_buttons' => [$this->primaryCtaButton($org)],
                'background_orbs' => true,
                'background_type' => 'gradient',
                'background_color' => null,
                'gradient_from' => '#0891b2',
                'gradient_to' => '#0e7490',
                'gradient_direction' => 'to-r',
                'background_image' => null,
                'background_overlay' => false,
                'overlay_color' => null,
                'overlay_opacity' => '50',
                'cta_container_bg_type' => 'none',
                'cta_container_color' => null,
                'cta_container_gradient_from' => null,
                'cta_container_gradient_to' => null,
                'cta_container_gradient_direction' => null,
                'cta_container_rounded' => '3xl',
                'cta_container_padding' => 'lg',
                'full_width' => false,
                'container_max_width' => '7xl',
                'vertical_padding' => 'lg',
                'css_id' => null,
                'css_classes' => '',
            ],
        ];
    }
}
