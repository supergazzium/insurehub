<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionLedger;
use App\Models\Policy;
use App\Models\PolicyPayment;
use Illuminate\Support\Facades\Log;

/**
 * Records the installment cost the AGENT bears (per the installment spec §10):
 *   Mode A → agent bears fee (main×2%) + interest (main×1%×งวด)
 *   Mode B → agent bears interest only
 *   Mode C → agent bears nothing
 *
 * Written to commission_ledgers as a NEGATIVE-amount row (payer = AGENT,
 * payout_type = INSTALLMENT_AGENT_COST) so commission settlement nets it
 * against the agent's payouts. Idempotent per payment batch — keyed on the
 * FIRST payment of the batch so it books exactly once even if งวด repay over
 * time. Decoupled from any promotion name (spec §10).
 */
class InstallmentAgentCost
{
    /**
     * @param  PolicyPayment  $firstPayment  the งวด-1 row of the batch (idempotency anchor)
     */
    public function record(
        Policy $policy,
        PolicyPayment $firstPayment,
        string $mode,
        float $agentFeeCost,
        float $agentInterestCost,
    ): void {
        $total = round($agentFeeCost + $agentInterestCost, 2);
        if ($total <= 0) {
            return; // Mode C, or nothing to book.
        }
        $agentId = $policy->writing_agent_id;
        if ($agentId === null) {
            return;
        }

        $key = "installment-agent-cost:payment:{$firstPayment->id}";
        if (CommissionLedger::query()->where('idempotency_key', $key)->exists()) {
            return; // already booked (replay/retry)
        }

        CommissionLedger::create([
            'tenant_id' => $policy->tenant_id,
            'payout_type' => CommissionLedger::TYPE_INSTALLMENT_AGENT_COST,
            'beneficiary_agent_id' => $agentId,
            'policy_id' => $policy->id,
            'policy_payment_id' => $firstPayment->id,
            'source_agent_id' => $agentId,
            'base_premium' => (float) ($policy->main_premium ?? 0),
            'rate_applied' => 0,
            // Negative — this is a cost the agent bears, not a payout.
            'amount' => -1 * $total,
            'standard_rate' => null,
            'mgmt_fee_rate' => null,
            'tier_id' => null,
            'rank_id_at_accrual' => $policy->rank_id !== null ? (int) $policy->rank_id : null,
            'payer_source' => CommissionLedger::PAYER_AGENT,
            'idempotency_key' => $key,
            'status' => CommissionLedger::STATUS_UNSETTLED,
        ]);

        Log::info('Installment agent cost booked', [
            'policy_id' => $policy->id,
            'agent_id' => $agentId,
            'mode' => $mode,
            'agent_fee_cost' => $agentFeeCost,
            'agent_interest_cost' => $agentInterestCost,
        ]);
    }
}
