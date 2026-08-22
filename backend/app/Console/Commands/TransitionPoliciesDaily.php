<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Policy;
use App\Models\PolicyEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * C-16 — daily maintenance for the policy state machine.
 *
 * Runs three jobs per B1-state-machine.md §7:
 *
 *   1. issued → active   when effective_date <= today (verb: `activated`)
 *   2. active → expired  when expiry_date < today     (verb: `expired`)
 *   3. draft retention   soft-delete drafts older than 30 days
 *
 * Every state transition writes a policy_events row so the audit trail
 * doesn't drift. Draft retention writes a deletion event only when
 * --dry-run is off; the soft-delete row itself is the primary evidence.
 *
 * Scheduled from routes/console.php at 00:15 Asia/Bangkok — the 15-min
 * buffer past midnight avoids clock-skew edge cases on the timestamp
 * comparisons. All work is idempotent; running twice on the same day
 * is a no-op after the first successful pass.
 */
class TransitionPoliciesDaily extends Command
{
    protected $signature = 'policies:transition-daily
        {--dry-run : Print what would change without writing}
        {--tenant= : Restrict to a single tenant id (default: all)}
        {--retention-days=30 : Draft retention window in days}';

    protected $description = 'Daily policy transitions: issued→active, active→expired, and draft retention cleanup.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $retentionDays = max(1, (int) $this->option('retention-days'));

        $header = $dryRun ? 'DRY RUN — no writes' : 'LIVE RUN';
        $this->info("policies:transition-daily [{$header}]");
        if ($tenantId !== null) {
            $this->line("  Scope: tenant_id = {$tenantId}");
        }
        $this->line("  Draft retention: {$retentionDays} days");
        $this->newLine();

        $today = Carbon::today();

        $activated = $this->runActivation($today, $dryRun, $tenantId);
        $expired = $this->runExpiration($today, $dryRun, $tenantId);
        $deleted = $this->runDraftRetention($today, $dryRun, $tenantId, $retentionDays);

        $this->newLine();
        $this->info(sprintf(
            'Summary: activated=%d, expired=%d, drafts_deleted=%d',
            $activated, $expired, $deleted,
        ));

        return self::SUCCESS;
    }

    /**
     * issued → active. Fires when effective_date has landed and the
     * scheduler is the next actor to touch the row. Guarded by the
     * state check so a row that skipped Issued (unlikely but possible
     * during shim rollout) won't be flipped.
     */
    private function runActivation(Carbon $today, bool $dryRun, ?int $tenantId): int
    {
        $q = Policy::query()
            ->where('status', 'issued')
            ->whereNotNull('effective_date')
            ->whereDate('effective_date', '<=', $today)
            ->when($tenantId !== null, fn ($qq) => $qq->where('tenant_id', $tenantId));

        $count = 0;
        foreach ($q->cursor() as $policy) {
            $count++;
            $this->line("  [activate] policy {$policy->id} (application_no={$policy->application_no}) effective={$policy->effective_date?->toDateString()}");
            if (! $dryRun) {
                $this->applyTransition($policy, 'active', 'activated', [
                    'effective_date' => $policy->effective_date?->toDateString(),
                ]);
            }
        }

        return $count;
    }

    /**
     * active → expired. Fires the day AFTER expiry so the operator sees
     * "expired today" behavior; a policy expiring 2026-08-22 flips to
     * expired on 2026-08-23's cron run.
     */
    private function runExpiration(Carbon $today, bool $dryRun, ?int $tenantId): int
    {
        $q = Policy::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->when($tenantId !== null, fn ($qq) => $qq->where('tenant_id', $tenantId));

        $count = 0;
        foreach ($q->cursor() as $policy) {
            $count++;
            $this->line("  [expire]   policy {$policy->id} (policy_no={$policy->policy_no}) expiry={$policy->expiry_date?->toDateString()}");
            if (! $dryRun) {
                $this->applyTransition($policy, 'expired', 'expired', [
                    'expiry_date' => $policy->expiry_date?->toDateString(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Draft retention: soft-delete draft policies whose `created_at` is
     * older than N days. Also writes a `retentionDeleted` PolicyEvent
     * so the audit trail reflects the reason. Uses the model's
     * SoftDeletes trait — restoration is possible via a manual
     * `restore()` call if a false-positive lands.
     */
    private function runDraftRetention(Carbon $today, bool $dryRun, ?int $tenantId, int $days): int
    {
        $cutoff = $today->copy()->subDays($days);
        $q = Policy::query()
            ->where('status', 'draft')
            ->where('created_at', '<', $cutoff)
            ->when($tenantId !== null, fn ($qq) => $qq->where('tenant_id', $tenantId));

        $count = 0;
        foreach ($q->cursor() as $policy) {
            $count++;
            $this->line("  [drop]     draft {$policy->id} created={$policy->created_at?->toDateString()}");
            if (! $dryRun) {
                // Audit event first so it's captured even if the row is
                // hard-deleted later (SoftDeletes retains policy_id → event
                // FK, but a purge would drop both).
                PolicyEvent::create([
                    'policy_id' => $policy->id,
                    'type' => 'retentionDeleted',
                    'occurred_at' => now(),
                    'by_user_id' => null,
                    'payload' => [
                        'reason' => 'draft-retention',
                        'ageDays' => (int) $policy->created_at?->diffInDays($today),
                    ],
                ]);
                $policy->delete();
            }
        }

        return $count;
    }

    /**
     * Persist the (status change + policy_events row) pair inside one
     * transaction. Matches the atomicity guarantee PolicyEventController
     * provides on the HTTP path — the scheduler shouldn't leave a row
     * in a state without a matching event, nor vice versa.
     */
    private function applyTransition(Policy $policy, string $newStatus, string $verb, array $payload): void
    {
        DB::transaction(function () use ($policy, $newStatus, $verb, $payload): void {
            $policy->update(['status' => $newStatus]);
            PolicyEvent::create([
                'policy_id' => $policy->id,
                'type' => $verb,
                'occurred_at' => now(),
                'by_user_id' => null,
                'payload' => $payload + ['source' => 'scheduler'],
            ]);
        });
    }
}
