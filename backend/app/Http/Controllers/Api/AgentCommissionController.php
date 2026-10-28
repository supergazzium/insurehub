<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Agent;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutPayee;
use App\Support\AgentLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only commission-detail endpoint per agent — powers the admin
 * "Agent Commission Detail" page.
 *
 * Returns every commission_ledgers row where this agent is the
 * beneficiary, joined with policy + payment + source-agent context.
 * Also returns the agent's own metadata (rank, upline chain, active
 * status) and per-payout-type totals.
 */
class AgentCommissionController extends ApiController
{
    public function show(Request $request, string $agentCode): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $agent = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_code', $agentCode)
            ->with(['rank', 'parent.rank'])
            ->firstOrFail();

        // Walk upline chain (max 20 hops matches engine's MAX_CHAIN_DEPTH).
        $uplineChain = [];
        $seen = [(int) $agent->id => true];
        $current = $agent->parent;
        $depth = 0;
        while ($current !== null && $depth < 20) {
            if (isset($seen[(int) $current->id])) {
                break;
            }
            $seen[(int) $current->id] = true;
            $uplineChain[] = [
                'id' => (string) $current->id,
                'code' => $current->agent_code,
                'name' => trim(($current->first_name ?? '').' '.($current->last_name ?? '')),
                'rankCode' => $current->rank?->code,
                'active' => (bool) $current->active,
            ];
            $current = $current->parent()->with('rank')->first();
            $depth++;
        }

        // Ledger rows for this agent as beneficiary.
        $rows = DB::table('commission_ledgers as cl')
            ->join('policy_payments as pp', 'pp.id', '=', 'cl.policy_payment_id')
            ->join('policies as pol', 'pol.id', '=', 'cl.policy_id')
            ->leftJoin('agents as src', 'src.id', '=', 'cl.source_agent_id')
            ->leftJoin('carriers as c', 'c.id', '=', 'pol.carrier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pol.product_id')
            ->leftJoin('product_types as pt', 'pt.id', '=', 'pr.product_type_id')
            ->where('cl.beneficiary_agent_id', $agent->id)
            ->where('cl.tenant_id', $tenantId)
            ->orderByDesc('cl.created_at')
            ->orderByDesc('cl.id')
            ->select([
                'cl.id', 'cl.payout_type', 'cl.status',
                'cl.base_premium', 'cl.rate_applied', 'cl.amount',
                'cl.standard_rate', 'cl.mgmt_fee_rate',
                'cl.created_at',
                'pp.reference as paymentReference', 'pp.payment_date',
                'pol.policy_no', 'pol.id as policy_id',
                'src.agent_code as sourceAgentCode',
                'c.code as carrierCode', 'c.name as carrierName',
                'pt.code as productTypeCode', 'pt.name_th as productTypeNameTh',
            ])
            ->get();

        // Totals per payout type + grand total.
        $totals = [
            'DIRECT_COMMISSION' => 0.0,
            'REFERRAL_FEE' => 0.0,
            'MANAGEMENT_DIFFERENTIAL' => 0.0,
        ];
        foreach ($rows as $r) {
            $totals[$r->payout_type] = ($totals[$r->payout_type] ?? 0.0) + (float) $r->amount;
        }
        $grandTotal = array_sum($totals);

        $downlineTree = $this->buildDownlineTree((int) $agent->id, $tenantId);

