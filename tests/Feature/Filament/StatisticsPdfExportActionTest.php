<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Statistics;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for the pre-existing Filament file-download bug found
 * while building order-protocols.md (code review, 2026-08-13) — the SAME
 * shape as OrderProtocolFilamentActionTest, discovered here first because
 * this is the only PDF feature in the app that predates that branch.
 *
 * Statistics::exportPdf()'s action closure `return
 * app(StatisticsExportService::class)->toPdf(...)` used to return
 * `Illuminate\Http\Response` (Pdf::download()'s real return type). Livewire's
 * SupportFileDownloads::valueIsntAFileResponse() only recognizes
 * StreamedResponse/BinaryFileResponse, so that Response was treated as the
 * action's ordinary return VALUE and json_encode()'d — the raw PDF binary
 * through JSON, throwing "Malformed UTF-8 characters" on every click. This
 * had never been caught because no test exercised the action's actual
 * Livewire round-trip; toCsv() (also on this page) never had the bug because
 * response()->streamDownload() already returns StreamedResponse.
 *
 * Fixed in StatisticsExportService::toPdf() by using
 * response()->streamDownload() too, matching toCsv() — no route to point
 * a ->url() action at here (the report is computed live from
 * $this->getStatsData()/$this->period, not addressable by a plain GET), so
 * this is the "fix the closure's return type" branch of the same bug,
 * unlike OrderProtocolFilamentActionTest's "point at an existing route"
 * fix — see order-protocols.md for the full argument.
 */
class StatisticsPdfExportActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_export_pdf_action_does_not_throw(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id);

        $this->actingAs($admin);
        $this->app['request']->attributes->set('tenant', $org);

        Livewire::test(Statistics::class)
            ->assertActionExists('exportPdf')
            ->callAction('exportPdf')
            ->assertHasNoActionErrors();
    }
}
