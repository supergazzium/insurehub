<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\AuditEntry;
use App\Models\CommissionReceivable;
use App\Models\CommissionReceiptBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Insurer commission receipt reconciliation — Phase 1 Manual (DEV Spec ชุดที่ 3).
 *
 * Receivable rows are seeded lazily from the existing commission data
 * (policies.comm_carrier_to_hub_amount for MAIN, policy_rebates.calculated_ov
 * for OV) — never recomputed here. Staff key the statement/received amounts;
 * the system computes the difference and suggests Matched/Mismatch. Every
 * status change is audited; Reopen/Correction requires a reason and uses an
 * optimistic-lock version.
 */
class CommissionReceiptService
{
    /**
     * Ensure a receivable row exists for every eligible policy in the filter,
     * seeded from the expected-amount sources. Idempotent — safe to call on
     * every list view (spec: History must show all, Main/OV show outstanding).
     */
    public function seedReceivables(int $tenantId, int $policyYear, int $insurerId): void
    {
        // Policies in scope that don't yet have a MAIN/OV receivable row.
        $policies = DB::table('policies as p')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.policy_year', $policyYear)
            ->where('p.carrier_id', $insurerId)
            ->whereIn('p.status', ['active', 'issued'])
            ->select([
                'p.id', 'p.carrier_id', 'p.policy_year',
                'p.comm_carrier_to_hub_amount',
                'r.calculated_amount', 'r.calculated_ov',
            ])
            ->get();

        foreach ($policies as $p) {
            $mainExpected = (float) ($p->comm_carrier_to_hub_amount ?: $p->calculated_amount ?: 0);
            $ovExpected = (float) ($p->calculated_ov ?: 0);

            CommissionReceivable::firstOrCreate(
                ['policy_id' => $p->id, 'commission_type' => CommissionReceivable::TYPE_MAIN],
                [
                    'tenant_id' => $tenantId,
                    'insurer_id' => $p->carrier_id,
                    'policy_year' => $p->policy_year,
                    'expected_amount' => $mainExpected,
                    'status' => CommissionReceivable::STATUS_PENDING,
                ],
            );
            CommissionReceivable::firstOrCreate(
                ['policy_id' => $p->id, 'commission_type' => CommissionReceivable::TYPE_OV],
                [
                    'tenant_id' => $tenantId,
                    'insurer_id' => $p->carrier_id,
                    'policy_year' => $p->policy_year,
                    'expected_amount' => $ovExpected,
                    'status' => CommissionReceivable::STATUS_PENDING,
                ],
            );
        }
    }

    /**
     * Manual review: staff key the statement amount; system suggests status.
     * Actual == Expected → Matched; else → Mismatch (requires a note).
     */
    public function review(CommissionReceivable $rec, float $statementAmount, ?string $note, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        $this->guardVersion($rec, $expectedVersion);
        if (in_array($rec->status, [CommissionReceivable::STATUS_RECEIVED], true)) {
            throw new RuntimeException('รายการนี้รับแล้ว ต้อง Reopen ก่อนแก้ไข');
        }

        $matched = abs($statementAmount - (float) $rec->expected_amount) <= CommissionReceivable::TOLERANCE;
        if (! $matched && ($note === null || trim($note) === '')) {
            throw new RuntimeException('ยอดไม่ตรง ต้องระบุหมายเหตุก่อนบันทึก');
        }

        $old = $rec->only(['status', 'statement_amount']);
        $rec->update([
            'statement_amount' => $statementAmount,
            'status' => $matched ? CommissionReceivable::STATUS_MATCHED : CommissionReceivable::STATUS_MISMATCH,
            'note' => $note,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ]);

        $this->audit($rec, $matched ? 'receivable.match' : 'receivable.mismatch', $userId, $old, $rec->only(['status', 'statement_amount']), $note);

        return $rec->fresh();
    }

