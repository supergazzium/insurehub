<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D8 (Group A only) — fix overlapping coverage dates on renewal CHAINS.
 *
 * The legacy source stored a renewal chain's yearly records with a shared
 * effective_date and cumulative expiry (e.g. policy 509-1037134: years 2–4 all
 * start 2021-08-19 with expiry stretching to 2023/2024/2025-08-19), so the years
 * overlap. This recomputes each overlapping year sequentially:
 *   year N effective = year (N-1)'s (corrected) expiry
 *   year N expiry    = effective + the chain's year-1 span (≈ one term)
 *
 * SCOPE — conservative, to protect genuine multi-year terms:
 *   - only CHAINS: a policy_no with more than one live record, ordered by year;
 *   - anchored on year 1 (kept as-is); a chain with no dated year-1 is skipped;
 *   - a year is corrected ONLY when it OVERLAPS the prior year's corrected expiry
 *     (effective < prior expiry). A year that already sits after the prior term
 *     (a gap or clean sequence) is left untouched. Single-record long-span
 *     policies (pension/savings/10-yr fire) are never considered — they aren't
 *     chains.
 *
 * No money changes (dates only). Idempotent: a second run finds no overlaps.
 *
 *   php artisan policies:fix-renewal-coverage-dates [--tenant=1] [--dry-run]
 */
class FixRenewalCoverageDates extends Command
{
    protected $signature = 'policies:fix-renewal-coverage-dates
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Fix overlapping coverage dates on legacy renewal chains (D8 Group A), leaving genuine multi-year terms alone.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // A real renewal chain = same (policy_no, customer_id) with MORE THAN ONE
        // DISTINCT policy_year. This excludes:
        //   - NULL / blank policy_no (unrelated legacy rows share a blank number);
        //   - co-insurance / multi-party rows (same policy_no, many customers, all
        //     year 1) — not renewals;
        //   - chains whose records are all year 1 (no real renewal progression).
        $chains = DB::table('policies')
            ->where('tenant_id', $tenant)->whereNull('deleted_at')
            ->whereNotNull('policy_no')->where('policy_no', '<>', '')
            ->whereNotNull('customer_id')
            ->groupBy('policy_no', 'customer_id')
            ->havingRaw('COUNT(DISTINCT policy_year) > 1 AND MAX(policy_year) > 1')
            ->get(['policy_no', 'customer_id']);

        $chainsFixed = 0;
        $yearsFixed = 0;
        $samples = [];

        foreach ($chains as $chain) {
            $years = DB::table('policies')
                ->where('tenant_id', $tenant)->whereNull('deleted_at')
                ->where('policy_no', $chain->policy_no)
                ->where('customer_id', $chain->customer_id)
                ->orderByRaw('policy_year IS NULL, policy_year')
                ->orderBy('id')
                ->get(['id', 'policy_year', 'effective_date', 'expiry_date']);

            // anchor on the first row that has an effective_date
            $anchor = $years->firstWhere(fn ($r) => $r->effective_date !== null);
            if ($anchor === null) {
                continue;                               // no datable anchor — skip
            }
            // chain term length = anchor's own span, default 365 if its expiry is absent/bad
            $span = 365;
            if ($anchor->expiry_date !== null) {
                $d = Carbon::parse($anchor->effective_date)->diffInDays(Carbon::parse($anchor->expiry_date), false);
                if ($d > 0 && $d < 4000) {
                    $span = $d;                         // keep sane term lengths only
                }
            }

            $prevExpiry = null;
            $touched = false;
            foreach ($years as $r) {
                if ($r->effective_date === null) {
                    continue;                           // can't place an undated year
                }
                $eff = Carbon::parse($r->effective_date);
                $exp = $r->expiry_date !== null ? Carbon::parse($r->expiry_date) : null;

                if ($prevExpiry !== null && $eff->lt($prevExpiry)) {
                    // OVERLAP → shift this year to start at the prior term's end.
                    $newEff = $prevExpiry->copy();
                    $newExp = $newEff->copy()->addDays($span);
                    if (! $dry) {
                        DB::table('policies')->where('id', $r->id)->update([
                            'effective_date' => $newEff->toDateString(),
                            'expiry_date' => $newExp->toDateString(),
                            'updated_at' => DB::raw('NOW()'),
                        ]);
                    }
                    if (count($samples) < 8) {
                        $samples[] = [$chain->policy_no, $r->policy_year,
                            $eff->toDateString().'→'.$newEff->toDateString(),
                            ($exp?->toDateString() ?? '—').'→'.$newExp->toDateString()];
                    }
                    $prevExpiry = $newExp;
                    $yearsFixed++;
                    $touched = true;
                } else {
                    // already sequential (or a gap) — keep, but carry its expiry.
                    $prevExpiry = $exp ?? $eff->copy()->addDays($span);
                }
            }
            if ($touched) {
                $chainsFixed++;
            }
        }

        if ($samples !== []) {
            $this->table(['policy_no', 'year', 'effective', 'expiry'], $samples);
        }
        $this->info(($dry ? '[dry-run] would fix ' : 'fixed ')
            .$yearsFixed.' overlapping years across '.$chainsFixed.' renewal chains.');

        return self::SUCCESS;
    }
}
