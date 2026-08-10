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
    /**
     * Human-readable party labels — written into
     * `commission_transactions.payer_level` and rendered by the PDF
     * statement / commission ledger UI. Kept for backward compatibility
     * with existing rows.
     */
    public const PARTY_INH = 'InH';

    public const PARTY_AGENT = 'AG';

    public const PARTY_OVERRIDE = 'Override';

    /**
     * DB codes stored in product_commission_rate_installments.party. The
     * Access importer preserves these lowercase codes verbatim; the
     * seeder (see ProductRateSeeder) uses the same set.
     *
     * These are what fetchProductRates() must actually compare against —
     * NOT the PARTY_* constants above, which are display strings that
     * happen to share names but not values.
     */
    private const DB_PARTY_INH = 'com';

    private const DB_PARTY_AGENT = 'ag';

    private const DB_PARTY_OVERRIDE = 'in';

    /**
     * Default installment_term for the "annual" rate row in the tall
     * table. The importer defaults null installment_term to this string,
     * and the seeder writes 'main' for the top row of the flat shape.
     * fetchProductRates() historically compared against integer 0, which
     * never matched anything.
     */
    private const DEFAULT_INSTALLMENT_TERM = 'main';

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
        $productRates = $this->fetchProductRates($policy->product_id, $payment->id);
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
     * @return array{inh: float, ag: float, override: float}
     */
    private function fetchProductRates(int $productId, int $paymentId): array
    {
        // Feature-gated behavior change — see config/commission.php.
        //
        // Before this PR, this method's rate lookup was silently broken:
        // the party match arm compared against 'InH'/'AG'/'Override' but
        // rows are stored as 'com'/'ag'/'in', and the installment_term
        // filter used integer 0 against a string column ('main').
        //
        // As a result, product-level rates from the tall table have
        // never applied since launch. Every accrual came from the
        // per-policy overrides main_com_rate_{inh,ag}.
        //
        // Turning the fix on retroactively would start creating InH/AG
        // rows on payments whose policies previously accrued nothing at
        // this layer. To avoid a silent ledger change, callers must
        // opt in via COMMISSION_READ_PRODUCT_RATES=true after auditing
        // the tall table and running recomputeForPolicy() to backfill
        // any older payments the operator wants to bring up to date.
        if (! (bool) config('commission.read_product_rates', false)) {
            return ['inh' => 0.0, 'ag' => 0.0, 'override' => 0.0];
        }

        $rows = DB::table('product_commission_rate_installments')
            ->where('product_id', $productId)
            ->where('installment_term', self::DEFAULT_INSTALLMENT_TERM)
            ->get(['party', 'rate']);

        $out = ['inh' => 0.0, 'ag' => 0.0, 'override' => 0.0];
        foreach ($rows as $r) {
            $rate = (float) $r->rate;
            match ($r->party) {
                self::DB_PARTY_INH => $out['inh'] = $rate,
                self::DB_PARTY_AGENT => $out['ag'] = $rate,
                self::DB_PARTY_OVERRIDE => $out['override'] = $rate,
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
