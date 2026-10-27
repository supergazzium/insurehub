<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\AuditEntry;
use App\Models\CommissionLedger;
use App\Models\CommissionPayoutAdjustment;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchAgent;
use App\Models\CommissionPayoutBatchItem;
use App\Models\CommissionPayoutPayee;
use App\Models\PolicyRebate;
use App\Support\PayoutTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Agent commission payout batches (DEV Spec ชุดที่ 2). Builds a payout run from
 * the eligible policies in a วันแจ้งงาน range, snapshots the agent-commission
 * amounts (source of truth), and marks the batch paid in one transaction —
 * reflecting the paid state into policy_rebates.agent_rebate_status /
 * agent_receive_date (the legacy Rebate_Status_AG / Rebate_Rec_Date_AG fields).
 */
class CommissionPayoutService
{
    /** Statuses that count a policy as "approved" for payout eligibility. */
    private const APPROVED_STATUSES = ['active', 'issued'];

    /** Value written to policy_rebates.agent_rebate_status when a batch is paid. */
    public const PAID_STATUS = 'paid';

    /** Withholding-tax rate applied to agent commission (หัก ณ ที่จ่าย 3%). */
    public const WHT_RATE = 0.03;

    /**
     * Full payee money breakdown for one beneficiary — the exact golden-CSV rule
     * (tests/fixtures/payout-golden.csv). `$gross` is the sum of the agent's
     * commission lines; `$deduction` is any clawback/correction applied BEFORE
     * tax. Returns all persisted payee amounts.
     *
     *   taxable = include: gross/1.07 · else: gross − deduction
     *   vat     = include: gross − taxable · exclude: taxable × 7% · none: 0
     *   total   = include: gross · exclude: taxable + vat · none: taxable
     *   wht     = taxable × wht_rate
     *   net     = total − wht
     *
     * @return array{taxable: float, vat: float, total: float, wht: float, net: float}
     */
    public static function payeeAmounts(float $gross, float $deduction, string $vatType, float $whtRate = self::WHT_RATE): array
    {
        $afterDeduct = round($gross - $deduction, 2);
        if ($vatType === '3') {              // VAT Include — VAT carved out of gross
            $taxable = round($afterDeduct / 1.07, 2);
            $vat = round($afterDeduct - $taxable, 2);
            $total = $afterDeduct;
        } elseif ($vatType === '2') {        // VAT Exclude — VAT added on top
            $taxable = $afterDeduct;
            $vat = round($afterDeduct * 0.07, 2);
            $total = round($afterDeduct + $vat, 2);
        } else {                             // non-VAT
            $taxable = $afterDeduct;
            $vat = 0.0;
            $total = $afterDeduct;
        }
        $wht = round($taxable * $whtRate, 2);
        $net = round($total - $wht, 2);

        return ['taxable' => $taxable, 'vat' => $vat, 'total' => $total, 'wht' => $wht, 'net' => $net];
    }

    /**
     * Eligible-item query, shared by preview() and createBatch() so the
     * snapshot always matches what was previewed. Returns a query builder over
     * policies joined to the amount sources; caller selects/aggregates.
     *
     * Eligibility (mapped from the Access VBA filter):
     *  - has a writing agent
     *  - created_at (วันแจ้งงาน) within [from, to] Bangkok, half-open on the end
     *  - not cancelled
     *  - status approved (active/issued)
     *  - agent commission not already paid (no paid rebate row)
     *  - not already sitting in a non-cancelled batch
     *
     * DIVERGENCE FROM THE ACCESS SPEC (documented decision, M3 audit):
     * The legacy VBA also gated on `Freelook_Status = TRUE` and
     * `Payment_InsComp_Status NOT IN ('2','5')`. Both are intentionally OMITTED
     * here:
     *   - Freelook only applies to life products; gating on it would wrongly
     *     exclude every non-life payout. The web model tracks freelook per-life
     *     policy (freelook_end_date) for the follow-up list, not as a payout gate.
     *   - There is no clean web equivalent of Payment_InsComp_Status; the
     *     insurer-payment state now lives in the separate receivables module.
     * If finance requires either gate, add it here per product-type — do not
     * re-introduce a blanket freelook check.
     */
    private function eligibleQuery(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null)
    {
        $q = DB::table('policies as p')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.writing_agent_id')
            ->where('p.status', '!=', 'cancelled');

        if ($from !== null) {
            $q->where('p.created_at', '>=', CarbonImmutable::parse($from, 'Asia/Bangkok')->startOfDay());
        }
        if ($to !== null) {
            $q->where('p.created_at', '<', CarbonImmutable::parse($to, 'Asia/Bangkok')->addDay()->startOfDay());
        }
        if ($agentId !== null) {
            $q->where('p.writing_agent_id', $agentId);
        }

        return $q
            ->whereIn('p.status', self::APPROVED_STATUSES)
            // Not already marked paid on the agent leg.
            ->where(function ($w): void {
                $w->whereNull('r.agent_rebate_status')
                    ->orWhere('r.agent_rebate_status', '')
                    ->orWhere('r.agent_rebate_status', '!=', self::PAID_STATUS);
            })
            // Not already in a LIVE batch. CANCELLED and REVERSED batches are
            // not live — their items are pending again (a reversal voids the
            // payment and frees the policies), so they must NOT block
            // eligibility, otherwise re-issuing a corrected batch after a
            // reversal would find nothing.
            ->whereNotExists(function ($q) use ($tenantId): void {
                $q->select(DB::raw(1))
                    ->from('commission_payout_batch_items as bi')
                    ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
                    ->whereColumn('bi.policy_id', 'p.id')
                    ->where('bi.tenant_id', $tenantId)
                    ->whereNotIn('b.status', [
                        CommissionPayoutBatch::STATUS_CANCELLED,
                        CommissionPayoutBatch::STATUS_REVERSED,
                    ]);
            });
    }

    /**
     * Canonical VAT type for payout math + PDF template: '1' none, '2' exclude
     * (add VAT on top), '3' include (VAT inside). Prefers the current agent
     * fields has_vat + vat_mode; falls back to the legacy numeric vat_type.
     *
     * @param object $agent  row/model exposing has_vat, vat_mode, vat_type
     */
    private function resolveVatType(object $agent): string
    {
        $hasVat = (bool) ($agent->has_vat ?? false);
        if ($hasVat) {
            $mode = (string) ($agent->vat_mode ?? '');
            return $mode === 'include' ? '3' : '2'; // default VAT agents to exclude
        }
        // Not flagged has_vat — honor a legacy numeric vat_type if present.
        $legacy = (string) ($agent->vat_type ?? '');
        if (in_array($legacy, ['1', '2', '3'], true)) {
            return $legacy;
        }

        return '1';
    }

    // ── 3-stage ledger-driven payout (calc_version 2) ───────────────────────
    // The v2 path consumes the CommissionLedger rows MgmCommissionEngine already
    // accrues (DIRECT_COMMISSION / MANAGEMENT_DIFFERENTIAL / REFERRAL_FEE) rather
    // than re-deriving from policies.comm_hub_to_agent_amount. Grouping is by
    // BENEFICIARY (so uplines get paid), one batch item per ledger line.
    //
    // DECISION (confirmed): the seller's DIRECT_COMMISSION stays on the existing
    // per-policy path — its ledger is NOT stamped payout_batch_id and is not
    // "consumed" here. The batch only ADDS the previously-unpaid upline override
    // + referral. A `base` item is still snapshotted for the statement, but it
    // carries no ledger linkage (ledger_id set, payout_batch_id left NULL).

    public const CALC_VERSION = 2;

    /** payout_type → batch-item line_type. */
    private const LINE_TYPE = [
        CommissionLedger::TYPE_DIRECT_COMMISSION => 'base',
        CommissionLedger::TYPE_MANAGEMENT_DIFFERENTIAL => 'override',
        CommissionLedger::TYPE_REFERRAL_FEE => 'referral',
    ];

    /** Ledger line types that the batch actually consumes (stamps + settles).
     *  DIRECT is excluded — it pays via the per-policy path. */
    private const CONSUMED_TYPES = [
        CommissionLedger::TYPE_MANAGEMENT_DIFFERENTIAL,
        CommissionLedger::TYPE_REFERRAL_FEE,
    ];

