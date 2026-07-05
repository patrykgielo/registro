<?php

namespace Tests\Unit\Models;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_can_be_created_with_required_fields(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();

        $cart = Cart::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_scope_active_returns_only_active_carts(): void
    {
        Cart::factory()->active()->create();
        Cart::factory()->abandoned()->create();
        Cart::factory()->converted()->create();

        $activeCarts = Cart::active()->get();

        $this->assertCount(1, $activeCarts);
        $this->assertEquals('active', $activeCarts->first()->status);
    }

    public function test_scope_active_excludes_non_active_statuses(): void
    {
        Cart::factory()->abandoned()->create();
        Cart::factory()->converted()->create();

        $this->assertCount(0, Cart::active()->get());
    }

    public function test_scope_for_user_returns_only_that_users_carts(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $org = Organization::factory()->create();

        // Two carts for the same user+org must differ in status — the
        // `carts_org_user_active_unique` constraint allows only one *active*
        // cart per (organization_id, user_id).
        Cart::factory()->create(['user_id' => $userA->id, 'organization_id' => $org->id]);
        Cart::factory()->abandoned()->create(['user_id' => $userA->id, 'organization_id' => $org->id]);
        Cart::factory()->create(['user_id' => $userB->id, 'organization_id' => $org->id]);

        $results = Cart::forUser($userA)->get();

        $this->assertCount(2, $results);
        $results->each(fn ($cart) => $this->assertEquals($userA->id, $cart->user_id));
    }

    public function test_scope_for_user_returns_empty_when_user_has_no_carts(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, Cart::forUser($user)->get());
    }

    public function test_cart_has_items_relation(): void
    {
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id]);
        CartItem::factory()->create(['cart_id' => $cart->id]);

        $this->assertCount(2, $cart->items);
        $this->assertInstanceOf(CartItem::class, $cart->items->first());
    }

    public function test_cart_items_relation_returns_empty_collection_when_no_items(): void
    {
        $cart = Cart::factory()->create();

        $this->assertCount(0, $cart->items);
    }

    public function test_cart_belongs_to_organization(): void
    {
        $org = Organization::factory()->create();
        $cart = Cart::factory()->create(['organization_id' => $org->id]);

        $this->assertInstanceOf(Organization::class, $cart->organization);
        $this->assertEquals($org->id, $cart->organization->id);
    }

    public function test_cart_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $cart->user);
        $this->assertEquals($user->id, $cart->user->id);
    }
}
