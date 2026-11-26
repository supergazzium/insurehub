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
        $staleSchedules = 0;
        $yearlyFixed = 0;
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

            // Always clear a single-row schedule on a multi-installment term: a
            // real N-installment plan can never have exactly one row, so it is a
            // stale leftover (built from the broken pre-fix premium) that drives
            // a false ชำระเกิน. Do this BEFORE the uniformity branch so bundled /
            // multi-year policies get their bogus schedule removed too.
            $sr = (int) DB::table('policy_installments')->where('policy_id', $p->id)->count();
            if ($sr === 1 && $term > 1) {
                $staleSchedules++;
                if (! $dry) {
                    DB::table('policy_installments')->where('policy_id', $p->id)->delete();
                }
            }
            // non-uniform payments (more than 2 distinct rounded amounts, or a
            // max/min spread > 10%). These are NOT within-year installments —
            // they are YEARLY RENEWALS mis-labeled as monthly/quarterly (one
            // payment per year, premium creeping up each year). The correct
            // premium is the LATEST year's payment, the mode is annual, and the
            // stale installment schedule must go (else it drives a false
            // ชำระเกิน). A genuinely-bundled group policy (payments on the SAME
            // dates) is left alone — detected by span: yearly renewals span many
            // months/years, bundled ones cluster on a day.
            $mn = (float) $agg->mn;
            $mx = (float) $agg->mx;
            $nonUniform = (int) $agg->distinct_amts > 2 || ($mn > 0 && ($mx - $mn) / $mn > 0.10);
            if ($nonUniform) {
                $dates = DB::table('policy_payments')->where('policy_id', $p->id)->whereNull('voided_at')
                    ->selectRaw('MIN(payment_date) mn, MAX(payment_date) mx')->first();
                $spanDays = ($dates && $dates->mn && $dates->mx)
                    ? (int) ((strtotime($dates->mx) - strtotime($dates->mn)) / 86400) : 0;
                // yearly renewals span > ~1 year across the payments
                if ($spanDays > 400) {
                    $latest = (float) (DB::table('policy_payments')->where('policy_id', $p->id)->whereNull('voided_at')
                        ->orderByDesc('payment_date')->value('amount'));
                    if ($latest > 0) {
                        if (! $dry) {
                            DB::table('policies')->where('id', $p->id)->update([
                                'annual_premium' => $latest,
                                'main_premium' => $latest,
                                'premium_mode' => 'annual',
                                'premium_check' => 'ok',
                                'updated_at' => DB::raw('NOW()'),
                            ]);
                            DB::table('policy_installments')->where('policy_id', $p->id)->delete();
                        }
                        $yearlyFixed++;

                        continue;
                    }
                }
                $skipped++;

                continue;
            }
            $perInst = (float) $agg->avg_amt;
            $annualGross = round($perInst * $term, 2);


            // scale the current net_premium (a per-installment net) up to annual.
            // current net_premium is one installment's net; annual net = × term.
            $curNet = (float) ($p->net_premium ?? 0);
            $annualNet = $curNet > 0 ? round($curNet * $term, 2) : null;
            // D10 — vat and duty_stamp are ALSO per-installment and must scale with
            // net, otherwise annual net + per-installment vat won't reconcile to the
            // annual total (net + vat + stamp ≠ total on the page).
            $curVat = (float) ($p->vat ?? 0);
            $annualVat = $curVat > 0 ? round($curVat * $term, 2) : null;
            $curStamp = (float) ($p->duty_stamp ?? 0);
            $annualStamp = $curStamp > 0 ? round($curStamp * $term, 2) : null;

            // --- 1. premium fix (only when the stored premium is wrong) ---
            $premiumWrong = abs((float) ($p->annual_premium ?? 0) - $annualGross) > 2.0
                || abs((float) ($p->main_premium ?? 0) - $annualGross) > 2.0;
            if ($premiumWrong) {
                $upd = [
                    'annual_premium' => $annualGross,
                    'main_premium' => $annualGross,
                    'premium_check' => 'ok',
                    'updated_at' => DB::raw('NOW()'),
                ];
                if ($annualNet !== null) {
                    $upd['net_premium'] = $annualNet;
                }
                if ($annualVat !== null) {
                    $upd['vat'] = $annualVat;
                }
                if ($annualStamp !== null) {
                    $upd['duty_stamp'] = $annualStamp;
                }
                if (count($samples) < 6) {
                    $samples[] = [$p->id, $term, $perInst, (float) ($p->main_premium ?? 0).'→'.$annualGross];
                }
                if (! $dry) {
                    DB::table('policies')->where('id', $p->id)->update($upd);
                }
                $fixed++;
            } else {
                $skipped++;
            }

            // --- 1b. D10 — reconcile vat to the ANNUAL figures (independent of
            //        the premium fix). After net is annualized, vat/stamp may
            //        still be per-installment, so net + stamp + vat ≠ the annual
            //        total on the page. Recompute vat = annual_total − net − stamp
            //        whenever they don't already reconcile, keeping the net
            //        (authoritative WHT/commission basis) untouched. ---
            $curAnnual = (float) (($upd['annual_premium'] ?? null) ?? ($p->annual_premium ?? 0));
            $curNetNow = (float) (($upd['net_premium'] ?? null) ?? ($p->net_premium ?? 0));
            $curStampNow = (float) (($upd['duty_stamp'] ?? null) ?? ($p->duty_stamp ?? 0));
            $curVatNow = (float) (($upd['vat'] ?? null) ?? ($p->vat ?? 0));
            if ($curAnnual > 0 && $curNetNow > 0
                && abs($curNetNow + $curStampNow + $curVatNow - $curAnnual) > 0.5) {
                $reconVat = round($curAnnual - $curNetNow - $curStampNow, 2);
                if ($reconVat >= 0 && ! $dry) {
                    DB::table('policies')->where('id', $p->id)->update([
                        'vat' => $reconVat, 'updated_at' => DB::raw('NOW()'),
                    ]);
                }
            }

            // --- 2. stale installment schedule cleanup (independent of the
            //        premium fix). The schedule was generated BEFORE the premium
            //        was corrected, so it often holds a single bogus row (e.g.
            //        one installment of ฿339.12) whose sum != the annual premium.
            //        PremiumDue prefers sum(amount_due) when a schedule exists, so
            //        a stale schedule reintroduces the false ชำระเกิน even after
            //        the premium is right. These policies are fully paid and the
            //        payments are the record — remove the stale schedule so `due`
            //        falls back to the (now-correct) annual premium. ---
            $schedTotal = (float) (DB::table('policy_installments')
                ->where('policy_id', $p->id)->sum('amount_due'));
            $schedRows = (int) DB::table('policy_installments')->where('policy_id', $p->id)->count();
            // a schedule whose total diverges from the annual premium is stale;
            // so is ANY single-row schedule on a multi-installment term (a real
            // N-installment plan can never have one row). Both drive a false
            // ชำระเกิน via PremiumDue's sum(amount_due) branch.
            if ($schedRows > 0 && (abs($schedTotal - $annualGross) > 2.0 || ($schedRows === 1 && $term > 1))) {
                $staleSchedules++;
                if (! $dry) {
                    DB::table('policy_installments')->where('policy_id', $p->id)->delete();
                }
            }
        }

        $this->table(['policy_id', 'term', 'per_installment', 'main_premium old→new'], $samples);
        $this->info(($dry ? '[dry-run] would fix ' : 'fixed ').$fixed.' installment premiums; '
            .$yearlyFixed.' yearly-renewal relabels; '
            .($dry ? 'would clear ' : 'cleared ').$staleSchedules.' stale schedules; '
            .$skipped.' skipped.');

        return self::SUCCESS;
    }
}
