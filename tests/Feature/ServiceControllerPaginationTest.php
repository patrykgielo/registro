<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * ServiceController::index() switched from ->get() to ->paginate(24) as part
 * of the query-optimization PR — covers both the controller (paginator
 * instance, correct per-page count) and the view (pagination links rendered
 * only when there are more than 24 active services).
 */
class ServiceControllerPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        // Illuminate\Pagination\Paginator::$defaultView is a class-level static
        // — in a full suite run, any earlier test that renders a Livewire
        // component using the WithPagination trait registers Livewire's own
        // pagination view (wire:click buttons, no ?page= query string) for the
        // rest of the PHP process, order-dependently breaking assertions tied
        // to plain-href markup here. Force Laravel's own default back before
        // each test so this test is deterministic regardless of run order.
        Paginator::useTailwind();

        $this->org = Organization::factory()->create();
        $this->app['request']->attributes->set('tenant', $this->org);
    }

    protected function tearDown(): void
    {
        // Paginator::$defaultView is a class-level static. Framework's own
        // default (Illuminate\Pagination\AbstractPaginator::$defaultView) is
        // already 'pagination::tailwind' — useTailwind() is the correct
        // "reset to real default" call, not a project-specific choice. Re-run
        // it here so this test doesn't leave a Livewire-registered view (or
        // any other override) active for whatever test happens to run next
        // in the same PHP process.
        Paginator::useTailwind();

        parent::tearDown();
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the project.
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
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

    public function test_index_paginates_to_24_per_page_and_renders_links_when_more_than_24_services(): void
    {
        Service::factory()->count(30)->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAsTenant($this->org)->get(route('services.index'));

        $response->assertOk();

        /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $services */
        $services = $response->viewData('services');

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $services);
        $this->assertCount(24, $services->items());
        $this->assertSame(30, $services->total());
        $this->assertTrue($services->hasPages());

        $response->assertSee('page=2', false);
    }

    public function test_index_does_not_render_pagination_links_when_24_or_fewer_services(): void
    {
        Service::factory()->count(5)->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->actingAsTenant($this->org)->get(route('services.index'));

        $response->assertOk();

        /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $services */
        $services = $response->viewData('services');

        $this->assertFalse($services->hasPages());
        $response->assertDontSee('page=2', false);
    }
}