    /**
     * Unpaid payable ledger lines for a tenant, partitioned ready/waiting by the
     * PayoutEligibility gate. Each line is one CommissionLedger row not yet in a
     * live batch.
     *
     * @return array{ready: list<array<string,mixed>>, waiting: list<array<string,mixed>>}
     */
    public function collectLines(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null): array
    {
        $q = CommissionLedger::query()
            ->join('policies as p', 'p.id', '=', 'commission_ledgers.policy_id')
            ->join('agents as a', 'a.id', '=', 'commission_ledgers.beneficiary_agent_id')
            ->where('commission_ledgers.tenant_id', $tenantId)
            ->where('commission_ledgers.status', CommissionLedger::STATUS_UNSETTLED)
            ->whereNull('commission_ledgers.payout_batch_id')
            // Exclude ledgers already snapshotted into a live batch item — covers
            // DIRECT lines too (they're batched as `base` but never stamped
            // payout_batch_id, so they'd otherwise reappear as "ready").
            ->whereNotExists(function ($w) {
                $w->select(DB::raw(1))
                    ->from('commission_payout_batch_items as bi')
                    ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
                    ->whereColumn('bi.ledger_id', 'commission_ledgers.id')
                    ->whereNotIn('b.status', [CommissionPayoutBatch::STATUS_CANCELLED, CommissionPayoutBatch::STATUS_REVERSED]);
            })
            ->whereIn('commission_ledgers.payout_type', CommissionLedger::PAYABLE_TYPES)
            ->whereNull('p.deleted_at')
            ->where('p.status', '!=', 'cancelled')
            ->when($from !== null, fn ($qq) => $qq->where('p.created_at', '>=', CarbonImmutable::parse($from, 'Asia/Bangkok')->startOfDay()))
            ->when($to !== null, fn ($qq) => $qq->where('p.created_at', '<', CarbonImmutable::parse($to, 'Asia/Bangkok')->addDay()->startOfDay()))
            ->when($agentId !== null, fn ($qq) => $qq->where('commission_ledgers.beneficiary_agent_id', $agentId))
            ->select([
                'commission_ledgers.id as ledger_id',
                'commission_ledgers.policy_id',
                'commission_ledgers.payout_type',
                'commission_ledgers.beneficiary_agent_id',
                'commission_ledgers.source_agent_id',
                'commission_ledgers.amount',
                'p.policy_no', 'p.application_no', 'p.created_at as policy_created_at',
                'p.main_premium', 'p.discount_amount', 'p.credit_card_fee',
                'a.agent_code', 'a.has_vat', 'a.vat_mode', 'a.vat_type', 'a.kind as agent_kind',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
            ])
            ->get();

        $policyIds = $q->pluck('policy_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $gates = $policyIds === [] ? [] : app(PayoutEligibility::class)->evaluate($tenantId, $policyIds);

        $ready = [];
        $waiting = [];
        foreach ($q as $r) {
            $gate = $gates[(int) $r->policy_id] ?? null;
            $blocked = $gate !== null
                && ($gate['override'] ?? null) === null
                && (! empty($gate['gates']) || ($gate['customerReason'] ?? null) !== null);
            $line = [
                'ledgerId' => (int) $r->ledger_id,
                'policyId' => (int) $r->policy_id,
                'policyNo' => $r->policy_no,
                'applicationNo' => $r->application_no,
                'payoutType' => $r->payout_type,
                'lineType' => self::LINE_TYPE[$r->payout_type] ?? 'base',
                'beneficiaryAgentId' => (int) $r->beneficiary_agent_id,
                'sourceAgentId' => $r->source_agent_id !== null ? (int) $r->source_agent_id : null,
                'agentCode' => $r->agent_code,
                'agentName' => $r->agent_name,
                'amount' => round((float) $r->amount, 2),
                'vatType' => $this->resolveVatType($r),
                'agentKind' => $r->agent_kind,
                'policyCreatedAt' => $r->policy_created_at,
                'override' => $gate['override'] ?? null,
                'reasons' => $gate['gates'] ?? [],
                'raw' => $r,
            ];
            if ($blocked) {
                $line['reasons'] = array_merge($gate['gates'] ?? [], ($gate['customerReason'] ?? null) ? [$gate['customerReason']] : []);
                $waiting[] = $line;
            } else {
                $ready[] = $line;
            }
        }

        return ['ready' => $ready, 'waiting' => $waiting];
    }

    /**
     * Resolve one policy's frozen agent-commission amount + its source.
     * Precedence: actual rebate → calculated rebate → policy comm column.
     *
     * @return array{main: float, rider: float, base: float, source: string}
     */
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

    /**
     * Build the full frozen snapshot for one policy row — the extra columns the
     * formal per-agent statement (reference PDF) needs beyond the base amounts.
     * WHT is 3% of the item's total commission (locked decision); the
     * payoff/discount column maps to policies.discount_amount and the card fee
     * to policies.credit_card_fee.
     *
     * @param  array{main: float, rider: float, base: float, source: string}  $amt
     * @return array<string, mixed>
     */
    private function snapshotColumns(object $row, array $amt): array
    {
        $itemCommission = $amt['main'] + $amt['rider'];
        // เบี้ยประกัน — net = main premium leg; total = full premium paid (fallbacks).
        $net = (float) ($row->main_premium ?? 0);
        $total = (float) ($row->total_premium_paid ?? $row->annual_premium ?? $row->main_premium ?? 0);
        // policies has no stored agent-commission rate column; derive the main
        // rate from commission ÷ net premium (fraction). Rider rate comes from
        // the policy_riders subquery.
        $mainRate = ($net > 0 && $amt['main'] > 0) ? round($amt['main'] / $net, 4) : null;

        return [
            'snapshot_net_premium' => round($net, 2),
            'snapshot_total_premium' => round($total, 2),
            'snapshot_main_rate' => $mainRate,
            'snapshot_rider_rate' => $row->rider_rate_ag !== null ? (float) $row->rider_rate_ag : null,
            'snapshot_payoff_discount' => round((float) ($row->discount_amount ?? 0), 2),
            'snapshot_card_fee' => round((float) ($row->credit_card_fee ?? 0), 2),
            'snapshot_wht_rate' => self::WHT_RATE,
            'snapshot_wht_amount' => round($itemCommission * self::WHT_RATE, 2),
            'snapshot_customer_name' => $row->customer_name ? trim((string) $row->customer_name) : null,
            'snapshot_rider_name' => $row->rider_names ? trim((string) $row->rider_names) : null,
            'snapshot_note' => null,
        ];
    }

    private function rows(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null, ?array $agentIds = null)
    {
        $rows = $this->eligibleQuery($tenantId, $from, $to, $agentId)
            ->when($agentIds !== null && $agentIds !== [], fn ($q) => $q->whereIn('p.writing_agent_id', $agentIds))
            ->select([
                'p.id as policy_id', 'p.policy_no', 'p.application_no', 'p.main_premium',
                'p.annual_premium', 'p.total_premium_paid',
                'p.discount_amount', 'p.credit_card_fee',
                'p.created_at as policy_created_at',
                'p.comm_hub_to_agent_amount', 'p.customer_id',
                'p.writing_agent_id as agent_id',
                'a.agent_code', 'a.vat_type', 'a.has_vat', 'a.vat_mode',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
                'r.calculated_agent_amount', 'r.actual_agent_amount', 'r.agent_rebate_status',
            ])
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            // Sort in PHP, not SQL: an ORDER BY on this 4-table join forces a
            // MySQL filesort that spools to /tmp. The result set is bounded
            // (hundreds of rows), so ordering in memory is both cheap and
            // resilient to disk/tmp pressure on the DB host.
            ->get()
            ->sortBy('agent_code')
            ->values();

        // Rider aggregates fetched in ONE grouped query and merged in PHP,
        // instead of three correlated subqueries per policy row. Correlated
        // subqueries force MySQL to materialise + filesort to /tmp per row —
        // slow, and fragile under disk/tmp pressure. This is O(1) queries.
        $policyIds = $rows->pluck('policy_id')->all();
        $riderAgg = [];
        if ($policyIds !== []) {
            $riderAgg = DB::table('policy_riders')
                ->whereIn('policy_id', $policyIds)
                ->groupBy('policy_id')
                ->select([
                    'policy_id',
                    DB::raw('COALESCE(SUM(com_amt_ag), 0) as rider_com_ag'),
                    DB::raw("GROUP_CONCAT(name SEPARATOR ', ') as rider_names"),
                    DB::raw('MAX(com_rate_ag) as rider_rate_ag'),
                ])
                ->get()
                ->keyBy('policy_id');
        }
        foreach ($rows as $row) {
            $agg = $riderAgg[$row->policy_id] ?? null;
            $row->rider_com_ag = $agg->rider_com_ag ?? 0;
            $row->rider_names = $agg->rider_names ?? null;
            $row->rider_rate_ag = $agg->rider_rate_ag ?? null;
        }

        return $rows;
    }

    /**
     * Split resolved rows into payable (commission > 0) vs needs-review
     * (commission ≤ 0 — unrated policies whose rate isn't set yet). The latter
     * are DATA ERRORS, not debt, so they must never enter a payout batch or a
     * ฿0 PDF; they're surfaced separately for the fix-up workflow (1C).
     *
     * @return array{payable: \Illuminate\Support\Collection, needsReview: \Illuminate\Support\Collection}
     */
    private function partitionPayable(\Illuminate\Support\Collection $rows, ?int $tenantId = null): array
    {
        // 1E: carrier-received / freelook / customer-paid gates. The tenant
        // toggles (payout_require_*) decide which gates apply; an active
        // PolicyPayoutOverride bypasses them. Gated policies are not paid — they
        // drop to needsReview with the gate reason.
        $gates = [];
        if ($tenantId !== null) {
            $ids = $rows->pluck('policy_id')->map(fn ($v) => (int) $v)->all();
            $gates = $ids === [] ? [] : app(PayoutEligibility::class)->evaluate($tenantId, $ids);
        }

        $payable = [];
        $needsReview = [];
        foreach ($rows as $row) {
            $amt = $this->resolveAmount($row);
            $gate = $gates[(int) $row->policy_id] ?? null;
            $gateBlocked = $gate !== null
                && ($gate['override'] ?? null) === null
                && (! empty($gate['gates']) || ($gate['customerReason'] ?? null) !== null);

            if ($gateBlocked) {
                $reason = $gate['gates'][0]['label'] ?? ($gate['customerReason']['label'] ?? 'ยังไม่พร้อมจ่าย');
                $row->review_reason = $reason;
                $needsReview[] = $row;
            } elseif (round($amt['main'] + $amt['rider'], 2) > 0) {
                $payable[] = $row;
            } else {
                $row->review_reason = 'ยังไม่ตั้งอัตราค่าคอม (ค่าคอม ฿0)';
                $needsReview[] = $row;
            }
        }

        return [
            'payable' => collect($payable),
            'needsReview' => collect($needsReview),
        ];
    }

    /**
     * Preview eligible items grouped by agent — no persistence.
     *
     * @return array{agents: array<int, array<string, mixed>>, totals: array<string, mixed>, warnings: array<int, string>, needsReview: array<int, array<string, mixed>>}
     */
    public function preview(int $tenantId, ?string $from = null, ?string $to = null, ?int $agentId = null): array
    {
        $all = $this->rows($tenantId, $from, $to, $agentId);
        ['payable' => $rows, 'needsReview' => $needsReviewRows] = $this->partitionPayable($all, $tenantId);
        $byAgent = [];
        $warnings = [];
        $grandTotal = 0.0;
        $itemCount = 0;

        foreach ($rows as $row) {
            $amt = $this->resolveAmount($row);
            $total = $amt['main'] + $amt['rider'];
            $key = $row->agent_id ?? 0;
            if (! isset($byAgent[$key])) {
                $byAgent[$key] = [
                    'agentId' => $row->agent_id ? (string) $row->agent_id : null,
                    'agentCode' => $row->agent_code,
                    'agentName' => $row->agent_name,
                    'vatType' => $this->resolveVatType($row),
                    'itemCount' => 0,
                    'amount' => 0.0,
                ];
            }
            $byAgent[$key]['itemCount']++;
            $byAgent[$key]['amount'] += $total;
            $grandTotal += $total;
            $itemCount++;

            if (! $row->agent_code) {
                $warnings[] = "กรมธรรม์ {$row->policy_no} ไม่มีรหัสตัวแทน";
            }
        }

        // ฿0 / unrated rows — data errors to fix, NOT payable debt.
        $needsReview = $needsReviewRows->map(fn ($r) => [
            'policyId' => (string) $r->policy_id,
            'policyNo' => $r->policy_no,
            'agentCode' => $r->agent_code,
            'agentName' => $r->agent_name,
            'reason' => $r->review_reason ?? 'ต้องตรวจสอบ',
        ])->values()->all();

        $agents = array_values($byAgent);

        // Bank presence per agent (decrypted via the model) → a missingBank flag
        // so the UI can group "รอข้อมูล" agents and keep them out of a payout.
        $agentIds = array_values(array_filter(array_map(fn ($a) => $a['agentId'] ? (int) $a['agentId'] : null, $agents)));
        $bank = \App\Models\Agent::query()->whereIn('id', $agentIds)->get(['id', 'bank_account_no'])->keyBy('id');
        foreach ($agents as &$a) {
            $a['amount'] = round($a['amount'], 2);
            $acct = $a['agentId'] ? ($bank[(int) $a['agentId']]?->bank_account_no ?? null) : null;
            $a['missingBank'] = $acct === null || trim((string) $acct) === '';
            if ($a['missingBank'] && $a['amount'] > 0) {
                $warnings[] = "ตัวแทน {$a['agentCode']} ไม่มีเลขบัญชีธนาคาร — ต้องแก้ข้อมูลก่อนจ่าย";
            }
        }
        unset($a);

        return [
            'agents' => $agents,
            'totals' => [
                'totalAgents' => count($agents),
                'totalItems' => $itemCount,
                'totalAmount' => round($grandTotal, 2),
                'needsReviewCount' => count($needsReview),
            ],
            'warnings' => array_values(array_unique($warnings)),
            'needsReview' => $needsReview,
        ];
    }

    /**
     * Agent-centric outstanding list: every agent that has unpaid, eligible
     * commission (optionally within a date range), with their item count and
     * total amount owed. Powers the agent-first payout landing page.
     *
     * @return array{agents: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function byAgent(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        // v2: group READY ledger lines by beneficiary so uplines (override /
        // referral) appear alongside the seller's direct commission.
        $ready = $this->collectLines($tenantId, $from, $to)['ready'];
        $byAgent = [];
        foreach ($ready as $line) {
            $key = $line['beneficiaryAgentId'];
            if (! isset($byAgent[$key])) {
                $byAgent[$key] = [
                    'agentId' => (string) $key,
                    'agentCode' => $line['agentCode'],
                    'agentName' => $line['agentName'],
                    'vatType' => $line['vatType'],
                    'itemCount' => 0,
                    'amount' => 0.0,
                ];
            }
            $byAgent[$key]['itemCount']++;
            $byAgent[$key]['amount'] = round($byAgent[$key]['amount'] + $line['amount'], 2);
        }
        $agents = array_values($byAgent);
        usort($agents, fn ($a, $b) => ($b['amount'] <=> $a['amount']));

        return [
            'agents' => $agents,
            'totals' => [
                'totalAgents' => count($agents),
                'totalItems' => array_sum(array_column($agents, 'itemCount')),
                'totalAmount' => round(array_sum(array_column($agents, 'amount')), 2),
            ],
        ];
    }

    /**
     * One agent's outstanding line items: the eligible policies + per-policy
     * amount that a payout would cover. Powers the agent payout detail view.
     *
     * @return array{agent: array<string,mixed>|null, items: array<int,array<string,mixed>>, totals: array<string,mixed>}
     */
    public function agentDetail(int $tenantId, int $agentId, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->rows($tenantId, $from, $to, $agentId);
        $items = [];
        $total = 0.0;
        $agent = null;

        foreach ($rows as $row) {
            $amt = $this->resolveAmount($row);
            $lineTotal = round($amt['main'] + $amt['rider'], 2);
            $total += $lineTotal;
            if ($agent === null) {
                $agent = [
                    'agentId' => (string) $row->agent_id,
                    'agentCode' => $row->agent_code,
                    'agentName' => $row->agent_name,
                    'vatType' => $this->resolveVatType($row),
                ];
            }
            $items[] = [
                'policyId' => (int) $row->policy_id,
                'policyNo' => $row->policy_no,
                'applicationNo' => $row->application_no,
                'basePremium' => round((float) ($row->main_premium ?? 0), 2),
                'agentCommission' => round($amt['main'], 2),
                'riderCommission' => round($amt['rider'], 2),
                'amount' => $lineTotal,
                'amountSource' => $amt['source'],
                'policyDate' => $row->policy_created_at ? substr((string) $row->policy_created_at, 0, 10) : null,
            ];
        }

        return [
            'agent' => $agent,
            'items' => $items,
            'totals' => [
                'itemCount' => count($items),
                'totalAmount' => round($total, 2),
            ],
        ];
    }

    /**
     * Pay a single agent in one action: snapshot that agent's eligible items
     * into a fresh single-agent batch, approve it, and mark it paid — all
     * atomically. The batch stays as the audit + double-pay record behind the
     * scenes. Returns the paid batch.
     */
    public function payAgent(
        int $tenantId,
        int $agentId,
        string $paymentDate,
        ?string $reference,
        ?int $userId,
        ?string $from = null,
        ?string $to = null,
        ?string $note = null,
        bool $allowSelfApprove = false,
    ): CommissionPayoutBatch {
        // One user does create→approve→pay in one shot, so this path INHERENTLY
        // self-approves — it requires the payouts.self_approve capability.
        if (! $allowSelfApprove) {
            throw new RuntimeException('จ่ายทันที (สร้าง+อนุมัติ+จ่ายในคนเดียว) ต้องมีสิทธิ์ "อนุมัติรอบที่ตัวเองสร้าง" — ไม่งั้นให้สร้างรอบแล้วให้คนอื่นอนุมัติ');
        }

        return DB::transaction(function () use ($tenantId, $agentId, $paymentDate, $reference, $userId, $from, $to, $note): CommissionPayoutBatch {
            $batch = $this->createBatch($tenantId, $from, $to, $userId, $note, $agentId);
            $batch = $this->approveBatch($batch, $userId, allowSelfApprove: true);

            return $this->markPaid($batch, $paymentDate, $reference, $userId);
        });
    }

    /**
     * Snapshot the eligible items into a new DRAFT batch.
     *
     * The batch spans many agents (the monthly run pays 30–50 at once). Pass
     * `$agentId` for the single-agent quick-pay path, or `$agentIds` to build a
     * batch for a hand-picked selection; both null = every eligible agent in the
     * range. In addition to the frozen item snapshot, one
     * commission_payout_batch_agents row is created per agent with the per-agent
     * roll-up + bank (decrypted via the Agent model) so the bulk pay + per-agent
     * transfer references work.
     *
     * @param  array<int>|null  $agentIds
     */
    public function createBatch(
        int $tenantId,
        ?string $from,
        ?string $to,
        ?int $userId,
        ?string $note = null,
        ?int $agentId = null,
        ?array $agentIds = null,
        ?string $periodLabel = null,
    ): CommissionPayoutBatch {
        // v2 ledger-driven: snapshot the READY payable ledger lines (one batch
        // item per line, grouped by beneficiary). DIRECT lines become `base`
        // items but their ledger is NOT consumed (stays on the per-policy path);
        // override + referral ledgers ARE consumed (payout_batch_id stamped).
        $ready = $this->collectLines($tenantId, $from, $to, $agentId);
        $lines = collect($ready['ready']);
        if ($agentIds !== null && $agentIds !== []) {
            $lines = $lines->filter(fn ($l) => in_array($l['beneficiaryAgentId'], $agentIds, true))->values();
        }
        // ฿0 lines (e.g. zero-amount management differential) are data, not debt.
        $lines = $lines->filter(fn ($l) => $l['amount'] > 0)->values();
        if ($lines->isEmpty()) {
            throw new RuntimeException('ไม่พบรายการค่าคอมที่เข้าเงื่อนไขในช่วงวันที่ที่ระบุ');
        }

        // When no explicit range is given, span the batch from the lines' policy
        // dates so the NOT NULL date columns hold.
        if ($from === null || $to === null) {
            $dates = $lines->pluck('policyCreatedAt')->filter()->map(fn ($d) => substr((string) $d, 0, 10))->sort()->values();
            $today = CarbonImmutable::now('Asia/Bangkok')->toDateString();
            $from ??= $dates->first() ?: $today;
            $to ??= $dates->last() ?: $today;
        }
        if ($periodLabel === null) {
            $fromM = CarbonImmutable::parse($from, 'Asia/Bangkok');
            $toM = CarbonImmutable::parse($to, 'Asia/Bangkok');
            $periodLabel = $fromM->isSameMonth($toM)
                ? $toM->translatedFormat('F Y')
                : $fromM->translatedFormat('M Y').' – '.$toM->translatedFormat('M Y');
        }
        $referenceCode = $this->nextReferenceCode($tenantId, $to);

        return DB::transaction(function () use ($tenantId, $from, $to, $userId, $note, $lines, $periodLabel, $referenceCode): CommissionPayoutBatch {
            $batch = CommissionPayoutBatch::create([
                'tenant_id' => $tenantId,
                'reference_code' => $referenceCode,
                'from_date' => $from,
                'to_date' => $to,
                'period_label' => $periodLabel,
                'status' => CommissionPayoutBatch::STATUS_DRAFT,
                'calc_version' => self::CALC_VERSION,
                'created_by_user_id' => $userId,
                'note' => $note,
            ]);

            $byAgent = [];
            $total = 0.0;
            $consumeLedgerIds = [];
            foreach ($lines as $line) {
                $amount = $line['amount'];
                $vatType = $line['vatType'];
                $itemVat = self::vatFor($amount, $vatType);
                $wht = round($amount * self::WHT_RATE, 2);

                CommissionPayoutBatchItem::create([
                    'batch_id' => $batch->id,
                    'tenant_id' => $tenantId,
                    'policy_id' => $line['policyId'],
                    'line_type' => $line['lineType'],
                    'ledger_id' => $line['ledgerId'],
                    'agent_id' => $line['beneficiaryAgentId'],
                    'source_agent_id' => $line['sourceAgentId'],
                    'agent_code' => $line['agentCode'],
                    'vat_type' => $vatType,
                    'vat_amount' => $itemVat,
                    'snapshot_base_premium' => round((float) ($line['raw']->main_premium ?? 0), 2),
                    'snapshot_agent_commission' => $amount,
                    'snapshot_rider_commission' => 0,
                    'snapshot_wht_rate' => self::WHT_RATE,
                    'snapshot_wht_amount' => $wht,
                    'amount_source' => 'ledger',
                    'item_status' => CommissionPayoutBatchItem::ITEM_PENDING,
                ]);

                // Only override + referral ledgers are consumed by the batch.
                if (in_array($line['payoutType'], self::CONSUMED_TYPES, true)) {
                    $consumeLedgerIds[] = $line['ledgerId'];
                }

                $key = $line['beneficiaryAgentId'];
                if (! isset($byAgent[$key])) {
                    $byAgent[$key] = [
                        'agent_id' => $key, 'agent_code' => $line['agentCode'], 'agent_name' => $line['agentName'],
                        'vat_type' => $vatType, 'item_count' => 0,
                        'gross_commission' => 0.0, 'wht_amount' => 0.0, 'vat_amount' => 0.0,
                    ];
                }
                $byAgent[$key]['item_count']++;
                $byAgent[$key]['gross_commission'] += $amount;
                $byAgent[$key]['wht_amount'] += $wht;
                $byAgent[$key]['vat_amount'] += $itemVat;
                $total += $amount;
            }

            // Stamp the consumed ledgers into this batch (DIRECT stays untouched).
            if ($consumeLedgerIds !== []) {
                CommissionLedger::whereIn('id', $consumeLedgerIds)->update(['payout_batch_id' => $batch->id]);
            }

            // Per-beneficiary roll-up + bank (encrypted → via model) + Phase-1 guards.
            $agentIdList = array_values(array_filter(array_map(fn ($a) => $a['agent_id'], $byAgent)));
            $bank = \App\Models\Agent::query()->whereIn('id', $agentIdList)
                ->get(['id', 'bank_account_no', 'bank_account_name'])->keyBy('id');

            foreach ($byAgent as $a) {
                $gross = round($a['gross_commission'], 2);
                $wht = round($a['wht_amount'], 2);
                $vat = round($a['vat_amount'], 2);
                $net = round($gross - $wht, 2);                      // net_transfer excludes VAT (money-safety 1A)
                $vatExpectedNet = round($gross + $vat - $wht, 2);
                $ag = $bank[$a['agent_id']] ?? null;
                $bankNo = $ag?->bank_account_no;
                $needsInfo = $bankNo === null || trim((string) $bankNo) === '';
                CommissionPayoutBatchAgent::create([
                    'batch_id' => $batch->id, 'tenant_id' => $tenantId,
                    'agent_id' => $a['agent_id'], 'agent_code' => $a['agent_code'], 'agent_name' => $a['agent_name'],
                    'vat_type' => $a['vat_type'],
                    'bank_account_no' => $bankNo, 'bank_account_name' => $ag?->bank_account_name,
                    'needs_info' => $needsInfo, 'needs_info_reason' => $needsInfo ? 'ไม่มีเลขบัญชีธนาคาร' : null,
                    'item_count' => $a['item_count'],
                    'gross_commission' => $gross, 'deduct_amount' => 0, 'wht_amount' => $wht,
                    'vat_amount' => $vat, 'net_transfer' => $net, 'vat_expected_net' => $vatExpectedNet,
                ]);
            }

            $batch->update([
                'total_agents' => count($byAgent),
                'total_items' => $lines->count(),
                'total_amount' => round($total, 2),
            ]);

            // Attach pending deductions (clawback etc.) for this batch's agents
            // so they reduce the payee's taxable base (golden G19).
            CommissionPayoutAdjustment::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('batch_id')
                ->where('status', CommissionPayoutAdjustment::STATUS_PENDING)
                ->whereIn('agent_id', array_keys($byAgent))
                ->update(['batch_id' => $batch->id]);

            // P2: materialize payee rows (base/override/referral split + VAT/WHT).
            $this->materializePayees($batch->fresh());

            $this->audit($tenantId, $userId, 'commission_payout.create', "batch:{$batch->id}", [
                'from' => $from, 'to' => $to, 'agents' => count($byAgent),
                'items' => $lines->count(), 'amount' => round($total, 2),
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Materialize one CommissionPayoutPayee per beneficiary from the batch's
     * line-typed items + attached deductions, applying the golden VAT/WHT rule.
     * Idempotent (upsert by batch_id+agent_id) so it can run at create AND
     * re-run at approve to pick up adjustments. P2 of the 3-stage engine.
     */
    public function materializePayees(CommissionPayoutBatch $batch): void
    {
        $items = $batch->items()->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)->get();
        if ($items->isEmpty()) {
            return;
        }
        $agentIds = $items->pluck('agent_id')->filter()->unique()->values()->all();
        $agents = \App\Models\Agent::query()->whereIn('id', $agentIds)
            ->get(['id', 'agent_code', 'first_name', 'last_name', 'kind', 'juristic_name', 'tax_id', 'id_card', 'address', 'has_vat', 'vat_mode', 'vat_type', 'bank_name_text', 'bank_account_no', 'bank_account_name'])
            ->keyBy('id');

        // Deductions per agent: clawback / special_deduct / correction attached to
        // this batch, applied before tax (golden G19).
        $deductByAgent = $batch->adjustments()
            ->whereIn('type', [CommissionPayoutAdjustment::TYPE_CLAWBACK, CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT, CommissionPayoutAdjustment::TYPE_CORRECTION])
            ->get()->groupBy('agent_id')->map(fn ($g) => round((float) $g->sum('amount'), 2));

        foreach ($items->groupBy('agent_id') as $agentId => $agentItems) {
            if (! $agentId) {
                continue;
            }
            $agent = $agents->get((int) $agentId);
            $base = 0.0; $override = 0.0; $referral = 0.0;
            foreach ($agentItems as $it) {
                $amt = (float) $it->snapshot_agent_commission;
                match ($it->line_type) {
                    'referral' => $referral += $amt,
                    'override' => $override += $amt,
                    default => $base += $amt,
                };
            }
            $gross = round($base + $override + $referral, 2);
            $deduction = (float) ($deductByAgent[$agentId] ?? 0);
            $vatType = $agent ? $this->resolveVatType($agent) : '1';
            $m = self::payeeAmounts($gross, $deduction, $vatType);
            $isCorp = ($agent?->kind ?? 'individual') === 'corporate';

            CommissionPayoutPayee::updateOrCreate(
                ['batch_id' => $batch->id, 'agent_id' => (int) $agentId],
                [
                    'tenant_id' => $batch->tenant_id,
                    'agent_code' => $agent?->agent_code,
                    'payee_name' => $isCorp ? $agent?->juristic_name : trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')),
                    'payee_kind' => $agent?->kind ?? 'individual',
                    'payee_tax_id' => $isCorp ? $agent?->tax_id : $agent?->id_card,
                    'payee_address' => $agent?->address,
                    'vat_type' => $vatType,
                    'wht_rate' => self::WHT_RATE * 100,   // stored as percent (3.00)
                    'wht_form' => $isCorp ? CommissionPayoutPayee::FORM_PND53 : CommissionPayoutPayee::FORM_PND3,
                    'base_amount' => round($base, 2),
                    'self_mgmt_amount' => 0,
                    'referral_amount' => round($referral, 2),
                    'override_amount' => round($override, 2),
                    'gross_amount' => $gross,
                    'deduction_amount' => $deduction,
                    'taxable_amount' => $m['taxable'],
                    'vat_amount' => $m['vat'],
                    'total_amount' => $m['total'],
                    'wht_amount' => $m['wht'],
                    'net_amount' => $m['net'],
                    'bank_name' => $agent?->bank_name_text,
                    'bank_account_no' => $agent?->bank_account_no,
                    'bank_account_name' => $agent?->bank_account_name,
                ],
            );
        }
    }

    public function addAdjustment(
        CommissionPayoutBatch $batch,
        ?int $agentId,
        ?string $agentCode,
        float $amount,
        string $reason,
        ?int $userId,
        string $type = CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT,
    ): CommissionPayoutAdjustment {
        if (! $batch->isEditable()) {
            throw new RuntimeException('ไม่สามารถแก้ไข batch ที่จ่ายแล้วหรือยกเลิกแล้ว');
        }
        if ($amount < 0) {
            throw new RuntimeException('ยอดหักต้องไม่ติดลบ');
        }
        if (! in_array($type, CommissionPayoutAdjustment::TYPES, true)) {
            throw new RuntimeException('ประเภทรายการปรับปรุงไม่ถูกต้อง');
        }
        $adj = CommissionPayoutAdjustment::create([
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'agent_id' => $agentId,
            'agent_code' => $agentCode,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);
        // Keep the agent's roll-up net in sync (deduct/bonus change the transfer).
        if ($agentId !== null) {
            $this->recomputeAgentRollup($batch, $agentId);
        }
        $this->audit($batch->tenant_id, $userId, 'commission_payout.adjust', "batch:{$batch->id}", [
            'agent' => $agentCode, 'type' => $type, 'amount' => $amount, 'reason' => $reason,
        ]);

        return $adj;
    }

    /**
     * Assign sequential 50-ทวิ certificate numbers to every payee in a just-paid
     * batch. Numbers run per tenant+Buddhist-year, row-locked so concurrent
     * batches never collide (mirrors the REC- receipt sequence). Already-numbered
     * payees are left untouched (idempotent on re-pay). Must run inside the
     * markPaid transaction.
     */
    private function assignCertNumbers(CommissionPayoutBatch $batch, string $paymentDate): void
    {
        $payees = $batch->payees()->whereNull('cert_no')->orderBy('id')->get();
        if ($payees->isEmpty()) {
            return;
        }
        $certDate = CarbonImmutable::parse($paymentDate, 'Asia/Bangkok');
        $year = (int) $certDate->year + 543; // Buddhist year (พ.ศ.) — e.g. 2026 → 2569.

        // Row-lock (or create) this tenant+year sequence so the increment is atomic.
        $seqRow = DB::table('tax_cert_sequences')
            ->where('tenant_id', $batch->tenant_id)->where('year', $year)
            ->lockForUpdate()->first();
        if ($seqRow === null) {
            DB::table('tax_cert_sequences')->insert([
                'tenant_id' => $batch->tenant_id, 'year' => $year, 'last_no' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $seqRow = DB::table('tax_cert_sequences')
                ->where('tenant_id', $batch->tenant_id)->where('year', $year)
                ->lockForUpdate()->first();
        }
        $next = (int) $seqRow->last_no;

        foreach ($payees as $payee) {
            $next++;
            $payee->update([
                'cert_book' => $year,
                'cert_no' => $next,
                'cert_date' => $certDate->toDateString(),
            ]);
        }

        DB::table('tax_cert_sequences')
            ->where('tenant_id', $batch->tenant_id)->where('year', $year)
            ->update(['last_no' => $next, 'updated_at' => now()]);
    }

    /**
     * View-model for the 50-ทวิ withholding-tax certificate (pdf.wht-certificate).
     * Pulls the frozen payee figures; the tax id is decrypted by the model cast.
     */
    public function certificateData(CommissionPayoutPayee $payee): array
    {
        $tenant = $payee->batch?->tenant;

        return [
            'batchId' => (int) $payee->batch_id,
            'book' => $payee->cert_book,
            'number' => $payee->cert_no,
            'label' => $payee->certificateLabel(),
            'date' => $payee->cert_date?->format('d/m/Y'),
            'form' => $payee->wht_form,
            'company' => [
                'name' => $tenant?->name ?? '',
                'taxId' => $tenant?->tax_id,
                'address' => $tenant?->address,
            ],
            'payee' => [
                'name' => $payee->payee_name,
                'code' => $payee->agent_code,
                'kind' => $payee->payee_kind,
                'taxId' => $payee->payee_tax_id,
                'address' => $payee->payee_address,
            ],
            'rate' => number_format((float) $payee->wht_rate, 2),
            'paidAmount' => number_format((float) $payee->taxable_amount, 2),
            'whtAmount' => number_format((float) $payee->wht_amount, 2),
            'whtText' => PayoutTax::bahtText((float) $payee->wht_amount),
        ];
    }

    /**
     * Mark the whole batch paid — atomic. Writes the paid marker + date into
     * policy_rebates for every item's policy (updateOrCreate, since the rebate
     * row may not exist yet), flips items + batch to paid, and audits.
     */
    public function markPaid(CommissionPayoutBatch $batch, string $paymentDate, ?string $reference, ?int $userId): CommissionPayoutBatch
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

        // 1B hard guard: never transfer to an agent with no bank account.
        $needsInfo = $batch->agentRows()->where('needs_info', true)->where('paid', false)->where('reversed', false)->pluck('agent_code');
        if ($needsInfo->isNotEmpty()) {
            throw new RuntimeException('จ่ายไม่ได้ — มีตัวแทนที่ยังไม่มีเลขบัญชีธนาคาร: '.$needsInfo->implode(', ').' (แก้ข้อมูลหรือนำออกจากรอบก่อน)');
        }

        return DB::transaction(function () use ($batch, $paymentDate, $reference, $userId): CommissionPayoutBatch {
            $items = $batch->items()->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)->get();

            foreach ($items as $item) {
                // Guard: the policy must not have been paid by another live batch.
                $paidElsewhere = CommissionPayoutBatchItem::query()
                    ->where('policy_id', $item->policy_id)
                    ->where('batch_id', '!=', $batch->id)
                    ->where('item_status', CommissionPayoutBatchItem::ITEM_PAID)
                    ->exists();
                if ($paidElsewhere) {
                    throw new RuntimeException("กรมธรรม์ #{$item->policy_id} ถูกจ่ายค่าคอมไปแล้วโดย batch อื่น");
                }

                PolicyRebate::updateOrCreate(
                    ['policy_id' => $item->policy_id],
                    [
                        'tenant_id' => $batch->tenant_id,
                        'agent_rebate_status' => self::PAID_STATUS,
                        'agent_receive_date' => $paymentDate,
                    ],
                );

                $item->update(['item_status' => CommissionPayoutBatchItem::ITEM_PAID]);
            }

            // Mark every not-yet-paid agent row paid (bulk pay). Per-agent
            // references set earlier via markAgentPaid() are preserved; agents
            // with none inherit the batch reference.
            $batch->agentRows()->where('paid', false)->where('reversed', false)->get()
                ->each(function (CommissionPayoutBatchAgent $ar) use ($reference, $userId): void {
                    $ar->update([
                        'paid' => true,
                        'paid_at' => now(),
                        'paid_by_user_id' => $userId,
                        'payment_reference' => $ar->payment_reference ?: $reference,
                    ]);
                });

            // v2: settle the ledger rows this batch consumed (override + referral).
            CommissionLedger::where('payout_batch_id', $batch->id)
                ->where('status', CommissionLedger::STATUS_UNSETTLED)
                ->update(['status' => CommissionLedger::STATUS_SETTLED, 'paid_at' => now()]);

            // v2: stamp sequential 50-ทวิ certificate numbers on this batch's payees.
            $this->assignCertNumbers($batch, $paymentDate);

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

    /**
     * Mark ONE agent in the batch paid, with that agent's own transfer
     * reference — the per-agent bank-transfer flow. Flips only that agent's
     * items + rebate rows. When every non-excluded agent is paid, the batch
     * itself flips to PAID. The batch must be APPROVED first (same gate as the
     * bulk pay).
     */
    public function markAgentPaid(
        CommissionPayoutBatch $batch,
        int $agentId,
        string $paymentDate,
        ?string $reference,
        ?int $userId,
    ): CommissionPayoutBatch {
        if ($batch->status !== CommissionPayoutBatch::STATUS_APPROVED) {
            throw new RuntimeException('ต้องอนุมัติ batch ก่อนจึงจะบันทึกจ่ายรายตัวแทนได้');
        }
        $agentRow = $batch->agentRows()->where('agent_id', $agentId)->first();
        if ($agentRow === null) {
            throw new RuntimeException('ไม่พบตัวแทนนี้ใน batch');
        }
        if ($agentRow->paid) {
            throw new RuntimeException('ตัวแทนนี้ถูกบันทึกจ่ายแล้ว');
        }

        return DB::transaction(function () use ($batch, $agentId, $agentRow, $paymentDate, $reference, $userId): CommissionPayoutBatch {
            $items = $batch->items()
                ->where('agent_id', $agentId)
                ->where('item_status', CommissionPayoutBatchItem::ITEM_PENDING)
                ->get();

            foreach ($items as $item) {
                PolicyRebate::updateOrCreate(
                    ['policy_id' => $item->policy_id],
                    [
                        'tenant_id' => $batch->tenant_id,
                        'agent_rebate_status' => self::PAID_STATUS,
                        'agent_receive_date' => $paymentDate,
                    ],
                );
                $item->update(['item_status' => CommissionPayoutBatchItem::ITEM_PAID]);
            }

            $agentRow->update([
                'paid' => true,
                'paid_at' => now(),
                'paid_by_user_id' => $userId,
                'payment_reference' => $reference,
            ]);

            $this->audit($batch->tenant_id, $userId, 'commission_payout.mark_agent_paid', "batch:{$batch->id}", [
                'agent' => $agentRow->agent_code, 'items' => $items->count(),
                'payment_date' => $paymentDate, 'reference' => $reference,
            ]);

            // Flip the whole batch once every non-excluded agent is paid.
            $remaining = $batch->agentRows()->where('paid', false)->where('reversed', false)->count();
            if ($remaining === 0) {
                $batch->update([
                    'status' => CommissionPayoutBatch::STATUS_PAID,
                    'paid_by_user_id' => $userId,
                    'paid_at' => now(),
                    'payment_date' => $batch->payment_date ?: $paymentDate,
                ]);
                $this->audit($batch->tenant_id, $userId, 'commission_payout.mark_paid', "batch:{$batch->id}", [
                    'via' => 'per-agent-complete',
                ]);
            }

            return $batch->fresh();
        });
    }

    /** Approve a batch so it can be paid (spec §8.1: separate approve step). */
    public function approveBatch(CommissionPayoutBatch $batch, ?int $userId, bool $allowSelfApprove = false): CommissionPayoutBatch
    {
        if (! in_array($batch->status, [CommissionPayoutBatch::STATUS_DRAFT, CommissionPayoutBatch::STATUS_GENERATED], true)) {
            throw new RuntimeException('อนุมัติได้เฉพาะ batch สถานะร่าง/สร้างเอกสารแล้ว');
        }
        // 1D maker-checker: the approver must differ from the creator, unless the
        // tenant grants payouts.self_approve (small teams with no SoD).
        if (! $allowSelfApprove && $userId !== null && (int) $batch->created_by_user_id === (int) $userId) {
            throw new RuntimeException('ผู้อนุมัติต้องไม่ใช่คนเดียวกับผู้สร้างรอบจ่าย — ให้คนอื่นอนุมัติ หรือขอสิทธิ์อนุมัติเอง');
        }
        $batch->update([
            'status' => CommissionPayoutBatch::STATUS_APPROVED,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
        ]);
        // Re-freeze payees at approval so they reflect any exclude/adjust since create.
        if ((int) $batch->calc_version === self::CALC_VERSION) {
            $this->materializePayees($batch->fresh());
        }
        $this->audit($batch->tenant_id, $userId, 'commission_payout.approve', "batch:{$batch->id}", []);

        return $batch->fresh();
    }

    /** Revoke approval, returning the batch to DRAFT (before it is paid). */
    public function unapproveBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        if ($batch->status !== CommissionPayoutBatch::STATUS_APPROVED) {
            throw new RuntimeException('ยกเลิกอนุมัติได้เฉพาะ batch ที่อนุมัติแล้วและยังไม่จ่าย');
        }
        $batch->update([
            'status' => CommissionPayoutBatch::STATUS_DRAFT,
            'approved_by_user_id' => null,
            'approved_at' => null,
        ]);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.unapprove', "batch:{$batch->id}", []);

        return $batch->fresh();
    }

    /** Next PO-YYMM-NN reference for the batch's end month (per tenant). */
    private function nextReferenceCode(int $tenantId, string $to): string
    {
        $stamp = CarbonImmutable::parse($to, 'Asia/Bangkok')->format('ym');
        $prefix = "PO-{$stamp}-";
        $last = CommissionPayoutBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('reference_code', 'like', $prefix.'%')
            ->orderByDesc('reference_code')
            ->value('reference_code');
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return sprintf('%s%02d', $prefix, $seq);
    }

    public function cancelBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        if ($batch->status === CommissionPayoutBatch::STATUS_PAID) {
            throw new RuntimeException('ไม่สามารถยกเลิก batch ที่จ่ายแล้ว (ต้องทำ reversal)');
        }
        // 1F: a cancelled batch must never carry paid-* fields (reachable via
        // reverseAgent leaving them set before a cancel).
        $batch->update([
            'status' => CommissionPayoutBatch::STATUS_CANCELLED,
            'payment_date' => null,
            'paid_at' => null,
            'paid_by_user_id' => null,
            'payment_reference' => null,
        ]);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.cancel', "batch:{$batch->id}", []);

        return $batch->fresh();
    }

    // ── Wrong-payment handling ───────────────────────────────────────────────

    /**
     * Reverse a PAID batch (void a wrong payment). Un-marks every paid item,
     * restores each policy's original agent_rebate_status so the commission
     * becomes eligible again, flips agent rows + batch to REVERSED, and records
     * the reason. Paid financial records are never deleted — only reversed, with
     * a full audit trail. After a reversal, use reissueBatch() to re-pay
     * correctly.
     */
    public function reverseBatch(CommissionPayoutBatch $batch, string $reason, ?int $userId): CommissionPayoutBatch
    {
        if ($batch->status !== CommissionPayoutBatch::STATUS_PAID) {
            throw new RuntimeException('ทำรายการย้อนกลับได้เฉพาะ batch ที่จ่ายแล้ว');
        }

        return DB::transaction(function () use ($batch, $reason, $userId): CommissionPayoutBatch {
            $items = $batch->items()->where('item_status', CommissionPayoutBatchItem::ITEM_PAID)->get();
            foreach ($items as $item) {
                // Restore the pre-batch rebate status (may have been null/empty).
                PolicyRebate::where('policy_id', $item->policy_id)->update([
                    'agent_rebate_status' => $item->original_agent_rebate_status,
                    'agent_receive_date' => null,
                ]);
                $item->update(['item_status' => CommissionPayoutBatchItem::ITEM_PENDING]);
            }

            $batch->agentRows()->update([
                'paid' => false,
                'reversed' => true,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            $batch->update([
                'status' => CommissionPayoutBatch::STATUS_REVERSED,
                'reversed_by_user_id' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            $this->audit($batch->tenant_id, $userId, 'commission_payout.reverse', "batch:{$batch->id}", [
                'items' => $items->count(), 'reason' => $reason,
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Reverse a single agent within a PAID (or partially-paid) batch — the
     * per-agent wrong-payment case. Restores that agent's items only; the batch
     * drops back from PAID to APPROVED so the remaining agents stay payable.
     */
    public function reverseAgent(CommissionPayoutBatch $batch, int $agentId, string $reason, ?int $userId): CommissionPayoutBatch
    {
        $agentRow = $batch->agentRows()->where('agent_id', $agentId)->first();
        if ($agentRow === null) {
            throw new RuntimeException('ไม่พบตัวแทนนี้ใน batch');
        }
        if (! $agentRow->paid) {
            throw new RuntimeException('ตัวแทนนี้ยังไม่ได้จ่าย จึงย้อนกลับไม่ได้');
        }

        return DB::transaction(function () use ($batch, $agentId, $agentRow, $reason, $userId): CommissionPayoutBatch {
            $items = $batch->items()
                ->where('agent_id', $agentId)
                ->where('item_status', CommissionPayoutBatchItem::ITEM_PAID)
                ->get();
            foreach ($items as $item) {
                PolicyRebate::where('policy_id', $item->policy_id)->update([
                    'agent_rebate_status' => $item->original_agent_rebate_status,
                    'agent_receive_date' => null,
                ]);
                $item->update(['item_status' => CommissionPayoutBatchItem::ITEM_PENDING]);
            }

            $agentRow->update([
                'paid' => false, 'paid_at' => null, 'payment_reference' => null,
                'reversed' => true, 'reversed_at' => now(), 'reversal_reason' => $reason,
            ]);

            // A paid batch with a reversed agent is no longer fully paid. Clear
            // payment_date too (1F) so it can't later become a cancelled batch
            // that still shows a paid date.
            if ($batch->status === CommissionPayoutBatch::STATUS_PAID) {
                $batch->update([
                    'status' => CommissionPayoutBatch::STATUS_APPROVED,
                    'paid_at' => null, 'paid_by_user_id' => null, 'payment_date' => null,
                ]);
            }

            $this->audit($batch->tenant_id, $userId, 'commission_payout.reverse_agent', "batch:{$batch->id}", [
                'agent' => $agentRow->agent_code, 'items' => $items->count(), 'reason' => $reason,
            ]);

            return $batch->fresh();
        });
    }

    /**
     * Re-issue: after a reversal, build a fresh DRAFT batch that re-covers the
     * now-eligible policies so the correct payment can be made. The new batch is
     * linked to the one it corrects via reissued_from_batch_id. Because the
     * reversed items are pending again and no longer sit in a live batch, the
     * normal eligibility query picks them up.
     */
    public function reissueBatch(CommissionPayoutBatch $reversed, ?int $userId): CommissionPayoutBatch
    {
        if ($reversed->status !== CommissionPayoutBatch::STATUS_REVERSED) {
            throw new RuntimeException('ออกใหม่ได้เฉพาะ batch ที่ทำรายการย้อนกลับแล้ว');
        }
        $agentIds = $reversed->agentRows()->whereNotNull('agent_id')->pluck('agent_id')->all();
        $batch = $this->createBatch(
            $reversed->tenant_id,
            $reversed->from_date?->toDateString(),
            $reversed->to_date?->toDateString(),
            $userId,
            'ออกใหม่จากรอบ #' . $reversed->id,
            null,
            $agentIds,
            $reversed->period_label,
        );
        $batch->update(['reissued_from_batch_id' => $reversed->id]);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.reissue', "batch:{$batch->id}", [
            'from_batch' => $reversed->id,
        ]);

        return $batch->fresh();
    }

    // ── Per-item edit (before pay) ───────────────────────────────────────────

    /**
     * Exclude / re-include a single line item from a batch before it is paid
     * (item_status excluded ⇄ pending). Recomputes the affected agent's roll-up
     * and the batch totals. Only DRAFT/GENERATED/APPROVED batches are editable.
     */
    public function setItemExcluded(CommissionPayoutBatch $batch, int $itemId, bool $excluded, ?int $userId): CommissionPayoutBatch
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException('แก้ไขรายการได้เฉพาะ batch ที่ยังไม่จ่าย');
        }
        $item = $batch->items()->where('id', $itemId)->first();
        if ($item === null) {
            throw new RuntimeException('ไม่พบรายการใน batch');
        }
        if ($item->item_status === CommissionPayoutBatchItem::ITEM_PAID) {
            throw new RuntimeException('รายการนี้จ่ายแล้ว แก้ไขไม่ได้');
        }

        return DB::transaction(function () use ($batch, $item, $excluded, $userId): CommissionPayoutBatch {
            $item->update([
                'item_status' => $excluded
                    ? CommissionPayoutBatchItem::ITEM_EXCLUDED
                    : CommissionPayoutBatchItem::ITEM_PENDING,
            ]);
            $this->recomputeAgentRollup($batch, (int) ($item->agent_id ?? 0));
            $this->recomputeBatchTotals($batch);
            $this->audit($batch->tenant_id, $userId, 'commission_payout.item_' . ($excluded ? 'exclude' : 'include'), "batch:{$batch->id}", [
                'item' => $item->id, 'policy' => $item->policy_id,
            ]);

            return $batch->fresh();
        });
    }

    /** Recompute one agent's roll-up row from its non-excluded items + adjustments. */
    private function recomputeAgentRollup(CommissionPayoutBatch $batch, int $agentId): void
    {
        $agentRow = $batch->agentRows()->where('agent_id', $agentId ?: null)->first();
        if ($agentRow === null) {
            return;
        }
        $items = $batch->items()
            ->where('agent_id', $agentId ?: null)
            ->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)
            ->get();

        $gross = 0.0;
        $wht = 0.0;
        $vat = 0.0;
        $payoffCard = 0.0;
        foreach ($items as $it) {
            $gross += $it->total();
            $wht += (float) $it->snapshot_wht_amount;
            $vat += (float) $it->vat_amount;
            $payoffCard += (float) $it->snapshot_payoff_discount + (float) $it->snapshot_card_fee;
        }
        // Bonuses add to the payout; special_deduct / correction subtract.
        $bonus = (float) $batch->adjustments()
            ->where('agent_id', $agentId ?: null)
            ->where('type', CommissionPayoutAdjustment::TYPE_BONUS)->sum('amount');
        $subtractAdj = (float) $batch->adjustments()
            ->where('agent_id', $agentId ?: null)
            ->whereIn('type', [CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT, CommissionPayoutAdjustment::TYPE_CORRECTION])
            ->sum('amount');
        $deduct = round($payoffCard + $subtractAdj, 2);
        // net_transfer UNCHANGED (no VAT); vat_expected_net mirrors it + VAT.
        $net = round($gross + $bonus - $deduct - $wht, 2);
        $vat = round($vat, 2);

        $agentRow->update([
            'item_count' => $items->count(),
            'gross_commission' => round($gross, 2),
            'deduct_amount' => $deduct,
            'wht_amount' => round($wht, 2),
            'vat_amount' => $vat,
            'net_transfer' => $net,
            'vat_expected_net' => round($net + $vat, 2),
        ]);
    }

    /** Recompute the batch header roll-ups from its non-excluded items. */
    private function recomputeBatchTotals(CommissionPayoutBatch $batch): void
    {
        $items = $batch->items()->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)->get();
        $total = 0.0;
        $agents = [];
        foreach ($items as $it) {
            $total += $it->total();
            $agents[$it->agent_id ?? 0] = true;
        }
        $batch->update([
            'total_agents' => count($agents),
            'total_items' => $items->count(),
            'total_amount' => round($total, 2),
        ]);
    }

    /** VAT rate applied to agent commission when the agent carries VAT. */
    private const VAT_RATE = 0.07;

    /**
     * VAT amount for a given commission base and agent VAT type. Single source
     * of truth shared by createBatch, recomputeAgentRollup, and the PDF builder
     * so the stored vat_amount and the printed statement never diverge.
     *   '2' VAT Exclude : VAT = commission × 7%
     *   '3' VAT Include : VAT = commission − commission/1.07 (carved out)
     *   '1' non-VAT     : 0
     */
    public static function vatFor(float $commission, string $vatType): float
    {
        if ($vatType === '2') {
            return round($commission * self::VAT_RATE, 2);
        }
        if ($vatType === '3') {
            return round($commission - round($commission / (1 + self::VAT_RATE), 2), 2);
        }

        return 0.0;
    }

    /**
     * Assemble the per-agent PDF payload for a batch: the agent's items plus
     * VAT-aware totals. `$agentId` of 0 matches the "no agent" bucket.
     * Returns null when the agent has no items in the batch.
     *
     * @return array<string, mixed>|null
     */
    public function buildAgentPdfData(CommissionPayoutBatch $batch, ?int $agentId, ?string $agentCode): ?array
    {
        $items = $batch->items()
            ->with(['policy:id,policy_no,application_no,created_at,customer_id', 'policy.customer:id,first_name,last_name', 'agent:id,agent_code,first_name,last_name,vat_type,has_vat,vat_mode'])
            ->where('item_status', '!=', CommissionPayoutBatchItem::ITEM_EXCLUDED)
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($agentId === null && $agentCode !== null, fn ($q) => $q->where('agent_code', $agentCode))
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $first = $items->first();
        $agentIdResolved = $first->agent_id;
        $vatType = $first->vat_type ?: ($first->agent ? $this->resolveVatType($first->agent) : '1');

        // Per-agent payment-tracking row carries the frozen bank + roll-ups.
        $agentRow = $batch->agentRows()
            ->when($agentIdResolved !== null, fn ($q) => $q->where('agent_id', $agentIdResolved))
            ->when($agentIdResolved === null, fn ($q) => $q->where('agent_code', $first->agent_code))
            ->first();

        // ── Line rows (mirror the printed columns) ──────────────────────────
        $rows = [];
        $sumNet = 0.0;
        $sumTotal = 0.0;
        $sumMain = 0.0;
        $sumRider = 0.0;
        $sumPayoff = 0.0;
        $sumCard = 0.0;
        $sumCommA = 0.0;    // ค่านายหน้าสุทธิ (A) = main + rider − (payoff + card)
        $sumWht = 0.0;
        $sumPayable = 0.0;  // ยอดจ่ายสุทธิ
        $sumVat = 0.0;      // VAT column (corporate agents only)
        $i = 0;
        foreach ($items as $it) {
            $i++;
            $main = (float) $it->snapshot_agent_commission;
            $rider = (float) $it->snapshot_rider_commission;
            $payoff = (float) $it->snapshot_payoff_discount;
            $card = (float) $it->snapshot_card_fee;
            $commA = round($main + $rider - $payoff - $card, 2);
            // VAT + หัก ณ ที่จ่าย + ยอดจ่ายสุทธิ — 3 แบบตามประเภทตัวแทน (vatType):
            //   '1' non-VAT      : VAT=0,            wht = C × 3%,         payable = C − wht
            //   '3' VAT Include  : VAT = C − C/1.07, wht = (C/1.07) × 3%,  payable = C − VAT − wht
            //   '2' VAT Exclude  : VAT = C × 7%,     wht = C × 3%,         payable = C + VAT − wht
            $vat = self::vatFor($commA, $vatType);
            if ($vatType === '3') {
                // VAT Include: WHT is 3% of the VAT-exclusive base; VAT is carved out.
                $wht = round(round($commA / (1 + self::VAT_RATE), 2) * self::WHT_RATE, 2);
                $payable = round($commA - $vat - $wht, 2);
            } elseif ($vatType === '2') {
                // VAT Exclude: VAT added on top.
                $wht = round($commA * self::WHT_RATE, 2);
                $payable = round($commA + $vat - $wht, 2);
            } else {
                $wht = round($commA * self::WHT_RATE, 2);
                $payable = round($commA - $wht, 2);
            }

            $cust = $it->policy?->customer;
            $custName = $it->snapshot_customer_name
                ?: ($cust ? trim("{$cust->first_name} {$cust->last_name}") : null);

            $rows[] = [
                'no' => $i,
                'applicationNo' => $it->policy?->application_no,
                'policyNo' => $it->policy?->policy_no,
                'policyDate' => $it->policy?->created_at?->format('d/m/Y'),
                'customerName' => $custName,
                'riderName' => $it->snapshot_rider_name,
                'netPremium' => (float) $it->snapshot_net_premium,
                'totalPremium' => (float) $it->snapshot_total_premium,
                'mainRatePct' => $it->snapshot_main_rate !== null ? round((float) $it->snapshot_main_rate * 100, 2) : null,
                'riderRatePct' => $it->snapshot_rider_rate !== null ? round((float) $it->snapshot_rider_rate * 100, 2) : null,
                'payoffDiscount' => $payoff,
                'cardFee' => $card,
                'commissionA' => $commA,
                'vat' => $vat,
                'wht' => $wht,
                'payable' => $payable,
                'note' => $it->snapshot_note,
            ];
            $sumNet += (float) $it->snapshot_net_premium;
            $sumTotal += (float) $it->snapshot_total_premium;
            $sumMain += $main;
            $sumRider += $rider;
            $sumPayoff += $payoff;
            $sumCard += $card;
            $sumCommA += $commA;
            $sumWht += $wht;
            $sumPayable += $payable;
            $sumVat += $vat;
        }

        // ── Per-agent adjustments (bonus / special_deduct / correction) ─────
        $adjQuery = $batch->adjustments()
            ->when($agentIdResolved !== null, fn ($q) => $q->where('agent_id', $agentIdResolved))
            ->when($agentIdResolved === null, fn ($q) => $q->where('agent_code', $first->agent_code));
        $bonus = (float) (clone $adjQuery)->where('type', CommissionPayoutAdjustment::TYPE_BONUS)->sum('amount');
        $otherDeduct = (float) (clone $adjQuery)
            ->whereIn('type', [CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT, CommissionPayoutAdjustment::TYPE_CORRECTION])
            ->sum('amount');

        // ── Summary block (mirrors the printed WHT recap) ───────────────────
        // WHT is 3% of the taxable base. For VAT-include agents the base is the
        // ex-VAT amount (commA / 1.07), so the WHT total already summed per-row
        // ($sumWht) is the source of truth; derive the base shown back from it.
        $whtFromCommissionTax = round($sumWht, 2);
        $whtFromCommission = $vatType === '3' ? round($sumCommA / 1.07, 2) : round($sumCommA, 2);
        $whtFromBonus = round($bonus, 2);
        $whtFromBonusTax = round($whtFromBonus * self::WHT_RATE, 2);
        $whtFromPayoff = round($sumPayoff, 2);
        $whtFromPayoffTax = round($whtFromPayoff * self::WHT_RATE, 2);

        $whtBaseTotal = round($whtFromCommission + $whtFromBonus + $whtFromPayoff, 2);
        $whtTaxTotal = round($whtFromCommissionTax + $whtFromBonusTax + $whtFromPayoffTax, 2);

        // ยอดเงินโอน (net transfer): prefer the frozen agent-row value; else derive.
        $netTransfer = $agentRow !== null
            ? round((float) $agentRow->net_transfer, 2)
            : round($sumCommA + $bonus - $otherDeduct - $whtTaxTotal, 2);

        $agentName = $agentRow?->agent_name
            ?: ($first->agent ? trim("{$first->agent->first_name} {$first->agent->last_name}") : '');

        return [
            'vatType' => $vatType,
            'agent' => [
                'code' => $first->agent_code ?? $agentCode,
                'name' => $agentName,
                'bankAccountNo' => $agentRow?->bank_account_no,
                'bankAccountName' => $agentRow?->bank_account_name,
            ],
            'rows' => $rows,
            'columnTotals' => [
                'netPremium' => round($sumNet, 2),
                'totalPremium' => round($sumTotal, 2),
                'payoffDiscount' => round($sumPayoff, 2),
                'cardFee' => round($sumCard, 2),
                'commissionA' => round($sumCommA, 2),
                'vat' => round($sumVat, 2),
                'wht' => round($sumWht, 2),
                'payable' => round($sumPayable, 2),
            ],
            'summary' => [
                'whtFromCommission' => $whtFromCommission,
                'whtFromCommissionTax' => $whtFromCommissionTax,
                'whtFromBonus' => $whtFromBonus,
                'whtFromBonusTax' => $whtFromBonusTax,
                'whtFromPayoff' => $whtFromPayoff,
                'whtFromPayoffTax' => $whtFromPayoffTax,
                'salesPromoTax' => 0.0,      // บวกเงินค่าส่งเสริมการขาย (Tax Related) — reserved
                'whtBaseTotal' => $whtBaseTotal,
                'whtTaxTotal' => $whtTaxTotal,
                'nonTaxAdjust' => round($bonus - $otherDeduct, 2), // หักอื่นๆ (+) / บวก (-) Non-tax
                'netTransfer' => $netTransfer,
            ],
            'batch' => [
                'id' => $batch->id,
                'periodLabel' => $batch->period_label,
                'from' => $batch->from_date?->toDateString(),
                'to' => $batch->to_date?->toDateString(),
            ],
        ];
    }

    /**
     * Flat reconciliation rows for the Excel/CSV export (spec §6.1). One row
     * per batch item with the snapshot amounts + agent + policy identifiers.
     * Read-only evidence — reflects the frozen batch snapshot, not a re-query.
     *
     * @return array<int, array<string, string|float|null>>
     */
    public function exportRows(CommissionPayoutBatch $batch): array
    {
        $items = $batch->items()
            ->with(['policy:id,policy_no,application_no,created_at,customer_id', 'policy.customer:id,first_name,last_name', 'agent:id,agent_code,first_name,last_name,vat_type'])
            ->orderBy('agent_code')
            ->get();

        // Per-agent adjustment totals for the deduct column.
        $adjByAgent = [];
        foreach ($batch->adjustments as $adj) {
            $key = $adj->agent_id ?? 0;
            $adjByAgent[$key] = ($adjByAgent[$key] ?? 0) + (float) $adj->amount;
        }

        $rows = [];
        foreach ($items as $it) {
            $cust = $it->policy?->customer;
            $agentName = $it->agent ? trim("{$it->agent->first_name} {$it->agent->last_name}") : '';
            $rows[] = [
                'application_no' => $it->policy?->application_no,
                'policy_no' => $it->policy?->policy_no,
                'create_date' => $it->policy?->created_at?->format('Y-m-d'),
                'agent_code' => $it->agent_code,
                'agent_name' => $agentName,
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

    /** VAT filename tag per spec §5.1. */
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

    // ── History / management reads ───────────────────────────────────────────

    /**
     * Paginated, filterable batch history. Filters: date range (on payment_date,
     * falling back to created_at), period_label, status, and a free-text query
     * matched against period label, note, and payment reference.
     *
     * @return array{rows: \Illuminate\Support\Collection, total: int, page: int, perPage: int}
     */
    public function history(int $tenantId, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['perPage'] ?? 25)));

        $q = CommissionPayoutBatch::query()->where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['period'])) {
            $q->where('period_label', $filters['period']);
        }
        if (! empty($filters['fromDate'])) {
            $from = CarbonImmutable::parse($filters['fromDate'], 'Asia/Bangkok')->toDateString();
            $q->where(fn ($w) => $w->whereDate('payment_date', '>=', $from)
                ->orWhere(fn ($ww) => $ww->whereNull('payment_date')->whereDate('created_at', '>=', $from)));
        }
        if (! empty($filters['toDate'])) {
            $to = CarbonImmutable::parse($filters['toDate'], 'Asia/Bangkok')->toDateString();
            $q->where(fn ($w) => $w->whereDate('payment_date', '<=', $to)
                ->orWhere(fn ($ww) => $ww->whereNull('payment_date')->whereDate('created_at', '<=', $to)));
        }
        if (! empty($filters['q'])) {
            $needle = '%' . $filters['q'] . '%';
            $q->where(fn ($w) => $w->where('period_label', 'like', $needle)
                ->orWhere('note', 'like', $needle)
                ->orWhere('payment_reference', 'like', $needle));
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * Reconciliation KPIs across the (optionally date-filtered) batch history.
     * Paid = sum of PAID batches; pending = APPROVED/DRAFT/GENERATED not yet
     * paid; reversed count; plus batch/agent tallies.
     *
     * @return array<string, mixed>
     */
    public function historySummary(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $q = CommissionPayoutBatch::query()->where('tenant_id', $tenantId);
        if ($from !== null) {
            $f = CarbonImmutable::parse($from, 'Asia/Bangkok')->toDateString();
            $q->where(fn ($w) => $w->whereDate('payment_date', '>=', $f)
                ->orWhere(fn ($ww) => $ww->whereNull('payment_date')->whereDate('created_at', '>=', $f)));
        }
        if ($to !== null) {
            $t = CarbonImmutable::parse($to, 'Asia/Bangkok')->toDateString();
            $q->where(fn ($w) => $w->whereDate('payment_date', '<=', $t)
                ->orWhere(fn ($ww) => $ww->whereNull('payment_date')->whereDate('created_at', '<=', $t)));
        }
        $batches = $q->get();

        $paid = $batches->where('status', CommissionPayoutBatch::STATUS_PAID);
        $pending = $batches->whereIn('status', [
            CommissionPayoutBatch::STATUS_DRAFT,
            CommissionPayoutBatch::STATUS_GENERATED,
            CommissionPayoutBatch::STATUS_APPROVED,
        ]);
        $reversed = $batches->where('status', CommissionPayoutBatch::STATUS_REVERSED);

        return [
            'batchCount' => $batches->count(),
            'paidBatchCount' => $paid->count(),
            'pendingBatchCount' => $pending->count(),
            'reversedBatchCount' => $reversed->count(),
            'paidAmount' => round((float) $paid->sum('total_amount'), 2),
            'pendingAmount' => round((float) $pending->sum('total_amount'), 2),
            'reversedAmount' => round((float) $reversed->sum('total_amount'), 2),
            'totalAgentsPaid' => (int) $paid->sum('total_agents'),
        ];
    }

    /**
     * The audit timeline for one batch — reads back the AuditEntry rows this
     * service writes (target = "batch:{id}"), joined to users for readable
     * actor names, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditTimeline(int $tenantId, int $batchId): array
    {
        return AuditEntry::query()
            ->leftJoin('users as u', 'u.id', '=', 'audit_entries.user_id')
            ->where('audit_entries.tenant_id', $tenantId)
            ->where('audit_entries.target', "batch:{$batchId}")
            ->where('audit_entries.action', 'like', 'commission_payout.%')
            ->orderBy('audit_entries.occurred_at')
            ->orderBy('audit_entries.id')
            ->get([
                'audit_entries.action', 'audit_entries.occurred_at', 'audit_entries.actor',
                'audit_entries.metadata', 'u.name as user_name',
            ])
            ->map(fn ($e) => [
                'action' => str_replace('commission_payout.', '', (string) $e->action),
                'at' => $e->occurred_at?->toIso8601String(),
                'actor' => $e->user_name ?: ($e->actor === 'system' ? 'system' : "user #{$e->actor}"),
                'metadata' => is_string($e->metadata) ? json_decode($e->metadata, true) : $e->metadata,
            ])
            ->all();
    }

    /**
     * Resolve a bank-transfer reference to the batch(es)/agent(s) that carry it
     * — for reconciling payouts against a bank statement. Matches both the
     * batch-level reference and per-agent references.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByReference(int $tenantId, string $reference): array
    {
        $needle = '%' . $reference . '%';
        $hits = [];

        // Per-agent references.
        CommissionPayoutBatchAgent::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_reference', 'like', $needle)
            ->with('batch:id,period_label,status,payment_date')
            ->get()
            ->each(function ($ar) use (&$hits): void {
                $hits[] = [
                    'batchId' => (string) $ar->batch_id,
                    'periodLabel' => $ar->batch?->period_label,
                    'status' => $ar->batch?->status,
                    'paymentDate' => $ar->batch?->payment_date?->toDateString(),
                    'agentCode' => $ar->agent_code,
                    'agentName' => $ar->agent_name,
                    'reference' => $ar->payment_reference,
                    'amount' => round((float) $ar->net_transfer, 2),
                    'level' => 'agent',
                ];
            });

        // Batch-level references (bulk pay).
        CommissionPayoutBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('payment_reference', 'like', $needle)
            ->get()
            ->each(function ($b) use (&$hits): void {
                $hits[] = [
                    'batchId' => (string) $b->id,
                    'periodLabel' => $b->period_label,
                    'status' => $b->status,
                    'paymentDate' => $b->payment_date?->toDateString(),
                    'agentCode' => null,
                    'agentName' => null,
                    'reference' => $b->payment_reference,
                    'amount' => round((float) $b->total_amount, 2),
                    'level' => 'batch',
                ];
            });

        return $hits;
    }
}
