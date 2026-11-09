<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The importer originally hard-coded premium_mode='annual' for every policy,
 * ignoring the legacy Installment_Term. This derives the correct mode from the
 * already-migrated installment_term:
 *   12 → monthly · 4 → quarterly · 2 → semiannual · other >1 → monthly · else annual.
 * Idempotent; fixes existing data without a full re-import.
 */
class BackfillPremiumMode extends Command
{
    protected $signature = 'policies:backfill-premium-mode {--dry-run} {--tenant=1}';

    protected $description = 'Derive policy premium_mode from installment_term (fixes the hard-coded annual).';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $map = [
            'monthly' => ['12'],
            'quarterly' => ['4'],
            'semiannual' => ['2'],
        ];
        $changed = [];

        // the explicit standard terms
        foreach ($map as $mode => $terms) {
            $q = DB::table('policies')->where('tenant_id', $tenantId)
                ->whereIn('installment_term', $terms)->where('premium_mode', '<>', $mode);
            $changed[$mode] = $q->count();
            if (! $dry) {
                $q->update(['premium_mode' => $mode]);
            }
        }
        // any OTHER multi-installment term (3, 6, …) → monthly catch-all
        $qOther = DB::table('policies')->where('tenant_id', $tenantId)
            ->whereNotIn('installment_term', ['1', '0', '12', '4', '2'])
            ->whereNotNull('installment_term')->where('installment_term', '<>', '')
            ->whereRaw('CAST(installment_term AS UNSIGNED) > 1')
            ->where('premium_mode', '<>', 'monthly');
        $changed['monthly (other terms)'] = $qOther->count();
        if (! $dry) {
            $qOther->update(['premium_mode' => 'monthly']);
        }

        $this->table(['premium_mode', 'policies set'], collect($changed)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info($dry ? '[DRY-RUN] no writes' : 'premium_mode backfilled');

        return self::SUCCESS;
    }
}
