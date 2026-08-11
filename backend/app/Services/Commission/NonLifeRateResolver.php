<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CarrierProductTypeRate;
use App\Models\Policy;
use App\Models\PolicyPayment;

/**
 * Resolves standard commission rate for non-life policies from the
 * (carrier × product_type) matrix (PR-A4).
 *
 * Rate resolution:
 *   1. policy → product → product_type_id (must be set; PR-A3)
 *   2. (policy.carrier_id, product.product_type_id) → row in
 *      carrier_product_type_rates
 *   3. row.standard_rate is the answer (nullable = "carrier doesn't
 *      sell this type")
 *
 * Returns null when any step fails, which the engine treats as "no
 * DIRECT_COMMISSION accrual for this payment". Missing product_type
 * is a data-classification gap (admin should assign it via PR-A3's
 * admin UI); missing matrix cell is a business setup gap (admin should
 * add the rate via PR-A4's admin UI).
 *
 * Effective dating: currently ignores valid_start / valid_end (schema
 * has them but revision workflow is deferred). Reads any row for
 * (tenant, carrier, product_type); if multiple exist, take the highest
 * id (most recently created).
 */
class NonLifeRateResolver implements BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        $product = $policy->product;
        if ($product === null || $product->product_type_id === null) {
            return null;
        }

        $row = CarrierProductTypeRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('carrier_id', $policy->carrier_id)
            ->where('product_type_id', $product->product_type_id)
            ->orderByDesc('id')
            ->first();

        if ($row === null || $row->standard_rate === null) {
            return null;
        }

        return new BaseRate(
            rate: (float) $row->standard_rate,
            source: "carrier_product_type_rates:{$row->id}",
        );
    }
}
