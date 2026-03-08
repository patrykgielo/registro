<?php

namespace Tests\Feature\Middleware;

use App\Http\Middleware\EnsureSuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private EnsureSuperAdmin $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new EnsureSuperAdmin;
    }

    public function test_super_admin_can_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $request = Request::create('/platform');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn () => response('ok'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_regular_admin_cannot_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $request = Request::create('/platform');
        $request->setUserResolver(fn () => $user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $request = Request::create('/platform');
        $request->setUserResolver(fn () => null);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->middleware->handle($request, fn () => response('ok'));
    }
}
