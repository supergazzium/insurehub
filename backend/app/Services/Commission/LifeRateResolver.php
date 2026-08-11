<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Policy;
use App\Models\PolicyPayment;
use Illuminate\Support\Facades\Log;

/**
 * STUB implementation for Life policies. The real Life rate source is
 * PR #5's product_life_rate_dimensions + product_life_rates tables,
 * which are on the `feat/product-life-matrix` branch and NOT yet
 * merged to main / this branch.
 *
 * Until PR #5 merges, this resolver logs a warning and returns null,
 * meaning Life policies get no DIRECT_COMMISSION accrual. When PR #5
 * merges, this class gets rewritten to actually walk the age×sum×year
 * dimensions and return the correct rate. That's PR-H in the plan.
 */
class LifeRateResolver implements BaseRateResolver
{
    public function resolve(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        Log::warning('LifeRateResolver: PR #5 not merged yet — Life commission accrual skipped', [
            'policy_id' => $policy->id,
            'payment_id' => $payment->id,
        ]);

        return null;
    }
}
