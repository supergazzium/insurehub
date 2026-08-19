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
 * Three payout types produced per payment:
 *
 * 1. DIRECT_COMMISSION (PR-D)
 *    → one row for the seller.
 *    → amount = premium × (base_rate + seller_mgmt_fee)
 *
 * 2. REFERRAL_FEE (PR-E, this file)
 *    → one row for the seller's DIRECT upline (agents.parent_agent_id).
 *      Skips inactive / deleted parents by walking upward to the first
 *      live agent (matches your worked example where 1st Lv5 got both
 *      REFERRAL and MGMT_DIFFERENTIAL).
 *    → rate = product_type.tier.referral_fee_rate (TIER_FULL 1%,
 *      TIER_PARTIAL / TIER_DIRECT_ONLY 0%)
 *    → skipped entirely if the seller has no upline or the tier's
 *      referral rate is 0 (no zero-amount rows written for referral;
 *      zero-amount rows are only produced by the differential walk
 *      per user instruction).
 *
 * 3. MANAGEMENT_DIFFERENTIAL (PR-F, this file)
 *    → walk the upline chain from seller's direct upline upward.
 *      max_passed starts at seller_mgmt_fee.
 *      For each upline:
 *          diff = max(0, upline_mgmt_fee - max_passed)
 *          max_passed = max(max_passed, upline_mgmt_fee)
 *      One row per upline. ZERO-AMOUNT ROWS ARE WRITTEN for audit
 *      (user chose "write for audit" over "skip zeros").
 *    → skips inactive uplines but continues walking upward.
 *    → source_agent_id = seller (the sale that generated this payout).
 *
 * BREAKOFF_OVERRIDE deferred indefinitely per user (spec incomplete).
 *
 * Every row is snapshotted (rate_applied, standard_rate, mgmt_fee_rate,
 * tier_id, rank_id_at_accrual) so future tier/matrix edits don't rewrite
 * history. Idempotency key locks out double-accrual on replays.
 */
class MgmCommissionEngine
{
    private const MAX_CHAIN_DEPTH = 20;

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

        // One tier lookup fuels all three flows.
        [$tierId, $referralRate] = $this->resolveTierAndReferralRate($policy);
        $sellerMgmtFee = $this->resolveMgmtFeeForAgent($tierId, $seller->rank_id);

        $created = [];

        // 1. DIRECT_COMMISSION — seller keeps base + own mgmt fee.
        $created[] = $this->accrueDirectCommission(
            $policy, $payment, $seller, $basePremium, $baseRate, $tierId, $sellerMgmtFee,
        );

        // 2. REFERRAL_FEE — 1% (or 0% for reduced tiers) to seller's
        // direct live upline. Zero-rate tiers skip entirely.
        if ($referralRate > 0) {
            $referralUpline = $this->firstLiveUpline((int) $seller->id);
            if ($referralUpline !== null) {
                $created[] = $this->accrueReferralFee(
                    $policy, $payment, $seller, $referralUpline, $basePremium, $referralRate, $tierId,
                );
            }
        }

        // 3. MANAGEMENT_DIFFERENTIAL — walk chain from seller's direct
        // upline upward. max_passed accumulates.
        foreach ($this->accrueManagementDifferential(
            $policy, $payment, $seller, $basePremium, $tierId, $sellerMgmtFee,
        ) as $row) {
            $created[] = $row;
        }

