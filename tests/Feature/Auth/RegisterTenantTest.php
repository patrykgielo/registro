<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\RegisterController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RegisterTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_method_attaches_user_to_tenant_from_request(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Demo Salon',
            'slug' => 'demo',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $user->assignRole('customer');

        // Simulate a request with tenant resolved by middleware
        $request = Request::create('/register', 'POST');
        $request->attributes->set('tenant', $org);

        // Call the registered() hook directly
        $controller = new RegisterController;
        $controller->callAction('registered', [$request, $user]);

        // User should be attached to the tenant org with customer role
        $this->assertTrue($user->organizations()->where('organization_id', $org->id)->exists());
        $this->assertEquals('customer', $user->organizations()->first()->pivot->role);
    }

    public function test_registered_method_without_tenant_does_not_attach(): void
    {
        $user = User::factory()->create();

        // Request without tenant attribute (root domain)
        $request = Request::create('/register', 'POST');

        $controller = new RegisterController;
        $controller->callAction('registered', [$request, $user]);

        $this->assertEquals(0, $user->organizations()->count());
    }

    public function test_registration_on_root_domain_creates_user(): void
    {
        config(['app.domain' => 'registro.local']);

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/register', [
                'first_name' => 'Root',
                'last_name' => 'User',
                'email' => 'root@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertRedirect();

        $user = User::where('email', 'root@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(0, $user->organizations()->count());
    }
}
