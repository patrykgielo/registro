<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Enums\TemplateKey;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Post;
use App\Models\Promotion;
use App\Models\ReminderConfig;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins App\Traits\NormalizesEmptyJsonToNull against its consumers.
 * Filament's Repeater/FileUpload/KeyValue dehydrate an untouched empty field
 * to `[]`, never `null`; RichEditor dehydrates an untouched empty editor to
 * `<p></p>`, never `null`/`''`. Without this trait, a no-op "Zapisz" on a
 * record whose column was genuinely NULL silently rewrites it to `[]` /
 * `<p></p>` — which Auditable-tracked models then log as a real change that
 * never happened, and which `@if($model->body)` guards in the 5 CMS layout
 * partials treat as present, rendering an empty padded content container
 * (PanelWalkthroughTest, 2026-08-30).
 */
class NormalizesEmptyJsonToNullTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_empty_array_on_location_opening_hours_and_gallery_stores_null(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create([
            'opening_hours' => null,
            'gallery' => null,
        ]);

        $location->opening_hours = [];
        $location->gallery = [];
        $location->save();

        $this->assertNull($location->fresh()->getRawOriginal('opening_hours'));
        $this->assertNull($location->fresh()->getRawOriginal('gallery'));
    }

    public function test_saving_non_empty_array_on_location_opening_hours_is_preserved(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $location = Location::factory()->for($org, 'organization')->create(['opening_hours' => null]);

        $location->opening_hours = ['monday' => ['09:00', '17:00']];
        $location->save();

        $this->assertSame(['monday' => ['09:00', '17:00']], $location->fresh()->opening_hours);
    }

    public function test_saving_empty_array_on_reminder_config_settings_stores_null(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $config = ReminderConfig::create([
            'organization_id' => $org->id,
            'name' => 'Test',
            'channel' => 'sms',
            'template_key' => TemplateKey::APPOINTMENT_REMINDER_24H->value,
            'settings' => null,
        ]);

        $config->settings = [];
        $config->save();

        $this->assertNull($config->fresh()->getRawOriginal('settings'));
    }

    // -- CMS models: content / gallery / features (JSON half) -----------------

    public function test_saving_empty_content_on_each_cms_model_stores_null(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $page = Page::create(['organization_id' => $org->id, 'title' => 'Page A', 'slug' => 'page-a', 'content' => null]);
        $post = Post::create(['organization_id' => $org->id, 'title' => 'Post A', 'slug' => 'post-a', 'body' => 'x', 'content' => null]);
        $portfolioItem = PortfolioItem::create(['organization_id' => $org->id, 'title' => 'Portfolio A', 'slug' => 'portfolio-a', 'content' => null, 'gallery' => null]);
        $promotion = Promotion::create(['organization_id' => $org->id, 'title' => 'Promo A', 'slug' => 'promo-a', 'body' => 'x', 'content' => null]);
        $service = Service::factory()->for($org, 'organization')->create(['content' => null, 'features' => null]);

        $page->content = [];
        $page->save();
        $post->content = [];
        $post->save();
        $portfolioItem->content = [];
        $portfolioItem->gallery = [];
        $portfolioItem->save();
        $promotion->content = [];
        $promotion->save();
        $service->content = [];
        $service->features = [];
        $service->save();

        $this->assertNull($page->fresh()->getRawOriginal('content'));
        $this->assertNull($post->fresh()->getRawOriginal('content'));
        $this->assertNull($portfolioItem->fresh()->getRawOriginal('content'));
        $this->assertNull($portfolioItem->fresh()->getRawOriginal('gallery'));
        $this->assertNull($promotion->fresh()->getRawOriginal('content'));
        $this->assertNull($service->fresh()->getRawOriginal('content'));
        $this->assertNull($service->fresh()->getRawOriginal('features'));
    }

    public function test_saving_non_empty_content_and_gallery_is_preserved(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $portfolioItem = PortfolioItem::create([
            'organization_id' => $org->id,
            'title' => 'Portfolio B',
            'slug' => 'portfolio-b',
            'content' => [['type' => 'text', 'data' => ['content' => 'hello']]],
            'gallery' => ['gallery/one.jpg'],
        ]);

        $portfolioItem->save();

        $this->assertSame([['type' => 'text', 'data' => ['content' => 'hello']]], $portfolioItem->fresh()->content);
        $this->assertSame(['gallery/one.jpg'], $portfolioItem->fresh()->gallery);
    }

    // -- CMS models: body (empty-HTML half) ------------------------------------

    public static function emptyHtmlBodyProvider(): array
    {
        return [
            'empty paragraph' => ['<p></p>'],
            'paragraph with a bare line break' => ['<p><br></p>'],
            'paragraph with a non-breaking space' => ['<p>&nbsp;</p>'],
            'pure whitespace' => ["   \n  "],
        ];
    }

    #[DataProvider('emptyHtmlBodyProvider')]
    public function test_saving_empty_html_body_stores_null(string $emptyHtml): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $page = Page::create(['organization_id' => $org->id, 'title' => 'Page C', 'slug' => 'page-c-'.md5($emptyHtml), 'body' => null]);

        $page->body = $emptyHtml;
        $page->save();

        $this->assertNull($page->fresh()->getRawOriginal('body'));
    }

    public function test_saving_empty_html_body_stores_null_on_every_cms_model(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $page = Page::create(['organization_id' => $org->id, 'title' => 'Page D', 'slug' => 'page-d', 'body' => null]);
        $post = Post::create(['organization_id' => $org->id, 'title' => 'Post D', 'slug' => 'post-d', 'body' => 'placeholder']);
        $portfolioItem = PortfolioItem::create(['organization_id' => $org->id, 'title' => 'Portfolio D', 'slug' => 'portfolio-d', 'body' => null]);
        $promotion = Promotion::create(['organization_id' => $org->id, 'title' => 'Promo D', 'slug' => 'promo-d', 'body' => 'placeholder']);
        $service = Service::factory()->for($org, 'organization')->create(['body' => null]);

        foreach ([$page, $post, $portfolioItem, $promotion, $service] as $model) {
            $model->body = '<p></p>';
            $model->save();
            $this->assertNull($model->fresh()->getRawOriginal('body'), $model::class.' should normalize empty body to NULL');
        }
    }

    public function test_saving_non_empty_html_body_is_preserved(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $page = Page::create(['organization_id' => $org->id, 'title' => 'Page E', 'slug' => 'page-e', 'body' => null]);

        $page->body = '<p>Realna treść</p>';
        $page->save();

        $this->assertSame('<p>Realna treść</p>', $page->fresh()->body);
    }

    /**
     * The exact risk called out in the task: a body holding only an image or
     * a video embed, with zero surrounding text, must NOT be treated as
     * empty just because strip_tags(...) would leave no readable text.
     */
    public function test_saving_html_body_with_only_an_image_or_iframe_is_preserved(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $withImage = Page::create(['organization_id' => $org->id, 'title' => 'Page F', 'slug' => 'page-f', 'body' => null]);
        $withIframe = Page::create(['organization_id' => $org->id, 'title' => 'Page G', 'slug' => 'page-g', 'body' => null]);

        $withImage->body = '<p><img src="/storage/pages/photo.jpg" alt=""></p>';
        $withImage->save();

        $withIframe->body = '<iframe src="https://www.youtube.com/embed/xyz"></iframe>';
        $withIframe->save();

        $this->assertNotNull($withImage->fresh()->getRawOriginal('body'));
        $this->assertStringContainsString('photo.jpg', $withImage->fresh()->body);
        $this->assertNotNull($withIframe->fresh()->getRawOriginal('body'));
        $this->assertStringContainsString('youtube.com', $withIframe->fresh()->body);
    }
}
