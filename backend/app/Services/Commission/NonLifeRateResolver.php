<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\PolicyPayment;
use App\Models\ProductCommissionRate;

/**
 * Resolves standard commission rate for non-life policies from
 * product_commission_rates(direction='hub_to_agent', scheme='flat'), which
 * is edited on the Product form.
 *
 * The carrier × product-type matrix (carrier_product_type_rates) used to
 * serve as a fallback here. It's now retired as a rate source — every
 * product must carry its own rate. The
 * 2027_02_09_000100_backfill_hub_to_agent_from_matrix.php migration
 * copied historical matrix values into hub_to_agent so existing products
 * don't lose their rate at cutover. The matrix table and its controller
 * survive for now (frozen 410 on writes) but the engine no longer reads
 * them; a follow-up PR will drop the table entirely.
 *
 * Missing hub_to_agent row → null → engine skips DIRECT accrual and logs
 * a warning; admin must set the rate on the product page.
 */
class NonLifeRateResolver implements BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        $product = $policy->product;
        if ($product === null) {
            return null;
        }

        $row = ProductCommissionRate::query()
            ->where('tenant_id', $policy->tenant_id)
            ->where('product_id', $product->id)
            ->where('direction', ProductCommissionRate::DIRECTION_HUB_TO_AGENT)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->scheme !== ProductCommissionRate::SCHEME_FLAT) {
            // Product classified as Non-Life but rate row is life_years —
            // shouldn't happen with today's ProductController, but bail
            // rather than silently reading a per-year rate as flat.
            return null;
        }

        if ($row->flat_rate === null) {
            return null;
        }

        return new BaseRate(
            rate: (float) $row->flat_rate,
            source: "product_commission_rates:{$row->id}",
        );
    }
}
