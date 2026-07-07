<?php

namespace Tests\Unit\Models;

use App\Enums\ServiceType;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Support\Services\ServiceQueryParams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for Service::scopeFilterBy() — the WP_Query-style filtering scope
 * introduced by the query-optimization PR. No production caller consumes it
 * yet (see app/docs/features/query-optimization.md), but it's a public API
 * surface and must be correct before it gets a real caller.
 */
class ServiceScopeFilterByTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_type(): void
    {
        Service::factory()->create(['service_type' => ServiceType::TimeSlot]);
        Service::factory()->itemRental()->create();
        Service::factory()->itemRental()->create();

        $result = Service::filterBy(new ServiceQueryParams(type: 'item_rental'))->get();

        $this->assertCount(2, $result);
        $this->assertTrue($result->every(fn (Service $s) => $s->service_type === ServiceType::ItemRental));
    }

    public function test_filters_by_category(): void
    {
        $category = RentalCategory::factory()->create(['slug' => 'elektronarzedzia']);
        $otherCategory = RentalCategory::factory()->create(['slug' => 'rusztowania']);

        $matching = Service::factory()->itemRental()->create(['rental_category_id' => $category->id]);
        Service::factory()->itemRental()->create(['rental_category_id' => $otherCategory->id]);

        $result = Service::filterBy(new ServiceQueryParams(category: 'elektronarzedzia'))->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $matching->id));
    }

    public function test_filters_by_featured(): void
    {
        $featured = Service::factory()->create(['is_popular' => true]);
        Service::factory()->create(['is_popular' => false]);

        $result = Service::filterBy(new ServiceQueryParams(featured: true))->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $featured->id));
    }

    public function test_filters_by_exclude(): void
    {
        $keep = Service::factory()->create();
        $excluded = Service::factory()->create();

        $result = Service::filterBy(new ServiceQueryParams(exclude: [$excluded->id]))->get();

        $this->assertTrue($result->contains('id', $keep->id));
        $this->assertFalse($result->contains('id', $excluded->id));
    }

    public function test_excludes_inactive_services(): void
    {
        Service::factory()->create(['is_active' => false]);
        $active = Service::factory()->create(['is_active' => true]);

        $result = Service::filterBy(new ServiceQueryParams)->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $active->id));
    }

    public function test_order_by_price_asc(): void
    {
        $expensive = Service::factory()->itemRental()->create(['price_per_day' => 300]);
        $cheap = Service::factory()->itemRental()->create(['price_per_day' => 50]);
        $mid = Service::factory()->itemRental()->create(['price_per_day' => 120]);

        $result = Service::filterBy(new ServiceQueryParams(orderBy: 'price_asc'))->get();

        $this->assertSame([$cheap->id, $mid->id, $expensive->id], $result->pluck('id')->all());
    }

    public function test_order_by_price_desc(): void
    {
        $expensive = Service::factory()->itemRental()->create(['price_per_day' => 300]);
        $cheap = Service::factory()->itemRental()->create(['price_per_day' => 50]);
        $mid = Service::factory()->itemRental()->create(['price_per_day' => 120]);

        $result = Service::filterBy(new ServiceQueryParams(orderBy: 'price_desc'))->get();

        $this->assertSame([$expensive->id, $mid->id, $cheap->id], $result->pluck('id')->all());
    }

    public function test_order_by_newest(): void
    {
        $older = Service::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = Service::factory()->create(['created_at' => now()]);

        $result = Service::filterBy(new ServiceQueryParams(orderBy: 'newest'))->get();

        $this->assertSame([$newer->id, $older->id], $result->pluck('id')->all());
    }

    public function test_order_by_sort_order_default(): void
    {
        $second = Service::factory()->create(['sort_order' => 20]);
        $first = Service::factory()->create(['sort_order' => 10]);

        $result = Service::filterBy(new ServiceQueryParams)->get();

        $this->assertSame([$first->id, $second->id], $result->pluck('id')->all());
    }

    public function test_applies_limit(): void
    {
        Service::factory()->count(5)->create();

        $result = Service::filterBy(new ServiceQueryParams(limit: 2))->get();

        $this->assertCount(2, $result);
    }
}
