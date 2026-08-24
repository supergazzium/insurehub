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
 * C-20 — SNAPSHOT PREFERENCE: when the policy carries a commission_snapshot
 * (frozen at create time by PolicyObserver), the bands AND the rate-row
 * fallback are read from that frozen copy instead of the live product tables.
 * This makes the whole life-rate basis immutable to later product edits.
 * Policies without a snapshot (all legacy rows) resolve live as before.
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
        // C-22: a per-policy per-YEAR override vector wins first (life).
        $vec = is_array($policy->comm_override) ? ($policy->comm_override['hubToAgent'] ?? null) : null;
        if (is_array($vec)) {
            $col = CommissionSnapshot::overrideYearColumn(max(1, (int) $policy->policy_year));
            if (isset($vec[$col]) && $vec[$col] !== null) {
                return new BaseRate(
                    rate: (float) $vec[$col],
                    source: "policy_override_vector:hub_to_agent:{$col}",
                );
            }
        }

        // C-21: a single-scalar per-policy override wins over product/snapshot.
        if ($policy->comm_hub_to_agent_rate !== null) {
            return new BaseRate(
                rate: (float) $policy->comm_hub_to_agent_rate,
                source: 'policy_override:hub_to_agent',
            );
        }

        $product = $policy->product;
        $snapshot = CommissionSnapshot::fromPolicy($policy);

        // Live path still needs a product; snapshot path is self-contained.
        if ($product === null && $snapshot === null) {
            return null;
        }

        $policyYear = max(1, (int) $policy->policy_year);
        $sumAssured = (float) $policy->coverage;
        $entryAge = $this->deriveEntryAge($policy);

        // 1. Banded lookup — from snapshot when present, else live.
        $bands = $this->bands($policy, $snapshot);

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
                source: $this->bandSource($band, $column),
            );
        }

        // 2. Fallback to single-row life_years rate — from snapshot when present.
        $rateRow = $this->rateRow($policy, $snapshot);

        if ($rateRow === null) {
            Log::info('LifeRateResolver: no bands, no product_commission_rates row', [
                'policy_id' => $policy->id,
                'product_id' => $policy->product_id,
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
                source: $this->rateSource($rateRow, $column),
            );
        }

        // 3. Flat fallback — product classified as Life but rate row is flat.
        if ($rateRow->flat_rate !== null) {
            return new BaseRate(
                rate: (float) $rateRow->flat_rate,
                source: $this->rateSource($rateRow, null),
            );
        }

        return null;
    }

    /**
     * hub_to_agent bands — frozen snapshot bands when present, else live,
     * ordered by band_seq.
     *
     * @return list<ProductCommissionBand>
     */
    private function bands(Policy $policy, ?CommissionSnapshot $snapshot): array
    {
        if ($snapshot !== null) {
            return $snapshot->bands(ProductCommissionBand::DIRECTION_HUB_TO_AGENT);
        }

        return ProductCommissionBand::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $policy->product_id)
            ->where('direction', ProductCommissionBand::DIRECTION_HUB_TO_AGENT)
            ->orderBy('band_seq')
            ->get()
            ->all();
    }

    /**
     * hub_to_agent rate-row fallback — frozen snapshot row when present, else
     * the live newest-effective row.
     */
    private function rateRow(Policy $policy, ?CommissionSnapshot $snapshot): ?ProductCommissionRate
    {
        if ($snapshot !== null) {
            return $snapshot->rateRow(ProductCommissionRate::DIRECTION_HUB_TO_AGENT);
        }

        return ProductCommissionRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $policy->product_id)
            ->where('direction', ProductCommissionRate::DIRECTION_HUB_TO_AGENT)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function bandSource(ProductCommissionBand $band, string $column): string
    {
        return $band->id === null
            ? "commission_snapshot:band:{$column}"
            : "product_commission_bands:{$band->id}:{$column}";
    }

    private function rateSource(ProductCommissionRate $row, ?string $column): string
    {
        if ($row->id === null) {
            return $column === null
                ? 'commission_snapshot:hub_to_agent'
                : "commission_snapshot:hub_to_agent:{$column}";
        }

        return $column === null
            ? "product_commission_rates:{$row->id}"
            : "product_commission_rates:{$row->id}:{$column}";
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
