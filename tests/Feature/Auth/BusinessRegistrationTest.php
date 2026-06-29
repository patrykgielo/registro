<?php

namespace Tests\Feature\Auth;

use App\Enums\Industry;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
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

        $response->assertSessionHasErrors(['org_name', 'slug', 'industry']);
    }

    public function test_step1_stores_session_and_redirects(): void
    {
        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Test Salon',
            'slug' => 'test-salon',
            'industry' => 'auto_detailing',
        ]);

        $response->assertRedirect(route('register.step2'));
        $response->assertSessionHas('business_register.step1');
    }

    public function test_step1_rejects_invalid_industry(): void
    {
        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Test',
            'slug' => 'test-org',
            'industry' => 'invalid_industry',
        ]);

        $response->assertSessionHasErrors('industry');
    }

    public function test_step1_rejects_reserved_slugs(): void
    {
        $response = $this->post(route('register.step1.store'), [
            'org_name' => 'Admin',
            'slug' => 'admin',
            'industry' => 'general_services',
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
            'industry' => 'general_services',
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
                'industry' => 'auto_detailing',
            ],
        ])->get(route('register.step2'));

        $response->assertOk();
        $response->assertSee('Bella Studio');
        $response->assertSee('bella-studio.registro.app');
        $response->assertSee('Auto detailing');
    }

    public function test_step2_validates_required_fields(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Test',
                'slug' => 'test-org',
                'industry' => 'general_services',
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
                'industry' => 'general_services',
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

    public function test_full_flow_creates_org_with_industry(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Nowy Salon',
                'slug' => 'nowy-salon',
                'industry' => 'auto_detailing',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Anna',
            'last_name' => 'Nowak',
            'email' => 'anna@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('register.step3'));

        // User created
        $user = User::where('email', 'anna@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Anna', $user->first_name);
        $this->assertTrue($user->hasRole('admin'));

        // Organization created with industry
        $org = Organization::where('slug', 'nowy-salon')->first();
        $this->assertNotNull($org);
        $this->assertEquals('Nowy Salon', $org->name);
        $this->assertEquals('time_slot', $org->booking_type);
        $this->assertEquals(Industry::AutoDetailing, $org->industry);
        $this->assertTrue($org->is_active);
        $this->assertNotNull($org->trial_ends_at);
        $this->assertEquals($user->id, $org->owner_id);

        // Pivot exists
        $this->assertTrue($user->organizations()->where('organization_id', $org->id)->exists());
        $this->assertEquals('owner', $user->organizations()->first()->pivot->role);

        // User is logged in
        $this->assertAuthenticatedAs($user);
    }

    public function test_equipment_rental_sets_item_rental_booking_type(): void
    {
        $response = $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Wypozyczalnia',
                'slug' => 'wypozyczalnia',
                'industry' => 'equipment_rental',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $org = Organization::where('slug', 'wypozyczalnia')->first();
        $this->assertEquals('item_rental', $org->booking_type);
        $this->assertEquals(Industry::EquipmentRental, $org->industry);
    }

    public function test_equipment_rental_registration_has_empty_catalog(): void
    {
        $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Narzedzia',
                'slug' => 'narzedzia',
                'industry' => 'equipment_rental',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan-rental@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $org = Organization::where('slug', 'narzedzia')->first();

        $this->assertEquals(
            0,
            RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_auto_detailing_registration_has_empty_catalog_and_features_set(): void
    {
        $this->withSession([
            'business_register.step1' => [
                'org_name' => 'Detailing',
                'slug' => 'detailing',
                'industry' => 'auto_detailing',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan-detail@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $org = Organization::where('slug', 'detailing')->first();

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );

        // Industry feature flags ARE set during registration
        $this->assertTrue($org->hasFeature('vehicles'));
        $this->assertTrue($org->hasFeature('mobile_service'));
    }

    public function test_general_services_registration_has_empty_catalog(): void
    {
        $this->withSession([
            'business_register.step1' => [
                'org_name' => 'General',
                'slug' => 'general',
                'industry' => 'general_services',
            ],
        ])->post(route('register.step2.store'), [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'email' => 'jan-gen@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $org = Organization::where('slug', 'general')->first();

        $this->assertEquals(
            0,
            Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count()
        );
    }

    public function test_step3_page_loads(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Step3 Org',
            'slug' => 'step3-org',
            'booking_type' => 'time_slot',
            'industry' => 'auto_detailing',
            'owner_id' => $owner->id,
            'is_active' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['business_register.organization_id' => $org->id])
            ->get(route('register.step3'));

        $response->assertOk();
        $response->assertViewIs('onboarding.step3');
    }

    public function test_step3_saves_personalization(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Personalize Org',
            'slug' => 'personalize-org',
            'booking_type' => 'time_slot',
            'industry' => 'auto_detailing',
            'owner_id' => $owner->id,
            'is_active' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['business_register.organization_id' => $org->id])
            ->post(route('register.step3.store'), [
                'city' => 'Warszawa',
                'address' => 'ul. Główna 10',
                'mobile_service' => '1',
                'service_radius_km' => 25,
            ]);

        $response->assertRedirect(route('register.welcome'));

        $org->refresh();
        $this->assertEquals('Warszawa', data_get($org->settings, 'location.city'));
        $this->assertEquals('ul. Główna 10', data_get($org->settings, 'location.address'));
        $this->assertTrue(data_get($org->settings, 'features.mobile_service'));
        $this->assertEquals(25, data_get($org->settings, 'location.service_radius_km'));
    }

    public function test_step3_skip_redirects_to_welcome(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Skip Org',
            'slug' => 'skip-org',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['business_register.organization_id' => $org->id])
            ->get(route('register.welcome'));

        $response->assertOk();
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

    public function test_existing_org_without_industry_works(): void
    {
        $owner = User::factory()->create();
        $org = Organization::create([
            'name' => 'Legacy Org',
            'slug' => 'legacy-org',
            'booking_type' => 'time_slot',
            'industry' => null,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        // hasFeature should still work without industry
        $this->assertFalse($org->hasFeature('vehicles'));
        $this->assertFalse($org->hasFeature('mobile_service'));

        // term() should return defaults
        $this->assertEquals('usługa', $org->term('service'));
        $this->assertEquals('rezerwacja', $org->term('booking'));
    }
}
