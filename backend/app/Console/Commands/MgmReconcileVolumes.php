<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Commission\VolumeAccumulator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly reconciliation for member_volume_accumulations.
 *
 * The PolicyPaymentObserver keeps volumes live during normal operation.
 * This command exists as a safety net for:
 *   - Observer failures (transaction crashed, queued job dropped)
 *   - Data fixes (admin retroactively fixes an agent's rank_id or moves
 *     an agent under a different parent — team volumes for all affected
 *     uplines need a rebuild the observer can't trigger)
 *   - First-time backfill after seeding
 *
 * Runs for a specific month (default: current month) across all tenants.
 * Rebuilds every agent's row from payment history + current rank tree.
 * Safe to run multiple times a day — idempotent.
 *
 * Wire into the schedule in bootstrap/app.php or routes/console.php:
 *
 *   Schedule::command('mgm:reconcile-volumes')->daily()->at('02:00');
 */
class MgmReconcileVolumes extends Command
{
    protected $signature = 'mgm:reconcile-volumes
        {--month= : YYYY-MM. Defaults to current month.}
        {--tenant= : Restrict to a single tenant id. Defaults to all.}';

    protected $description = 'Rebuild member_volume_accumulations rows for a given month from source truth.';

    public function handle(VolumeAccumulator $accumulator): int
    {
        $yearMonth = (string) ($this->option('month') ?? Carbon::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $this->error("Invalid --month {$yearMonth}. Expected YYYY-MM.");

            return self::FAILURE;
        }

        $tenantOpt = $this->option('tenant');
        $tenants = $tenantOpt !== null
            ? Tenant::query()->whereKey((int) $tenantOpt)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched. Nothing to do.');

            return self::SUCCESS;
        }

        $totalAgents = 0;
        foreach ($tenants as $tenant) {
            $this->info(">>> tenant={$tenant->id} month={$yearMonth}");
            $count = $accumulator->reconcileMonth((int) $tenant->id, $yearMonth);
            $totalAgents += $count;
            $this->line("    reconciled {$count} agent(s)");
        }

        $this->info("Done. Reconciled {$totalAgents} agent-month rows across {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
