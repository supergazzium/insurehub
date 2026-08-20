<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PolicyPayment;
use App\Services\Commission\MgmCommissionEngine;
use App\Services\Commission\RankPromotionService;
use App\Services\Commission\VolumeAccumulator;
use Illuminate\Support\Facades\Log;

/**
 * Fires the MGM pipeline on every new payment.
 *
 * Order is load-bearing:
 *   1. VolumeAccumulator (PR-B) writes the seller's + uplines' rows for
 *      the payment's month.
 *   2. RankPromotionService (PR-C) walks the same chain and evaluates
 *      each agent against the fresh volumes. Any qualifying promotion
 *      is instant.
 *   3. MgmCommissionEngine (this PR) reads the (possibly just-promoted)
 *      rank_id to compute mgmt fees, then writes commission_ledgers
 *      rows for DIRECT_COMMISSION (PR-D), REFERRAL_FEE (PR-E),
 *      MANAGEMENT_DIFFERENTIAL (PR-F).
 *
 * Failure isolation: each stage is independently try/catch'd. A payment
 * must succeed even if the whole pipeline crashes. Later stages skip
 * when earlier stages fail (don't commission against stale volumes).
 * Nightly reconciliation (`mgm:reconcile-volumes`) catches missed
 * volume + promotion; commission recompute is a separate future
 * operation (per-policy `recomputeForPolicy`, not built yet).
 */
class PolicyPaymentObserver
{
    public function __construct(
        private readonly VolumeAccumulator $accumulator,
        private readonly RankPromotionService $promotion,
        private readonly MgmCommissionEngine $engine,
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

            return;  // don't evaluate promotion / commission against stale volumes
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
            // Continue to commission — a missed promotion just means the
            // seller earns at their previous rank's mgmt fee this payment.
            // The next payment (or the nightly reconciliation) fixes rank;
            // ledger rows for this payment will look consistent with the
            // rank_id at the time of accrual.
        }

        try {
            $this->engine->accrueForPayment($payment);
        } catch (\Throwable $e) {
            Log::error('MGM commission accrual failed on payment.created', [
                'payment_id' => $payment->id,
                'policy_id' => $payment->policy_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
