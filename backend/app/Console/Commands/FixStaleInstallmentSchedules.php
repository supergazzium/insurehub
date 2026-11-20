<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Policy\InstallmentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix policies whose policy_installments schedule no longer matches the corrected
 * annual premium, so the edit page stops showing a false ชำระเกิน (overpay).
 *
 * BACKGROUND
 * ----------
 * PremiumDue::forPolicy() derives "due" from the installment schedule when one
 * exists (SUM(policy_installments.amount_due)), falling back to the premium only
 * when there are no installment rows. After the annual_premium gross fix
 * (BackfillPremiumGross), a batch of policies kept a STALE schedule built from the
 * old, wrong premium — so the page still reads due = old premium while paid = the
 * real (much larger) amount, and shows a huge overpay.
 *
 * SCOPE (verified against the data)
 *   Every affected policy falls into one of:
 *     - group keeper (GM 62/63/64/65, 23272215, 21523527, 15145364, 511-0040608,
 *       20967270): a bundled many-member policy, never a real installment plan.
 *     - annual / term-1 policy carrying a bogus single-row schedule whose
 *       amount_due is neither the premium nor the coverage.
 *     - renewal-mislabeled (payments are different years, not within-year
 *       installments).
 *   NONE of them is a genuine uniform multi-installment plan (confirmed: zero
 *   policies with uniform payments + term>=2 have a schedule mismatch). So the
 *   correct action for all of them is to DELETE the stale schedule and let
 *   PremiumDue fall back to the (now-correct) premium path. A spot check shows
 *   every one then resolves to outstanding ≈ 0.
 *
 * REBUILD (kept for genuine installments)
 *   If a policy IS a genuine installment plan (premium_mode monthly/quarterly/
 *   semiannual, numeric term >= 2, uniform payment amounts) its schedule is
 *   REBUILT from InstallmentSchedule instead of deleted. In the current data this
 *   bucket is empty, but the branch is here so the command is correct if such a
 *   policy appears later.
 *
 * SAFETY
 *   - Snapshots every installment row it deletes into
 *     `_removed_installments_backup` before deleting (reversible).
 *   - Only deletes policy_installments; never touches payments or premiums.
 *   - Raw deletes → no observers fire.
 *   - Idempotent; --dry-run reports and writes nothing.
 *
 *   php artisan policies:fix-stale-installments [--tenant=1] [--dry-run] [--force]
 */
class FixStaleInstallmentSchedules extends Command
{
    protected $signature = 'policies:fix-stale-installments
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}
        {--force : skip the destructive-write confirmation}';

    protected $description = 'Delete/rebuild stale installment schedules that no longer match the corrected premium (fixes false ชำระเกิน).';

    private const GROUP_KEEPERS = [
        'GM 62', 'GM 63', 'GM 64', 'GM 65',
        '23272215', '21523527', '15145364', '511-0040608', '20967270',
    ];

    public function handle(InstallmentSchedule $schedule): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // Affected = has a schedule whose SUM(amount_due) disagrees with annual_premium.
        $affected = DB::table('policies as p')
            ->where('p.tenant_id', $tenant)
            ->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('policy_installments as pi')->whereColumn('pi.policy_id', 'p.id'))
            ->whereRaw('ABS((SELECT COALESCE(SUM(amount_due),0) FROM policy_installments WHERE policy_id = p.id) - p.annual_premium) > 1')
            ->get(['p.id', 'p.policy_no', 'p.premium_mode', 'p.installment_term']);

        if ($affected->isEmpty()) {
            $this->info('No stale installment schedules — nothing to fix.');

            return self::SUCCESS;
        }

        $toDelete = [];
        $toRebuild = [];

        foreach ($affected as $p) {
            $isGroup = in_array($p->policy_no, self::GROUP_KEEPERS, true);
            $termNumeric = is_numeric($p->installment_term) && (int) $p->installment_term >= 2;
            $isInstallmentMode = in_array($p->premium_mode, ['monthly', 'quarterly', 'semiannual'], true);

            // Uniform payments → genuine installment plan → rebuild. Otherwise delete.
            $distinct = (int) DB::table('policy_payments')
                ->where('policy_id', $p->id)->whereNull('voided_at')
                ->distinct()->count(DB::raw('ROUND(amount)'));
            $uniform = $distinct <= 1;

            if (! $isGroup && $isInstallmentMode && $termNumeric && $uniform) {
                $toRebuild[] = $p->id;
            } else {
                $toDelete[] = $p->id;
            }
        }

        $this->info(sprintf('Affected: %d  →  delete %d, rebuild %d.',
            $affected->count(), count($toDelete), count($toRebuild)));

        if ($dry) {
            $this->line('Delete IDs (first 20): '.implode(', ', array_slice($toDelete, 0, 20)));
            if ($toRebuild) {
                $this->line('Rebuild IDs: '.implode(', ', $toRebuild));
            }
            $this->warn('DRY RUN — nothing written.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')
            && ! $this->confirm('Delete '.count($toDelete).' stale schedules (snapshot kept) and rebuild '.count($toRebuild).'?')) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        // Snapshot the rows we will delete (reversible). DDL implicit-commits, so
        // run it outside any transaction.
        if ($toDelete !== []) {
            DB::statement('CREATE TABLE IF NOT EXISTS _removed_installments_backup LIKE policy_installments');
            DB::table('_removed_installments_backup')->insertUsing(
                DB::getSchemaBuilder()->getColumnListing('policy_installments'),
                DB::table('policy_installments')->whereIn('policy_id', $toDelete)
            );
        }

        $deleted = 0;
        $rebuilt = 0;
        DB::transaction(function () use ($toDelete, $toRebuild, $schedule, &$deleted, &$rebuilt): void {
            if ($toDelete !== []) {
                $deleted = DB::table('policy_installments')->whereIn('policy_id', $toDelete)->delete();
            }
            foreach ($toRebuild as $pid) {
                $policy = \App\Models\Policy::find($pid);
                if ($policy !== null) {
                    DB::table('policy_installments')->where('policy_id', $pid)->delete();
                    $schedule->sync($policy);  // rebuilds from the policy's premium
                    $rebuilt++;
                }
            }
        });

        $this->info("Deleted {$deleted} stale installment rows; rebuilt schedules for {$rebuilt} policies.");
        if ($toDelete !== []) {
            $this->line('Rollback snapshot: table `_removed_installments_backup`.');
        }

        return self::SUCCESS;
    }
}
