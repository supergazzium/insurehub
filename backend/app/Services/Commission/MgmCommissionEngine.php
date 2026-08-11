<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\CommissionLedger;
use App\Models\CommissionTierRankRate;
use App\Models\Policy;
use App\Models\PolicyPayment;
use Illuminate\Support\Facades\DB;

/**
 * Core commission accrual engine. Called by PolicyPaymentObserver AFTER
 * VolumeAccumulator + RankPromotionService have run.
 *
 * PR-D (this file): dispatcher + DIRECT_COMMISSION only.
 * PR-E: REFERRAL_FEE (1% to seller's direct upline).
 * PR-F: MANAGEMENT_DIFFERENTIAL (walk chain with max_passed algorithm).
 *
 * DIRECT_COMMISSION math:
 *
 *   base_rate     = carrier×product_type matrix (non-life) or
 *                   product_life_rates (Life, PR-H stub)
 *   mgmt_fee      = commission_tier_rank_rates for (product_type.tier,
 *                   seller.rank)
 *   direct_rate   = base_rate + mgmt_fee
 *   direct_amount = policy_payments.amount × direct_rate
 *
 * One ledger row per (payment, DIRECT_COMMISSION, seller). Idempotency
 * key locks out double-accrual on replays. Rate columns are snapshotted
 * so tier / matrix edits don't rewrite history.
 */
class MgmCommissionEngine
{
    public function __construct(
        private readonly NonLifeRateResolver $nonLifeResolver,
        private readonly LifeRateResolver $lifeResolver,
    ) {}

    /**
     * Accrue commission rows for one payment. Idempotent — safe to call
     * multiple times.
     *
     * @return list<CommissionLedger> Rows created on THIS call (empty
     *                                if already accrued or if the policy can't yield a rate)
     */
    public function accrueForPayment(PolicyPayment $payment): array
    {
        $policy = $payment->policy()->with(['product', 'carrier'])->first();
        if ($policy === null || $policy->writing_agent_id === null) {
            return [];
        }

        $basePremium = (float) $payment->amount;
        if ($basePremium <= 0) {
            return [];
        }

        $seller = Agent::query()->find($policy->writing_agent_id);
        if ($seller === null) {
            return [];
        }

        $baseRate = $this->resolveBaseRate($policy, $payment);
        if ($baseRate === null) {
            // No matrix cell / no life-rate row — surface via nullable
            // standard_rate flow. Nothing to accrue.
            return [];
        }

        $created = [];

        // Step 1: DIRECT_COMMISSION for the seller.
        $direct = $this->accrueDirectCommission($policy, $payment, $seller, $basePremium, $baseRate);
        if ($direct !== null) {
            $created[] = $direct;
        }

        // Steps 2 (REFERRAL_FEE) + 3 (MANAGEMENT_DIFFERENTIAL) land in
        // PR-E and PR-F respectively. This skeleton stops at DIRECT.

        return $created;
    }

    /**
     * Dispatch based on insure_type: Life uses PR #5 tables (stubbed until
     * PR-H), non-life uses the carrier×product_type matrix.
     */
    private function resolveBaseRate(Policy $policy, PolicyPayment $payment): ?BaseRate
    {
        $insureType = strtolower((string) ($policy->carrier?->insure_type ?? ''));
        if ($insureType === 'life') {
            return $this->lifeResolver->resolve($policy, $payment);
        }

        return $this->nonLifeResolver->resolve($policy, $payment);
    }

    /**
     * Write the seller's DIRECT_COMMISSION row (or return the existing
     * row if idempotency key matches). Combines the standard rate + the
     * seller's own mgmt fee — matches Excel Sheet2's "ค่าคอมถ้าขายเองของ
     * แต่ละชั้น" column (seller keeps base + their own rank's mgmt fee).
     */
    private function accrueDirectCommission(
        Policy $policy,
        PolicyPayment $payment,
        Agent $seller,
        float $basePremium,
        BaseRate $baseRate,
    ): ?CommissionLedger {
        // Look up seller's mgmt fee via product_type.tier × seller.rank.
        // If any part of that chain is missing (no product_type, no tier,
        // no rank), mgmt_fee defaults to 0 and DIRECT_COMMISSION is
        // just base_rate × premium.
        $mgmtFee = 0.0;
        $tierId = null;
        $rankId = $seller->rank_id;

        if ($policy->product?->product_type_id !== null && $seller->rank_id !== null) {
            $tierId = DB::table('product_types')
                ->where('id', $policy->product->product_type_id)
                ->value('tier_id');

            if ($tierId !== null) {
                $mgmtFee = (float) (CommissionTierRankRate::query()
                    ->where('tier_id', $tierId)
                    ->where('rank_id', $seller->rank_id)
                    ->value('mgmt_fee_rate') ?? 0);
            }
        }

        $directRate = $baseRate->rate + $mgmtFee;
        $amount = round($basePremium * $directRate, 2);

        return $this->upsertLedger(
            payoutType: CommissionLedger::TYPE_DIRECT_COMMISSION,
            beneficiaryAgentId: (int) $seller->id,
            policy: $policy,
            payment: $payment,
            sourceAgentId: (int) $seller->id,  // seller is source for their own DIRECT
            basePremium: $basePremium,
            rateApplied: $directRate,
            amount: $amount,
            standardRate: $baseRate->rate,
            mgmtFeeRate: $mgmtFee,
            tierId: $tierId !== null ? (int) $tierId : null,
            rankIdAtAccrual: $rankId !== null ? (int) $rankId : null,
        );
    }

    /**
     * Insert-or-return via idempotency_key. Central write path so PR-E and
     * PR-F can call the same helper for REFERRAL / DIFFERENTIAL rows.
     */
    private function upsertLedger(
        string $payoutType,
        int $beneficiaryAgentId,
        Policy $policy,
        PolicyPayment $payment,
        ?int $sourceAgentId,
        float $basePremium,
        float $rateApplied,
        float $amount,
        ?float $standardRate,
        ?float $mgmtFeeRate,
        ?int $tierId,
        ?int $rankIdAtAccrual,
    ): CommissionLedger {
        $key = "payment:{$payment->id}:{$payoutType}:{$beneficiaryAgentId}";

        // Fast path: already accrued (replay / retry).
        $existing = CommissionLedger::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        return CommissionLedger::create([
            'tenant_id' => $policy->tenant_id,
            'payout_type' => $payoutType,
            'beneficiary_agent_id' => $beneficiaryAgentId,
            'policy_id' => $policy->id,
            'policy_payment_id' => $payment->id,
            'source_agent_id' => $sourceAgentId,
            'base_premium' => $basePremium,
            'rate_applied' => $rateApplied,
            'amount' => $amount,
            'standard_rate' => $standardRate,
            'mgmt_fee_rate' => $mgmtFeeRate,
            'tier_id' => $tierId,
            'rank_id_at_accrual' => $rankIdAtAccrual,
            'payer_source' => CommissionLedger::PAYER_INSURER,
            'idempotency_key' => $key,
            'status' => CommissionLedger::STATUS_UNSETTLED,
        ]);
    }
}
