<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `posts` has UNIQUE(organization_id, slug) — PostResource's slug field used a global
 * `->unique(ignoreRecord: true)`, stricter than the schema. Two tenants each publishing a post
 * with the same title (routine, not seeded) collide, and editing either post afterward — even
 * without touching its slug — is rejected. Same root cause as `LocationSlugUniqueScopeTest`.
 */
class PostResourceSlugUniqueScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenantA;

    private Organization $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->tenantA = Organization::factory()->create();
        $this->tenantB = Organization::factory()->create();

        $this->adminA = User::factory()->create();
        $this->adminA->assignRole('admin');
        $this->adminA->organizations()->attach($this->tenantA->id, ['role' => 'admin']);
    }

    public function test_editing_a_post_without_changing_its_slug_succeeds_even_when_another_tenant_has_the_same_slug(): void
    {
        Post::create(['organization_id' => $this->tenantB->id, 'title' => 'Nowości', 'slug' => 'nowosci', 'body' => 'Treść']);
        $postA = Post::create(['organization_id' => $this->tenantA->id, 'title' => 'Nowości', 'slug' => 'nowosci', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPost::class, ['record' => $postA->getKey()])
            ->fillForm(['excerpt' => 'Zaktualizowany skrót'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zaktualizowany skrót', $postA->fresh()->excerpt);
    }

    public function test_reusing_a_slug_already_taken_by_another_post_of_the_same_tenant_is_still_rejected(): void
    {
        Post::create(['organization_id' => $this->tenantA->id, 'title' => 'Archiwum', 'slug' => 'archiwum', 'body' => 'Treść']);
        $postA = Post::create(['organization_id' => $this->tenantA->id, 'title' => 'Nowości', 'slug' => 'nowosci', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(EditPost::class, ['record' => $postA->getKey()])
            ->fillForm(['slug' => 'archiwum'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    public function test_creating_a_post_with_a_slug_only_taken_by_another_tenant_succeeds(): void
    {
        Post::create(['organization_id' => $this->tenantB->id, 'title' => 'Wolny slug', 'slug' => 'wolny-slug', 'body' => 'Treść']);

        session(['tenant_id' => $this->tenantA->id]);
        $this->actingAs($this->adminA);

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'Nowy wpis',
                'slug' => 'wolny-slug',
                'body' => 'Treść nowego wpisu',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('posts', [
            'organization_id' => $this->tenantA->id,
            'slug' => 'wolny-slug',
        ]);
    }
}
