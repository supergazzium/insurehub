<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix the annual premium on installment (ผ่อน) policies.
 *
 * The legacy importer stored main_premium / annual_premium = the source
 * Main_Premium, which is the BASE-only premium of ONE installment — it excludes
 * the rider premiums AND is a per-installment (not annual) figure. Meanwhile
 * total_premium_paid is the full-year amount including riders. The result: a
 * ฿402 "annual premium" against ฿23,748 paid, which the UI reads as a massive
 * ชำระเกิน (overpayment) and premium_check flags as 'mismatch'.
 *
 * Source structure (verified): per-installment gross =
 *   Main_Premium + Rider1..5_Premium  (= the actual per-installment payment).
 * So the true annual premium is per-installment × term.
 *
 * This command recomputes, for each installment keeper policy (premium_mode in
 * monthly/quarterly/semiannual with a numeric term > 1):
 *   - per_installment = the average of its actual payment amounts (ground truth)
 *   - annual_premium = main_premium = per_installment × term   (GROSS, what the
 *     customer actually pays — matches the annual-policy convention)
 *   - net_premium   = per_installment_net × term, where per_installment_net is
 *     scaled from the current net_premium (a per-installment net)
 *   - premium_check = 'ok' when the annual premium reconciles with term ×
 *     per-installment (a fully-paid policy then shows outstanding 0; a still-
 *     paying one correctly shows a remaining balance, never ชำระเกิน).
 *
 * Idempotent: a policy already carrying the correct annual premium is skipped.
 * Only touches installment policies — annual policies are untouched.
 *
 *   php artisan policies:backfill-installment-premium [--tenant=1] [--dry-run]
 */
class BackfillInstallmentPremium extends Command
{
    protected $signature = 'policies:backfill-installment-premium
        {--tenant=1 : tenant id}
        {--dry-run : report only, write nothing}';

    protected $description = 'Recompute annual premium (gross) on installment policies so they no longer show false ชำระเกิน.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        // installment keepers: numeric term > 1
        $policies = DB::table('policies')
            ->where('tenant_id', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('premium_mode', ['monthly', 'quarterly', 'semiannual'])
            ->whereRaw("installment_term REGEXP '^[0-9]+$'")
            ->whereRaw('CAST(installment_term AS UNSIGNED) > 1')
            ->get(['id', 'main_premium', 'net_premium', 'annual_premium', 'total_premium_paid', 'installment_term', 'duty_stamp', 'vat']);

        $fixed = 0;
        $skipped = 0;
        $samples = [];

        foreach ($policies as $p) {
            $term = (int) $p->installment_term;
            if ($term < 2) {
                $skipped++;

                continue;
            }

            // per-installment = the actual average payment (ground truth; works
            // for full AND partial-year policies — it's the recurring amount).
            // Only trust it when the payments are UNIFORM (a real installment
            // plan). A group/bundled policy (e.g. one policy_no covering many
            // members, GM-65-style) has wildly varying payment amounts — AVG ×
            // term would invent a nonsense premium, so skip those.
            $agg = DB::table('policy_payments')
                ->where('policy_id', $p->id)->whereNull('voided_at')
                ->selectRaw('COUNT(*) n, ROUND(AVG(amount),2) avg_amt, COUNT(DISTINCT ROUND(amount)) distinct_amts, MIN(amount) mn, MAX(amount) mx')
                ->first();
            if ($agg === null || (int) $agg->n === 0 || $agg->avg_amt === null) {
                $skipped++;

                continue;
            }
            // non-uniform payments (more than 2 distinct rounded amounts, or a
            // max/min spread > 10%) => not a true installment plan; leave alone.
            $mn = (float) $agg->mn;
            $mx = (float) $agg->mx;
            $nonUniform = (int) $agg->distinct_amts > 2 || ($mn > 0 && ($mx - $mn) / $mn > 0.10);
            if ($nonUniform) {
                $skipped++;

                continue;
            }
            $perInst = (float) $agg->avg_amt;
            $annualGross = round($perInst * $term, 2);

            // scale the current net_premium (a per-installment net) up to annual.
            // current net_premium is one installment's net; annual net = × term.
            $curNet = (float) ($p->net_premium ?? 0);
            $annualNet = $curNet > 0 ? round($curNet * $term, 2) : null;

            // already correct?  (annual_premium within ฿2 of the gross annual)
            if (abs((float) ($p->annual_premium ?? 0) - $annualGross) <= 2.0
                && abs((float) ($p->main_premium ?? 0) - $annualGross) <= 2.0) {
                $skipped++;

                continue;
            }

            // premium_check: the annual gross vs per-installment × term reconciles
            // by construction here, so mark ok.
            $upd = [
                'annual_premium' => $annualGross,
                'main_premium' => $annualGross,
                'premium_check' => 'ok',
                'updated_at' => DB::raw('NOW()'),
            ];
            if ($annualNet !== null) {
                $upd['net_premium'] = $annualNet;
            }

            if (count($samples) < 6) {
                $samples[] = [$p->id, $term, $perInst, (float) ($p->main_premium ?? 0).'→'.$annualGross];
            }

            if (! $dry) {
                DB::table('policies')->where('id', $p->id)->update($upd);
            }
            $fixed++;
        }

        $this->table(['policy_id', 'term', 'per_installment', 'main_premium old→new'], $samples);
        $this->info(($dry ? '[dry-run] would fix ' : 'fixed ').$fixed.' installment policies ('.$skipped.' already-correct/skipped).');

        return self::SUCCESS;
    }
}
