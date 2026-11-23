<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore policy_year (ปีของกรมธรรม์) from the source.
 *
 * The importer wrote policy_year straight from the source (correct), but a later
 * step (collapse coverage-span recompute / year backfill) re-derived it from the
 * renewal chain and overwrote the source value on 1,957 live policies — e.g. a
 * source year-1 application showing as year 2. act_year was NOT affected (0
 * mismatches), so only policy_year is restored here.
 *
 * The source value lives in the legacy staging DB
 * (insurehub_legacy.applications.policy_year), keyed by application_code, and each
 * live policy keeps its own application_no — so this is an exact per-row restore
 * to the number the source recorded for that application.
 *
 * SAFETY
 *   - Writes ONLY policies.policy_year. customer_year, act_year, dates, money,
 *     and renewal links are untouched.
 *   - Snapshots (id, old policy_year) into _policy_year_backup before writing.
 *   - Raw update → no observers. Idempotent. --dry-run reports the diff.
 *
 *   php artisan policies:restore-policy-year [--tenant=1] [--dry-run]
 */
class RestorePolicyYearFromSource extends Command
{
    protected $signature = 'policies:restore-policy-year
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Restore policy_year from the source (undo the renewal-chain re-derivation on 1,957 policies).';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // source policy_year per application_code
        $src = DB::connection('insurehub_legacy')->table('applications')
            ->whereNotNull('policy_year')
            ->pluck('policy_year', 'application_code');

        $policies = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereNotNull('application_no')
            ->get(['id', 'application_no', 'policy_year']);

        $changes = [];
        foreach ($policies as $p) {
            if (! isset($src[$p->application_no])) {
                continue;
            }
            $target = (int) $src[$p->application_no];
            if ($target < 1) {
                continue;
            }
            if ((int) $p->policy_year !== $target) {
                $changes[$p->id] = [(int) $p->policy_year, $target];
            }
        }

        if ($changes === []) {
            $this->info('policy_year already matches source — nothing to restore.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s%d policies to restore policy_year.', $dry ? '[dry-run] ' : '', count($changes)));
        $sample = array_slice($changes, 0, 10, true);
        foreach ($sample as $id => [$old, $new]) {
            $this->line("   policy {$id}: {$old} → {$new}");
        }

        if ($dry) {
            $this->warn('DRY RUN — nothing written.');

            return self::SUCCESS;
        }

        // snapshot (DDL implicit-commits — outside any transaction)
        DB::statement('CREATE TABLE IF NOT EXISTS _policy_year_backup (
            policy_id BIGINT UNSIGNED PRIMARY KEY, old_policy_year SMALLINT UNSIGNED, snapped_at TIMESTAMP NULL)');

        $now = now();
        foreach ($changes as $id => [$old, $new]) {
            DB::table('_policy_year_backup')->updateOrInsert(
                ['policy_id' => $id], ['old_policy_year' => $old, 'snapped_at' => $now]
            );
            DB::table('policies')->where('id', $id)->update(['policy_year' => $new, 'updated_at' => $now]);
        }

        $this->info('Restored policy_year on '.count($changes).' policies. Rollback: table `_policy_year_backup`.');

        return self::SUCCESS;
    }
}
