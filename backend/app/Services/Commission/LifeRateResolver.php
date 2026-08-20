<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\ProductCommissionBand;
use App\Models\ProductCommissionRate;
use Illuminate\Support\Facades\Log;

/**
 * Resolves standard commission rate for Life policies from
 * product_commission_bands. Each band is a (sum-assured range, entry-age
 * range) window; the resolver picks the band matching the policy's SA and
 * insured entry age, then reads the yr_{policy_year} column.
 *
 * Resolution order:
 *   1. Find bands for (product, direction='hub_to_agent').
 *   2. Filter to those whose SA and entry-age ranges cover the payment context.
 *   3. Use the first (lowest band_seq) matching band's yr column.
 *
 * Fallbacks (in order) when bands are absent or none match:
 *   a. product_commission_rates scheme='life_years' (single row).
 *   b. product_commission_rates scheme='flat' flat_rate.
 *   c. null — engine skips DIRECT accrual.
 */
class LifeRateResolver implements BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        $product = $policy->product;
        if ($product === null) {
            return null;
        }

        $policyYear = max(1, (int) $policy->policy_year);
        $sumAssured = (float) $policy->coverage;
        $entryAge = $this->deriveEntryAge($policy);

        // 1. Banded lookup.
        $bands = ProductCommissionBand::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $product->id)
            ->where('direction', ProductCommissionBand::DIRECTION_HUB_TO_AGENT)
            ->orderBy('band_seq')
            ->get();

        foreach ($bands as $band) {
            if (! $band->matches($sumAssured, $entryAge)) {
                continue;
            }
            $column = ProductCommissionBand::yearColumn($policyYear);
            $rate = $band->{$column};
            if ($rate === null) {
                continue;
            }

            return new BaseRate(
                rate: (float) $rate,
                source: "product_commission_bands:{$band->id}:{$column}",
            );
        }

        // 2. Fallback to single-row life_years rate.
        $rateRow = ProductCommissionRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $product->id)
            ->where('direction', ProductCommissionRate::DIRECTION_HUB_TO_AGENT)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($rateRow === null) {
            Log::info('LifeRateResolver: no bands, no product_commission_rates row', [
                'policy_id' => $policy->id,
                'product_id' => $product->id,
            ]);

            return null;
        }

        if ($rateRow->scheme === ProductCommissionRate::SCHEME_LIFE_YEARS) {
            $column = ProductCommissionRate::lifeYearColumn($policyYear);
            $rate = $rateRow->{$column};
            if ($rate === null) {
                return null;
            }

            return new BaseRate(
                rate: (float) $rate,
                source: "product_commission_rates:{$rateRow->id}:{$column}",
            );
        }

        // 3. Flat fallback — product classified as Life but rate row is flat.
        if ($rateRow->flat_rate !== null) {
            return new BaseRate(
                rate: (float) $rateRow->flat_rate,
                source: "product_commission_rates:{$rateRow->id}",
            );
        }

        return null;
    }

    /**
     * Insured's entry age = year(policy.effective_date) - year(birth_date).
     * Returns null when either date is missing — resolver treats that as
     * "no age-constrained bands match" (unbounded age bands still work).
     */
    private function deriveEntryAge(Policy $policy): ?int
    {
        $birth = $policy->insured_person_birth_date;
        $effective = $policy->effective_date;
        if ($birth === null || $effective === null) {
            return null;
        }

        return max(0, (int) $birth->diffInYears($effective));
    }
}
