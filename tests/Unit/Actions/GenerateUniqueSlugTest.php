<?php

namespace Tests\Unit\Actions;

use App\Actions\Onboarding\GenerateUniqueSlug;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateUniqueSlugTest extends TestCase
{
    use RefreshDatabase;

    private GenerateUniqueSlug $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new GenerateUniqueSlug;
    }

    public function test_generates_slug_from_name(): void
    {
        $slug = $this->generator->execute('My Salon');
        $this->assertEquals('my-salon', $slug);
    }

    public function test_handles_polish_characters(): void
    {
        $slug = $this->generator->execute('Łódź Klinika');
        $this->assertStringNotContainsString('ł', $slug);
        $this->assertStringNotContainsString('ó', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function test_avoids_reserved_slugs(): void
    {
        $slug = $this->generator->execute('Admin');
        $this->assertNotEquals('admin', $slug);
    }

    public function test_avoids_taken_slugs(): void
    {
        $owner = User::factory()->create();
        Organization::create([
            'name' => 'Taken',
            'slug' => 'taken',
            'booking_type' => 'time_slot',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $slug = $this->generator->execute('Taken');
        $this->assertNotEquals('taken', $slug);
        $this->assertStringStartsWith('taken', $slug);
    }

    public function test_truncates_long_names(): void
    {
        $slug = $this->generator->execute(str_repeat('Long Name ', 20));
        $this->assertLessThanOrEqual(53, strlen($slug)); // 50 + possible suffix
    }

    public function test_handles_short_names(): void
    {
        $slug = $this->generator->execute('AB');
        $this->assertGreaterThanOrEqual(3, strlen($slug));
    }
}
