<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\PolicyPayment;

/**
 * Contract for looking up the standard commission rate for a policy payment.
 *
 * Two implementations dispatched by MgmCommissionEngine based on the
 * policy's carrier.insure_type:
 *   NonLifeRateResolver — reads carrier_product_type_rates matrix (PR-A4)
 *   LifeRateResolver    — reads product_life_rates (PR #5, kept open)
 *
 * Returns null when the rate can't be resolved (missing product_type,
 * missing matrix cell, missing life-rate row). The engine treats null
 * as "don't accrue DIRECT_COMMISSION" — not zero, missing. Ops surface
 * this as a warning in the ledger admin UI (future).
 */
interface BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate;
}
