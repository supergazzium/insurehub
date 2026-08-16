<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CarrierProductTypeRate;
use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\ProductCommissionRate;

/**
 * Resolves standard commission rate for non-life policies. As of the
 * per-product commission rewrite, the primary source is
 * product_commission_rates(direction='hub_to_agent', scheme='flat'), which
 * is edited on the Product form. Matrix (carrier_product_type_rates)
 * remains as a fallback so unpopulated products keep accruing at the old
 * rate — soft cutover; the matrix admin page is now read-only.
 *
 * Resolution order:
 *   1. policy → product → product_commission_rates row for hub_to_agent.
 *      If found AND scheme=flat AND flat_rate is non-null → use it.
 *   2. Fall back to carrier_product_type_rates matrix cell for
 *      (policy.carrier_id, product.product_type_id).
 *   3. Both missing → null (engine skips DIRECT accrual).
 *
 * The `source` field on the returned BaseRate identifies which layer
 * answered so the ledger snapshot preserves provenance.
 */
class NonLifeRateResolver implements BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        $product = $policy->product;
        if ($product === null) {
            return null;
        }

        // Primary — per-product row.
        $row = ProductCommissionRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $product->id)
            ->where('direction', ProductCommissionRate::DIRECTION_HUB_TO_AGENT)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row !== null && $row->scheme === ProductCommissionRate::SCHEME_FLAT && $row->flat_rate !== null) {
            return new BaseRate(
                rate: (float) $row->flat_rate,
                source: "product_commission_rates:{$row->id}",
            );
        }

        // Fallback — matrix cell. Only meaningful when product_type_id is set.
        if ($product->product_type_id === null) {
            return null;
        }

        $matrix = CarrierProductTypeRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('carrier_id', $policy->carrier_id)
            ->where('product_type_id', $product->product_type_id)
            ->orderByDesc('id')
            ->first();

        if ($matrix === null || $matrix->standard_rate === null) {
            return null;
        }

        return new BaseRate(
            rate: (float) $matrix->standard_rate,
            source: "carrier_product_type_rates:{$matrix->id}",
        );
    }
}
