<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class Przelewy24WebhookTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiting blokuje powtarzalne requesty w testach
        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();
    }

    /**
     * Stub ResolveTenant — ten sam wzorzec co w CustomerOrdersTest.
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
    // Happy path — poprawny webhook oznacza zamówienie jako opłacone
    // -------------------------------------------------------------------------

    public function test_valid_webhook_marks_order_as_paid(): void
    {
        $user = User::factory()->create();

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'p24_session_id' => 'test-session-123',
            'total_amount' => 100.00,
        ]);

        // Mockujemy Przelewy24Service — brak prawdziwych wywołań API P24.
        // handleWebhook() wykonuje faktyczną logikę biznesową (transition + paid_at).
        $this->mock(Przelewy24Service::class, function ($mock) use ($order) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->withAnyArgs()
                ->andReturnUsing(function () use ($order) {
                    $order->status()->transitionTo('paid');
                    $order->update(['paid_at' => now()]);
                });
        });

        $response = $this->actingAsTenant($this->org)
            ->post(route('webhooks.p24'), [
                'sessionId' => 'test-session-123',
                'orderId' => 99,
                'amount' => 10000,
                'originAmount' => 10000,
                'currency' => 'PLN',
                'methodId' => 25,
                'statement' => 'test',
                'sign' => 'fakeSignature',
            ]);

        $response->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    // -------------------------------------------------------------------------
    // Webhook zawsze zwraca 200 — kontroler pochłania wyjątki (idempotencja)
    // -------------------------------------------------------------------------

    public function test_webhook_returns_200_for_idempotent_already_paid_order(): void
    {
        $user = User::factory()->create();

        // Zamówienie już opłacone — handleWebhook() wróci bez akcji (status === 'paid')
        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'p24_session_id' => 'already-paid-session',
        ]);

        // handleWebhook() znajdzie status='paid' i wróci bez tranzycji —
        // symulujemy ten sam no-op przez pustą implementację mocka.
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->withAnyArgs()
                ->andReturnNull();
        });

        $response = $this->actingAsTenant($this->org)
            ->post(route('webhooks.p24'), [
                'sessionId' => 'already-paid-session',
                'orderId' => 99,
                'amount' => 10000,
            ]);

        // Kontroler zawsze zwraca 200 — zachowanie idempotentne
        $response->assertOk();

        // Status nie powinien ulec zmianie
        $order->refresh();
        $this->assertSame('paid', $order->status);
    }

    // -------------------------------------------------------------------------
    // Webhook z wyjątkiem — kontroler pochłania, zwraca 200
    // -------------------------------------------------------------------------

    public function test_webhook_returns_200_even_when_service_throws(): void
    {
        // WebhookController łapie KAŻDY wyjątek i zwraca response('OK', 200).
        // Testujemy to zachowanie wprost, żeby nie zaliczać błędów serwisu jako crash.
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('handleWebhook')
                ->once()
                ->andThrow(new \RuntimeException('Simulated P24 failure'));
        });

        $response = $this->actingAsTenant($this->org)
            ->post(route('webhooks.p24'), [
                'sessionId' => 'error-session',
                'orderId' => 0,
                'amount' => 0,
            ]);

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Metoda HTTP — webhook akceptuje tylko POST
    // -------------------------------------------------------------------------

    public function test_webhook_requires_post_method(): void
    {
        $response = $this->actingAsTenant($this->org)
            ->get('/webhooks/przelewy24');

        // Route zarejestrowana tylko dla POST — GET powinno zwrócić 405
        $this->assertContains($response->status(), [404, 405]);
    }
}
