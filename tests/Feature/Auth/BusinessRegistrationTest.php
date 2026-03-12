<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_step1_page_loads(): void
    {
        $response = $this->get(route('register'));
        $response->assertOk();
        $response->assertViewIs('auth.register-business-step1');
    }

    public function test_step1_validates_required_fields(): void
    {
        $response = $this->post(route('register.step1.store'), []);

        $response->assertSessionHasErrors(['org_name', 'slug', 'booking_type']);
    }

    public function test_step1_stores_session_and_redirects(): void
    {
        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Test Salon',
            'slug' => 'test-salon',
            'booking_type' => 'time_slot',
        ]);

        $response->assertRedirect(route('register.step2'));
        $response->assertSessionHas('business_register.step1');
    }

    public function test_step1_rejects_reserved_slugs(): void
    {
        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Admin',
            'slug' => 'admin',
            'booking_type' => 'time_slot',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_step1_rejects_taken_slugs(): void
    {
        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Existing',
            'slug' => 'existing',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Another',
            'slug' => 'existing',
            'booking_type' => 'time_slot',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_step2_redirects_without_step1_data(): void
    {
        $response = $this->get(route('register.step2'));
        $response->assertRedirect(route('register'));
    }

    public function test_step2_shows_form_with_step1_data(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Bella Studio',
                'slug' => 'bella-studio',
                'booking_type' => 'time_slot',
            ],
        ])->get(route('register.step2'));

        $response->assertOk();
        $response->assertSee('Bella Studio');
        $response->assertSee('bella-studio.registro.app');
    }

    public function test_step2_validates_required_fields(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Test',
                'slug' => 'test-org',
                'booking_type' => 'time_slot',
            ],
        ])->post(route('register.step2.store'), []);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password', 'terms']);
    }

    public function test_step2_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Test',
                'slug' => 'test-org',
                'booking_type' => 'time_slot',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Test',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_full_flow_creates_org_and_user(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Nowy Salon',
                'slug' => 'nowy-salon',
                'booking_type' => 'time_slot',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'anna@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('register.welcome'));

        // User created
        $user = User::where('email', 'anna@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Anna', $user->first_name);
        $this->assertTrue($user->hasRole('admin'));

        // Organization created
        $org = Organization::where('slug', 'nowy-salon')->first();
        $this->assertNotNull($org);
        $this->assertEquals('Nowy Salon', $org->name);
        $this->assertEquals('time_slot', $org->booking_type);
        $this->assertTrue($org->is_active);
        $this->assertNotNull($org->trial_ends_at);
        $this->assertEquals($user->id, $org->owner_id);

        // Pivot exists
        $this->assertTrue($user->organizations()->where('organization_id', $org->id)->exists());
        $this->assertEquals('owner', $user->organizations()->first()->pivot->role);

        // User is logged in
        $this->assertAuthenticatedAs($user);
    }

    public function test_welcome_page_shows_org_info(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Welcome Org',
            'slug' => 'welcome-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['business_register.organization_id' => $org->id])
            ->get(route('register.welcome'));

        $response->assertOk();
        $response->assertSee('Welcome Org');
        $response->assertSee('welcome-org');
    }

    public function test_check_slug_returns_availability(): void
    {
        $response = $this->getJson(route('register.check-slug', ['slug' => 'fresh-slug']));
        $response->assertOk();
        $response->assertJson(['available' => true]);

        // Reserved slug
        $response = $this->getJson(route('register.check-slug', ['slug' => 'admin']));
        $response->assertOk();
        $response->assertJson(['available' => false]);
    }

    public function test_generate_slug_returns_slug(): void
    {
        $response = $this->getJson(route('register.generate-slug', ['name' => 'My Business']));
        $response->assertOk();
        $response->assertJsonStructure(['slug']);
    }

    public function test_get_started_redirects_to_register(): void
    {
        $response = $this->get('/get-started');
        $response->assertRedirect('/register');
        $response->assertStatus(301);
    }

    public function test_get_started_step2_redirects_to_register_step2(): void
    {
        $response = $this->get('/get-started/step/2');
        $response->assertRedirect('/register/step/2');
        $response->assertStatus(301);
    }
}
