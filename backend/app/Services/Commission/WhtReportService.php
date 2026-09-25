<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Withholding-tax (ภาษีหัก ณ ที่จ่าย 3%) report for tax filing.
 *
 * The WHT itself is captured per payout in commission_payout_batch_agents
 * (wht_amount) — this service aggregates those PAID, non-reversed rows per
 * agent over a period so finance can file ภ.ง.ด.53 and issue หนังสือรับรอง
 * การหักภาษี ณ ที่จ่าย (50 ทวิ). It is a read-only reporting layer over the
 * existing payout data; no WHT is recomputed here.
 *
 * A row is included when the agent-payment is paid AND not reversed — a
 * reversed payout never actually withheld tax, so it must not appear on a
 * filing.
 */
class WhtReportService
{
    /**
     * Per-agent WHT totals for a date range (matched on the batch payment_date,
     * i.e. when the tax was actually withheld). Enriches each agent with the
     * tax identity needed for filing (tax_id / national id, payee type).
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function report(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $q = CommissionPayoutBatchAgent::query()
            ->join('commission_payout_batches as b', 'b.id', '=', 'commission_payout_batch_agents.batch_id')
            ->where('commission_payout_batch_agents.tenant_id', $tenantId)
            ->where('commission_payout_batch_agents.paid', true)
            ->where('commission_payout_batch_agents.reversed', false)
            ->where('b.status', '!=', CommissionPayoutBatch::STATUS_REVERSED);

        if ($from !== null) {
            $q->whereDate('b.payment_date', '>=', CarbonImmutable::parse($from, 'Asia/Bangkok')->toDateString());
        }
        if ($to !== null) {
            $q->whereDate('b.payment_date', '<=', CarbonImmutable::parse($to, 'Asia/Bangkok')->toDateString());
        }

        // Aggregate per agent across all their paid payouts in the window.
        $agg = $q->groupBy('commission_payout_batch_agents.agent_id', 'commission_payout_batch_agents.agent_code', 'commission_payout_batch_agents.agent_name')
            ->select([
                'commission_payout_batch_agents.agent_id',
                'commission_payout_batch_agents.agent_code',
                'commission_payout_batch_agents.agent_name',
                DB::raw('COUNT(*) as payout_count'),
                DB::raw('SUM(commission_payout_batch_agents.gross_commission) as gross_total'),
                DB::raw('SUM(commission_payout_batch_agents.wht_amount) as wht_total'),
                DB::raw('SUM(commission_payout_batch_agents.net_transfer) as net_total'),
            ])
            ->get();

        // Decrypt tax identity once per agent via the model (id_card is encrypted).
        $agentIds = $agg->pluck('agent_id')->filter()->all();
        $agents = Agent::query()
            ->whereIn('id', $agentIds)
            ->get(['id', 'kind', 'tax_id', 'id_card', 'juristic_name', 'first_name', 'last_name', 'address', 'branch'])
            ->keyBy('id');

        $rows = [];
        $sumGross = 0.0;
        $sumWht = 0.0;
        $sumNet = 0.0;
        foreach ($agg as $r) {
            $a = $r->agent_id ? ($agents[$r->agent_id] ?? null) : null;
            $isCorporate = $a?->kind === 'corporate';
            // ภ.ง.ด.53 payee tax id: นิติบุคคล uses tax_id (13-digit), บุคคลธรรมดา
            // uses the national ID (id_card). Fall back to whichever exists.
            $taxId = $isCorporate
                ? ($a?->tax_id ?: $a?->id_card)
                : ($a?->id_card ?: $a?->tax_id);
            $payeeName = $isCorporate
                ? ($a?->juristic_name ?: $r->agent_name)
                : ($r->agent_name ?: trim(($a?->first_name ?? '') . ' ' . ($a?->last_name ?? '')));

            $rows[] = [
                'agentId' => $r->agent_id ? (string) $r->agent_id : null,
                'agentCode' => $r->agent_code,
                'payeeName' => $payeeName,
                'payeeType' => $isCorporate ? 'corporate' : 'individual',
                'taxId' => $taxId,
                'address' => $a?->address,
                'branch' => $a?->branch,
                'payoutCount' => (int) $r->payout_count,
                'grossCommission' => round((float) $r->gross_total, 2),
                'whtAmount' => round((float) $r->wht_total, 2),
                'netPaid' => round((float) $r->net_total, 2),
            ];
            $sumGross += (float) $r->gross_total;
            $sumWht += (float) $r->wht_total;
            $sumNet += (float) $r->net_total;
        }

        // Stable, filing-friendly order: by agent code.
        usort($rows, fn ($x, $y) => strcmp((string) $x['agentCode'], (string) $y['agentCode']));

        return [
            'rows' => $rows,
            'totals' => [
                'payeeCount' => count($rows),
                'grossCommission' => round($sumGross, 2),
                'whtAmount' => round($sumWht, 2),
                'netPaid' => round($sumNet, 2),
            ],
        ];
    }
}