        return array_values(array_filter($created));
    }

    // ── Rate resolvers ────────────────────────────────────────────────────

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
     * Look up the tier for a policy and its referral rate.
     *
     * Tier resolution order:
     *   1. products.commission_tier_id — the operator-set "ระดับค่าคอม"
     *      on the product form.
     *   2. Fallback: product_types.tier_id via the product's product_type_id.
     *      Retained for rollout safety; legacy products that had a
     *      product_type_id but were never re-saved through the new form
     *      keep working.
     *
     * @return array{0: int|null, 1: float} [tier_id, referral_fee_rate].
     *                                      referral_fee_rate defaults to 0 when tier missing.
     */
    private function resolveTierAndReferralRate(Policy $policy): array
    {
        $tierId = $policy->product?->commission_tier_id !== null
            ? (int) $policy->product->commission_tier_id
            : null;

        if ($tierId === null) {
            $productTypeId = $policy->product?->product_type_id;
            if ($productTypeId === null) {
                return [null, 0.0];
            }

            $tierId = DB::table('product_types')
                ->where('id', $productTypeId)
                ->value('tier_id');
            if ($tierId === null) {
                return [null, 0.0];
            }
            $tierId = (int) $tierId;
        }

        // Referral rate lives on the tier×rank grid. It's tier-scoped
        // (typically constant across all rank rows within a tier), so
        // we look up against any rank row — take level 1 which always
        // exists per RankSeeder.
        $rate = (float) (DB::table('commission_tier_rank_rates as ctrr')
            ->join('ranks as r', 'r.id', '=', 'ctrr.rank_id')
            ->where('ctrr.tier_id', $tierId)
            ->where('r.level', 1)
            ->value('ctrr.referral_fee_rate') ?? 0);

        return [(int) $tierId, $rate];
    }

    /**
     * Mgmt fee for a specific agent in a specific tier. Returns 0 when
     * any part of the lookup chain is missing (no tier, no rank, or no
     * row in commission_tier_rank_rates).
     */
    private function resolveMgmtFeeForAgent(?int $tierId, ?int $rankId): float
    {
        if ($tierId === null || $rankId === null) {
            return 0.0;
        }

        return (float) (CommissionTierRankRate::query()
            ->where('tier_id', $tierId)
            ->where('rank_id', $rankId)
            ->value('mgmt_fee_rate') ?? 0);
    }

    // ── Accrual writers ───────────────────────────────────────────────────

    /**
     * Write the seller's DIRECT_COMMISSION row. Combines standard rate +
     * seller's own mgmt fee — matches Excel Sheet2's "ค่าคอมถ้าขายเอง
     * ของแต่ละชั้น" column.
     */
    private function accrueDirectCommission(
        Policy $policy,
        PolicyPayment $payment,
        Agent $seller,
        float $basePremium,
        BaseRate $baseRate,
        ?int $tierId,
        float $sellerMgmtFee,
    ): CommissionLedger {
        $directRate = $baseRate->rate + $sellerMgmtFee;
        $amount = round($basePremium * $directRate, 2);

        return $this->upsertLedger(
            payoutType: CommissionLedger::TYPE_DIRECT_COMMISSION,
            beneficiaryAgentId: (int) $seller->id,
            policy: $policy,
            payment: $payment,
            sourceAgentId: (int) $seller->id,
            basePremium: $basePremium,
            rateApplied: $directRate,
            amount: $amount,
            standardRate: $baseRate->rate,
            mgmtFeeRate: $sellerMgmtFee,
            tierId: $tierId,
            rankIdAtAccrual: $seller->rank_id !== null ? (int) $seller->rank_id : null,
        );
    }

    /**
     * REFERRAL_FEE: `premium × referral_rate` paid to the seller's first
     * live upline. Standard rate is the base commission (informational
     * only — the rate_applied that drives amount is the referral_rate).
     */
    private function accrueReferralFee(
        Policy $policy,
        PolicyPayment $payment,
        Agent $seller,
        Agent $upline,
        float $basePremium,
        float $referralRate,
        ?int $tierId,
    ): CommissionLedger {
        $amount = round($basePremium * $referralRate, 2);

        return $this->upsertLedger(
            payoutType: CommissionLedger::TYPE_REFERRAL_FEE,
            beneficiaryAgentId: (int) $upline->id,
            policy: $policy,
            payment: $payment,
            sourceAgentId: (int) $seller->id,
            basePremium: $basePremium,
            rateApplied: $referralRate,
            amount: $amount,
            standardRate: null,   // referral is not derived from standard
            mgmtFeeRate: null,    // referral is not a mgmt fee
            tierId: $tierId,
            rankIdAtAccrual: $upline->rank_id !== null ? (int) $upline->rank_id : null,
        );
    }

    /**
     * MANAGEMENT_DIFFERENTIAL: walk the upline chain from seller's direct
     * upline upward. For each upline, write a row with
     *   diff_rate = max(0, upline_mgmt_fee - max_passed)
     * where max_passed accumulates the highest mgmt_fee seen so far
     * (starting at seller_mgmt_fee).
     *
     * Zero-amount rows ARE written per user's explicit choice ("write
     * for audit"). Deleted/inactive uplines are skipped but the walk
     * continues upward — commissions still flow past a departed link.
     *
     * @return list<CommissionLedger>
     */
    private function accrueManagementDifferential(
        Policy $policy,
        PolicyPayment $payment,
        Agent $seller,
        float $basePremium,
        ?int $tierId,
        float $sellerMgmtFee,
    ): array {
        $created = [];
        $maxPassed = $sellerMgmtFee;
        $depth = 0;
        $seen = [(int) $seller->id => true];

        $currentParentId = $seller->parent_agent_id !== null ? (int) $seller->parent_agent_id : null;

        while ($currentParentId !== null && $depth < self::MAX_CHAIN_DEPTH) {
            if (isset($seen[$currentParentId])) {
                break; // cycle guard
            }
            $seen[$currentParentId] = true;

            $upline = Agent::query()->find($currentParentId);
            if ($upline === null) {
                break;
            }

            // Skip inactive/deleted uplines but continue walking upward.
            $isLive = $upline->deleted_at === null && (bool) $upline->active;
            if (! $isLive) {
                $currentParentId = $upline->parent_agent_id !== null ? (int) $upline->parent_agent_id : null;
                $depth++;

                continue;
            }

            $uplineMgmtFee = $this->resolveMgmtFeeForAgent($tierId, $upline->rank_id);
            $diffRate = max(0, $uplineMgmtFee - $maxPassed);
            $amount = round($basePremium * $diffRate, 2);

            $created[] = $this->upsertLedger(
                payoutType: CommissionLedger::TYPE_MANAGEMENT_DIFFERENTIAL,
                beneficiaryAgentId: (int) $upline->id,
                policy: $policy,
                payment: $payment,
                sourceAgentId: (int) $seller->id,
                basePremium: $basePremium,
                rateApplied: $diffRate,
                amount: $amount,
                standardRate: null,
                mgmtFeeRate: $uplineMgmtFee,
                tierId: $tierId,
                rankIdAtAccrual: $upline->rank_id !== null ? (int) $upline->rank_id : null,
            );

            // Update max_passed AFTER we've written this upline's row.
            if ($uplineMgmtFee > $maxPassed) {
                $maxPassed = $uplineMgmtFee;
            }

            $currentParentId = $upline->parent_agent_id !== null ? (int) $upline->parent_agent_id : null;
            $depth++;
        }

        return $created;
    }

    // ── Upline helpers ────────────────────────────────────────────────────

    /**
     * Walk up from an agent skipping inactive/deleted links; return the
     * first live upline (or null if none). Used for REFERRAL_FEE, whose
     * beneficiary rule per Excel Sheet2 rule 5 is "the seller's team
     * leader" — i.e. the nearest live person above.
     */
    private function firstLiveUpline(int $agentId): ?Agent
    {
        $seen = [$agentId => true];
        $depth = 0;
        $currentParentId = Agent::query()->where('id', $agentId)->value('parent_agent_id');

        while ($currentParentId !== null && $depth < self::MAX_CHAIN_DEPTH) {
            $currentParentId = (int) $currentParentId;
            if (isset($seen[$currentParentId])) {
                return null; // cycle
            }
            $seen[$currentParentId] = true;

            $agent = Agent::query()->find($currentParentId);
            if ($agent === null) {
                return null;
            }
            if ($agent->deleted_at === null && (bool) $agent->active) {
                return $agent;
            }

            $currentParentId = $agent->parent_agent_id;
            $depth++;
        }

        return null;
    }

    // ── Ledger writer ─────────────────────────────────────────────────────

    /**
     * Insert-or-return via idempotency_key. Central write path for all
     * three payout types.
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
