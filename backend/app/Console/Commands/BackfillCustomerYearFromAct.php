<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Set customer_year (ปีของลูกค้า) = act_year.
 *
 * Business rule (confirmed 2026-11-26): ปีของลูกค้า on the policy page IS the
 * source Act_Year — it is NOT a "years since the customer's first policy" tenure.
 * The migration (BackfillPolicyYears) derived customer_year from the renewal
 * chain / customer history instead, so it diverges from act_year on ~4,365 live
 * policies (e.g. an Act_Year-2 พ.ร.บ. renewal on a policy-year-1 policy shows
 * ปีของลูกค้า 1 when it should be 2).
 *
 * act_year itself matches the source exactly (0 mismatches) and is left untouched;
 * this command simply mirrors it into customer_year so the badge reads correctly.
 *
 * SAFETY
 *   - Writes only policies.customer_year. Nothing else touched.
 *   - Raw update → no observers. Idempotent. --dry-run supported.
 *
 *   php artisan policies:customer-year-from-act [--tenant=1] [--dry-run]
 */
class BackfillCustomerYearFromAct extends Command
{
    protected $signature = 'policies:customer-year-from-act
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Set customer_year (ปีของลูกค้า) = act_year, matching the source.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $base = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereRaw('(customer_year IS NULL OR customer_year <> act_year)');

        $n = (clone $base)->count();
        if ($n === 0) {
            $this->info('customer_year already equals act_year everywhere — nothing to do.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry-run] ' : '')."Setting customer_year = act_year on {$n} policies.");
        if ($dry) {
            $sample = (clone $base)->orderBy('id')
                ->limit(10)->get(['id', 'policy_no', 'act_year', 'customer_year']);
            foreach ($sample as $p) {
                $this->line("   policy {$p->id} ({$p->policy_no}): customer_year "
                    .($p->customer_year ?? 'NULL')." → {$p->act_year}");
            }
            $this->warn('DRY RUN — nothing written.');

            return self::SUCCESS;
        }

        $updated = $base->update([
            'customer_year' => DB::raw('act_year'),
            'updated_at' => now(),
        ]);

        $this->info("Updated customer_year on {$updated} policies.");

        return self::SUCCESS;
    }
}
