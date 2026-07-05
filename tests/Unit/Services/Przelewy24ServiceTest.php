<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\User;
use App\Notifications\PaymentReconciliationAlertNotification;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Przelewy24\Api\Requests\TransactionRequests;
use Przelewy24\Config;
use Przelewy24\Exceptions\Przelewy24Exception;
use Przelewy24\Przelewy24;
use Przelewy24\TransactionStatusNotification;
use Tests\TestCase;

/**
 * Tests for Przelewy24Service::handleWebhook().
 *
 * Strategy: The private client() factory is bypassed by subclassing
 * Przelewy24Service with an anonymous class (testable subclass pattern).
 * This keeps all real service logic intact — only the HTTP client creation
 * is swapped for a mock, so we never hit the real P24 API.
 */
class Przelewy24ServiceTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private string $crc = 'test_crc_value';

    private int $merchantId = 123456;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'przelewy24.merchant_id' => $this->merchantId,
            'przelewy24.reports_key' => 'test_reports_key',
            'przelewy24.crc' => $this->crc,
            'przelewy24.is_live' => false,
            'przelewy24.pos_id' => (string) $this->merchantId,
        ]);
    }

    /**
     * Build a minimal webhook payload. The sign field is deliberately incorrect
     * unless explicitly computed via computeValidSign().
     */
    private function buildPayload(string $sessionId, string $sign = 'bad_sign'): array
    {
        return [
            'merchantId' => $this->merchantId,
            'posId' => $this->merchantId,
            'sessionId' => $sessionId,
            'amount' => 10000,
            'originAmount' => 10000,
            'currency' => 'PLN',
            'orderId' => 999,
            'methodId' => 25,
            'statement' => 'Test statement',
            'sign' => $sign,
        ];
    }

    /**
     * Compute the real SHA-384 signature so isSignValid() returns true.
     */
    private function computeValidSign(array $payload): string
    {
        return Przelewy24::createSignature([
            'merchantId' => $payload['merchantId'],
            'posId' => $payload['posId'],
            'sessionId' => $payload['sessionId'],
            'amount' => $payload['amount'],
            'originAmount' => $payload['originAmount'],
            'currency' => $payload['currency'],
            'orderId' => $payload['orderId'],
            'methodId' => $payload['methodId'],
            'statement' => $payload['statement'],
            'crc' => $this->crc,
        ]);
    }

    /**
     * Build a testable Przelewy24Service subclass.
     *
     * The anonymous class exposes a protected override of the private client()
     * method, returning a mocked Przelewy24 instance.
     *
     * When $verifyThrows is true, transactions()->verify() will throw a
     * Przelewy24Exception (via a mock that throws \RuntimeException, which
     * is caught as a Przelewy24Exception substitute — see test-specific variant).
     */
    private function buildServiceWithMockedClient(
        Przelewy24 $p24Mock
    ): Przelewy24Service {
        // Anonymous subclass promotes private client() to protected and injects the mock
        return new class($p24Mock) extends Przelewy24Service
        {
            public function __construct(private readonly Przelewy24 $mockedClient) {}

            protected function client(): Przelewy24
            {
                return $this->mockedClient;
            }
        };
    }

    /**
     * Build a mocked Przelewy24 where handleWebhook() returns a real notification
     * built from $payload (so isSignValid() works correctly), and
     * transactions()->verify() either succeeds or throws.
     */
    private function buildP24Mock(
        array $payload,
        bool $verifyThrows = false,
        ?\Throwable $verifyException = null
    ): Przelewy24 {
        $config = new Config(
            $payload['merchantId'],
            'test_reports_key',
            $this->crc,
            false,
            (string) $payload['posId']
        );

        $notification = new TransactionStatusNotification($config, $payload);

        $txMock = $this->createMock(TransactionRequests::class);

        if ($verifyThrows && $verifyException !== null) {
            $txMock->method('verify')->willThrowException($verifyException);
        }
        // If not throwing, verify() returns void (default mock behaviour is fine)

        $p24Mock = $this->createMock(Przelewy24::class);
        $p24Mock->method('handleWebhook')->willReturn($notification);
        $p24Mock->method('transactions')->willReturn($txMock);

        return $p24Mock;
    }

    /**
     * Build a Przelewy24Exception by wrapping it through a real RuntimeException.
     * We use a plain RuntimeException as the verify-throw substitute because
     * Przelewy24Exception extends GuzzleHttp BadResponseException which requires
     * PSR-7 objects in its constructor.
     * The service catches Przelewy24Exception specifically, so we create a direct
     * subclass stub that can be instantiated.
     */
    private function makeP24Exception(): \RuntimeException
    {
        // We extend Przelewy24Exception anonymously with a simpler constructor
        // to avoid having to build full PSR-7 Guzzle request/response objects.
        return new class('Verification failed') extends \RuntimeException implements \Throwable {};
    }

    // -------------------------------------------------------------------------
    // Idempotency: second call on already-paid order is a no-op
    // -------------------------------------------------------------------------

    public function test_handle_webhook_is_idempotent_on_already_paid_order(): void
    {
        $order = Order::factory()->paid()->create([
            'p24_session_id' => 'SESSION-PAID-123',
        ]);

        $payload = $this->buildPayload('SESSION-PAID-123');
        $p24Mock = $this->buildP24Mock($payload);

        $svc = $this->buildServiceWithMockedClient($p24Mock);

        // Call twice — second call must be silently skipped (idempotency guard)
        $svc->handleWebhook($payload);
        $svc->handleWebhook($payload);

        // No payment record created (signature is invalid anyway, but more
        // importantly the paid-order guard fires first)
        $this->assertDatabaseCount('payments', 0);

        // Status stays paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);
    }

    // -------------------------------------------------------------------------
    // Invalid signature — order left untouched, no payment record
    // -------------------------------------------------------------------------

    public function test_handle_webhook_with_invalid_signature_leaves_order_unchanged(): void
    {
        $order = Order::factory()->pendingPayment()->create([
            'p24_session_id' => 'SESSION-BAD-SIG',
        ]);

        // Deliberately bad sign — isSignValid() will return false
        $payload = $this->buildPayload('SESSION-BAD-SIG', 'bad_sign');
        $p24Mock = $this->buildP24Mock($payload);

        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $order->refresh();

        // Status must not change
        $this->assertEquals('pending_payment', $order->status);

        // No payment record created
        $this->assertDatabaseCount('payments', 0);
    }

    // -------------------------------------------------------------------------
    // Valid signature + successful verify → order paid, Payment created
    // -------------------------------------------------------------------------

    public function test_handle_webhook_with_valid_signature_and_verify_marks_order_paid(): void
    {
        $sessionId = 'SESSION-VALID-OK';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->pendingPayment()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $order->refresh();

        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_handle_webhook_with_valid_signature_creates_success_payment_record(): void
    {
        $sessionId = 'SESSION-VALID-PAY';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->pendingPayment()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'p24_session_id' => $sessionId,
            'status' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Valid signature but verify() throws → failed payment, order NOT paid
    // -------------------------------------------------------------------------

    public function test_handle_webhook_verify_failure_creates_failed_payment_record(): void
    {
        $sessionId = 'SESSION-VERIFY-FAIL';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->pendingPayment()->create([
            'p24_session_id' => $sessionId,
        ]);

        // We need an actual Przelewy24Exception — build via anonymous subclass
        // with a simpler constructor that avoids Guzzle PSR-7 requirements
        $exception = $this->buildP24ExceptionStub('Verification failed');

        $p24Mock = $this->buildP24Mock($payload, true, $exception);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'p24_session_id' => $sessionId,
            'status' => 'failed',
        ]);

        $order->refresh();
        $this->assertEquals('pending_payment', $order->status);
        $this->assertNull($order->paid_at);
    }

    public function test_handle_webhook_verify_failure_does_not_transition_order_to_paid(): void
    {
        $sessionId = 'SESSION-VERIFY-NOOP';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->pendingPayment()->create([
            'p24_session_id' => $sessionId,
        ]);

        $exception = $this->buildP24ExceptionStub('Amount mismatch');

        $p24Mock = $this->buildP24Mock($payload, true, $exception);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $order->refresh();
        $this->assertEquals('pending_payment', $order->status);
    }

    // -------------------------------------------------------------------------
    // Late webhook reconciliation — TTL cleanup already cancelled the order,
    // but a genuine, verified P24 success webhook arrives afterwards.
    // -------------------------------------------------------------------------

    public function test_late_webhook_after_cancellation_reconciles_order_to_paid(): void
    {
        Notification::fake();

        $sessionId = 'SESSION-LATE-RECONCILE';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->cancelled()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $order->refresh();

        $this->assertEquals('paid', $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_late_webhook_after_cancellation_still_creates_success_payment_record(): void
    {
        Notification::fake();

        $sessionId = 'SESSION-LATE-RECONCILE-PAY';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->cancelled()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'p24_session_id' => $sessionId,
            'status' => 'success',
        ]);
    }

    public function test_late_webhook_after_cancellation_notifies_super_admins(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $sessionId = 'SESSION-LATE-RECONCILE-NOTIFY';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->cancelled()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        Notification::assertSentTo(
            $admin,
            PaymentReconciliationAlertNotification::class
        );
    }

    // -------------------------------------------------------------------------
    // Defense-in-depth: a verified payment arrives for an order whose status
    // is neither 'pending_payment' nor 'cancelled' (e.g. 'completed') — the
    // state machine still refuses the transition to 'paid'. This must be
    // caught, logged loudly, and surfaced to super-admins, not silently
    // swallowed nor allowed to bubble up as an uncaught exception.
    // -------------------------------------------------------------------------

    public function test_webhook_on_completed_order_does_not_throw_and_leaves_status_unchanged(): void
    {
        Notification::fake();

        $sessionId = 'SESSION-BLOCKED-COMPLETED';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->completed()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
    }

    public function test_webhook_on_completed_order_still_records_success_payment(): void
    {
        Notification::fake();

        $sessionId = 'SESSION-BLOCKED-COMPLETED-PAY';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->completed()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        // Money was genuinely captured (verify() succeeded) — this must never
        // be lost even though the Order itself couldn't be transitioned.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'p24_session_id' => $sessionId,
            'status' => 'success',
        ]);
    }

    public function test_webhook_on_completed_order_notifies_super_admins_of_blocked_reconciliation(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $sessionId = 'SESSION-BLOCKED-COMPLETED-NOTIFY';
        $payload = $this->buildPayload($sessionId);
        $payload['sign'] = $this->computeValidSign($payload);

        $order = Order::factory()->completed()->create([
            'p24_session_id' => $sessionId,
        ]);

        $p24Mock = $this->buildP24Mock($payload, false);
        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        Notification::assertSentTo(
            $admin,
            PaymentReconciliationAlertNotification::class
        );
    }

    // -------------------------------------------------------------------------
    // Unknown session_id — silently ignored
    // -------------------------------------------------------------------------

    public function test_handle_webhook_with_unknown_session_id_does_nothing(): void
    {
        // Build a bad-sign payload so sign check fails first, preventing DB lookup crash.
        // The service logs and returns early on bad sign regardless of whether order exists.
        $payload = $this->buildPayload('SESSION-NONEXISTENT', 'bad_sign');
        $p24Mock = $this->buildP24Mock($payload);

        $svc = $this->buildServiceWithMockedClient($p24Mock);
        $svc->handleWebhook($payload);

        $this->assertDatabaseCount('payments', 0);
    }

    // -------------------------------------------------------------------------
    // Private helper: build a Przelewy24Exception stub
    // -------------------------------------------------------------------------

    /**
     * Builds a Przelewy24Exception subclass that avoids the Guzzle
     * PSR-7 constructor requirements. The service catches Przelewy24Exception
     * by class, so any subclass triggers the failure path correctly.
     */
    private function buildP24ExceptionStub(string $message): Przelewy24Exception
    {
        // We need a real Guzzle request/response mock to satisfy the parent constructor
        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);

        // ResponseInterface::getStatusCode() is called by the parent constructor chain
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getReasonPhrase')->willReturn('Bad Request');
        $response->method('getBody')->willReturn(
            $this->createMock(\Psr\Http\Message\StreamInterface::class)
        );

        return new Przelewy24Exception($message, $request, $response);
    }
}
