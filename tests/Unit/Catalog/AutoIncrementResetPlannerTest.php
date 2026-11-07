<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Support\Catalog\AutoIncrementResetPlanner;
use PHPUnit\Framework\TestCase;

class AutoIncrementResetPlannerTest extends TestCase
{
    public function test_never_on_sqlite_or_other_drivers(): void
    {
        foreach (['sqlite', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            $d = AutoIncrementResetPlanner::decide($driver, false, false);
            $this->assertFalse($d['reset'], $driver);
        }
    }

    public function test_skips_when_rows_at_or_above_the_lowest_id_remain(): void
    {
        $this->assertFalse(AutoIncrementResetPlanner::decide('mysql', true, false)['reset']);
    }

    public function test_skips_when_audit_logs_would_alias_the_ids(): void
    {
        $d = AutoIncrementResetPlanner::decide('mysql', false, true);

        $this->assertFalse($d['reset']);
        $this->assertStringContainsString('audit_logs', $d['reason']);
    }

    public function test_skips_when_another_live_ledger_row_points_at_or_above_the_lowest_id(): void
    {
        $d = AutoIncrementResetPlanner::decide('mysql', false, false, true);

        $this->assertFalse($d['reset']);
        $this->assertStringContainsString('ledger', $d['reason']);
        $this->assertTrue(AutoIncrementResetPlanner::decide('mysql', false, false, false)['reset']);
    }

    public function test_resets_only_when_mysql_nothing_remains_and_no_audit_alias(): void
    {
        $this->assertTrue(AutoIncrementResetPlanner::decide('mysql', false, false)['reset']);
    }

    public function test_only_whitelisted_tables_are_ever_considered(): void
    {
        $this->assertSame(
            ['services', 'rental_categories', 'service_units', 'service_location_stocks'],
            array_keys(AutoIncrementResetPlanner::TABLES)
        );
    }
}