    public function confirmReceived(CommissionReceivable $rec, float $receivedAmount, string $receivedDate, ?int $receiptBatchId, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        $this->guardVersion($rec, $expectedVersion);
        if ($rec->status === CommissionReceivable::STATUS_RECEIVED) {
            throw new RuntimeException('รายการนี้ยืนยันรับแล้ว (ต้อง Reopen เพื่อแก้ไข)');
        }
        if ($rec->status === CommissionReceivable::STATUS_MISMATCH) {
            throw new RuntimeException('ยอดไม่ตรง (Mismatch) — ต้อง resolve ก่อนยืนยันรับ');
        }

        $old = $rec->only(['status', 'received_amount', 'received_date']);
        $rec->update([
            'received_amount' => $receivedAmount,
            'received_date' => $receivedDate,
            'receipt_batch_id' => $receiptBatchId,
            'status' => CommissionReceivable::STATUS_RECEIVED,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ]);

        $this->audit($rec, 'receivable.receive', $userId, $old, $rec->only(['status', 'received_amount', 'received_date']), null);

        return $rec->fresh();
    }

    public function markNoCommission(CommissionReceivable $rec, string $reason, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        $this->guardVersion($rec, $expectedVersion);
        if (trim($reason) === '') {
            throw new RuntimeException('ต้องระบุเหตุผลสำหรับ No Commission');
        }
        $old = $rec->only(['status']);
        $rec->update([
            'status' => CommissionReceivable::STATUS_NO_COMMISSION,
            'note' => $reason,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ]);
        $this->audit($rec, 'receivable.no_commission', $userId, $old, $rec->only(['status']), $reason);

        return $rec->fresh();
    }

    /** Reopen a Received / No Commission row back to Pending (spec §7). */
    public function reopen(CommissionReceivable $rec, string $reason, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        $this->guardVersion($rec, $expectedVersion);
        if (trim($reason) === '') {
            throw new RuntimeException('ต้องระบุเหตุผลสำหรับ Reopen');
        }
        if (! in_array($rec->status, [CommissionReceivable::STATUS_RECEIVED, CommissionReceivable::STATUS_NO_COMMISSION], true)) {
            throw new RuntimeException('Reopen ได้เฉพาะรายการที่ Received หรือ No Commission');
        }
        $old = $rec->only(['status', 'received_amount', 'received_date']);
        $rec->update([
            'status' => CommissionReceivable::STATUS_PENDING,
            'note' => $reason,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ]);
        $this->audit($rec, 'receivable.reopen', $userId, $old, $rec->only(['status']), $reason);

        return $rec->fresh();
    }

    public function createReceiptBatch(int $tenantId, array $data, ?int $userId): CommissionReceiptBatch
    {
        $seq = CommissionReceiptBatch::where('tenant_id', $tenantId)->count() + 1;
        $batch = CommissionReceiptBatch::create([
            'tenant_id' => $tenantId,
            'batch_no' => sprintf('REC-%s-%04d', now()->format('Ym'), $seq),
            'insurer_id' => $data['insurerId'],
            'policy_year' => $data['policyYear'] ?? null,
            'statement_date' => $data['statementDate'] ?? null,
            'received_file_date' => $data['receivedFileDate'] ?? null,
            'remark' => $data['remark'] ?? null,
            'created_by_user_id' => $userId,
        ]);

        return $batch;
    }

    /** Dashboard feed (spec §12.1) — expected/received/outstanding roll-ups. */
    public function summary(int $tenantId, ?int $policyYear, ?int $insurerId): array
    {
        $q = CommissionReceivable::query()->where('tenant_id', $tenantId);
        if ($policyYear) {
            $q->where('policy_year', $policyYear);
        }
        if ($insurerId) {
            $q->where('insurer_id', $insurerId);
        }

        $rows = (clone $q)->get(['status', 'commission_type', 'expected_amount', 'received_amount', 'statement_amount']);

        $expected = 0.0;
        $received = 0.0;
        $outstanding = 0.0;
        $mismatchCount = 0;
        $mismatchAmount = 0.0;
        $noCommissionCount = 0;

        foreach ($rows as $r) {
            $expected += (float) $r->expected_amount;
            if ($r->status === CommissionReceivable::STATUS_RECEIVED) {
                $received += (float) ($r->received_amount ?? 0);
            } elseif ($r->status === CommissionReceivable::STATUS_NO_COMMISSION) {
                $noCommissionCount++;
            } else {
                $outstanding += (float) $r->expected_amount;
                if ($r->status === CommissionReceivable::STATUS_MISMATCH) {
                    $mismatchCount++;
                    $mismatchAmount += abs((float) ($r->statement_amount ?? 0) - (float) $r->expected_amount);
                }
            }
        }

        return [
            'expected' => round($expected, 2),
            'received' => round($received, 2),
            'outstanding' => round($outstanding, 2),
            'mismatchCount' => $mismatchCount,
            'mismatchAmount' => round($mismatchAmount, 2),
            'noCommissionCount' => $noCommissionCount,
        ];
    }

