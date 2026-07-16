<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\AgentPayout;
use App\Models\CommissionTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin payout cycle.
 *
 * Given a (period_from, period_to) window and a set of agents (or all),
 *   1. Bundle every commission_transactions.status='unsettled' row created
 *      inside the window, grouped by agent
 *   2. Sum → gross_amount
 *   3. Look up agent.vat_type → WHT rate (Q4 mapping — Access convention)
 *   4. Compute net_amount = gross - wht
 *   5. Create a draft AgentPayout row + set commission_transactions.status=settled
 *      + commission_transactions.settled_by_payout_id
 *
 * All inside a transaction so partial failures roll back.
 *
 * WHT rate table (Access convention, confirmed by product owner):
 *   vat_type = '1' → 3% (individual, standard withholding)
 *   vat_type = '2' → 0% (exempt)
 *   vat_type = '3' → 1% (juristic, VAT-inclusive)
 *   otherwise      → 3% (safe default)
 */
class PayoutService
{
    /** @return list<AgentPayout> */
    public function createBatch(
        int $tenantId,
        string $periodFrom,
        string $periodTo,
        ?array $agentIds,
        int $createdByUserId,
    ): array {
        // Preview → gross per agent, then materialize one payout per non-zero agent.
        $groups = $this->previewByAgent($tenantId, $periodFrom, $periodTo, $agentIds);

        $payouts = [];
        DB::transaction(function () use ($groups, $tenantId, $periodFrom, $periodTo, $createdByUserId, &$payouts): void {
            foreach ($groups as $g) {
                if ($g['gross'] == 0.0 && empty($g['txnIds'])) {
                    continue;
                }

                $agent = Agent::find($g['agentId']);
                $wRate = self::whtRateFor($agent?->vat_type);
                $wht = round($g['gross'] * $wRate, 2);
                $net = round($g['gross'] - $wht, 2);

                $p = AgentPayout::create([
                    'tenant_id' => $tenantId,
                    'agent_id' => $g['agentId'],
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'status' => 'draft',
                    'gross_amount' => $g['gross'],
                    'wht_rate' => $wRate,
                    'wht_amount' => $wht,
                    'net_amount' => $net,
                    'created_by_user_id' => $createdByUserId,
                ]);

                // Settle the txns: mark settled + link to payout.
                if (!empty($g['txnIds'])) {
                    CommissionTransaction::whereIn('id', $g['txnIds'])
                        ->update([
                            'status' => 'settled',
                            'settled_by_payout_id' => $p->id,
                            'updated_at' => now(),
                        ]);
                }

                $payouts[] = $p;
            }
        });

        return $payouts;
    }

    /**
     * Non-destructive preview — returns per-agent grouping without creating
     * any payout rows. UI shows this before the admin confirms.
     *
     * @return list<array{agentId:int, agentCode:string, agentName:string, gross:float, txnIds:list<int>, txnCount:int, vatType:string}>
     */
    public function previewByAgent(
        int $tenantId,
        string $periodFrom,
        string $periodTo,
        ?array $agentIds,
    ): array {
        $q = CommissionTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'unsettled')
            ->whereBetween('created_at', [$periodFrom.' 00:00:00', $periodTo.' 23:59:59']);
        if ($agentIds !== null && !empty($agentIds)) {
            $q->whereIn('agent_id', $agentIds);
        }

        // Fetch all matching txns and group by agent server-side.
        $txns = $q->get(['id', 'agent_id', 'amount']);
        if ($txns->isEmpty()) return [];

        $agentIdsInPlay = $txns->pluck('agent_id')->unique()->all();
        $agents = Agent::query()
            ->whereIn('id', $agentIdsInPlay)
            ->get(['id', 'agent_code', 'first_name', 'last_name', 'vat_type'])
            ->keyBy('id');

        $groups = [];
        foreach ($txns as $t) {
            $aid = (int) $t->agent_id;
            if (! isset($groups[$aid])) {
                $agent = $agents->get($aid);
                $groups[$aid] = [
                    'agentId' => $aid,
                    'agentCode' => $agent?->agent_code ?? '',
                    'agentName' => trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
                    'gross' => 0.0,
                    'txnIds' => [],
                    'txnCount' => 0,
                    'vatType' => (string) ($agent?->vat_type ?? ''),
                ];
            }
            $groups[$aid]['gross'] += (float) $t->amount;
            $groups[$aid]['txnIds'][] = (int) $t->id;
            $groups[$aid]['txnCount']++;
        }

        // Round gross + drop agents whose net (after reversals) is zero from the display.
        foreach ($groups as &$g) {
            $g['gross'] = round($g['gross'], 2);
        }

        // Sort desc by gross so biggest agents are on top.
        return array_values(collect($groups)->sortByDesc('gross')->values()->all());
    }

    public function markIssued(AgentPayout $payout, int $userId): AgentPayout
    {
        if ($payout->status !== 'draft') {
            abort(409, "Cannot issue payout in status '{$payout->status}'.");
        }
        $payout->update([
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        return $payout->fresh();
    }

    public function markPaid(AgentPayout $payout, string $bankRef, int $userId): AgentPayout
    {
        if (!in_array($payout->status, ['draft', 'issued'], true)) {
            abort(409, "Cannot mark payout paid in status '{$payout->status}'.");
        }
        $payout->update([
            'status' => 'paid',
            'bank_ref' => $bankRef,
            'paid_at' => now(),
            'issued_at' => $payout->issued_at ?? now(),
        ]);
        return $payout->fresh();
    }

    /**
     * Voiding a payout undoes the settlement: linked txns go back to unsettled
     * so they can be re-batched. Only allowed for draft/issued payouts —
     * a paid one requires an accounting reversal, not a void.
     */
    public function void(AgentPayout $payout, string $reason): AgentPayout
    {
        if (! in_array($payout->status, ['draft', 'issued'], true)) {
            abort(409, "Cannot void payout in status '{$payout->status}'.");
        }
        DB::transaction(function () use ($payout): void {
            CommissionTransaction::where('settled_by_payout_id', $payout->id)
                ->update([
                    'status' => 'unsettled',
                    'settled_by_payout_id' => null,
                    'updated_at' => now(),
                ]);
            $payout->update(['status' => 'void']);
        });
        return $payout->fresh();
    }

    private static function whtRateFor(?string $vatType): float
    {
        return match ($vatType) {
            '1' => 0.03,   // individual — 3% withholding
            '2' => 0.0,    // exempt
            '3' => 0.01,   // juristic — 1%
            default => 0.03,
        };
    }
}
