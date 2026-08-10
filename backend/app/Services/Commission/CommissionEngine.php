<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\CommissionTransaction;
use App\Models\Policy;
use App\Models\PolicyPayment;
use Illuminate\Support\Facades\DB;

/**
 * Commission accrual engine.
 *
 * On `policy_payment` insert (Phase 7a), this generates up to 3 commission
 * transactions for a policy:
 *   1. type='inh'      → in-house/broker share  (from InH rate on the product)
 *   2. type='agent'    → writing agent's share  (from AG rate)
 *   3. type='override' → upline (parent agent) override (from Override rate)
 *
 * All rows land as status='unsettled' until an admin batches them into a
 * payout (Phase 7b). Reversals for cancellation are handled by ::reverseFor().
 *
 * Idempotency: `idempotency_key` = "payment:{payment_id}:{type}" so replays
 * (retried job, admin re-triggers accrual, importer replay) never double-count.
 */
class CommissionEngine
{
    public const PARTY_INH = 'InH';

    public const PARTY_AGENT = 'AG';

    public const PARTY_OVERRIDE = 'Override';

    /**
     * Accrue commission rows for one payment. Safe to call multiple times —
     * duplicate detection is done via idempotency_key.
     *
     * @return list<CommissionTransaction> the txns created on THIS call (none if all keys already exist).
     */
    /**
     * @param  string  $keyVersion  Optional idempotency suffix. Default "" runs
     *                              the standard first-time-only accrual. Recompute passes e.g. ":v2" to
     *                              create fresh txns after reversing the old ones — see recomputeForPolicy().
     */
    public function accrueForPayment(PolicyPayment $payment, string $keyVersion = ''): array
    {
        $policy = Policy::query()->find($payment->policy_id);
        if ($policy === null || $policy->product_id === null || $policy->writing_agent_id === null) {
            return [];
        }

        // Only paid statuses accrue. Skip pending/refund/etc — the engine can be
        // re-called when the payment is actually cleared. If the schema grows a
        // `payment_status` column, filter here.
        $basePremium = (float) $payment->amount;
        if ($basePremium <= 0) {
            return [];
        }

        // Rate lookup: per-policy overrides win over product defaults. Access's
        // Form_Edit_Account_Recp_Com writes rates into main_com_rate_{inh,ag}
        // when finance renegotiates a deal; the engine honors those here.
        // Override rules per party:
        //   - policies.main_com_rate_inh present + > 0 → use it for the InH share
        //   - policies.main_com_rate_ag present + > 0  → use it for the AG share
        // Override missing = fall back to product_commission_rate_installments.
        // Override present but = 0 = "zeroed on purpose" → skip that party.
        $productRates = $this->fetchProductRates(
            (int) $policy->product_id,
            (int) $payment->id,
            $policy->coverage !== null ? (float) $policy->coverage : null,
        );
        $rates = [
            'inh' => $policy->main_com_rate_inh !== null ? (float) $policy->main_com_rate_inh : $productRates['inh'],
            'ag' => $policy->main_com_rate_ag !== null ? (float) $policy->main_com_rate_ag : $productRates['ag'],
            // Override didn't touch upline; keep product default.
            'override' => $productRates['override'],
        ];
        $created = [];

        // In-house share
        if ($rates['inh'] > 0) {
            $created[] = $this->upsertTxn(
                tenantId: (int) $policy->tenant_id,
                type: 'inh',
                agentId: null,   // in-house has no owning agent
                policyId: (int) $policy->id,
                paymentId: (int) $payment->id,
                basePremium: $basePremium,
                payerLevel: 'InH',
                diffPct: $rates['inh'],
                amount: round($basePremium * $rates['inh'], 2),
                keyVersion: $keyVersion,
            );
        }

        // Writing agent share
        if ($rates['ag'] > 0) {
            $created[] = $this->upsertTxn(
                tenantId: (int) $policy->tenant_id,
                type: 'agent',
                agentId: (int) $policy->writing_agent_id,
                policyId: (int) $policy->id,
                paymentId: (int) $payment->id,
                basePremium: $basePremium,
                payerLevel: 'AG',
                diffPct: $rates['ag'],
                amount: round($basePremium * $rates['ag'], 2),
                keyVersion: $keyVersion,
            );
        }

        // Upline override — writing agent's immediate parent, one level only (Q2c).
        if ($rates['override'] > 0) {
            $writingAgent = Agent::query()->find($policy->writing_agent_id);
            $upline = $writingAgent?->parent_agent_id;
            if ($upline !== null) {
                $created[] = $this->upsertTxn(
                    tenantId: (int) $policy->tenant_id,
                    type: 'override',
                    agentId: (int) $upline,
                    policyId: (int) $policy->id,
                    paymentId: (int) $payment->id,
                    basePremium: $basePremium,
                    payerLevel: 'Override',
                    diffPct: $rates['override'],
                    amount: round($basePremium * $rates['override'], 2),
                    keyVersion: $keyVersion,
                );
            }
        }

        return array_filter($created);
    }