    /**
     * Full reconciliation dashboard (spec §12): insurer-side KPIs from the
     * receivables plus agent-side payable/paid from the payout module, monthly
     * expected-vs-received, outstanding-by-insurer, and a policy-level table.
     * Reuses existing commission data — nothing recomputed.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $tenantId, ?int $policyYear, ?int $insurerId): array
    {
        // Insurer-side KPIs (reuse summary()).
        $ins = $this->summary($tenantId, $policyYear, $insurerId);

        // ── Agent-side: payable (policies.comm_hub_to_agent_amount + riders)
        //    and paid (paid payout batch items). Scoped to the same filter. ──
        $payQ = DB::table('policies as p')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', ['active', 'issued']);
        if ($policyYear) {
            $payQ->where('p.policy_year', $policyYear);
        }
        if ($insurerId) {
            $payQ->where('p.carrier_id', $insurerId);
        }
        $agentPayable = (float) (clone $payQ)->sum('p.comm_hub_to_agent_amount');

        $paidQ = DB::table('commission_payout_batch_items as bi')
            ->join('policies as p', 'p.id', '=', 'bi.policy_id')
            ->where('bi.tenant_id', $tenantId)
            ->where('bi.item_status', 'paid');
        if ($policyYear) {
            $paidQ->where('p.policy_year', $policyYear);
        }
        if ($insurerId) {
            $paidQ->where('p.carrier_id', $insurerId);
        }
        $agentPaid = (float) (clone $paidQ)->sum(DB::raw('bi.snapshot_agent_commission + bi.snapshot_rider_commission'));

        // ── Monthly Expected vs Received (by policy effective_date). ──
        $monthlyQ = DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->where('cr.tenant_id', $tenantId)
            ->whereNotNull('p.effective_date');
        if ($policyYear) {
            $monthlyQ->where('cr.policy_year', $policyYear);
        }
        if ($insurerId) {
            $monthlyQ->where('cr.insurer_id', $insurerId);
        }
        $monthly = (clone $monthlyQ)
            ->selectRaw("DATE_FORMAT(p.effective_date, '%Y-%m') as ym")
            ->selectRaw('SUM(cr.expected_amount) as expected')
            ->selectRaw("SUM(CASE WHEN cr.status = 'Received' THEN cr.received_amount ELSE 0 END) as received")
            ->groupBy('ym')->orderBy('ym')
            ->get()
            ->map(fn ($r): array => [
                'month' => $r->ym,
                'expected' => round((float) $r->expected, 2),
                'received' => round((float) $r->received, 2),
            ]);

        // ── Outstanding by insurer (Pending/Matched/Mismatch). ──
        $outByInsurerQ = DB::table('commission_receivables as cr')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'cr.insurer_id')
            ->where('cr.tenant_id', $tenantId)
            ->whereIn('cr.status', ['Pending', 'Matched', 'Mismatch']);
        if ($policyYear) {
            $outByInsurerQ->where('cr.policy_year', $policyYear);
        }
        $outstandingByInsurer = (clone $outByInsurerQ)
            ->selectRaw('ca.name as insurer')
            ->selectRaw('SUM(cr.expected_amount) as amount')
            ->groupBy('ca.name')->orderByDesc('amount')
            ->limit(15)->get()
            ->map(fn ($r): array => ['insurer' => $r->insurer ?? '—', 'amount' => round((float) $r->amount, 2)]);

        // ── Policy-level reconciliation table (spec §12.3). ──
        $policyRows = $this->policyLevel($tenantId, $policyYear, $insurerId);

        return [
            'kpi' => [
                'expectedInsurer' => $ins['expected'],
                'receivedInsurer' => $ins['received'],
                'outstanding' => $ins['outstanding'],
                'mismatchCount' => $ins['mismatchCount'],
                'mismatchAmount' => $ins['mismatchAmount'],
                'noCommissionCount' => $ins['noCommissionCount'],
                'agentPayable' => round($agentPayable, 2),
                'agentPaid' => round($agentPaid, 2),
                'expectedMargin' => round($ins['expected'] - $agentPayable, 2),
                'realizedMargin' => round($ins['received'] - $agentPaid, 2),
            ],
            'monthly' => $monthly,
            'outstandingByInsurer' => $outstandingByInsurer,
            'policyLevel' => $policyRows,
        ];
    }

    /**
     * Policy-level rows: insurer expected/received (MAIN+OV), Main/OV status,
     * agent payable/paid, and both margins. Capped for the dashboard table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function policyLevel(int $tenantId, ?int $policyYear, ?int $insurerId): array
    {
        $q = DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->where('cr.tenant_id', $tenantId);
        if ($policyYear) {
            $q->where('cr.policy_year', $policyYear);
        }
        if ($insurerId) {
            $q->where('cr.insurer_id', $insurerId);
        }

        // Aggregate MAIN/OV per policy.
        $recs = $q->select([
            'cr.policy_id', 'cr.commission_type', 'cr.expected_amount', 'cr.received_amount', 'cr.status',
            'p.policy_no', 'p.application_no', 'p.comm_hub_to_agent_amount',
        ])->limit(3000)->get();

        $byPolicy = [];
        foreach ($recs as $r) {
            $pid = $r->policy_id;
            if (! isset($byPolicy[$pid])) {
                $byPolicy[$pid] = [
                    'policyId' => (string) $pid,
                    'policyNo' => $r->policy_no ?: $r->application_no,
                    'insurerExpected' => 0.0,
                    'insurerReceived' => 0.0,
                    'mainStatus' => null,
                    'ovStatus' => null,
                    'agentPayable' => round((float) ($r->comm_hub_to_agent_amount ?? 0), 2),
                    'agentPaid' => 0.0,
                ];
            }
            $byPolicy[$pid]['insurerExpected'] += (float) $r->expected_amount;
            if ($r->status === 'Received') {
                $byPolicy[$pid]['insurerReceived'] += (float) ($r->received_amount ?? 0);
            }
            if ($r->commission_type === 'MAIN') {
                $byPolicy[$pid]['mainStatus'] = $r->status;
            } else {
                $byPolicy[$pid]['ovStatus'] = $r->status;
            }
        }

        // Agent paid per policy (paid batch items).
        $pids = array_keys($byPolicy);
        if (! empty($pids)) {
            $paid = DB::table('commission_payout_batch_items')
                ->whereIn('policy_id', $pids)
                ->where('item_status', 'paid')
                ->selectRaw('policy_id, SUM(snapshot_agent_commission + snapshot_rider_commission) as paid')
                ->groupBy('policy_id')->get();
            foreach ($paid as $p) {
                if (isset($byPolicy[$p->policy_id])) {
                    $byPolicy[$p->policy_id]['agentPaid'] = round((float) $p->paid, 2);
                }
            }
        }

        return array_map(function (array $r): array {
            $r['insurerExpected'] = round($r['insurerExpected'], 2);
            $r['insurerReceived'] = round($r['insurerReceived'], 2);
            $r['expectedMargin'] = round($r['insurerExpected'] - $r['agentPayable'], 2);
            $r['cashMargin'] = round($r['insurerReceived'] - $r['agentPaid'], 2);

            return $r;
        }, array_slice(array_values($byPolicy), 0, 500));
    }

    private function guardVersion(CommissionReceivable $rec, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $rec->version !== $expectedVersion) {
            throw new RuntimeException('ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดใหม่ (version conflict)');
        }
    }

    private function audit(CommissionReceivable $rec, string $action, ?int $userId, array $old, array $new, ?string $reason): void
    {
        AuditEntry::create([
            'tenant_id' => $rec->tenant_id,
            'user_id' => $userId,
            'occurred_at' => now(),
            'actor' => $userId ? (string) $userId : 'system',
            'action' => $action,
            'target' => "receivable:{$rec->id}",
            'result' => 'success',
            'metadata' => ['old' => $old, 'new' => $new, 'reason' => $reason],
        ]);
    }
}
