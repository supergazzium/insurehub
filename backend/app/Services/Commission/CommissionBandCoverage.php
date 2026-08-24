<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\ProductCommissionBand;

/**
 * C-23: check whether a policy's insured age / sum-assured falls inside a
 * commission band that actually carries a rate — for the hub_to_agent
 * direction (the one that pays the selling agent).
 *
 * WHY: banded products (e.g. PDTOK0114) constrain commission bands by
 * sum-assured AND entry-age ranges. A policy outside every rated band resolves
 * to NO rate → the MGM engine silently skips the agent's DIRECT commission
 * (logs a warning only). This service surfaces that at policy creation so the
 * operator sees it — a warning on draft/save, a hard block on finalize.
 *
 * Only meaningful for products that HAVE hub_to_agent bands. Products with no
 * bands (flat, or single-row life_years) always "cover" — this returns
 * covered=true so the check is a no-op for them.
 */
final class CommissionBandCoverage
{
    /**
     * @return array{banded: bool, covered: bool, entryAge: ?int, sumAssured: float,
     *               reason: ?string}
     */
    public static function check(Policy $policy): array
    {
        $sumAssured = (float) $policy->coverage;
        $entryAge = self::entryAge($policy);

        $bands = ProductCommissionBand::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $policy->product_id)
            ->where('direction', ProductCommissionBand::DIRECTION_HUB_TO_AGENT)
            ->orderBy('band_seq')
            ->get();

        // Not a banded product — nothing to validate.
        if ($bands->isEmpty()) {
            return ['banded' => false, 'covered' => true, 'entryAge' => $entryAge, 'sumAssured' => $sumAssured, 'reason' => null];
        }

        foreach ($bands as $band) {
            if (! $band->matches($sumAssured, $entryAge)) {
                continue;
            }
            // A band matches — but only counts if it carries at least one rate
            // (an empty catch-all band still resolves to no commission).
            if (self::bandHasAnyRate($band)) {
                return ['banded' => true, 'covered' => true, 'entryAge' => $entryAge, 'sumAssured' => $sumAssured, 'reason' => null];
            }
        }

        // No rated band matched — pin down why for a helpful message.
        $reason = self::describeGap($bands, $sumAssured, $entryAge);

        return ['banded' => true, 'covered' => false, 'entryAge' => $entryAge, 'sumAssured' => $sumAssured, 'reason' => $reason];
    }

    private static function bandHasAnyRate(ProductCommissionBand $band): bool
    {
        foreach (['yr_1', 'yr_2', 'yr_3', 'yr_4', 'yr_5', 'yr_6_up'] as $col) {
            if ($band->{$col} !== null) {
                return true;
            }
        }

        return false;
    }

    /** Human-readable reason for the ops warning / error message (Thai). */
    private static function describeGap($bands, float $sumAssured, ?int $entryAge): string
    {
        // Report the tightest constraints across the RATED bands so the
        // operator knows the acceptable window.
        $ageMins = [];
        $ageMaxes = [];
        $saMins = [];
        $saMaxes = [];
        foreach ($bands as $band) {
            if (! self::bandHasAnyRate($band)) {
                continue;
            }
            if ($band->entry_age_min !== null) {
                $ageMins[] = (int) $band->entry_age_min;
            }
            if ($band->entry_age_max !== null) {
                $ageMaxes[] = (int) $band->entry_age_max;
            }
            if ($band->sum_assured_min !== null) {
                $saMins[] = (float) $band->sum_assured_min;
            }
            if ($band->sum_assured_max !== null) {
                $saMaxes[] = (float) $band->sum_assured_max;
            }
        }

        $parts = [];
        if ($entryAge === null && ($ageMins !== [] || $ageMaxes !== [])) {
            $parts[] = 'ไม่พบวันเกิดผู้เอาประกัน (ต้องระบุเพื่อคำนวณอายุแรกเข้า)';
        } elseif ($ageMins !== [] || $ageMaxes !== []) {
            $lo = $ageMins !== [] ? min($ageMins) : 0;
            $hi = $ageMaxes !== [] ? max($ageMaxes) : 999;
            if ($entryAge !== null && ($entryAge < $lo || $entryAge > $hi)) {
                $parts[] = "อายุแรกเข้า {$entryAge} ปี อยู่นอกช่วง {$lo}-{$hi} ปี";
            }
        }
        if ($saMins !== [] || $saMaxes !== []) {
            $lo = $saMins !== [] ? min($saMins) : 0;
            $hi = $saMaxes !== [] ? max($saMaxes) : null;
            if ($sumAssured < $lo || ($hi !== null && $sumAssured > $hi)) {
                $hiTxt = $hi !== null ? number_format($hi) : '∞';
                $parts[] = 'ทุนประกัน '.number_format($sumAssured)." อยู่นอกช่วง ".number_format($lo)."-{$hiTxt} บาท";
            }
        }

        if ($parts === []) {
            $parts[] = 'ไม่มีอัตราค่าคอมมิชชั่นที่ตรงกับกรมธรรม์นี้ (สินค้ายังไม่ได้ตั้งค่าอัตราครบ)';
        }

        return implode('; ', $parts);
    }

    private static function entryAge(Policy $policy): ?int
    {
        $birth = $policy->insured_person_birth_date;
        $effective = $policy->effective_date;
        if ($birth === null || $effective === null) {
            return null;
        }

        return max(0, (int) $birth->diffInYears($effective));
    }
}
