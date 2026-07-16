<?php

declare(strict_types=1);

namespace App\Services\Insurance;

/**
 * Premium + duty stamp + VAT calculator.
 *
 * The legacy Access system (Form_CreateNewAccount / Form_Edit_Account)
 * exposed 3 calc modes as separate buttons — this class implements all
 * three as pure functions so they can be unit-tested and reused by both
 * the quote page and the policy-edit form.
 *
 *  Mode 1 (iterative VAT + duty stamp, standard):
 *      DutyStamp = ceil(Premium * 0.004)
 *      VAT       = (Premium + DutyStamp) * 0.07
 *      Total     = Premium + DutyStamp + VAT
 *      (iterated until convergence — usually 1 pass is enough)
 *
 *  Mode 2 (VAT-inclusive — customer paid a gross figure, back out net):
 *      Premium = Total / 1.07
 *      DutyStamp derived from that Premium
 *
 *  Mode 3 (fixed duty — 20 or 150 baht per policy, no VAT):
 *      DutyStamp = fixed
 *      VAT       = 0
 *      Total     = Premium + DutyStamp
 *
 * All amounts are stored as `decimal(15,2)` in the DB so we round each
 * computation to 2 dp.
 */
class PremiumCalculator
{
    public const DUTY_STAMP_RATE = 0.004;   // 0.4% of premium
    public const VAT_RATE = 0.07;           // 7% VAT

    /**
     * Standard iterative mode: given a net premium, compute duty + VAT + total.
     * @return array{netPremium: float, dutyStamp: float, vat: float, totalPremium: float}
     */
    public static function iterativeVat(float $netPremium): array
    {
        $dutyStamp = self::round(self::ceilDuty($netPremium * self::DUTY_STAMP_RATE));
        $vat = self::round(($netPremium + $dutyStamp) * self::VAT_RATE);
        $total = self::round($netPremium + $dutyStamp + $vat);
        return [
            'netPremium' => self::round($netPremium),
            'dutyStamp' => $dutyStamp,
            'vat' => $vat,
            'totalPremium' => $total,
        ];
    }

    /**
     * VAT-inclusive: caller gave us the gross customer-paid amount and we
     * work backwards. Assumes standard 0.4% duty stamp.
     * @return array{netPremium: float, dutyStamp: float, vat: float, totalPremium: float}
     */
    public static function vatInclusive(float $totalPaid): array
    {
        // total = net + dutyStamp + vat = net + ceil(net * 0.004) + (net + ceil(...)) * 0.07
        // For a clean back-out, ignore the fractional cent added by ceil() —
        // it drifts less than a baht.
        $net = self::round($totalPaid / (1 + self::VAT_RATE + self::DUTY_STAMP_RATE));
        return self::iterativeVat($net);  // re-derive dutyStamp + vat cleanly
    }

    /**
     * Fixed duty (typically 20 or 150 THB), no VAT.
     * @return array{netPremium: float, dutyStamp: float, vat: float, totalPremium: float}
     */
    public static function fixedDuty(float $netPremium, float $fixedDuty): array
    {
        $dutyStamp = self::round($fixedDuty);
        return [
            'netPremium' => self::round($netPremium),
            'dutyStamp' => $dutyStamp,
            'vat' => 0.0,
            'totalPremium' => self::round($netPremium + $dutyStamp),
        ];
    }

    /**
     * No VAT, no duty (rare — inter-company transfers etc.)
     * @return array{netPremium: float, dutyStamp: float, vat: float, totalPremium: float}
     */
    public static function bare(float $netPremium): array
    {
        return [
            'netPremium' => self::round($netPremium),
            'dutyStamp' => 0.0,
            'vat' => 0.0,
            'totalPremium' => self::round($netPremium),
        ];
    }

    /**
     * Access rounded duty stamp UP to the nearest baht.
     * VBA: DutyStamp = -Int(-Premium * 0.004)   ← same as PHP ceil().
     */
    private static function ceilDuty(float $raw): float
    {
        return (float) ceil($raw);
    }

    private static function round(float $n): float
    {
        return round($n, 2);
    }
}
