<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class AddToCartTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->user = User::factory()->create();
    }

    /**
     * Bind a test double for ResolveTenant so that $org is always resolved
     * from request attributes — same pattern used throughout the project.
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    // -------------------------------------------------------------------------
    // Guest protection
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $response = $this->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_authenticated_user_can_add_available_item_to_cart(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $startDate = now()->addDay()->toDateString();
        $endDate = now()->addDays(3)->toDateString();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'quantity' => 1,
            ]);

        $response->assertRedirect();

        // SQLite stores date columns as "YYYY-MM-DD 00:00:00" — match on service_id + quantity only,
        // then verify the dates on the hydrated model to stay DB-agnostic.
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $service->id,
            'quantity' => 1,
        ]);

        $item = \App\Models\CartItem::where('service_id', $service->id)->firstOrFail();
        $this->assertEquals($startDate, $item->start_date->toDateString());
        $this->assertEquals($endDate, $item->end_date->toDateString());
    }

    public function test_cart_item_is_linked_to_correct_user_cart(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'quantity' => 2,
            ]);

        $cart = Cart::where('user_id', $this->user->id)
            ->where('organization_id', $this->org->id)
            ->first();

        $this->assertNotNull($cart);
        $this->assertEquals(1, $cart->items()->count());
    }

    public function test_redirect_back_includes_success_flash(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    // -------------------------------------------------------------------------
    // Quantity exceeds available stock
    // -------------------------------------------------------------------------

    public function test_quantity_exceeding_available_stock_returns_availability_error(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 99, // vastly exceeds stock
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('availability');

        $this->assertDatabaseMissing('cart_items', [
            'service_id' => $service->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    public function test_start_date_in_the_past_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertSessionHasErrors('start_date');

        $this->assertDatabaseMissing('cart_items', [
            'service_id' => $service->id,
        ]);
    }

    public function test_end_date_before_start_date_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(), // before start
                'quantity' => 1,
            ]);

        $response->assertSessionHasErrors('end_date');
    }

    public function test_missing_service_id_fails_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertSessionHasErrors('service_id');
    }

    public function test_quantity_zero_fails_validation(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 0,
            ]);

        $response->assertSessionHasErrors('quantity');
    }

    public function test_nonexistent_service_id_fails_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('cart.add'), [
                'service_id' => 999999,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertSessionHasErrors('service_id');
    }

    // -------------------------------------------------------------------------
    // No tenant context — 404
    // -------------------------------------------------------------------------

    public function test_request_without_tenant_returns_404(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        // No actingAsTenant — request attributes will have no tenant
        $response = $this->actingAs($this->user)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertNotFound();
    }
}
