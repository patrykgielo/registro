<?php

declare(strict_types=1);

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * VULN-003-adjacent hardening: old_values/new_values must be encrypted at rest
 * (PESEL, ID-document numbers are duplicated verbatim into these columns by
 * Order/User $auditInclude), while legacy plaintext rows written before this
 * change remain readable.
 */
class AuditLogEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_values_are_not_stored_as_plaintext_in_the_database(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => ['customer_pesel' => '12345678901'],
            'new_values' => ['customer_pesel' => '98765432109'],
        ]);

        $raw = DB::table('audit_logs')->where('id', $log->id)->first();

        $this->assertIsString($raw->old_values);
        $this->assertStringNotContainsString('12345678901', $raw->old_values);
        $this->assertStringNotContainsString('98765432109', $raw->new_values);
    }

    public function test_encrypted_values_are_transparently_decrypted_on_read(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => ['customer_pesel' => '12345678901'],
            'new_values' => null,
        ]);

        $fresh = AuditLog::find($log->id);

        $this->assertSame(['customer_pesel' => '12345678901'], $fresh->old_values);
    }

    public function test_legacy_plaintext_rows_remain_readable(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => json_encode(['customer_pesel' => '11111111111']),
            'new_values' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $log = AuditLog::find($id);

        $this->assertSame(['customer_pesel' => '11111111111'], $log->old_values);
    }

    public function test_legacy_plaintext_row_logs_a_decrypt_warning_with_record_id_not_value(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => json_encode(['customer_pesel' => '22222222222']),
            'new_values' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::spy();

        AuditLog::find($id)->old_values;

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($id) {
                return $context['id'] === $id
                    && ! str_contains($message, '22222222222')
                    && ! str_contains(json_encode($context), '22222222222');
            });
    }

    public function test_corrupted_ciphertext_also_logs_a_decrypt_warning(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => 'not-valid-ciphertext-and-not-valid-json',
            'new_values' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::spy();

        $decoded = AuditLog::find($id)->old_values;

        // Genuine corruption: fallback json_decode() also fails — surfaced as null,
        // but (unlike the legacy-plaintext case) the warning is the only signal it happened.
        $this->assertNull($decoded);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['id'] === $id);
    }

    public function test_null_values_remain_null(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_CREATED,
            'old_values' => null,
            'new_values' => null,
        ]);

        $this->assertNull($log->fresh()->old_values);
        $this->assertNull($log->fresh()->new_values);
    }
}
