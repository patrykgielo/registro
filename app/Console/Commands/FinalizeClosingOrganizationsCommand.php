<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationLifecycleState;
use App\Jobs\CancelInFlightObligationsJob;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finalize organizations that have been in Closing state past the grace window.
 *
 * Eligibility: lifecycle_state = 'closing' AND closing_initiated_at <= now() - closing_grace_days.
 *
 * Per eligible org:
 * 1. Defensively dispatch CancelInFlightObligationsJob (catches any stragglers the initial
 *    dispatch may have missed — e.g. obligations created between initiation and finalization).
 * 2. Transition lifecycle_state → Closed (forceLifecycleTransition = true to bypass
 *    obligation guard — any remaining obligations are cleaned by the job above).
 *    OrganizationObserver sets closed_at and purge_after as side-effects.
 *
 * Follows the destructive commands pattern (console-commands.md):
 * - --dry-run : show what would change, no DB writes
 * - --force   : skip interactive confirmation prompt
 * - audit log : Log::info start + Log::warning before destructive step + Log::info completed
 */
class FinalizeClosingOrganizationsCommand extends Command
{
    protected $signature = 'organizations:finalize-closing
        {--dry-run : Show what would be finalized without making changes}
        {--force : Skip interactive confirmation prompt}';

    protected $description = 'Transition Closing organizations past their grace window to Closed (Faza 5.4a)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $graceDays = (int) config('retention.closing_grace_days', 14);
        $cutoff = now()->subDays($graceDays);

        Log::info('organizations:finalize-closing start', [
            'dry_run' => $dryRun,
            'grace_days' => $graceDays,
            'cutoff' => $cutoff->toIso8601String(),
            'invoked_at' => now()->toIso8601String(),
        ]);

        $eligible = Organization::where('lifecycle_state', OrganizationLifecycleState::Closing->value)
            ->where('closing_initiated_at', '<=', $cutoff)
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No organizations eligible for finalization.');
            Log::info('organizations:finalize-closing completed — no eligible orgs');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[DRY-RUN] {$eligible->count()} organization(s) eligible for finalization:");

            foreach ($eligible as $org) {
                $this->line(
                    "[DRY-RUN] Org #{$org->id} \"{$org->name}\" — "
                    ."closing_initiated_at: {$org->closing_initiated_at}"
                );
            }

            Log::info('organizations:finalize-closing dry-run completed', ['count' => $eligible->count()]);

            return self::SUCCESS;
        }

        Log::warning('organizations:finalize-closing about to finalize', [
            'count' => $eligible->count(),
            'org_ids' => $eligible->pluck('id')->all(),
            'interactive' => $this->input->isInteractive(),
        ]);

        $this->warn("About to finalize {$eligible->count()} Closing organization(s) → Closed. This is irreversible.");

        if ($this->input->isInteractive() && ! $this->option('force') && ! $this->confirm('Continue with finalization?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($eligible as $org) {
            $this->line("Finalizing org #{$org->id} \"{$org->name}\"...");

            try {
                // Defensive dispatch: cancel any stragglers missed by the initial offboarding job.
                CancelInFlightObligationsJob::dispatch($org->id, 'Zamknięcie działalności — finalizacja');

                // Transition to Closed; observer sets closed_at + purge_after.
                $org->forceLifecycleTransition = true;
                $org->lifecycle_state = OrganizationLifecycleState::Closed;
                $org->save();

                Log::info('organizations:finalize-closing: org finalized', [
                    'org_id' => $org->id,
                    'closed_at' => $org->fresh()->closed_at?->toIso8601String(),
                    'purge_after' => $org->fresh()->purge_after?->toIso8601String(),
                ]);

                $this->info("  Org #{$org->id}: transitioned to Closed.");
                $processed++;
            } catch (\Throwable $e) {
                Log::error('organizations:finalize-closing: transition failed', [
                    'org_id' => $org->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Finalization failed for org #{$org->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Finalization completed: {$processed} organization(s) processed, {$failed} failed.");

        Log::info('organizations:finalize-closing completed', [
            'processed' => $processed,
            'failed' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
