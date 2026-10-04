<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\CommissionLedger;
use App\Models\CommissionPayoutAdjustment;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchItem;
use App\Models\CommissionPayoutPayee;
use App\Models\Policy;
use App\Models\PolicyPayoutOverride;
use App\Models\PolicyRebate;
use App\Models\Tenant;
use App\Support\PayoutTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommissionPayoutService
{
    private const APPROVED_STATUSES = ['active', 'issued'];

    public const PAID_STATUS = 'paid';

    private const VAT_RATE = 0.07;

    private const LEDGER_LINE = [
        CommissionLedger::TYPE_DIRECT_COMMISSION => CommissionPayoutBatchItem::LINE_SELF_MGMT,
        CommissionLedger::TYPE_REFERRAL_FEE => CommissionPayoutBatchItem::LINE_REFERRAL,
        CommissionLedger::TYPE_MANAGEMENT_DIFFERENTIAL => CommissionPayoutBatchItem::LINE_OVERRIDE,
    ];

    public function __construct(private readonly PayoutEligibility $eligibility = new PayoutEligibility()) {}

    private function resolveVatType(object $agent): string
    {
        $hasVat = (bool) ($agent->has_vat ?? false);
        if ($hasVat) {
            $mode = (string) ($agent->vat_mode ?? '');

            return $mode === 'include' ? '3' : '2';
        }
        $legacy = (string) ($agent->vat_type ?? '');
        if (in_array($legacy, ['1', '2', '3'], true)) {
            return $legacy;
        }

        return '1';
    }

    private function resolveAmount(object $row): array
    {
        $rider = (float) ($row->rider_com_ag ?? 0);
        $base = (float) ($row->main_premium ?? 0);

        if (($row->actual_agent_amount ?? null) !== null && (float) $row->actual_agent_amount > 0) {
            return ['main' => (float) $row->actual_agent_amount, 'rider' => 0.0, 'base' => $base, 'source' => 'rebate_actual'];
        }
        if (($row->calculated_agent_amount ?? null) !== null && (float) $row->calculated_agent_amount > 0) {
            return ['main' => (float) $row->calculated_agent_amount, 'rider' => 0.0, 'base' => $base, 'source' => 'rebate_calculated'];
        }
        if (($row->comm_hub_to_agent_amount ?? null) !== null && (float) $row->comm_hub_to_agent_amount > 0) {
            return ['main' => (float) $row->comm_hub_to_agent_amount, 'rider' => $rider, 'base' => $base, 'source' => 'policy_comm'];
        }

        return ['main' => 0.0, 'rider' => $rider, 'base' => $base, 'source' => 'none'];
    }

    private function bounds(?string $from, ?string $to): array
    {
        return [
            $from !== null ? CarbonImmutable::parse($from, 'Asia/Bangkok')->startOfDay() : null,
            $to !== null ? CarbonImmutable::parse($to, 'Asia/Bangkok')->addDay()->startOfDay() : null,
        ];
    }

    private function basePolicyRows(int $tenantId, ?string $from, ?string $to, ?int $agentId): Collection
    {
        [$fromTs, $toTs] = $this->bounds($from, $to);
        $allocated = DB::table('commission_payout_batch_items as bi')
            ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
            ->where('bi.tenant_id', $tenantId)
            ->where('bi.line_type', CommissionPayoutBatchItem::LINE_BASE)
            ->where('bi.item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)
            ->where('b.status', '!=', CommissionPayoutBatch::STATUS_CANCELLED)
            ->groupBy('bi.policy_id')
            ->selectRaw('bi.policy_id, SUM(bi.snapshot_agent_commission) as alloc_main, SUM(bi.snapshot_rider_commission) as alloc_rider');

        return DB::table('policies as p')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoinSub($allocated, 'al', 'al.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.writing_agent_id')
            ->where('p.imported_from_legacy', false)
            ->whereIn('p.status', self::APPROVED_STATUSES)
            ->where(function ($w): void {
                $w->whereNull('r.agent_rebate_status')
                    ->orWhere('r.agent_rebate_status', '')
                    ->orWhere('r.agent_rebate_status', '!=', self::PAID_STATUS);
            })
            ->when($toTs !== null, fn ($q) => $q->where('p.created_at', '<', $toTs))
            ->when($fromTs !== null, fn ($q) => $q->where(fn ($w) => $w->where('p.created_at', '>=', $fromTs)->orWhereNotNull('al.policy_id')))
            ->when($agentId !== null, fn ($q) => $q->where('p.writing_agent_id', $agentId))
            ->select([
                'p.id as policy_id', 'p.policy_no', 'p.application_no', 'p.main_premium', 'p.created_at as policy_created_at',
                'p.comm_hub_to_agent_amount', 'p.writing_agent_id as agent_id',
                'a.agent_code', 'a.vat_type', 'a.has_vat', 'a.vat_mode',
                'a.kind as agent_kind', 'a.juristic_name as agent_juristic_name', 'a.tax_id as agent_tax_id',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'r.calculated_agent_amount', 'r.actual_agent_amount', 'r.agent_rebate_status',
                'al.alloc_main', 'al.alloc_rider',
                DB::raw('(SELECT COALESCE(SUM(pr.com_amt_ag),0) FROM policy_riders pr WHERE pr.policy_id = p.id) as rider_com_ag'),
            ])
            ->orderBy('a.agent_code')
            ->orderBy('p.id')
            ->get();
    }

    private function ledgerRows(int $tenantId, ?string $to, ?int $agentId): Collection
    {
        [, $toTs] = $this->bounds(null, $to);

        return DB::table('commission_ledgers as l')
            ->join('policies as p', 'p.id', '=', 'l.policy_id')
            ->leftJoin('agents as src', 'src.id', '=', 'l.source_agent_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.payout_batch_id')
            ->where('l.status', CommissionLedger::STATUS_UNSETTLED)
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', self::APPROVED_STATUSES)
            ->where(function ($w): void {
                $w->where(fn ($x) => $x->whereIn('l.payout_type', [CommissionLedger::TYPE_REFERRAL_FEE, CommissionLedger::TYPE_MANAGEMENT_DIFFERENTIAL])->where('l.amount', '>', 0))
                    ->orWhere(fn ($x) => $x->where('l.payout_type', CommissionLedger::TYPE_DIRECT_COMMISSION)->where('l.mgmt_fee_amount', '>', 0));
            })
            ->when($toTs !== null, fn ($q) => $q->where('l.created_at', '<', $toTs))
            ->when($agentId !== null, fn ($q) => $q->where('l.beneficiary_agent_id', $agentId))
            ->select([
                'l.id', 'l.payout_type', 'l.beneficiary_agent_id', 'l.source_agent_id', 'l.policy_id', 'l.base_premium',
                'l.amount', 'l.mgmt_fee_amount', 'l.rate_applied', 'l.created_at',
                'p.policy_no', 'p.application_no', 'p.created_at as policy_created_at',
                'src.agent_code as source_code',
                DB::raw("CONCAT_WS(' ', src.first_name, src.last_name) as source_name"),
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
            ])
            ->orderBy('l.id')
            ->get();
    }

    public function collectLines(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null): array
    {
        $baseRows = $this->basePolicyRows($tenantId, $from, $to, $agentId);
        $ledgerRows = $this->ledgerRows($tenantId, $to, $agentId);
        $policyIds = array_merge($baseRows->pluck('policy_id')->all(), $ledgerRows->pluck('policy_id')->all());
        $eligibility = $this->eligibility->evaluate($tenantId, $policyIds);

        $ready = [];
        $waiting = [];
        $warnings = [];

        foreach ($baseRows as $row) {
            $amt = $this->resolveAmount($row);
            $eMain = PayoutTax::satang($amt['main']);
            $eRider = PayoutTax::satang($amt['rider']);
            $entitlement = $eMain + $eRider;
            if ($entitlement <= 0) {
                $warnings[] = "กรมธรรม์ ".($row->policy_no ?: $row->application_no)." ({$row->agent_code}) ไม่มียอดค่าคอมตัวแทน";

                continue;
            }
            $allocMain = PayoutTax::satang($row->alloc_main);
            $allocRider = PayoutTax::satang($row->alloc_rider);
            $allocated = $allocMain + $allocRider;
            $remaining = $entitlement - $allocated;
            if ($remaining <= 0) {
                if ($remaining < 0) {
                    $warnings[] = "กรมธรรม์ ".($row->policy_no ?: $row->application_no).' ยอดค่าคอมลดลงต่ำกว่าที่จ่ายไปแล้ว '.number_format(PayoutTax::baht(-$remaining), 2).' บาท';
                }

                continue;
            }
            $ev = $eligibility[(int) $row->policy_id] ?? ['ratio' => [1, 1], 'ratioLabel' => null, 'gates' => [], 'customerReason' => null, 'override' => null];
            $line = [
                'key' => "base:{$row->policy_id}",
                'lineType' => CommissionPayoutBatchItem::LINE_BASE,
                'policyId' => (int) $row->policy_id,
                'policyNo' => $row->policy_no,
                'applicationNo' => $row->application_no,
                'customerName' => trim((string) $row->customer_name) ?: null,
                'policyDate' => $row->policy_created_at ? substr((string) $row->policy_created_at, 0, 10) : null,
                'agentId' => (int) $row->agent_id,
                'sourceAgentId' => null,
                'sourceAgent' => null,
                'ledgerId' => null,
                'basePremium' => $amt['base'],
                'entitlement' => $entitlement,
                'allocated' => $allocated,
                'ratio' => $ev['ratio'],
                'ratioLabel' => $ev['ratioLabel'],
                'amountSource' => $amt['source'],
                'override' => $ev['override'],
                'reasons' => [],
            ];
            if ($ev['gates'] !== [] || $ev['customerReason'] !== null) {
                $waiting[] = $line + [
                    'amount' => $remaining, 'main' => max(0, $eMain - $allocMain), 'rider' => max(0, $eRider - $allocRider),
                ];
                $waiting[array_key_last($waiting)]['reasons'] = array_values(array_filter(array_merge($ev['gates'], [$ev['customerReason']])));

                continue;
            }
            [$k, $n] = $ev['ratio'];
            $due = PayoutTax::prorate($entitlement, $k, $n) - $allocated;
            if ($due > 0) {
                $riderDue = min($due, max(0, PayoutTax::prorate($eRider, $k, $n) - $allocRider));
                $ready[] = $line + ['amount' => $due, 'main' => $due - $riderDue, 'rider' => $riderDue];
            }
            $left = $remaining - max(0, $due);
            if ($left > 0) {
                $waiting[] = array_merge($line, [
                    'key' => "base-rest:{$row->policy_id}",
                    'amount' => $left, 'main' => $left, 'rider' => 0,
                    'reasons' => [['code' => PayoutEligibility::REASON_CUSTOMER, 'label' => 'รอลูกค้าชำระงวดถัดไป'.($ev['ratioLabel'] ? " ({$ev['ratioLabel']})" : '')]],
                ]);
            }
        }

        foreach ($ledgerRows as $row) {
            $lineType = self::LEDGER_LINE[$row->payout_type];
            $amount = PayoutTax::satang($row->payout_type === CommissionLedger::TYPE_DIRECT_COMMISSION ? $row->mgmt_fee_amount : $row->amount);
            if ($amount <= 0) {
                continue;
            }
            $ev = $eligibility[(int) $row->policy_id] ?? ['ratio' => [1, 1], 'ratioLabel' => null, 'gates' => [], 'customerReason' => null, 'override' => null];
            $line = [
                'key' => "ledger:{$row->id}",
                'lineType' => $lineType,
                'policyId' => (int) $row->policy_id,
                'policyNo' => $row->policy_no,
                'applicationNo' => $row->application_no,
                'customerName' => trim((string) $row->customer_name) ?: null,
                'policyDate' => $row->policy_created_at ? substr((string) $row->policy_created_at, 0, 10) : null,
                'agentId' => (int) $row->beneficiary_agent_id,
                'sourceAgentId' => $row->source_agent_id !== null ? (int) $row->source_agent_id : null,
                'sourceAgent' => $row->source_code ? trim($row->source_code.' · '.trim((string) $row->source_name)) : null,
                'ledgerId' => (int) $row->id,
                'basePremium' => (float) $row->base_premium,
                'entitlement' => $amount,
                'allocated' => 0,
                'ratio' => [1, 1],
                'ratioLabel' => null,
                'amountSource' => 'ledger',
                'override' => $ev['override'],
                'amount' => $amount,
                'main' => $amount,
                'rider' => 0,
                'reasons' => [],
            ];
            if ($ev['gates'] !== []) {
                $line['reasons'] = $ev['gates'];
                $waiting[] = $line;

                continue;
            }
            $ready[] = $line;
        }

        return ['ready' => $ready, 'waiting' => $waiting, 'warnings' => array_values(array_unique($warnings))];
    }

    private function agentsById(array $ids): Collection
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));

        return $ids === [] ? collect() : Agent::query()->with('bank')->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function agentName(?Agent $agent): string
    {
        if ($agent === null) {
            return '';
        }

        return $agent->kind === 'corporate' && filled($agent->juristic_name)
            ? (string) $agent->juristic_name
            : trim(($agent->first_name ?? '').' '.($agent->last_name ?? ''));
    }

    private function payeeSnapshot(Agent $agent, ?Tenant $tenant): array
    {
        $corporate = $agent->kind === 'corporate';
        $defaultRate = $corporate ? ($tenant?->wht_rate_corporate ?? 3) : ($tenant?->wht_rate_individual ?? 3);
        $rate = $agent->wht_rate !== null ? (float) $agent->wht_rate : (float) $defaultRate;
        $address = collect([
            $agent->address,
            $agent->building_floor,
            filled($agent->moo) ? 'หมู่ '.$agent->moo : null,
            $agent->moo_ban,
            filled($agent->soi) ? 'ซ.'.$agent->soi : null,
            filled($agent->road) ? 'ถ.'.$agent->road : null,
            $agent->sub_district,
            $agent->district,
            $agent->province,
            $agent->postcode,
        ])->filter(fn ($v) => filled($v))->implode(' ');

        return [
            'agent_code' => $agent->agent_code,
            'payee_name' => $this->agentName($agent),
            'payee_kind' => $corporate ? 'corporate' : 'individual',
            'payee_tax_id' => $corporate ? ($agent->tax_id ?: null) : ($agent->id_card ?: $agent->tax_id ?: null),
            'payee_address' => $address !== '' ? $address : null,
            'vat_type' => $this->resolveVatType($agent),
            'wht_rate' => round($rate, 2),
            'wht_form' => $corporate ? CommissionPayoutPayee::FORM_PND53 : CommissionPayoutPayee::FORM_PND3,
            'bank_name' => $agent->bank?->name_th ?? $agent->bank_name_text,
            'bank_account_no' => $agent->bank_account_no,
            'bank_account_name' => $agent->bank_account_name,
        ];
    }

    private function pendingDeductionRows(int $tenantId, ?int $agentId = null): Collection
    {
        $adjustments = CommissionPayoutAdjustment::query()
            ->with('policy:id,policy_no,application_no')
            ->where('tenant_id', $tenantId)
            ->where('status', CommissionPayoutAdjustment::STATUS_PENDING)
            ->whereNull('batch_id')
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->orderBy('id')
            ->get()
            ->map(fn (CommissionPayoutAdjustment $a): array => [
                'id' => (string) $a->id,
                'source' => 'adjustment',
                'agentId' => (int) $a->agent_id,
                'type' => $a->type,
                'typeLabel' => CommissionPayoutAdjustment::TYPE_LABELS[$a->type] ?? $a->type,
                'amount' => PayoutTax::satang($a->amount),
                'reason' => $a->reason,
                'policyId' => $a->policy_id !== null ? (string) $a->policy_id : null,
                'policyNo' => $a->policy?->policy_no ?: $a->policy?->application_no,
                'createdAt' => $a->created_at?->toIso8601String(),
            ]);

        $costs = DB::table('commission_ledgers as l')
            ->leftJoin('policies as p', 'p.id', '=', 'l.policy_id')
            ->where('l.tenant_id', $tenantId)
            ->whereIn('l.payout_type', CommissionLedger::DEDUCTION_TYPES)
            ->where('l.status', CommissionLedger::STATUS_UNSETTLED)
            ->whereNull('l.payout_batch_id')
            ->where('l.amount', '<', 0)
            ->when($agentId !== null, fn ($q) => $q->where('l.beneficiary_agent_id', $agentId))
            ->orderBy('l.id')
            ->get(['l.id', 'l.payout_type', 'l.beneficiary_agent_id', 'l.amount', 'l.policy_id', 'l.created_at', 'p.policy_no', 'p.application_no'])
            ->map(fn ($l): array => [
                'id' => 'ledger:'.$l->id,
                'source' => 'ledger',
                'agentId' => (int) $l->beneficiary_agent_id,
                'type' => CommissionPayoutAdjustment::LEDGER_TYPES[$l->payout_type],
                'typeLabel' => CommissionPayoutAdjustment::TYPE_LABELS[CommissionPayoutAdjustment::LEDGER_TYPES[$l->payout_type]],
                'amount' => -PayoutTax::satang($l->amount),
                'reason' => $l->payout_type === CommissionLedger::TYPE_COMMISSION_PAID_AT_SOURCE
                    ? 'ตัวแทนหักค่าคอมจากเบี้ยไปแล้วตอนรับชำระ จึงหักออกจากรอบจ่ายนี้'
                    : 'ค่าธรรมเนียม/ดอกเบี้ยผ่อนชำระที่ตัวแทนรับภาระ',
                'policyId' => (string) $l->policy_id,
                'policyNo' => $l->policy_no ?: $l->application_no,
                'createdAt' => $l->created_at ? CarbonImmutable::parse($l->created_at)->toIso8601String() : null,
            ]);

        return $adjustments->concat($costs)->values();
    }

    public function pendingDeductions(int $tenantId, int $agentId): array
    {
        return $this->pendingDeductionRows($tenantId, $agentId)
            ->map(fn (array $d): array => array_merge($d, ['amount' => PayoutTax::baht($d['amount'])]))
            ->all();
    }

    private function formatLine(array $l): array
    {
        return [
            'key' => $l['key'],
            'lineType' => $l['lineType'],
            'lineLabel' => CommissionPayoutBatchItem::LINE_LABELS[$l['lineType']] ?? $l['lineType'],
            'policyId' => $l['policyId'],
            'policyNo' => $l['policyNo'],
            'applicationNo' => $l['applicationNo'],
            'customerName' => $l['customerName'],
            'policyDate' => $l['policyDate'],
            'sourceAgent' => $l['sourceAgent'],
            'basePremium' => round((float) $l['basePremium'], 2),
            'agentCommission' => PayoutTax::baht($l['main']),
            'riderCommission' => PayoutTax::baht($l['rider']),
            'amount' => PayoutTax::baht($l['amount']),
            'entitlement' => PayoutTax::baht($l['entitlement']),
            'ratioLabel' => $l['ratioLabel'],
            'amountSource' => $l['amountSource'],
            'override' => $l['override'],
            'reasons' => $l['reasons'],
        ];
    }

    private function typeTotals(array $lines): array
    {
        $out = array_fill_keys(CommissionPayoutBatchItem::LINE_TYPES, 0);
        foreach ($lines as $l) {
            $out[$l['lineType']] += $l['amount'];
        }

        return $out;
    }

    public function preview(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null): array
    {
        $collected = $this->collectLines($tenantId, $from, $to, $agentId);
        $agents = $this->agentsById(array_column($collected['ready'], 'agentId'));
        $byAgent = [];
        foreach ($collected['ready'] as $l) {
            $key = $l['agentId'];
            if (! isset($byAgent[$key])) {
                $agent = $agents->get($key);
                $byAgent[$key] = [
                    'agentId' => (string) $key,
                    'agentCode' => $agent?->agent_code,
                    'agentName' => $this->agentName($agent),
                    'vatType' => $agent ? $this->resolveVatType($agent) : '1',
                    'itemCount' => 0,
                    'amount' => 0,
                ];
            }
            $byAgent[$key]['itemCount']++;
            $byAgent[$key]['amount'] += $l['amount'];
        }
        $rows = array_values(array_map(fn ($a) => array_merge($a, ['amount' => PayoutTax::baht($a['amount'])]), $byAgent));

        return [
            'agents' => $rows,
            'totals' => [
                'totalAgents' => count($rows),
                'totalItems' => count($collected['ready']),
                'totalAmount' => PayoutTax::baht(array_sum(array_column($collected['ready'], 'amount'))),
            ],
            'warnings' => $collected['warnings'],
        ];
    }

    public function byAgent(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $collected = $this->collectLines($tenantId, $from, $to);
        $deductions = $this->pendingDeductionRows($tenantId);
        $ids = array_merge(array_column($collected['ready'], 'agentId'), array_column($collected['waiting'], 'agentId'), $deductions->pluck('agentId')->all());
        $agents = $this->agentsById($ids);
        $tenant = Tenant::query()->find($tenantId);

        $acc = [];
        foreach ($agents as $id => $agent) {
            $acc[$id] = ['ready' => [], 'waitingAmount' => 0, 'waitingCount' => 0, 'deduction' => 0];
        }
        foreach ($collected['ready'] as $l) {
            $acc[$l['agentId']]['ready'][] = $l;
        }
        foreach ($collected['waiting'] as $l) {
            $acc[$l['agentId']]['waitingAmount'] += $l['amount'];
            $acc[$l['agentId']]['waitingCount']++;
        }
        foreach ($deductions as $d) {
            if (isset($acc[$d['agentId']])) {
                $acc[$d['agentId']]['deduction'] += $d['amount'];
            }
        }

        $rows = [];
        foreach ($acc as $id => $a) {
            $agent = $agents->get($id);
            if ($agent === null) {
                continue;
            }
            $gross = array_sum(array_column($a['ready'], 'amount'));
            if ($gross <= 0 && $a['waitingAmount'] <= 0 && $a['deduction'] <= 0) {
                continue;
            }
            $snap = $this->payeeSnapshot($agent, $tenant);
            $tax = PayoutTax::compute($gross, min($a['deduction'], $gross), $snap['vat_type'], PayoutTax::basisPoints($snap['wht_rate']));
            $types = $this->typeTotals($a['ready']);
            $rows[] = [
                'agentId' => (string) $id,
                'agentCode' => $agent->agent_code,
                'agentName' => $snap['payee_name'],
                'vatType' => $snap['vat_type'],
                'whtRate' => $snap['wht_rate'],
                'itemCount' => count($a['ready']),
                'amount' => PayoutTax::baht($gross),
                'byType' => array_map(fn ($v) => PayoutTax::baht($v), $types),
                'waitingCount' => $a['waitingCount'],
                'waitingAmount' => PayoutTax::baht($a['waitingAmount']),
                'pendingDeduction' => PayoutTax::baht($a['deduction']),
                'netAmount' => PayoutTax::baht($tax['net']),
            ];
        }
        usort($rows, fn ($x, $y) => [$y['amount'], $y['waitingAmount']] <=> [$x['amount'], $x['waitingAmount']]);

        return [
            'agents' => $rows,
            'totals' => [
                'totalAgents' => count(array_filter($rows, fn ($r) => $r['amount'] > 0)),
                'totalItems' => array_sum(array_column($rows, 'itemCount')),
                'totalAmount' => round(array_sum(array_column($rows, 'amount')), 2),
                'totalWaiting' => round(array_sum(array_column($rows, 'waitingAmount')), 2),
                'totalNet' => round(array_sum(array_column($rows, 'netAmount')), 2),
            ],
            'warnings' => $collected['warnings'],
        ];
    }

    public function agentDetail(int $tenantId, int $agentId, ?string $from = null, ?string $to = null): array
    {
        $collected = $this->collectLines($tenantId, $from, $to, $agentId);
        $deductions = $this->pendingDeductionRows($tenantId, $agentId);
        $agent = Agent::query()->with('bank')->where('tenant_id', $tenantId)->find($agentId);
        $tenant = Tenant::query()->find($tenantId);

        $gross = array_sum(array_column($collected['ready'], 'amount'));
        $deductionTotal = (int) $deductions->sum('amount');
        $applied = min($deductionTotal, $gross);
        $snap = $agent ? $this->payeeSnapshot($agent, $tenant) : null;
        $tax = PayoutTax::compute($gross, $applied, $snap['vat_type'] ?? '1', PayoutTax::basisPoints($snap['wht_rate'] ?? 0));
        $types = $this->typeTotals($collected['ready']);

        return [
            'agent' => $agent ? [
                'agentId' => (string) $agent->id,
                'agentCode' => $agent->agent_code,
                'agentName' => $snap['payee_name'],
                'vatType' => $snap['vat_type'],
                'kind' => $snap['payee_kind'],
                'whtRate' => $snap['wht_rate'],
                'whtForm' => $snap['wht_form'],
                'whtFormLabel' => CommissionPayoutPayee::FORM_LABELS[$snap['wht_form']] ?? null,
                'bankName' => $snap['bank_name'],
                'hasBankAccount' => filled($snap['bank_account_no']),
            ] : null,
            'items' => array_map(fn ($l) => $this->formatLine($l), $collected['ready']),
            'waiting' => array_map(fn ($l) => $this->formatLine($l), $collected['waiting']),
            'pendingDeductions' => $deductions->map(fn (array $d): array => array_merge($d, ['amount' => PayoutTax::baht($d['amount'])]))->all(),
            'warnings' => $collected['warnings'],
            'totals' => [
                'itemCount' => count($collected['ready']),
                'totalAmount' => PayoutTax::baht($gross),
                'byType' => array_map(fn ($v) => PayoutTax::baht($v), $types),
                'pendingDeduction' => PayoutTax::baht($deductionTotal),
                'appliedDeduction' => PayoutTax::baht($applied),
                'carryForward' => PayoutTax::baht(max(0, $deductionTotal - $gross)),
                'taxableAmount' => PayoutTax::baht($tax['taxable']),
                'vatAmount' => PayoutTax::baht($tax['vat']),
                'grossWithVat' => PayoutTax::baht($tax['total']),
                'whtAmount' => PayoutTax::baht($tax['wht']),
                'netAmount' => PayoutTax::baht($tax['net']),
                'waitingCount' => count($collected['waiting']),
                'waitingAmount' => PayoutTax::baht(array_sum(array_column($collected['waiting'], 'amount'))),
            ],
        ];
    }

    private function lockTenant(int $tenantId): void
    {
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
    }

    private function lockBatch(CommissionPayoutBatch $batch): CommissionPayoutBatch
    {
        return CommissionPayoutBatch::query()->lockForUpdate()->findOrFail($batch->id);
    }

    public function payAgent(
        int $tenantId,
        int $agentId,
        string $paymentDate,
        ?string $reference,
        ?int $userId,
        ?string $from = null,
        ?string $to = null,
        ?string $note = null,
    ): CommissionPayoutBatch {
        return DB::transaction(function () use ($tenantId, $agentId, $paymentDate, $reference, $userId, $from, $to, $note): CommissionPayoutBatch {
            $batch = $this->createBatch($tenantId, $from, $to, $userId, $note, $agentId);
            $batch = $this->approveBatch($batch, $userId);

            return $this->markPaid($batch, $paymentDate, $reference, $userId);
        });
    }

    public function createBatch(int $tenantId, ?string $from, ?string $to, ?int $userId, ?string $note = null, ?int $agentId = null): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($tenantId, $from, $to, $userId, $note, $agentId): CommissionPayoutBatch {
            $this->lockTenant($tenantId);
            $ready = $this->collectLines($tenantId, $from, $to, $agentId)['ready'];
            if ($ready === []) {
                throw new RuntimeException('ไม่พบรายการค่าคอมที่พร้อมจ่าย (ผ่านเงื่อนไขก่อนจ่าย) ในช่วงวันที่ที่ระบุ');
            }
            if ($from === null || $to === null) {
                $dates = collect($ready)->pluck('policyDate')->filter()->sort()->values();
                $today = CarbonImmutable::now('Asia/Bangkok')->toDateString();
                $from ??= $dates->first() ?: $today;
                $to ??= $dates->last() ?: $today;
            }

            $batch = CommissionPayoutBatch::create([
                'tenant_id' => $tenantId,
                'from_date' => $from,
                'to_date' => $to,
                'status' => CommissionPayoutBatch::STATUS_DRAFT,
                'calc_version' => CommissionPayoutBatch::CALC_V2,
                'created_by_user_id' => $userId,
                'note' => $note,
            ]);

            $agents = $this->agentsById(array_column($ready, 'agentId'));
            $tenant = Tenant::query()->find($tenantId);
            $ledgerIds = [];
            $total = 0;
            foreach ($ready as $l) {
                $agent = $agents->get($l['agentId']);
                $snap = $agent ? $this->payeeSnapshot($agent, $tenant) : null;
                CommissionPayoutBatchItem::create([
                    'batch_id' => $batch->id,
                    'tenant_id' => $tenantId,
                    'policy_id' => $l['policyId'],
                    'line_type' => $l['lineType'],
                    'ledger_id' => $l['ledgerId'],
                    'agent_id' => $l['agentId'],
                    'source_agent_id' => $l['sourceAgentId'],
                    'agent_code' => $agent?->agent_code,
                    'vat_type' => $snap['vat_type'] ?? '1',
                    'payee_name' => $snap['payee_name'] ?? null,
                    'payee_kind' => $snap['payee_kind'] ?? null,
                    'payee_tax_id' => $agent?->tax_id,
                    'snapshot_base_premium' => round((float) $l['basePremium'], 2),
                    'snapshot_agent_commission' => PayoutTax::baht($l['main']),
                    'snapshot_rider_commission' => PayoutTax::baht($l['rider']),
                    'paid_ratio' => $l['ratio'][1] > 0 ? round($l['ratio'][0] / $l['ratio'][1], 4) : 1,
                    'entitlement_amount' => PayoutTax::baht($l['entitlement']),
                    'ratio_label' => $l['ratioLabel'],
                    'condition_override' => $l['override'] !== null,
                    'amount_source' => $l['amountSource'],
                    'item_status' => CommissionPayoutBatchItem::ITEM_PENDING,
                ]);
                if ($l['ledgerId'] !== null) {
                    $ledgerIds[] = $l['ledgerId'];
                }
                $total += $l['amount'];
            }
            $this->claimLedger($ledgerIds, (int) $batch->id);

            $batch->update([
                'total_agents' => count(array_unique(array_column($ready, 'agentId'))),
                'total_items' => count($ready),
                'total_amount' => PayoutTax::baht($total),
            ]);
            $this->applyPendingDeductions($batch, $tenantId);
            $this->recomputePayees($batch->fresh());

            $this->audit($tenantId, $userId, 'commission_payout.create', "batch:{$batch->id}", [
                'from' => $from, 'to' => $to, 'lines' => count($ready), 'amount' => PayoutTax::baht($total),
            ]);

            return $batch->fresh();
        });
    }

    private function claimLedger(array $ids, int $batchId): void
    {
        if ($ids === []) {
            return;
        }
        $claimed = DB::table('commission_ledgers')->whereIn('id', $ids)->whereNull('payout_batch_id')->update(['payout_batch_id' => $batchId]);
        if ($claimed !== count($ids)) {
            throw new RuntimeException('รายการค่าคอมบางรายการถูกนำเข้ารอบจ่ายอื่นไปแล้ว กรุณาลองใหม่');
        }
    }

    private function applyPendingDeductions(CommissionPayoutBatch $batch, int $tenantId): void
    {
        $totals = $batch->items()
            ->whereNotNull('agent_id')
            ->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)
            ->groupBy('agent_id')
            ->selectRaw('agent_id, SUM(snapshot_agent_commission + snapshot_rider_commission) as total')
            ->pluck('total', 'agent_id');

        foreach ($totals as $agentId => $total) {
            $available = PayoutTax::satang($total) - PayoutTax::satang($batch->adjustments()->where('agent_id', $agentId)->sum('amount'));
            $pending = CommissionPayoutAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('agent_id', $agentId)
                ->where('status', CommissionPayoutAdjustment::STATUS_PENDING)
                ->whereNull('batch_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($pending as $adj) {
                if ($available <= 0) {
                    break;
                }
                $amount = PayoutTax::satang($adj->amount);
                if ($amount <= $available) {
                    $adj->update(['batch_id' => $batch->id, 'status' => CommissionPayoutAdjustment::STATUS_APPLIED, 'applied_at' => now()]);
                    $available -= $amount;

                    continue;
                }
                CommissionPayoutAdjustment::create([
                    'batch_id' => $batch->id,
                    'tenant_id' => $adj->tenant_id,
                    'agent_id' => $adj->agent_id,
                    'agent_code' => $adj->agent_code,
                    'policy_id' => $adj->policy_id,
                    'type' => $adj->type,
                    'status' => CommissionPayoutAdjustment::STATUS_APPLIED,
                    'applied_at' => now(),
                    'amount' => PayoutTax::baht($available),
                    'reason' => $adj->reason.' (หักบางส่วน)',
                    'created_by_user_id' => $adj->created_by_user_id,
                ]);
                $adj->update(['amount' => PayoutTax::baht($amount - $available)]);
                $available = 0;
            }

            if (! $batch->isV2()) {
                continue;
            }
            $costs = DB::table('commission_ledgers')
                ->where('tenant_id', $tenantId)
                ->where('beneficiary_agent_id', $agentId)
                ->whereIn('payout_type', CommissionLedger::DEDUCTION_TYPES)
                ->where('status', CommissionLedger::STATUS_UNSETTLED)
                ->whereNull('payout_batch_id')
                ->where('amount', '<', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'payout_type', 'amount', 'policy_id']);
            foreach ($costs as $cost) {
                $amount = -PayoutTax::satang($cost->amount);
                if ($amount > $available) {
                    continue;
                }
                $this->claimLedger([(int) $cost->id], (int) $batch->id);
                CommissionPayoutAdjustment::create([
                    'batch_id' => $batch->id,
                    'tenant_id' => $tenantId,
                    'agent_id' => $agentId,
                    'agent_code' => Agent::query()->whereKey($agentId)->value('agent_code'),
                    'policy_id' => $cost->policy_id,
                    'ledger_id' => $cost->id,
                    'type' => CommissionPayoutAdjustment::LEDGER_TYPES[$cost->payout_type],
                    'status' => CommissionPayoutAdjustment::STATUS_APPLIED,
                    'applied_at' => now(),
                    'amount' => PayoutTax::baht($amount),
                    'reason' => CommissionPayoutAdjustment::TYPE_LABELS[CommissionPayoutAdjustment::LEDGER_TYPES[$cost->payout_type]],
                ]);
                $available -= $amount;
            }
        }
    }

    public function recomputePayees(CommissionPayoutBatch $batch, bool $freeze = false): void
    {
        if (! $batch->isV2()) {
            return;
        }
        $tenant = Tenant::query()->find($batch->tenant_id);
        $items = $batch->items()->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)->get();
        $adjustments = $batch->adjustments()->get()->groupBy('agent_id');
        $agentIds = $items->pluck('agent_id')->filter()->unique()->values()->all();
        $agents = $this->agentsById($agentIds);

        foreach ($agentIds as $agentId) {
            $agent = $agents->get((int) $agentId);
            if ($agent === null) {
                continue;
            }
            $sums = array_fill_keys(CommissionPayoutBatchItem::LINE_TYPES, 0);
            foreach ($items->where('agent_id', $agentId) as $item) {
                $sums[$item->line_type] = ($sums[$item->line_type] ?? 0) + PayoutTax::satang($item->snapshot_agent_commission) + PayoutTax::satang($item->snapshot_rider_commission);
            }
            $gross = array_sum($sums);
            $deduction = 0;
            foreach ($adjustments->get($agentId, collect()) as $adj) {
                $deduction += PayoutTax::satang($adj->amount);
            }
            $snap = $this->payeeSnapshot($agent, $tenant);
            $tax = PayoutTax::compute($gross, $deduction, $snap['vat_type'], PayoutTax::basisPoints($snap['wht_rate']));

            CommissionPayoutPayee::updateOrCreate(
                ['batch_id' => $batch->id, 'agent_id' => $agentId],
                array_merge($snap, [
                    'tenant_id' => $batch->tenant_id,
                    'base_amount' => PayoutTax::baht($sums[CommissionPayoutBatchItem::LINE_BASE]),
                    'self_mgmt_amount' => PayoutTax::baht($sums[CommissionPayoutBatchItem::LINE_SELF_MGMT]),
                    'referral_amount' => PayoutTax::baht($sums[CommissionPayoutBatchItem::LINE_REFERRAL]),
                    'override_amount' => PayoutTax::baht($sums[CommissionPayoutBatchItem::LINE_OVERRIDE]),
                    'gross_amount' => PayoutTax::baht($gross),
                    'deduction_amount' => PayoutTax::baht($tax['deduction']),
                    'taxable_amount' => PayoutTax::baht($tax['taxable']),
                    'vat_amount' => PayoutTax::baht($tax['vat']),
                    'total_amount' => PayoutTax::baht($tax['total']),
                    'wht_amount' => PayoutTax::baht($tax['wht']),
                    'net_amount' => PayoutTax::baht($tax['net']),
                    'frozen_at' => $freeze ? now() : null,
                ]),
            );
        }
        CommissionPayoutPayee::query()->where('batch_id', $batch->id)->whereNotIn('agent_id', $agentIds === [] ? [0] : $agentIds)->delete();
    }

    public function addAdjustment(CommissionPayoutBatch $batch, ?int $agentId, ?string $agentCode, float $amount, string $reason, ?int $userId): CommissionPayoutAdjustment
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException('ไม่สามารถแก้ไข batch ที่จ่ายแล้วหรือยกเลิกแล้ว');
        }
        if ($amount < 0) {
            throw new RuntimeException('ยอดหักต้องไม่ติดลบ');
        }
        if ($batch->isV2()) {
            if ($batch->status === CommissionPayoutBatch::STATUS_APPROVED) {
                throw new RuntimeException('รอบนี้อนุมัติแล้ว กรุณายกเลิกอนุมัติก่อนแก้ยอดหัก');
            }
            $gross = PayoutTax::satang($batch->items()->where('agent_id', $agentId)->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)->sum(DB::raw('snapshot_agent_commission + snapshot_rider_commission')));
            $already = PayoutTax::satang($batch->adjustments()->where('agent_id', $agentId)->sum('amount'));
            if ($already + PayoutTax::satang($amount) > $gross) {
                throw new RuntimeException('ยอดหักรวมเกินยอดค่าคอมของตัวแทนในรอบนี้');
            }
        }
        $adj = CommissionPayoutAdjustment::create([
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'agent_id' => $agentId,
            'agent_code' => $agentCode,
            'type' => CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT,
            'status' => CommissionPayoutAdjustment::STATUS_APPLIED,
            'applied_at' => now(),
            'amount' => $amount,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);
        $this->recomputePayees($batch);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.adjust', "batch:{$batch->id}", [
            'agent' => $agentCode, 'amount' => $amount, 'reason' => $reason,
        ]);

        return $adj;
    }

    public function markPaid(CommissionPayoutBatch $batch, string $paymentDate, ?string $reference, ?int $userId): CommissionPayoutBatch
    {
        if (! $batch->isV2()) {
            return $this->markPaidV1($batch, $paymentDate, $reference, $userId);
        }
        $excluded = DB::transaction(fn (): int => $this->excludeInvalidLines($batch));
        if ($excluded > 0) {
            throw new RuntimeException("มี {$excluded} รายการที่กรมธรรม์ถูกยกเลิกหรือลบหลังสร้างรอบ ระบบนำออกและคำนวณยอดใหม่แล้ว กรุณาตรวจสอบและอนุมัติอีกครั้ง");
        }

        return DB::transaction(function () use ($batch, $paymentDate, $reference, $userId): CommissionPayoutBatch {
            $this->lockTenant((int) $batch->tenant_id);
            $batch = $this->lockBatch($batch);
            $this->assertPayable($batch);

            $items = $batch->items()->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)->get();
            foreach ($items->where('line_type', CommissionPayoutBatchItem::LINE_BASE) as $item) {
                $paidBefore = PayoutTax::satang(DB::table('commission_payout_batch_items as bi')
                    ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
                    ->where('bi.policy_id', $item->policy_id)
                    ->where('bi.line_type', CommissionPayoutBatchItem::LINE_BASE)
                    ->where('bi.item_status', CommissionPayoutBatchItem::ITEM_PAID)
                    ->where('b.id', '!=', $batch->id)
                    ->sum(DB::raw('bi.snapshot_agent_commission + bi.snapshot_rider_commission')));
                $now = $paidBefore + PayoutTax::satang($item->total());
                $entitlement = PayoutTax::satang($item->entitlement_amount);
                if ($entitlement > 0 && $now > $entitlement) {
                    throw new RuntimeException("กรมธรรม์ #{$item->policy_id} จะจ่ายค่าคอมพื้นฐานเกินยอดสิทธิ์");
                }
                if ($entitlement > 0 && $now >= $entitlement) {
                    PolicyRebate::updateOrCreate(
                        ['policy_id' => $item->policy_id],
                        ['tenant_id' => $batch->tenant_id, 'agent_rebate_status' => self::PAID_STATUS, 'agent_receive_date' => $paymentDate],
                    );
                }
            }

            $ledgerIds = array_merge(
                $items->pluck('ledger_id')->filter()->all(),
                $batch->adjustments()->whereNotNull('ledger_id')->pluck('ledger_id')->all(),
            );
            if ($ledgerIds !== []) {
                DB::table('commission_ledgers')->whereIn('id', $ledgerIds)->update([
                    'status' => CommissionLedger::STATUS_SETTLED, 'paid_at' => now(), 'payout_batch_id' => $batch->id,
                ]);
            }
            $batch->items()->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)->update(['item_status' => CommissionPayoutBatchItem::ITEM_PAID]);

            $this->assignCertificates($batch, $paymentDate);

            $batch->update([
                'status' => CommissionPayoutBatch::STATUS_PAID,
                'paid_by_user_id' => $userId,
                'paid_at' => now(),
                'payment_date' => $paymentDate,
                'payment_reference' => $reference,
            ]);

            $this->audit($batch->tenant_id, $userId, 'commission_payout.mark_paid', "batch:{$batch->id}", [
                'lines' => $items->count(), 'payment_date' => $paymentDate, 'reference' => $reference,
                'net' => (float) $batch->payees()->sum('net_amount'), 'wht' => (float) $batch->payees()->sum('wht_amount'),
            ]);

            return $batch->fresh();
        });
    }

    private function assertPayable(CommissionPayoutBatch $batch): void
    {
        if ($batch->status === CommissionPayoutBatch::STATUS_PAID) {
            throw new RuntimeException('Batch นี้ถูกจ่ายแล้ว');
        }
        if ($batch->status === CommissionPayoutBatch::STATUS_CANCELLED) {
            throw new RuntimeException('Batch นี้ถูกยกเลิกแล้ว');
        }
        if ($batch->status !== CommissionPayoutBatch::STATUS_APPROVED) {
            throw new RuntimeException('ต้องอนุมัติ batch ก่อนจึงจะยืนยันการจ่ายได้');
        }
    }

    private function excludeInvalidLines(CommissionPayoutBatch $batch): int
    {
        $batch = $this->lockBatch($batch);
        $invalid = $batch->items()
            ->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)
            ->whereNotIn('policy_id', Policy::query()->whereIn('status', self::APPROVED_STATUSES)->select('id'))
            ->get();
        if ($invalid->isEmpty()) {
            return 0;
        }
        $ledgerIds = $invalid->pluck('ledger_id')->filter()->all();
        if ($ledgerIds !== []) {
            DB::table('commission_ledgers')->whereIn('id', $ledgerIds)->update(['payout_batch_id' => null]);
        }
        CommissionPayoutBatchItem::query()->whereIn('id', $invalid->pluck('id'))->update(['item_status' => CommissionPayoutBatchItem::ITEM_EXCLUDED]);
        $batch->update(['status' => CommissionPayoutBatch::STATUS_DRAFT, 'approved_by_user_id' => null, 'approved_at' => null]);
        $this->recomputePayees($batch);
        $this->audit((int) $batch->tenant_id, null, 'commission_payout.exclude', "batch:{$batch->id}", ['lines' => $invalid->pluck('id')->all()]);

        return $invalid->count();
    }

    private function assignCertificates(CommissionPayoutBatch $batch, string $paymentDate): void
    {
        $year = (int) substr($paymentDate, 0, 4);
        $payees = $batch->payees()->where('wht_amount', '>', 0)->whereNull('cert_no')->orderBy('agent_code')->get();
        foreach ($payees as $payee) {
            $seq = DB::table('tax_cert_sequences')->where('tenant_id', $batch->tenant_id)->where('year', $year)->lockForUpdate()->first();
            if ($seq === null) {
                DB::table('tax_cert_sequences')->insert(['tenant_id' => $batch->tenant_id, 'year' => $year, 'last_no' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $seq = DB::table('tax_cert_sequences')->where('tenant_id', $batch->tenant_id)->where('year', $year)->lockForUpdate()->first();
            }
            $next = (int) $seq->last_no + 1;
            DB::table('tax_cert_sequences')->where('id', $seq->id)->update(['last_no' => $next, 'updated_at' => now()]);
            $payee->update(['cert_book' => $year + 543, 'cert_no' => $next, 'cert_date' => $paymentDate]);
        }
    }

    private function markPaidV1(CommissionPayoutBatch $batch, string $paymentDate, ?string $reference, ?int $userId): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $paymentDate, $reference, $userId): CommissionPayoutBatch {
            $this->lockTenant((int) $batch->tenant_id);
            $batch = $this->lockBatch($batch);
            $this->assertPayable($batch);
            $items = $batch->items()->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)->get();

            foreach ($items as $item) {
                $paidElsewhere = CommissionPayoutBatchItem::query()
                    ->where('policy_id', $item->policy_id)
                    ->where('line_type', CommissionPayoutBatchItem::LINE_BASE)
                    ->where('batch_id', '!=', $batch->id)
                    ->where('item_status', CommissionPayoutBatchItem::ITEM_PAID)
                    ->exists();
                if ($paidElsewhere) {
                    throw new RuntimeException("กรมธรรม์ #{$item->policy_id} ถูกจ่ายค่าคอมไปแล้วโดย batch อื่น");
                }

                PolicyRebate::updateOrCreate(
                    ['policy_id' => $item->policy_id],
                    ['tenant_id' => $batch->tenant_id, 'agent_rebate_status' => self::PAID_STATUS, 'agent_receive_date' => $paymentDate],
                );

                $item->update(['item_status' => CommissionPayoutBatchItem::ITEM_PAID]);
            }

            $batch->update([
                'status' => CommissionPayoutBatch::STATUS_PAID,
                'paid_by_user_id' => $userId,
                'paid_at' => now(),
                'payment_date' => $paymentDate,
                'payment_reference' => $reference,
            ]);

            $this->audit($batch->tenant_id, $userId, 'commission_payout.mark_paid', "batch:{$batch->id}", [
                'items' => $items->count(), 'payment_date' => $paymentDate, 'reference' => $reference,
            ]);

            return $batch->fresh();
        });
    }

    public function approveBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $userId): CommissionPayoutBatch {
            $this->lockTenant((int) $batch->tenant_id);
            $batch = $this->lockBatch($batch);
            if (! in_array($batch->status, [CommissionPayoutBatch::STATUS_DRAFT, CommissionPayoutBatch::STATUS_GENERATED], true)) {
                throw new RuntimeException('อนุมัติได้เฉพาะ batch สถานะร่าง/สร้างเอกสารแล้ว');
            }
            $this->recomputePayees($batch, true);
            $batch->update([
                'status' => CommissionPayoutBatch::STATUS_APPROVED,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);
            $this->audit($batch->tenant_id, $userId, 'commission_payout.approve', "batch:{$batch->id}", [
                'net' => (float) $batch->payees()->sum('net_amount'),
            ]);

            return $batch->fresh();
        });
    }

    public function unapproveBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $userId): CommissionPayoutBatch {
            $batch = $this->lockBatch($batch);
            if ($batch->status !== CommissionPayoutBatch::STATUS_APPROVED) {
                throw new RuntimeException('ยกเลิกอนุมัติได้เฉพาะ batch ที่อนุมัติแล้วและยังไม่จ่าย');
            }
            $batch->update([
                'status' => CommissionPayoutBatch::STATUS_DRAFT,
                'approved_by_user_id' => null,
                'approved_at' => null,
            ]);
            $this->recomputePayees($batch);
            $this->audit($batch->tenant_id, $userId, 'commission_payout.unapprove', "batch:{$batch->id}", []);

            return $batch->fresh();
        });
    }

    public function cancelBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $userId): CommissionPayoutBatch {
            $this->lockTenant((int) $batch->tenant_id);
            $batch = $this->lockBatch($batch);
            if ($batch->status === CommissionPayoutBatch::STATUS_PAID) {
                throw new RuntimeException('ไม่สามารถยกเลิก batch ที่จ่ายแล้ว (ต้องทำ reversal)');
            }
            $batch->update(['status' => CommissionPayoutBatch::STATUS_CANCELLED]);
            DB::table('commission_ledgers')->where('payout_batch_id', $batch->id)->update(['payout_batch_id' => null]);
            CommissionPayoutAdjustment::query()
                ->where('batch_id', $batch->id)
                ->whereIn('type', [CommissionPayoutAdjustment::TYPE_INSTALLMENT_COST, CommissionPayoutAdjustment::TYPE_COMMISSION_DEDUCTED])
                ->delete();
            $released = CommissionPayoutAdjustment::query()
                ->where('batch_id', $batch->id)
                ->where('type', CommissionPayoutAdjustment::TYPE_CLAWBACK)
                ->get();
            foreach ($released as $adj) {
                $adj->update(['batch_id' => null, 'status' => CommissionPayoutAdjustment::STATUS_PENDING, 'applied_at' => null]);
            }
            $this->mergePendingClawbacks((int) $batch->tenant_id, $released->map(fn ($a) => [$a->policy_id, $a->agent_id])->unique()->all());
            $this->audit($batch->tenant_id, $userId, 'commission_payout.cancel', "batch:{$batch->id}", []);

            return $batch->fresh();
        });
    }

    public function mergePendingClawbacks(int $tenantId, array $pairs): void
    {
        foreach ($pairs as [$policyId, $agentId]) {
            $rows = CommissionPayoutAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->where('policy_id', $policyId)
                ->where('agent_id', $agentId)
                ->where('type', CommissionPayoutAdjustment::TYPE_CLAWBACK)
                ->where('status', CommissionPayoutAdjustment::STATUS_PENDING)
                ->whereNull('batch_id')
                ->orderBy('id')
                ->get();
            if ($rows->count() < 2) {
                continue;
            }
            $keep = $rows->first();
            $keep->update(['amount' => PayoutTax::baht((int) $rows->sum(fn ($r) => PayoutTax::satang($r->amount))), 'reason' => preg_replace('/ \(หักบางส่วน\)$/u', '', (string) $keep->reason)]);
            CommissionPayoutAdjustment::query()->whereIn('id', $rows->slice(1)->pluck('id'))->delete();
        }
    }

    public function overridePolicy(int $tenantId, int $policyId, string $reason, ?int $userId): PolicyPayoutOverride
    {
        $override = PolicyPayoutOverride::create([
            'tenant_id' => $tenantId,
            'policy_id' => $policyId,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);
        $bypassed = $this->eligibilityWithoutOverride($tenantId, $policyId);
        $this->audit($tenantId, $userId, 'commission_payout.override', "policy:{$policyId}", [
            'reason' => $reason, 'override_id' => $override->id, 'bypassed' => $bypassed,
        ]);

        return $override;
    }

    public function revokeOverride(int $tenantId, int $policyId, ?int $userId): int
    {
        $count = PolicyPayoutOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('policy_id', $policyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $userId]);
        if ($count > 0) {
            $this->audit($tenantId, $userId, 'commission_payout.override_revoked', "policy:{$policyId}", []);
        }

        return $count;
    }

    private function eligibilityWithoutOverride(int $tenantId, int $policyId): array
    {
        $result = $this->eligibility->evaluate($tenantId, [$policyId])[$policyId] ?? null;

        return $result['override']['bypassed'] ?? [];
    }

    public function batchLines(CommissionPayoutBatch $batch, ?int $agentId = null): Collection
    {
        return $batch->items()
            ->with(['policy:id,policy_no,application_no,customer_id', 'policy.customer:id,first_name,last_name', 'sourceAgent:id,agent_code,first_name,last_name'])
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->orderBy('agent_code')
            ->orderBy('id')
            ->get();
    }

    public function companyInfo(int $tenantId): array
    {
        $t = Tenant::query()->find($tenantId);
        $address = collect([$t?->address, $t?->district, $t?->amphoe, $t?->province, $t?->postcode])->filter(fn ($v) => filled($v))->implode(' ');

        return [
            'name' => $t?->name ?? 'InsureHub',
            'nameEn' => $t?->name_en,
            'taxId' => $t?->tax_id,
            'oicLicense' => $t?->oic_license,
            'address' => $address,
            'phone' => $t?->phone,
            'email' => $t?->email,
            'website' => $t?->website,
        ];
    }

    public function buildAgentPdfData(CommissionPayoutBatch $batch, ?int $agentId, ?string $agentCode): ?array
    {
        if (! $batch->isV2()) {
            return $this->buildAgentPdfDataV1($batch, $agentId, $agentCode);
        }
        if ($agentId === null && $agentCode !== null) {
            $agentId = $batch->items()->where('agent_code', $agentCode)->value('agent_id');
        }
        $payee = $batch->payees()->where('agent_id', $agentId)->first();
        if ($payee === null) {
            return null;
        }
        $lines = $this->batchLines($batch, (int) $agentId)->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED);
        $sections = [];
        foreach (CommissionPayoutBatchItem::LINE_TYPES as $type) {
            $rows = $lines->where('line_type', $type)->values();
            if ($rows->isEmpty()) {
                continue;
            }
            $sections[] = [
                'type' => $type,
                'label' => CommissionPayoutBatchItem::LINE_LABELS[$type],
                'total' => round($rows->sum(fn ($i) => $i->total()), 2),
                'rows' => $rows->map(fn (CommissionPayoutBatchItem $i): array => [
                    'policyNo' => $i->policy?->policy_no,
                    'applicationNo' => $i->policy?->application_no,
                    'customerName' => $i->policy?->customer ? trim("{$i->policy->customer->first_name} {$i->policy->customer->last_name}") : null,
                    'source' => $i->sourceAgent ? trim($i->sourceAgent->agent_code.' · '.trim(($i->sourceAgent->first_name ?? '').' '.($i->sourceAgent->last_name ?? ''))) : null,
                    'base' => (float) $i->snapshot_base_premium,
                    'ratioLabel' => $i->ratio_label,
                    'override' => (bool) $i->condition_override,
                    'commission' => round($i->total(), 2),
                ])->all(),
            ];
        }
        $deductions = $batch->adjustments()->with('policy:id,policy_no,application_no')->where('agent_id', $agentId)->orderBy('id')->get()
            ->map(fn (CommissionPayoutAdjustment $a): array => [
                'label' => CommissionPayoutAdjustment::TYPE_LABELS[$a->type] ?? $a->type,
                'reason' => $a->reason,
                'policyNo' => $a->policy?->policy_no ?: $a->policy?->application_no,
                'amount' => (float) $a->amount,
            ])->all();

        return [
            'version' => 2,
            'vatType' => $payee->vat_type,
            'company' => $this->companyInfo((int) $batch->tenant_id),
            'agent' => [
                'code' => $payee->agent_code,
                'name' => $payee->payee_name,
                'kind' => $payee->payee_kind,
                'taxId' => $payee->payee_tax_id,
                'address' => $payee->payee_address,
                'bankName' => $payee->bank_name,
                'bankAccountNo' => $payee->bank_account_no,
            ],
            'sections' => $sections,
            'deductions' => $deductions,
            'items' => $lines->map(fn ($i) => ['commission' => round($i->total(), 2)])->values()->all(),
            'totals' => [
                'base' => (float) $payee->base_amount,
                'selfMgmt' => (float) $payee->self_mgmt_amount,
                'referral' => (float) $payee->referral_amount,
                'override' => (float) $payee->override_amount,
                'commission' => (float) $payee->gross_amount,
                'deduct' => (float) $payee->deduction_amount,
                'net' => round((float) $payee->gross_amount - (float) $payee->deduction_amount, 2),
                'baseExVat' => (float) $payee->taxable_amount,
                'vat' => (float) $payee->vat_amount,
                'totalWithVat' => (float) $payee->total_amount,
                'whtRate' => (float) $payee->wht_rate,
                'wht' => (float) $payee->wht_amount,
                'payable' => (float) $payee->net_amount,
                'payableText' => PayoutTax::bahtText((float) $payee->net_amount),
            ],
            'payee' => [
                'id' => (string) $payee->id,
                'whtForm' => $payee->wht_form,
                'whtFormLabel' => CommissionPayoutPayee::FORM_LABELS[$payee->wht_form] ?? null,
                'certificate' => $payee->certificateLabel(),
            ],
            'batch' => [
                'id' => $batch->id,
                'docNo' => sprintf('PAY-%s-%05d', $batch->created_at?->format('Ym') ?? date('Ym'), $batch->id),
                'from' => $batch->from_date?->toDateString(),
                'to' => $batch->to_date?->toDateString(),
                'status' => $batch->status,
                'paymentDate' => $batch->payment_date?->toDateString(),
                'paymentReference' => $batch->payment_reference,
            ],
        ];
    }

    private function buildAgentPdfDataV1(CommissionPayoutBatch $batch, ?int $agentId, ?string $agentCode): ?array
    {
        $items = $batch->items()
            ->with(['policy:id,policy_no,application_no,customer_id', 'policy.customer:id,first_name,last_name', 'agent:id,agent_code,first_name,last_name,kind,juristic_name,tax_id,vat_type,has_vat,vat_mode'])
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($agentId === null && $agentCode !== null, fn ($q) => $q->where('agent_code', $agentCode))
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $first = $items->first();
        $vatType = $first->vat_type ?: ($first->agent ? $this->resolveVatType($first->agent) : '1');

        $rows = [];
        $commission = 0.0;
        foreach ($items as $it) {
            $amt = $it->total();
            $commission += $amt;
            $cust = $it->policy?->customer;
            $rows[] = [
                'policyNo' => $it->policy?->policy_no,
                'applicationNo' => $it->policy?->application_no,
                'customerName' => $cust ? trim("{$cust->first_name} {$cust->last_name}") : null,
                'base' => (float) $it->snapshot_base_premium,
                'commission' => round($amt, 2),
            ];
        }

        $deduct = (float) $batch->adjustments()
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($agentId === null && $agentCode !== null, fn ($q) => $q->where('agent_code', $agentCode))
            ->sum('amount');

        $afterDeduct = round($commission - $deduct, 2);
        $vat = 0.0;
        $baseExVat = $afterDeduct;
        $payable = $afterDeduct;
        if ($vatType === '2') {
            $vat = round($afterDeduct * self::VAT_RATE, 2);
            $payable = round($afterDeduct + $vat, 2);
        } elseif ($vatType === '3') {
            $baseExVat = round($afterDeduct / (1 + self::VAT_RATE), 2);
            $vat = round($afterDeduct - $baseExVat, 2);
            $payable = $afterDeduct;
        }

        return [
            'version' => 1,
            'vatType' => $vatType,
            'company' => $this->companyInfo((int) $batch->tenant_id),
            'agent' => [
                'code' => $first->agent_code ?? $agentCode,
                'name' => $first->payeeName(),
                'kind' => $first->payee_kind ?? $first->agent?->kind,
                'taxId' => $first->payee_tax_id ?? $first->agent?->tax_id,
            ],
            'items' => $rows,
            'totals' => [
                'commission' => round($commission, 2),
                'deduct' => round($deduct, 2),
                'net' => $afterDeduct,
                'baseExVat' => $baseExVat,
                'vat' => $vat,
                'payable' => $payable,
            ],
            'batch' => [
                'id' => $batch->id,
                'from' => $batch->from_date?->toDateString(),
                'to' => $batch->to_date?->toDateString(),
            ],
        ];
    }

    public function certificateData(CommissionPayoutPayee $payee): array
    {
        $batch = $payee->batch;

        return [
            'company' => $this->companyInfo((int) $payee->tenant_id),
            'payee' => [
                'name' => $payee->payee_name,
                'code' => $payee->agent_code,
                'kind' => $payee->payee_kind,
                'taxId' => $payee->payee_tax_id,
                'address' => $payee->payee_address,
            ],
            'form' => $payee->wht_form,
            'formLabel' => CommissionPayoutPayee::FORM_LABELS[$payee->wht_form] ?? null,
            'book' => $payee->cert_book,
            'number' => $payee->cert_no,
            'label' => $payee->certificateLabel(),
            'date' => $payee->cert_date?->toDateString() ?? $batch?->payment_date?->toDateString(),
            'rate' => (float) $payee->wht_rate,
            'paidAmount' => (float) $payee->taxable_amount,
            'whtAmount' => (float) $payee->wht_amount,
            'whtText' => PayoutTax::bahtText((float) $payee->wht_amount),
            'batchId' => $batch?->id,
        ];
    }

    public function exportRows(CommissionPayoutBatch $batch): array
    {
        if (! $batch->isV2()) {
            return $this->exportRowsV1($batch);
        }

        return $this->batchLines($batch)->map(fn (CommissionPayoutBatchItem $it): array => [
            'batch_id' => $batch->id,
            'agent_code' => $it->agent_code,
            'agent_name' => $it->payeeName(),
            'line_type' => CommissionPayoutBatchItem::LINE_LABELS[$it->line_type] ?? $it->line_type,
            'policy_no' => $it->policy?->policy_no,
            'application_no' => $it->policy?->application_no,
            'customer_name' => $it->policy?->customer ? trim("{$it->policy->customer->first_name} {$it->policy->customer->last_name}") : null,
            'from_agent' => $it->sourceAgent ? trim($it->sourceAgent->agent_code.' '.trim(($it->sourceAgent->first_name ?? '').' '.($it->sourceAgent->last_name ?? ''))) : null,
            'base_premium' => round((float) $it->snapshot_base_premium, 2),
            'ratio' => $it->ratio_label,
            'amount' => round($it->total(), 2),
            'override' => $it->condition_override ? 'ยกเว้นเงื่อนไข' : '',
            'item_status' => $it->item_status,
            'payment_date' => $batch->payment_date?->format('Y-m-d'),
        ])->all();
    }

    public function payeeRows(CommissionPayoutBatch $batch): array
    {
        return $batch->payees()->orderBy('agent_code')->get()->map(fn (CommissionPayoutPayee $p): array => [
            'agent_code' => $p->agent_code,
            'payee_name' => $p->payee_name,
            'kind' => $p->payee_kind === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา',
            'base' => (float) $p->base_amount,
            'self_mgmt' => (float) $p->self_mgmt_amount,
            'referral' => (float) $p->referral_amount,
            'override' => (float) $p->override_amount,
            'gross' => (float) $p->gross_amount,
            'deduction' => (float) $p->deduction_amount,
            'taxable' => (float) $p->taxable_amount,
            'vat' => (float) $p->vat_amount,
            'total' => (float) $p->total_amount,
            'wht_rate' => (float) $p->wht_rate,
            'wht' => (float) $p->wht_amount,
            'net' => (float) $p->net_amount,
            'certificate' => $p->certificateLabel(),
        ])->all();
    }

    public function bankRows(CommissionPayoutBatch $batch): array
    {
        $i = 0;

        return $batch->payees()->where('net_amount', '>', 0)->orderBy('agent_code')->get()->map(function (CommissionPayoutPayee $p) use ($batch, &$i): array {
            $i++;

            return [
                'seq' => $i,
                'agent_code' => $p->agent_code,
                'account_name' => $p->bank_account_name ?: $p->payee_name,
                'bank' => $p->bank_name,
                'account_no' => $p->bank_account_no,
                'amount' => number_format((float) $p->net_amount, 2, '.', ''),
                'reference' => sprintf('PAY%05d-%s', $batch->id, $p->agent_code),
                'missing' => blank($p->bank_account_no) ? 'ไม่มีเลขบัญชี' : '',
            ];
        })->all();
    }

    private function exportRowsV1(CommissionPayoutBatch $batch): array
    {
        $items = $batch->items()
            ->with(['policy:id,policy_no,application_no,created_at,customer_id', 'policy.customer:id,first_name,last_name', 'agent:id,agent_code,first_name,last_name,kind,juristic_name,vat_type'])
            ->orderBy('agent_code')
            ->get();

        $rows = [];
        foreach ($items as $it) {
            $cust = $it->policy?->customer;
            $rows[] = [
                'application_no' => $it->policy?->application_no,
                'policy_no' => $it->policy?->policy_no,
                'create_date' => $it->policy?->created_at?->format('Y-m-d'),
                'agent_code' => $it->agent_code,
                'agent_name' => $it->payeeName(),
                'vat_type' => $it->vat_type,
                'customer_name' => $cust ? trim("{$cust->first_name} {$cust->last_name}") : null,
                'base_premium' => round((float) $it->snapshot_base_premium, 2),
                'main_commission' => round((float) $it->snapshot_agent_commission, 2),
                'rider_commission' => round((float) $it->snapshot_rider_commission, 2),
                'total_commission' => round($it->total(), 2),
                'amount_source' => $it->amount_source,
                'item_status' => $it->item_status,
                'payment_date' => $batch->payment_date?->format('Y-m-d'),
                'payment_reference' => $batch->payment_reference,
            ];
        }

        return $rows;
    }

    public function whtReport(int $tenantId, string $month, string $form): array
    {
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $rows = CommissionPayoutPayee::query()
            ->join('commission_payout_batches as b', 'b.id', '=', 'commission_payout_payees.batch_id')
            ->where('commission_payout_payees.tenant_id', $tenantId)
            ->where('b.status', CommissionPayoutBatch::STATUS_PAID)
            ->where('commission_payout_payees.wht_form', $form)
            ->where('commission_payout_payees.wht_amount', '>', 0)
            ->whereBetween('commission_payout_payees.cert_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->orderBy('commission_payout_payees.cert_date')
            ->orderBy('commission_payout_payees.cert_no')
            ->select('commission_payout_payees.*')
            ->get();

        $i = 0;
        $list = $rows->map(function (CommissionPayoutPayee $p) use (&$i): array {
            $i++;

            return [
                'seq' => $i,
                'payeeId' => (string) $p->id,
                'batchId' => (string) $p->batch_id,
                'taxId' => $p->payee_tax_id,
                'name' => $p->payee_name,
                'agentCode' => $p->agent_code,
                'address' => $p->payee_address,
                'date' => $p->cert_date?->toDateString(),
                'incomeType' => 'ค่านายหน้า',
                'rate' => (float) $p->wht_rate,
                'amount' => (float) $p->taxable_amount,
                'wht' => (float) $p->wht_amount,
                'condition' => 1,
                'certificate' => $p->certificateLabel(),
            ];
        })->all();

        return [
            'month' => $start->format('Y-m'),
            'form' => $form,
            'formLabel' => CommissionPayoutPayee::FORM_LABELS[$form] ?? $form,
            'rows' => $list,
            'totals' => [
                'count' => count($list),
                'amount' => round(array_sum(array_column($list, 'amount')), 2),
                'wht' => round(array_sum(array_column($list, 'wht')), 2),
            ],
        ];
    }

    public function vatTag(string $vatType): string
    {
        return match ($vatType) {
            '2' => '(VAT_E)',
            '3' => '(VAT_I)',
            default => '',
        };
    }

    private function audit(int $tenantId, ?int $userId, string $action, string $target, array $metadata): void
    {
        AuditEntry::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'occurred_at' => now(),
            'actor' => $userId ? (string) $userId : 'system',
            'action' => $action,
            'target' => $target,
            'result' => 'success',
            'metadata' => $metadata,
        ]);
    }
}