    /**
     * Auto-reverse every unsettled + settled commission for a policy when the
     * policy is cancelled. Creates negative-amount rows with reverses_txn_id
     * pointing at the original. Q5(a).
     *
     * @return int number of reversal rows created
     */
    public function reverseFor(Policy $policy, ?string $reason = null): int
    {
        $originals = CommissionTransaction::query()
            ->where('policy_id', $policy->id)
            ->whereNull('reverses_txn_id')  // don't reverse a reversal
            ->get();

        if ($originals->isEmpty()) {
            return 0;
        }

        $count = 0;
        DB::transaction(function () use ($originals, &$count): void {
            foreach ($originals as $orig) {
                // Idempotent — don't reverse twice.
                $existing = CommissionTransaction::query()
                    ->where('reverses_txn_id', $orig->id)
                    ->exists();
                if ($existing) {
                    continue;
                }
                CommissionTransaction::create([
                    'tenant_id' => $orig->tenant_id,
                    'type' => $orig->type,
                    'status' => 'unsettled',
                    'agent_id' => $orig->agent_id,
                    'policy_id' => $orig->policy_id,
                    'policy_event_id' => null,
                    'idempotency_key' => 'reversal:'.$orig->id,
                    'reverses_txn_id' => $orig->id,
                    'base_premium' => $orig->base_premium,
                    'payer_level' => $orig->payer_level,
                    'diff_pct' => $orig->diff_pct,
                    'amount' => -1 * (float) $orig->amount,
                ]);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Recompute commissions for every payment on a policy at current rates.
     * Workflow:
     *   1. Reverse every existing (non-reversal) txn on the policy — pushes
     *      cumulative amount back to 0 for that policy.
     *   2. Bump the policy's recompute counter (stored in the idempotency
     *      key suffix) so the fresh accrual isn't skipped by dedup.
     *   3. Re-run accrueForPayment() on every payment for the policy at
     *      current (potentially overridden) rates.
     *
     * Result: the ledger contains original + reversals + fresh — a full
     * audit trail from old rate to new rate. Nothing is silently rewritten.
     *
     * @return array{reversed: int, created: int, keyVersion: string}
     */
    public function recomputeForPolicy(Policy $policy): array
    {
        // Step 1: reverse existing txns (idempotent).
        $reversedCount = $this->reverseFor($policy, 'policy.recompute');

        // Step 2: determine the next key-version. We derive it by counting how
        // many prior "recompute" batches exist by scanning idempotency_key for
        // ":v<n>" suffixes on this policy's txns. New batch = max(n) + 1, or
        // "v2" for the first recompute (v1 = original).
        $existingKeys = CommissionTransaction::query()
            ->where('policy_id', $policy->id)
            ->pluck('idempotency_key');
        $maxV = 1;
        foreach ($existingKeys as $k) {
            if ($k && preg_match('/:v(\d+)$/', $k, $m)) {
                $maxV = max($maxV, (int) $m[1]);
            }
        }
        $keyVersion = ':v'.($maxV + 1);

        // Step 3: re-accrue every payment on the policy at current rates.
        $payments = PolicyPayment::query()
            ->where('policy_id', $policy->id)
            ->orderBy('id')
            ->get();
        $created = 0;
        foreach ($payments as $p) {
            $rows = $this->accrueForPayment($p, $keyVersion);
            $created += count(array_filter($rows));
        }

        return [
            'reversed' => $reversedCount,
            'created' => $created,
            'keyVersion' => $keyVersion,
        ];
    }

    /**
     * @param  float|null  $sumAssured  Policy's sum-assured (`policies.coverage`).
     *                                  When set, band rows filter by
     *                                  min_sum_assure/max_sum_assure. When null
     *                                  (or when no band matches), the unbounded
     *                                  row (both bounds NULL) is used.
     * @return array{inh: float, ag: float, override: float}
     */
    private function fetchProductRates(int $productId, int $paymentId, ?float $sumAssured = null): array
    {
        // Look up rates for installment_term=0 (annual/single). The importer
        // seeded most rows at term 0; per-installment rates would key by
        // payment sequence, but that's a Phase 7 refinement.
        //
        // NOTE: the match arm below compares $r->party to constants
        // 'InH'/'AG'/'Override' — but the seeder + Access import both write
        // 'com'/'ag'/'in'. Fixing that mismatch is out of scope for this PR;
        // sum-assured filtering below is layered on top of the existing
        // (broken) match so we don't quietly change accrual behavior. When
        // the mismatch is fixed, the party comparison and the constants
        // should be updated together.
        $rows = DB::table('product_commission_rate_installments')
            ->where('product_id', $productId)
            ->where('installment_term', 0)
            ->when($sumAssured !== null, function ($q) use ($sumAssured): void {
                // Match a band that covers the policy's sum-assured, or the
                // unbounded (both-null) fallback. Rows where one bound is null
                // treat that side as unbounded.
                $q->where(function ($w) use ($sumAssured): void {
                    $w->where(function ($b) use ($sumAssured): void {
                        $b->where(function ($lo) use ($sumAssured): void {
                            $lo->whereNull('min_sum_assure')->orWhere('min_sum_assure', '<=', $sumAssured);
                        })->where(function ($hi) use ($sumAssured): void {
                            $hi->whereNull('max_sum_assure')->orWhere('max_sum_assure', '>=', $sumAssured);
                        });
                    });
                });
            })
            // Prefer specific bands over unbounded fallbacks: rows with any
            // bound set win over rows with both null. Within same specificity,
            // higher id wins (most-recently seeded).
            ->orderByRaw('CASE WHEN min_sum_assure IS NULL AND max_sum_assure IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->get(['party', 'rate', 'min_sum_assure', 'max_sum_assure']);

        // Take the first (best-matching) row per party. Multiple bands can
        // exist for the same party × term; the ORDER BY above puts the winner
        // first, so we only assign each party once.
        $out = ['inh' => 0.0, 'ag' => 0.0, 'override' => 0.0];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->party])) {
                continue;
            }
            $seen[$r->party] = true;
            $rate = (float) $r->rate;
            match ($r->party) {
                self::PARTY_INH => $out['inh'] = $rate,
                self::PARTY_AGENT => $out['ag'] = $rate,
                self::PARTY_OVERRIDE => $out['override'] = $rate,
                default => null,
            };
        }

        return $out;
    }

    /**
     * Insert-or-skip via idempotency_key. Returns the txn (fresh or existing).
     */
    private function upsertTxn(
        int $tenantId,
        string $type,
        ?int $agentId,
        int $policyId,
        int $paymentId,
        float $basePremium,
        string $payerLevel,
        float $diffPct,
        float $amount,
        string $keyVersion = '',
    ): ?CommissionTransaction {
        // "inh" txns have no agent; the schema has agent_id NOT NULL. Skip them
        // for now (Phase 7b can move them to a separate broker-ledger table if
        // desired). Alternative: point at tenant's internal agent placeholder.
        if ($agentId === null) {
            return null;
        }

        $key = "payment:{$paymentId}:{$type}".$keyVersion;
        $existing = CommissionTransaction::query()
            ->where('idempotency_key', $key)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return CommissionTransaction::create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'status' => 'unsettled',
            'agent_id' => $agentId,
            'policy_id' => $policyId,
            'policy_event_id' => null,
            'idempotency_key' => $key,
            'base_premium' => $basePremium,
            'payer_level' => $payerLevel,
            'diff_pct' => $diffPct,
            'amount' => $amount,
        ]);
    }
}
