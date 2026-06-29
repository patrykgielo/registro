<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationLifecycleState;
use App\Models\AnalyticsEvent;
use App\Models\Cart;
use App\Models\Organization;
use App\Models\StatisticsSnapshot;
use App\Services\Lifecycle\OrganizationAnonymizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purge closed organizations: anonymize PII, delete ephemeral data, soft-delete the org.
 *
 * Eligibility: lifecycle_state = 'closed' AND purge_after <= now() AND deleted_at IS NULL.
 *
 * Per eligible org (in a DB transaction):
 *  1. OrganizationAnonymizationService::anonymize() — PII on orders/appointments/rentals/payments.
 *  2. Hard-delete ephemeral data: carts, analytics_events, statistics_daily_snapshots.
 *  3. Soft-delete the organization (bypassDeleteGuard = true to skip app-level guards).
 *     Legal records (orders, payments, tenant_payments, rentals) are intentionally LEFT.
 *     They are retained for ≥5–6 years (Art. 112 VAT / Art. 70 Ordynacja) — already anonymized.
 *
 * FUTURE: Hard-delete legal records after legal_records_years elapses (not implemented here).
 *         Add a separate `organizations:purge-legal-records --after-years=6` command when needed.
 *
 * Follows the destructive commands pattern (console-commands.md):
 * - --dry-run  : show what would change, no DB writes
 * - --force    : suppress interactive confirmation prompt
 * - audit log  : Log::info start + Log::warning before destructive step + Log::info completed
 * - transaction: per-org DB::transaction with catch \Throwable, Log::error, return FAILURE
 */
class PurgeClosedOrganizationsCommand extends Command
{
    protected $signature = 'organizations:purge
        {--dry-run : Show what would be anonymized/deleted without making changes}
        {--force : Skip interactive confirmation prompt}';

    protected $description = 'Anonymize PII and soft-delete closed organizations past their purge_after date (Faza 5.3a)';

    public function __construct(
        private readonly OrganizationAnonymizationService $anonymizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        Log::info('organizations:purge start', [
            'dry_run' => $dryRun,
            'invoked_at' => now()->toIso8601String(),
        ]);

        $eligible = Organization::where('lifecycle_state', OrganizationLifecycleState::Closed->value)
            ->where('purge_after', '<=', now())
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No organizations eligible for purge.');
            Log::info('organizations:purge completed — no eligible orgs');

            return self::SUCCESS;
        }

        // Dry run: report what would happen and exit without changes.
        if ($dryRun) {
            $this->info("[DRY-RUN] {$eligible->count()} organization(s) eligible for purge:");

            foreach ($eligible as $org) {
                $orders = DB::table('orders')->where('organization_id', $org->id)->count();
                $appointments = DB::table('appointments')->where('organization_id', $org->id)->count();
                $rentals = DB::table('rentals')->where('organization_id', $org->id)->count();
                $payments = DB::table('payments')->where('organization_id', $org->id)->count();
                $carts = DB::table('carts')->where('organization_id', $org->id)->count();
                $events = DB::table('analytics_events')->where('organization_id', $org->id)->count();
                $snapshots = DB::table('statistics_daily_snapshots')->where('organization_id', $org->id)->count();

                $this->line(
                    "[DRY-RUN] Org #{$org->id} \"{$org->name}\" (slug: {$org->slug}) — "
                    ."purge_after: {$org->purge_after}"
                );
                $this->line(
                    "          Anonymize: {$orders} orders, {$appointments} appointments, "
                    ."{$rentals} rentals, {$payments} payments"
                );
                $this->line(
                    "          Delete ephemeral: {$carts} carts, {$events} analytics events, "
                    ."{$snapshots} statistics snapshots"
                );
            }

            Log::info('organizations:purge dry-run completed', ['count' => $eligible->count()]);

            return self::SUCCESS;
        }

