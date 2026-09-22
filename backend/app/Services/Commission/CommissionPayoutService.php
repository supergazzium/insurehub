<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\AuditEntry;
use App\Models\CommissionPayoutAdjustment;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchItem;
use App\Models\PolicyRebate;
use Carbon\CarbonImmutable;
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
    private function eligibleQuery(int $tenantId, string $from, string $to)
    {
        $fromDt = CarbonImmutable::parse($from, 'Asia/Bangkok')->startOfDay();
        $toDtExclusive = CarbonImmutable::parse($to, 'Asia/Bangkok')->addDay()->startOfDay();

        return DB::table('policies as p')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.writing_agent_id')
            ->where('p.created_at', '>=', $fromDt)
            ->where('p.created_at', '<', $toDtExclusive)
            ->where('p.status', '!=', 'cancelled')
            ->whereIn('p.status', self::APPROVED_STATUSES)
            // Not already marked paid on the agent leg.
            ->where(function ($w): void {
                $w->whereNull('r.agent_rebate_status')
                    ->orWhere('r.agent_rebate_status', '')
                    ->orWhere('r.agent_rebate_status', '!=', self::PAID_STATUS);
            })
            // Not already in a live batch.
            ->whereNotExists(function ($q) use ($tenantId): void {
                $q->select(DB::raw(1))
                    ->from('commission_payout_batch_items as bi')
                    ->join('commission_payout_batches as b', 'b.id', '=', 'bi.batch_id')
                    ->whereColumn('bi.policy_id', 'p.id')
                    ->where('bi.tenant_id', $tenantId)
                    ->where('b.status', '!=', CommissionPayoutBatch::STATUS_CANCELLED);
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

    private function rows(int $tenantId, string $from, string $to)
    {
        return $this->eligibleQuery($tenantId, $from, $to)
            ->select([
                'p.id as policy_id', 'p.policy_no', 'p.application_no', 'p.main_premium',
                'p.comm_hub_to_agent_amount',
                'p.writing_agent_id as agent_id',
                'a.agent_code', 'a.vat_type', 'a.has_vat', 'a.vat_mode',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'r.calculated_agent_amount', 'r.actual_agent_amount', 'r.agent_rebate_status',
                DB::raw('(SELECT COALESCE(SUM(pr.com_amt_ag),0) FROM policy_riders pr WHERE pr.policy_id = p.id) as rider_com_ag'),
            ])
            ->orderBy('a.agent_code')
            ->get();
    }

    /**
     * Preview eligible items grouped by agent — no persistence.
     *
     * @return array{agents: array<int, array<string, mixed>>, totals: array<string, mixed>, warnings: array<int, string>}
     */
    public function preview(int $tenantId, string $from, string $to): array
    {
        $rows = $this->rows($tenantId, $from, $to);
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

            if ($amt['source'] === 'none') {
                $warnings[] = "กรมธรรม์ {$row->policy_no} ({$row->agent_code}) ไม่มียอดค่าคอมตัวแทน";
            }
            if (! $row->agent_code) {
                $warnings[] = "กรมธรรม์ {$row->policy_no} ไม่มีรหัสตัวแทน";
            }
        }

        $agents = array_values($byAgent);
        foreach ($agents as &$a) {
            $a['amount'] = round($a['amount'], 2);
        }

        return [
            'agents' => $agents,
            'totals' => [
                'totalAgents' => count($agents),
                'totalItems' => $itemCount,
                'totalAmount' => round($grandTotal, 2),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Snapshot the eligible items into a new DRAFT batch.
     */
    public function createBatch(int $tenantId, string $from, string $to, ?int $userId, ?string $note = null): CommissionPayoutBatch
    {
        $rows = $this->rows($tenantId, $from, $to);
        if ($rows->isEmpty()) {
            throw new RuntimeException('ไม่พบรายการค่าคอมที่เข้าเงื่อนไขในช่วงวันที่ที่ระบุ');
        }

        return DB::transaction(function () use ($tenantId, $from, $to, $userId, $note, $rows): CommissionPayoutBatch {
            $batch = CommissionPayoutBatch::create([
                'tenant_id' => $tenantId,
                'from_date' => $from,
                'to_date' => $to,
                'status' => CommissionPayoutBatch::STATUS_DRAFT,
                'created_by_user_id' => $userId,
                'note' => $note,
            ]);

            $agents = [];
            $total = 0.0;
            foreach ($rows as $row) {
                $amt = $this->resolveAmount($row);
                CommissionPayoutBatchItem::create([
                    'batch_id' => $batch->id,
                    'tenant_id' => $tenantId,
                    'policy_id' => $row->policy_id,
                    'agent_id' => $row->agent_id,
                    'agent_code' => $row->agent_code,
                    'vat_type' => $this->resolveVatType($row),
                    'snapshot_base_premium' => $amt['base'],
                    'snapshot_agent_commission' => $amt['main'],
                    'snapshot_rider_commission' => $amt['rider'],
                    'amount_source' => $amt['source'],
                    'item_status' => CommissionPayoutBatchItem::ITEM_PENDING,
                    'original_agent_rebate_status' => $row->agent_rebate_status,
                ]);
                $agents[$row->agent_id ?? 0] = true;
                $total += $amt['main'] + $amt['rider'];
            }

            $batch->update([
                'total_agents' => count($agents),
                'total_items' => $rows->count(),
                'total_amount' => round($total, 2),
            ]);

            $this->audit($tenantId, $userId, 'commission_payout.create', "batch:{$batch->id}", [
                'from' => $from, 'to' => $to, 'items' => $rows->count(), 'amount' => round($total, 2),
            ]);

            return $batch->fresh();
        });
    }

    public function addAdjustment(CommissionPayoutBatch $batch, ?int $agentId, ?string $agentCode, float $amount, string $reason, ?int $userId): CommissionPayoutAdjustment
    {
        if (! $batch->isEditable()) {
            throw new RuntimeException('ไม่สามารถแก้ไข batch ที่จ่ายแล้วหรือยกเลิกแล้ว');
        }
        if ($amount < 0) {
            throw new RuntimeException('ยอดหักต้องไม่ติดลบ');
        }
        $adj = CommissionPayoutAdjustment::create([
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'agent_id' => $agentId,
            'agent_code' => $agentCode,
            'type' => CommissionPayoutAdjustment::TYPE_SPECIAL_DEDUCT,
            'amount' => $amount,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.adjust', "batch:{$batch->id}", [
            'agent' => $agentCode, 'amount' => $amount, 'reason' => $reason,
        ]);

        return $adj;
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

    /** Approve a batch so it can be paid (spec §8.1: separate approve step). */
    public function approveBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        if (! in_array($batch->status, [CommissionPayoutBatch::STATUS_DRAFT, CommissionPayoutBatch::STATUS_GENERATED], true)) {
            throw new RuntimeException('อนุมัติได้เฉพาะ batch สถานะร่าง/สร้างเอกสารแล้ว');
        }
        $batch->update([
            'status' => CommissionPayoutBatch::STATUS_APPROVED,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
        ]);
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

    public function cancelBatch(CommissionPayoutBatch $batch, ?int $userId): CommissionPayoutBatch
    {
        if ($batch->status === CommissionPayoutBatch::STATUS_PAID) {
            throw new RuntimeException('ไม่สามารถยกเลิก batch ที่จ่ายแล้ว (ต้องทำ reversal)');
        }
        $batch->update(['status' => CommissionPayoutBatch::STATUS_CANCELLED]);
        $this->audit($batch->tenant_id, $userId, 'commission_payout.cancel', "batch:{$batch->id}", []);

        return $batch->fresh();
    }

    /** VAT rate applied to agent commission when the agent carries VAT. */
    private const VAT_RATE = 0.07;

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
            ->with(['policy:id,policy_no,application_no,customer_id', 'policy.customer:id,first_name,last_name', 'agent:id,agent_code,first_name,last_name,vat_type,has_vat,vat_mode'])
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($agentId === null && $agentCode !== null, fn ($q) => $q->where('agent_code', $agentCode))
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $first = $items->first();
        // The item's vat_type was resolved at batch creation; use it, else re-resolve
        // from the agent's current has_vat/vat_mode.
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

        // VAT presentation per VAT_TYPE (spec §5).
        $vat = 0.0;
        $net = $afterDeduct;
        $baseExVat = $afterDeduct;
        $payable = $afterDeduct;
        if ($vatType === '2') {
            // Exclude: add VAT on top.
            $vat = round($afterDeduct * self::VAT_RATE, 2);
            $payable = round($afterDeduct + $vat, 2);
        } elseif ($vatType === '3') {
            // Include: VAT already inside the amount.
            $baseExVat = round($afterDeduct / (1 + self::VAT_RATE), 2);
            $vat = round($afterDeduct - $baseExVat, 2);
            $payable = $afterDeduct;
        }

        $agentName = $first->agent ? trim("{$first->agent->first_name} {$first->agent->last_name}") : '';

        return [
            'vatType' => $vatType,
            'agent' => ['code' => $first->agent_code ?? $agentCode, 'name' => $agentName],
            'items' => $rows,
            'totals' => [
                'commission' => round($commission, 2),
                'deduct' => round($deduct, 2),
                'net' => $net,
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
}
