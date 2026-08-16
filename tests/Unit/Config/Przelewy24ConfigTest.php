<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Przelewy24\Przelewy24;

/**
 * config/przelewy24.php is a type adapter for the SDK's constructor, and the
 * 2026-08-16 production 500 was a type mismatch in it. These tests re-evaluate
 * the REAL config file (never a copy of its logic) against the env shapes that
 * matter, and then feed the result to the REAL SDK constructor from a
 * declare(strict_types=1) file — the same strictness Przelewy24Service has, and
 * the reason the mismatch was fatal rather than silently coerced.
 */
class Przelewy24ConfigTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $originalEnv = [];

    /** @var list<string> */
    private const P24_ENV = [
        'P24_MERCHANT_ID', 'P24_POS_ID', 'P24_CRC', 'P24_REPORTS_KEY', 'P24_LIVE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::P24_ENV as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function loadConfig(array $env): array
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        return require dirname(__DIR__, 3).'/config/przelewy24.php';
    }

    /**
     * The exact production state: .env.production.example ships every P24 key
     * present but empty. `env()` therefore returns '' (not null), which the old
     * `env('P24_POS_ID') !== null ? (int) env('P24_POS_ID') : null` turned into
     * int(0) — the value in the crash trace, `__construct(0, '', '', false, 0)`.
     */
    public function test_present_but_empty_env_yields_null_pos_id_not_zero(): void
    {
        $config = $this->loadConfig([
            'P24_MERCHANT_ID' => '',
            'P24_POS_ID' => '',
            'P24_CRC' => '',
            'P24_REPORTS_KEY' => '',
        ]);

        $this->assertNull($config['pos_id'], 'an empty P24_POS_ID must be null — int 0 is what took production down');
        $this->assertIsInt($config['merchant_id']);
        $this->assertIsString($config['reports_key']);
        $this->assertIsString($config['crc']);
    }

    public function test_absent_env_yields_null_pos_id(): void
    {
        $config = $this->loadConfig([]);

        $this->assertNull($config['pos_id']);
    }

    public function test_whitespace_only_pos_id_is_treated_as_absent(): void
    {
        $config = $this->loadConfig(['P24_POS_ID' => '  ']);

        $this->assertNull($config['pos_id']);
    }

    public function test_a_real_pos_id_reaches_the_sdk_as_a_string(): void
    {
        $config = $this->loadConfig(['P24_POS_ID' => '123456']);

        // The SDK declares `?string $posId`; a numeric env value must not be
        // cast to int on the way there.
        $this->assertSame('123456', $config['pos_id']);
    }

    /**
     * The regression itself, at full strength. This file is
     * declare(strict_types=1) exactly like Przelewy24Service, so the SDK
     * constructor is being called under the same rules that made int(0) fatal.
     * If config/przelewy24.php ever goes back to producing an int for pos_id,
     * this is a TypeError and the test fails — no mock, no stub, the real
     * vendor constructor.
     */
    public function test_config_values_are_accepted_by_the_real_sdk_constructor(): void
    {
        $config = $this->loadConfig([
            'P24_MERCHANT_ID' => '12345',
            'P24_POS_ID' => '12345',
            'P24_CRC' => 'a1b2c3d4',
            'P24_REPORTS_KEY' => 'reports-key',
            'P24_LIVE' => 'true',
        ]);

        $client = new Przelewy24(
            merchantId: $config['merchant_id'],
            reportsKey: $config['reports_key'],
            crc: $config['crc'],
            isLive: $config['is_live'],
            posId: $config['pos_id'],
        );

        $this->assertInstanceOf(Przelewy24::class, $client);
    }

    public function test_the_sdk_also_accepts_the_unset_pos_id_shape(): void
    {
        $config = $this->loadConfig([
            'P24_MERCHANT_ID' => '12345',
            'P24_CRC' => 'a1b2c3d4',
            'P24_REPORTS_KEY' => 'reports-key',
        ]);

        $client = new Przelewy24(
            merchantId: $config['merchant_id'],
            reportsKey: $config['reports_key'],
            crc: $config['crc'],
            isLive: $config['is_live'],
            posId: $config['pos_id'],
        );

        $this->assertInstanceOf(Przelewy24::class, $client);
    }
}