        // Confirm gate — skipped when --force or piped/non-interactive input.
        Log::warning('organizations:purge about to purge', [
            'count' => $eligible->count(),
            'org_ids' => $eligible->pluck('id')->all(),
            'interactive' => $this->input->isInteractive(),
        ]);

        $this->warn("About to purge {$eligible->count()} closed organization(s). This anonymizes PII and soft-deletes the org. Legal records are retained (anonymized).");

        if ($this->input->isInteractive() && ! $this->option('force') && ! $this->confirm('Continue with purge?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $processed = 0;
        $totalAnonymized = ['orders' => 0, 'appointments' => 0, 'rentals' => 0, 'payments' => 0];
        $totalEphemeralDeleted = ['carts' => 0, 'analytics_events' => 0, 'statistics_snapshots' => 0];

        foreach ($eligible as $org) {
            $this->line("Processing org #{$org->id} \"{$org->name}\"...");

            try {
                $ephemeralDeleted = [];

                DB::transaction(function () use ($org, &$totalAnonymized, &$ephemeralDeleted) {
                    // Step 1: Anonymize PII (orders, appointments, rentals, payment payloads).
                    $counts = $this->anonymizer->anonymize($org);

                    $totalAnonymized['orders'] += $counts['orders'];
                    $totalAnonymized['appointments'] += $counts['appointments'];
                    $totalAnonymized['rentals'] += $counts['rentals'];
                    $totalAnonymized['payments'] += $counts['payments'];

                    // Step 2: Hard-delete ephemeral data (no retention obligation).
                    $ephemeralDeleted['carts'] = Cart::withoutGlobalScope('organization')
                        ->where('organization_id', $org->id)
                        ->delete();

                    $ephemeralDeleted['analytics_events'] = AnalyticsEvent::where('organization_id', $org->id)
                        ->delete();

                    $ephemeralDeleted['statistics_snapshots'] = StatisticsSnapshot::where('organization_id', $org->id)
                        ->delete();

                    // Step 3: Soft-delete the organization.
                    // bypassDeleteGuard skips the app-level guards (OrganizationHasLegalRecordsException
                    // would fire because anonymized orders/payments still exist).
                    // Legal records remain with RESTRICT FK — they stay linked to the soft-deleted org row.
                    // FK RESTRICT only fires on hard DELETE, not on UPDATE deleted_at (soft-delete).
                    $org->bypassDeleteGuard = true;
                    $org->delete();
                });

                $totalEphemeralDeleted['carts'] += $ephemeralDeleted['carts'] ?? 0;
                $totalEphemeralDeleted['analytics_events'] += $ephemeralDeleted['analytics_events'] ?? 0;
                $totalEphemeralDeleted['statistics_snapshots'] += $ephemeralDeleted['statistics_snapshots'] ?? 0;

                $this->info("  Org #{$org->id}: PII anonymized, ephemeral deleted, org soft-deleted.");
                $processed++;

            } catch (\Throwable $e) {
                Log::error('organizations:purge transaction failed — rolled back', [
                    'org_id' => $org->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Purge failed for org #{$org->id}: {$e->getMessage()} (transaction rolled back)");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info("Purge completed: {$processed} organization(s) processed.");
        $this->line("Anonymized: {$totalAnonymized['orders']} orders, {$totalAnonymized['appointments']} appointments, {$totalAnonymized['rentals']} rentals, {$totalAnonymized['payments']} payments.");
        $this->line("Deleted (ephemeral): {$totalEphemeralDeleted['carts']} carts, {$totalEphemeralDeleted['analytics_events']} analytics events, {$totalEphemeralDeleted['statistics_snapshots']} statistics snapshots.");

        Log::info('organizations:purge completed', [
            'processed' => $processed,
            'anonymized' => $totalAnonymized,
            'ephemeral_deleted' => $totalEphemeralDeleted,
        ]);

        return self::SUCCESS;
    }
}
