<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionLedger;
use App\Models\Policy;
use App\Models\PolicyPayment;

class CommissionDeductedAtSource
{
    public function record(Policy $policy, PolicyPayment $payment): void
    {
        $amount = round((float) $payment->adjustment_amount, 2);
        if ($payment->adjustment_kind !== PolicyPayment::ADJ_COMMISSION_DEDUCTED || $amount <= 0) {
            return;
        }
        $agentId = $policy->writing_agent_id;
        if ($agentId === null) {
            return;
        }

        $key = "commission-deducted:payment:{$payment->id}";
        if (CommissionLedger::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        CommissionLedger::create([
            'tenant_id' => $policy->tenant_id,
            'payout_type' => CommissionLedger::TYPE_COMMISSION_PAID_AT_SOURCE,
            'beneficiary_agent_id' => $agentId,
            'policy_id' => $policy->id,
            'policy_payment_id' => $payment->id,
            'source_agent_id' => $agentId,
            'base_premium' => (float) $payment->volumeAmount(),
            'rate_applied' => 0,
            'amount' => -1 * $amount,
            'standard_rate' => null,
            'mgmt_fee_rate' => null,
            'tier_id' => null,
            'rank_id_at_accrual' => $policy->rank_id !== null ? (int) $policy->rank_id : null,
            'payer_source' => CommissionLedger::PAYER_AGENT,
            'idempotency_key' => $key,
            'status' => CommissionLedger::STATUS_UNSETTLED,
        ]);
    }

    public function hasLockedRows(PolicyPayment $payment): bool
    {
        return CommissionLedger::query()
            ->where('policy_payment_id', $payment->id)
            ->where('payout_type', '!=', CommissionLedger::TYPE_INSTALLMENT_AGENT_COST)
            ->where(fn ($q) => $q->whereNotNull('payout_batch_id')->orWhere('status', CommissionLedger::STATUS_SETTLED))
            ->exists();
    }

    public function reverseForPayment(PolicyPayment $payment): int
    {
        return CommissionLedger::query()
            ->where('policy_payment_id', $payment->id)
            ->where('payout_type', '!=', CommissionLedger::TYPE_INSTALLMENT_AGENT_COST)
            ->where('status', CommissionLedger::STATUS_UNSETTLED)
            ->update(['status' => CommissionLedger::STATUS_REVERSED]);
    }
}
