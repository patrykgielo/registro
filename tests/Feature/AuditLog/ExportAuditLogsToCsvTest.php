<?php

declare(strict_types=1);

namespace Tests\Feature\AuditLog;

use App\Actions\AuditLog\ExportAuditLogsToCsv;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ExportAuditLogsToCsvTest extends TestCase
{
    use RefreshDatabase;

    private function captureCsv(Collection $records): string
    {
        $response = (new ExportAuditLogsToCsv)->execute($records);

        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_export_masks_sensitive_fields_in_csv(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => ['customer_pesel' => '12345678901'],
            'new_values' => ['customer_pesel' => '98765432109'],
        ]);

        $csv = $this->captureCsv(AuditLog::whereKey($log->id)->get());

        $this->assertStringNotContainsString('12345678901', $csv);
        $this->assertStringNotContainsString('98765432109', $csv);
        $this->assertStringContainsString('8901', $csv);
        $this->assertStringContainsString('2109', $csv);
    }

    public function test_export_leaves_non_sensitive_fields_intact(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_UPDATED,
            'old_values' => ['customer_city' => 'Warszawa'],
            'new_values' => null,
        ]);

        $csv = $this->captureCsv(AuditLog::whereKey($log->id)->get());

        $this->assertStringContainsString('Warszawa', $csv);
    }

    public function test_export_action_fires_audited_export_event(): void
    {
        $log = AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'event' => AuditLog::EVENT_CREATED,
            'old_values' => null,
            'new_values' => null,
        ]);

        $this->assertSame(0, AuditLog::where('event', AuditLog::EVENT_EXPORTED)->count());

        $this->captureCsv(AuditLog::whereKey($log->id)->get());

        $exportLog = AuditLog::where('event', AuditLog::EVENT_EXPORTED)->first();

        $this->assertNotNull($exportLog);
        $this->assertSame(1, $exportLog->new_values['exported_count']);
        $this->assertSame([$log->id], $exportLog->new_values['exported_ids']);
    }
}
