<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore the WHT (หัก ณ ที่จ่าย) include/exclude flag lost in migration.
 *
 * Source semantics (Access WHT_Status), verified against the data:
 *   1 = INCLUDE WHT  (854 of 857 rows carry a WHT_Amt > 0)
 *   2 = EXCLUDE WHT  (all 4,894 rows carry WHT_Amt = 0)
 *   NULL = not applicable
 *
 * The staging lookup mapped these to the opaque labels "WHT 1" / "WHT 2", which
 * carry no include/exclude meaning, so the policy page couldn't tell whether WHT
 * applied. This command rewrites wht_status to the meaningful values the UI reads:
 *   source 1 → 'include'
 *   source 2 → 'exclude'
 *   (NULL stays NULL)
 *
 * SAFETY
 *   - Writes ONLY policies.wht_status (a label); wht_amt is untouched.
 *   - Reads the raw source flag from the legacy staging DB
 *     (insurehub_legacy.applications.wht_status) by application_no, so it is an
 *     exact per-row restore — run locally, ship via the dump.
 *   - Raw update → no observers. Idempotent. --dry-run supported.
 *
 *   php artisan policies:backfill-wht-status [--tenant=1] [--dry-run]
 */
class BackfillWhtStatus extends Command
{
    protected $signature = 'policies:backfill-wht-status
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Restore WHT include/exclude flag (wht_status) from the source (1=include, 2=exclude).';

    private const MAP = [1 => 'include', 2 => 'exclude'];

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // raw source flag per application_code
        $src = DB::connection('insurehub_legacy')->table('applications')
            ->whereNotNull('wht_status')
            ->pluck('wht_status', 'application_code');

        $updates = ['include' => 0, 'exclude' => 0];
        $skipped = 0;

        $policies = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereNotNull('application_no')
            ->get(['id', 'application_no', 'wht_status']);

        foreach ($policies as $p) {
            $raw = $src[$p->application_no] ?? null;
            $target = $raw !== null ? (self::MAP[(int) $raw] ?? null) : null;
            if ($target === null) {
                $skipped++;

                continue;
            }
            if ($p->wht_status === $target) {
                $skipped++;   // already correct (idempotent)

                continue;
            }
            if (! $dry) {
                DB::table('policies')->where('id', $p->id)->update([
                    'wht_status' => $target,
                    'updated_at' => now(),
                ]);
            }
            $updates[$target]++;
        }

        $this->info(sprintf('%sWHT status set → include: %d, exclude: %d  (unchanged/NA: %d).',
            $dry ? '[dry-run] ' : '', $updates['include'], $updates['exclude'], $skipped));
        if ($dry) {
            $this->warn('DRY RUN — nothing written.');
        }

        return self::SUCCESS;
    }
}
