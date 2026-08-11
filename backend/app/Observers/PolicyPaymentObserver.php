<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PolicyPayment;
use App\Services\Commission\RankPromotionService;
use App\Services\Commission\VolumeAccumulator;
use Illuminate\Support\Facades\Log;

/**
 * Fires the MGM volume accumulator + rank promotion evaluation on every
 * new payment.
 *
 * Order matters:
 *   1. VolumeAccumulator writes the seller's + uplines' rows for the
 *      payment's month.
 *   2. RankPromotionService walks the same chain and evaluates each
 *      agent against the fresh volumes. Any qualifying promotion is
 *      instant (Sheet2 rule 4).
 *
 * When PR-D lands, MgmCommissionEngine will fire AFTER both of these —
 * the engine reads the (possibly just-promoted) rank_id to compute
 * mgmt fees.
 *
 * Failures are logged but non-fatal — a payment must succeed even if
 * volume / promotion fail. Nightly reconciliation
 * (`mgm:reconcile-volumes`) catches missed volume updates; promotion
 * is re-evaluated on the next payment.
 */
class PolicyPaymentObserver
{
    public function __construct(
        private readonly VolumeAccumulator $accumulator,
        private readonly RankPromotionService $promotion,
    ) {}

    public function created(PolicyPayment $payment): void
    {
        try {
            $this->accumulator->accumulateForPayment($payment);
        } catch (\Throwable $e) {
            Log::error('MGM volume accumulation failed on payment.created', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);

            return;  // don't evaluate promotion against stale volumes
        }

        try {
            $policy = $payment->policy()->first();
            if ($policy?->writing_agent_id !== null) {
                $this->promotion->evaluateForChain((int) $policy->writing_agent_id);
            }
        } catch (\Throwable $e) {
            Log::error('MGM rank promotion failed on payment.created', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
