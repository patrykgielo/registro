<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalPricingTest extends TestCase
{
    use RefreshDatabase;

    private RentalAvailabilityService $availabilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availabilityService = app(RentalAvailabilityService::class);
    }

    private function makeService(array $overrides = []): Service
    {
        return Service::factory()->itemRental()->make(array_merge([
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'price_per_day_long' => null,
            'price_threshold_days' => null,
            'quantity_total' => 5,
        ], $overrides));
    }

    // ─────────────────────────────────────────────
    // Daily rate
    // ─────────────────────────────────────────────

    public function test_daily_pricing_for_single_day(): void
    {
        $service = $this->makeService(['price_per_day' => 100.00]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 1, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(100.0, (float) $result['unit_price']);
        $this->assertSame(100.0, (float) $result['total']);
    }

    public function test_daily_pricing_for_multiple_days(): void
    {
        $service = $this->makeService(['price_per_day' => 150.00]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 5, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(750.0, (float) $result['total']); // 5 × 150
    }

    // ─────────────────────────────────────────────
    // Quantity multiplier
    // ─────────────────────────────────────────────

    public function test_quantity_multiplies_total_price(): void
    {
        $service = $this->makeService(['price_per_day' => 100.00]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 3, quantity: 2);

        // 3 days × 100 × 2 items = 600
        $this->assertSame(600.0, (float) $result['total']);
    }

    public function test_single_day_multiple_items(): void
    {
        $service = $this->makeService(['price_per_day' => 80.00]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 1, quantity: 5);

        $this->assertSame(400.0, (float) $result['total']); // 80 × 5
    }

    // ─────────────────────────────────────────────
    // Weekly rate
    // ─────────────────────────────────────────────

    public function test_weekly_rate_used_when_cheaper_than_daily(): void
    {
        // price_per_day=100, price_per_week=500 (71.4/day — cheaper)
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 500.00,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 7, quantity: 1);

        $this->assertSame('weekly', $result['unit']);
        $this->assertSame(500.0, (float) $result['total']); // exactly 1 week
    }

    public function test_weekly_rate_with_remaining_days(): void
    {
        // 10 days = 1 week (500) + 3 days × 100 = 800
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 500.00,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 10, quantity: 1);

        $this->assertSame('weekly', $result['unit']);
        $this->assertSame(800.0, (float) $result['total']);
    }

    public function test_weekly_rate_not_used_when_more_expensive_than_daily(): void
    {
        // price_per_day=100, price_per_week=800 (114.3/day — more expensive)
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 800.00,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 7, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(700.0, (float) $result['total']); // 7 × 100
    }

    public function test_weekly_rate_not_applied_below_7_days(): void
    {
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 500.00,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 6, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(600.0, (float) $result['total']); // 6 × 100
    }

    public function test_weekly_rate_with_multiple_weeks_and_quantity(): void
    {
        // 2 weeks = 2 × 500 = 1000, × 2 items = 2000
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 500.00,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 14, quantity: 2);

        $this->assertSame('weekly', $result['unit']);
        $this->assertSame(2000.0, (float) $result['total']);
    }

    // ─────────────────────────────────────────────
    // Long-term (tiered) rate
    // ─────────────────────────────────────────────

    public function test_long_term_rate_applied_at_threshold(): void
    {
        // After 7 days, use 60/day instead of 100/day
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'price_per_day_long' => 60.00,
            'price_threshold_days' => 7,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 10, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(60.0, (float) $result['unit_price']);
        $this->assertSame(600.0, (float) $result['total']); // 10 × 60
    }

    public function test_long_term_rate_not_applied_below_threshold(): void
    {
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'price_per_day_long' => 60.00,
            'price_threshold_days' => 7,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 5, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(100.0, (float) $result['unit_price']);
        $this->assertSame(500.0, (float) $result['total']);
    }

    public function test_long_term_rate_at_exact_threshold(): void
    {
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => null,
            'price_per_day_long' => 70.00,
            'price_threshold_days' => 7,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 7, quantity: 1);

        // Exactly at threshold — long-term rate applies
        $this->assertSame(70.0, (float) $result['unit_price']);
        $this->assertSame(490.0, (float) $result['total']);
    }

    // ─────────────────────────────────────────────
    // Rounding
    // ─────────────────────────────────────────────

    public function test_total_price_rounded_to_two_decimal_places(): void
    {
        $service = $this->makeService(['price_per_day' => 33.33]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 3, quantity: 1);

        // 33.33 × 3 = 99.99
        $this->assertSame(99.99, (float) $result['total']);
    }

    public function test_weekly_total_rounded_to_two_decimal_places(): void
    {
        // 10 days = 1 week (499.99) + 3 × 100 = 799.99
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => 499.99,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 10, quantity: 1);

        $this->assertSame(799.99, (float) $result['total']);
    }

    // ─────────────────────────────────────────────
    // No weekly rate configured
    // ─────────────────────────────────────────────

    public function test_falls_back_to_daily_when_no_weekly_rate_configured(): void
    {
        $service = $this->makeService([
            'price_per_day' => 100.00,
            'price_per_week' => null,
        ]);

        $result = $this->availabilityService->calculatePricing($service, durationDays: 14, quantity: 1);

        $this->assertSame('daily', $result['unit']);
        $this->assertSame(1400.0, (float) $result['total']);
    }
}