        return response()->json([
            'agent' => [
                'id' => (string) $agent->id,
                'code' => $agent->agent_code,
                'name' => trim(($agent->first_name ?? '').' '.($agent->last_name ?? '')),
                'rankCode' => $agent->rank?->code,
                'rankLevel' => $agent->rank?->level,
                'active' => (bool) $agent->active,
                'hasLicense' => AgentLicense::isValid($agent),
            ],
            'uplineChain' => $uplineChain,
            'downlineTree' => $downlineTree,
            'ledger' => $rows->map(fn ($r) => [
                'id' => (string) $r->id,
                'payoutType' => $r->payout_type,
                'status' => $r->status,
                'basePremium' => (float) $r->base_premium,
                'rateApplied' => (float) $r->rate_applied,
                'amount' => (float) $r->amount,
                'standardRate' => $r->standard_rate !== null ? (float) $r->standard_rate : null,
                'mgmtFeeRate' => $r->mgmt_fee_rate !== null ? (float) $r->mgmt_fee_rate : null,
                'createdAt' => $r->created_at,
                'paymentReference' => $r->paymentReference,
                'paymentDate' => $r->payment_date,
                'policyNo' => $r->policy_no,
                'policyId' => (string) $r->policy_id,
                'sourceAgentCode' => $r->sourceAgentCode,
                'carrierCode' => $r->carrierCode,
                'carrierName' => $r->carrierName,
                'productTypeCode' => $r->productTypeCode,
                'productTypeNameTh' => $r->productTypeNameTh,
            ])->all(),
            'totals' => [
                'directCommission' => round($totals['DIRECT_COMMISSION'], 2),
                'referralFee' => round($totals['REFERRAL_FEE'], 2),
                'managementDifferential' => round($totals['MANAGEMENT_DIFFERENTIAL'], 2),
                'grandTotal' => round($grandTotal, 2),
            ],
        ]);
    }

    /**
     * Recursively build the downline subtree rooted at $parentId.
     *
     * Bounded by MAX_DEPTH to protect against pathological trees; deeper
     * downlines are truncated with a placeholder node showing "(N more)".
     * Excludes soft-deleted agents. Ordered alphabetically by agent_code
     * within each parent so the tree renders deterministically.
     *
     * @return list<array{code: string, name: string, rankCode: ?string, rankLevel: ?int, active: bool, childCount: int, children: array}>
     */
    private function buildDownlineTree(int $parentId, int $tenantId, int $depth = 0): array
    {
        $MAX_DEPTH = 5;
        if ($depth >= $MAX_DEPTH) {
            return [];
        }

        $children = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_agent_id', $parentId)
            ->whereNull('deleted_at')
            ->with('rank')
            ->orderBy('agent_code')
            ->get();

        $out = [];
        foreach ($children as $c) {
            $out[] = [
                'code' => $c->agent_code,
                'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                'rankCode' => $c->rank?->code,
                'rankLevel' => $c->rank?->level,
                'active' => (bool) $c->active,
                'children' => $this->buildDownlineTree((int) $c->id, $tenantId, $depth + 1),
            ];
        }

        return $out;
    }
    /**
     * GET /agents/{agentCode}/payout-batches — PAID payout batches this agent is
     * in, so an internal user can download the per-batch ใบจ่าย PDFs (ขายเอง /
     * แนะนำ) from the agent page. Mirrors the agent-portal earnings history, keyed
     * by agent code instead of the signed-in agent.
     */
    public function payoutBatches(Request $request, string $agentCode): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $agent = Agent::query()->where('tenant_id', $tenantId)->where('agent_code', $agentCode)->first();
        if ($agent === null) {
            return response()->json(['data' => []]);
        }

        $v2 = CommissionPayoutPayee::query()
            ->join('commission_payout_batches as b', 'b.id', '=', 'commission_payout_payees.batch_id')
            ->where('commission_payout_payees.agent_id', $agent->id)
            ->where('b.status', CommissionPayoutBatch::STATUS_PAID)
            ->orderByDesc('b.payment_date')->orderByDesc('b.id')->limit(60)
            ->get(['commission_payout_payees.*', 'b.payment_date as pay_date', 'b.period_label as period'])
            ->map(fn ($p): array => [
                'batchId' => (string) $p->batch_id,
                'version' => 2,
                'paymentDate' => $p->pay_date ? substr((string) $p->pay_date, 0, 10) : null,
                'periodLabel' => $p->period,
                'base' => (float) $p->base_amount,
                'referral' => round((float) $p->referral_amount + (float) $p->override_amount + (float) $p->self_mgmt_amount, 2),
                'gross' => (float) $p->gross_amount,
                'net' => (float) $p->net_amount,
                'certificate' => $p->certificateLabel(),
            ]);

        $v1 = DB::table('commission_payout_batch_items as bi')
            ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
            ->where('bi.agent_id', $agent->id)
            ->where('b.status', CommissionPayoutBatch::STATUS_PAID)
            ->where('b.calc_version', CommissionPayoutBatch::CALC_V1)
            ->groupBy('b.id', 'b.payment_date', 'b.period_label')
            ->orderByDesc('b.payment_date')->limit(60)
            ->selectRaw('b.id, b.payment_date, b.period_label, SUM(bi.snapshot_agent_commission + bi.snapshot_rider_commission) as gross')
            ->get()
            ->map(fn ($r): array => [
                'batchId' => (string) $r->id,
                'version' => 1,
                'paymentDate' => $r->payment_date ? substr((string) $r->payment_date, 0, 10) : null,
                'periodLabel' => $r->period_label,
                'base' => round((float) $r->gross, 2),
                'referral' => 0.0,
                'gross' => round((float) $r->gross, 2),
                'net' => round((float) $r->gross, 2),
                'certificate' => null,
            ]);

        $batches = $v2->concat($v1)->sortByDesc('paymentDate')->values();

        return response()->json(['data' => $batches, 'agentCode' => $agent->agent_code]);
    }
}

