<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\AuditEntry;
use App\Models\CommissionReceivable;
use App\Models\CommissionReceiptBatch;
use App\Models\Policy;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommissionReceiptService
{
    public const SEED_STATUSES = ['issued', 'active', 'expired', 'lapsed', 'cancelled'];

    public const LIST_LIMIT = 1000;

    private const LEGACY_NOTE = 'รับแล้วในระบบเดิม';

    public function seedReceivables(int $tenantId, int $insurerId, ?string $monthTo = null, ?int $policyYear = null): int
    {
        $legacy = "p.imported_from_legacy = 1 AND p.com_rec_check = 'Complete'";
        $expected = 'ROUND(COALESCE(NULLIF(p.comm_carrier_to_hub_amount, 0), NULLIF(r.calculated_amount, 0), 0), 2)';
        $now = now()->toDateTimeString();
        $columns = [
            'tenant_id', 'policy_id', 'insurer_id', 'policy_year', 'commission_type', 'line_no',
            'expected_amount', 'status', 'received_amount', 'source', 'note', 'version', 'created_at', 'updated_at',
        ];

        $main = $this->seedScope($tenantId, $insurerId, $monthTo, $policyYear)
            ->whereNotExists(fn (Builder $w) => $this->existingRow($w, CommissionReceivable::TYPE_MAIN))
            ->selectRaw(
                "p.tenant_id, p.id, p.carrier_id, p.policy_year, 'MAIN', 0, {$expected},
                CASE WHEN {$legacy} THEN 'Received' ELSE 'Pending' END,
                CASE WHEN {$legacy} THEN {$expected} ELSE NULL END,
                CASE WHEN {$legacy} THEN 'legacy' ELSE 'system' END,
                CASE WHEN {$legacy} THEN ? ELSE NULL END,
                0, ?, ?",
                [self::LEGACY_NOTE, $now, $now],
            );

        $ov = $this->seedScope($tenantId, $insurerId, $monthTo, $policyYear)
            ->where('r.calculated_ov', '>', 0)
            ->whereNotExists(fn (Builder $w) => $this->existingRow($w, CommissionReceivable::TYPE_OV))
            ->selectRaw(
                "p.tenant_id, p.id, p.carrier_id, p.policy_year, 'OV', 0, ROUND(r.calculated_ov, 2), 'Pending', NULL, 'system', NULL, 0, ?, ?",
                [$now, $now],
            );

        $count = 0;
        foreach ([$main, $ov] as $select) {
            try {
                $count += DB::table('commission_receivables')->insertUsing($columns, $select);
            } catch (UniqueConstraintViolationException) {
            }
        }

        return $count;
    }

    private function seedScope(int $tenantId, int $insurerId, ?string $monthTo, ?int $policyYear): Builder
    {
        return DB::table('policies as p')
            ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)
            ->where('p.carrier_id', $insurerId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', self::SEED_STATUSES)
            ->when($monthTo !== null, fn (Builder $q) => $q->where('p.effective_date', '<=', $this->monthEnd($monthTo)))
            ->when($policyYear !== null, fn (Builder $q) => $q->where('p.policy_year', $policyYear));
    }

    private function existingRow(Builder $w, string $type): void
    {
        $w->select(DB::raw(1))
            ->from('commission_receivables as cx')
            ->whereColumn('cx.policy_id', 'p.id')
            ->where('cx.commission_type', $type)
            ->where('cx.line_no', 0);
    }

    public function ensureRow(object $policy, string $type): CommissionReceivable
    {
        $existing = CommissionReceivable::query()
            ->where('policy_id', $policy->id)->where('commission_type', $type)->where('line_no', 0)->first();
        if ($existing !== null) {
            return $existing;
        }

        $expected = $type === CommissionReceivable::TYPE_MAIN
            ? (float) ($policy->comm_carrier_to_hub_amount ?: $policy->calculated_amount ?: 0)
            : (float) ($policy->calculated_ov ?: 0);
        $legacy = $type === CommissionReceivable::TYPE_MAIN && (bool) $policy->imported_from_legacy && $policy->com_rec_check === 'Complete';

        try {
            return CommissionReceivable::create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'insurer_id' => $policy->carrier_id,
                'policy_year' => $policy->policy_year,
                'commission_type' => $type,
                'line_no' => 0,
                'expected_amount' => round($expected, 2),
                'status' => $legacy ? CommissionReceivable::STATUS_RECEIVED : CommissionReceivable::STATUS_PENDING,
                'received_amount' => $legacy ? round($expected, 2) : null,
                'source' => $legacy ? CommissionReceivable::SOURCE_LEGACY : CommissionReceivable::SOURCE_SYSTEM,
                'note' => $legacy ? self::LEGACY_NOTE : null,
                'version' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            return CommissionReceivable::query()
                ->where('policy_id', $policy->id)->where('commission_type', $type)->where('line_no', 0)->firstOrFail();
        }
    }

    public function monthStart(string $ym): string
    {
        return Carbon::createFromFormat('!Y-m', $ym)->startOfMonth()->toDateString();
    }

    public function monthEnd(string $ym): string
    {
        return Carbon::createFromFormat('!Y-m', $ym)->endOfMonth()->toDateString();
    }

    private function joined(): Builder
    {
        return DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'cr.insurer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->leftJoin('commission_receipt_batches as rb', 'rb.id', '=', 'cr.receipt_batch_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'cr.received_by_user_id');
    }

    private function selectRow(Builder $q): Builder
    {
        return $q
            ->select([
                'cr.id', 'cr.commission_type', 'cr.line_no', 'cr.adjustment_kind', 'cr.policy_event_id',
                'cr.expected_amount', 'cr.statement_amount', 'cr.received_amount', 'cr.status', 'cr.received_date',
                'cr.version', 'cr.note', 'cr.diff_reason', 'cr.diff_note', 'cr.source', 'cr.receipt_batch_id',
                'cr.insurer_id', 'cr.policy_id',
                'rb.batch_no', 'ru.name as received_by_name',
                'p.policy_no', 'p.application_no', 'p.policy_year', 'p.status as policy_status', 'p.cancel_date',
                'p.effective_date', 'p.main_premium', 'p.net_premium',
                'p.comm_carrier_to_hub_amount', 'p.comm_carrier_to_hub_rate', 'p.imported_from_legacy',
                DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"), 'c.customer_code',
                'ca.name as insurer_name', 'a.agent_code',
                DB::raw("CONCAT_WS(' ', a.first_name, a.last_name) as agent_name"),
                'pr.name as product_name',
            ]);
    }

    public function listQuery(int $tenantId, array $f): Builder
    {
        $history = (bool) ($f['history'] ?? false);
        $q = $this->joined()->where('cr.tenant_id', $tenantId);

        if (! empty($f['insurerId'])) {
            $q->where('cr.insurer_id', (int) $f['insurerId']);
        }
        if (! empty($f['policyYear'])) {
            $q->where('p.policy_year', (int) $f['policyYear']);
        }

        $type = $f['type'] ?? 'ALL';
        match ($type) {
            'MAIN_ADJ' => $q->whereIn('cr.commission_type', [CommissionReceivable::TYPE_MAIN, CommissionReceivable::TYPE_ADJ]),
            'ALL' => null,
            default => $q->where('cr.commission_type', $type),
        };

        $statusFilter = $f['status'] ?? null;
        if ($statusFilter) {
            $q->where('cr.status', $statusFilter);
        } elseif (! $history) {
            $q->whereIn('cr.status', CommissionReceivable::OPEN_STATUSES);
        }

        $from = ! empty($f['monthFrom']) ? $this->monthStart($f['monthFrom']) : null;
        $to = ! empty($f['monthTo']) ? $this->monthEnd($f['monthTo']) : null;
        $outstanding = (bool) ($f['includeOutstanding'] ?? ! $history);
        if ($to !== null) {
            $q->where('p.effective_date', '<=', $to);
        }
        if ($from !== null) {
            $q->where(function (Builder $w) use ($from, $outstanding): void {
                $w->where('p.effective_date', '>=', $from);
                if ($outstanding) {
                    $w->orWhereIn('cr.status', CommissionReceivable::OPEN_STATUSES);
                }
            });
        }

        if (! empty($f['q'])) {
            $like = '%'.trim((string) $f['q']).'%';
            $q->where(function (Builder $w) use ($like): void {
                $w->where('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhere('c.customer_code', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like]);
            });
        }

        return $q;
    }

    public function listRows(int $tenantId, array $f, int $limit = self::LIST_LIMIT): array
    {
        $rows = $this->selectRow($this->listQuery($tenantId, $f))
            ->orderBy('p.effective_date')
            ->orderBy('p.policy_no')
            ->orderBy('cr.commission_type')
            ->orderBy('cr.line_no')
            ->limit($limit + 1)
            ->get();

        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $endorse = ReceivableHints::endorsementTotals($rows->pluck('policy_id')->map(fn ($v) => (int) $v)->all());

        return [
            'rows' => $rows->map(fn (object $r): array => $this->formatRow($r, $endorse[(int) $r->policy_id] ?? null))->values()->all(),
            'truncated' => $truncated,
        ];
    }

    public function formatRow(object $r, ?array $endorsement): array
    {
        $actual = $r->received_amount ?? $r->statement_amount;
        $row = [
            'id' => (string) $r->id,
            'commissionType' => $r->commission_type,
            'lineNo' => (int) $r->line_no,
            'adjustmentKind' => $r->adjustment_kind,
            'adjustmentKindLabel' => $r->adjustment_kind ? (CommissionReceivable::KIND_LABELS[$r->adjustment_kind] ?? $r->adjustment_kind) : null,
            'policyEventId' => $r->policy_event_id !== null ? (string) $r->policy_event_id : null,
            'policyId' => (string) $r->policy_id,
            'policyNo' => $r->policy_no,
            'applicationNo' => $r->application_no,
            'policyYear' => $r->policy_year,
            'policyStatus' => $r->policy_status,
            'cancelDate' => $r->cancel_date,
            'importedFromLegacy' => (bool) $r->imported_from_legacy,
            'customerName' => $r->customer_name,
            'customerCode' => $r->customer_code,
            'insurerId' => $r->insurer_id !== null ? (string) $r->insurer_id : null,
            'insurerName' => $r->insurer_name,
            'agentCode' => $r->agent_code,
            'agentName' => $r->agent_name,
            'productName' => $r->product_name,
            'effectiveDate' => $r->effective_date,
            'mainPremium' => $r->main_premium !== null ? (float) $r->main_premium : null,
            'netPremium' => $r->net_premium !== null ? (float) $r->net_premium : ($r->main_premium !== null ? (float) $r->main_premium : null),
            'policyCarrierAmount' => $r->comm_carrier_to_hub_amount !== null ? (float) $r->comm_carrier_to_hub_amount : null,
            'policyCarrierRate' => $r->comm_carrier_to_hub_rate !== null ? (float) $r->comm_carrier_to_hub_rate : null,
            'rate' => $r->comm_carrier_to_hub_rate !== null ? (float) $r->comm_carrier_to_hub_rate : null,
            'expectedAmount' => round((float) $r->expected_amount, 2),
            'statementAmount' => $r->statement_amount !== null ? round((float) $r->statement_amount, 2) : null,
            'receivedAmount' => $r->received_amount !== null ? round((float) $r->received_amount, 2) : null,
            'differenceAmount' => $actual !== null ? round((float) $actual - (float) $r->expected_amount, 2) : null,
            'status' => $r->status,
            'receivedDate' => $r->received_date,
            'receivedByName' => $r->received_by_name,
            'batchId' => $r->receipt_batch_id !== null ? (string) $r->receipt_batch_id : null,
            'batchNo' => $r->batch_no,
            'note' => $r->note,
            'diffReason' => $r->diff_reason,
            'diffReasonLabel' => $r->diff_reason ? (CommissionReceivable::REASON_LABELS[$r->diff_reason] ?? $r->diff_reason) : null,
            'diffNote' => $r->diff_note,
            'source' => $r->source,
            'version' => (int) $r->version,
        ];

        return $row + ReceivableHints::forRow($row, $endorsement);
    }

    public function rowById(int $id): array
    {
        $one = $this->selectRow($this->joined()->where('cr.id', $id))->first();
        if ($one === null) {
            throw new RuntimeException('ไม่พบรายการ');
        }
        $endorse = ReceivableHints::endorsementTotals([(int) $one->policy_id]);

        return $this->formatRow($one, $endorse[(int) $one->policy_id] ?? null);
    }

    public function rowsForPolicy(int $tenantId, int $policyId): array
    {
        $rows = $this->selectRow($this->joined()->where('cr.tenant_id', $tenantId)->where('cr.policy_id', $policyId))
            ->orderBy('cr.commission_type')->orderBy('cr.line_no')->get();
        $endorse = ReceivableHints::endorsementTotals([$policyId]);

        return $rows->map(fn (object $r): array => $this->formatRow($r, $endorse[$policyId] ?? null))->values()->all();
    }

    public function review(CommissionReceivable $rec, float $statementAmount, ?string $note, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        return DB::transaction(function () use ($rec, $statementAmount, $note, $userId, $expectedVersion): CommissionReceivable {
            $rec = $this->lock($rec);
            $this->applyReview($rec, $statementAmount, $note, $userId, $expectedVersion);

            return $rec->fresh();
        });
    }

    private function applyReview(CommissionReceivable $rec, float $statementAmount, ?string $note, ?int $userId, ?int $expectedVersion): void
    {
        $this->guardVersion($rec, $expectedVersion);
        if (! $rec->isOpen()) {
            throw new RuntimeException('รายการนี้ปิดแล้ว ต้องเปิดแก้ก่อนจึงจะคีย์ยอดใหม่ได้');
        }
        $this->guardSign($rec, $statementAmount);

        $matched = abs($statementAmount - (float) $rec->expected_amount) <= CommissionReceivable::TOLERANCE;
        $old = $rec->only(['status', 'statement_amount']);
        $values = [
            'statement_amount' => round($statementAmount, 2),
            'status' => $matched ? CommissionReceivable::STATUS_MATCHED : CommissionReceivable::STATUS_MISMATCH,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ];
        if ($note !== null && trim($note) !== '') {
            $values['note'] = trim($note);
        }
        $rec->update($values);

        $this->audit($rec, $matched ? 'receivable.match' : 'receivable.mismatch', $userId, $old, $rec->only(['status', 'statement_amount']), $note);
    }

    public function bulkReview(int $tenantId, array $items, ?int $userId): Collection
    {
        return DB::transaction(function () use ($tenantId, $items, $userId): Collection {
            $recs = $this->lockMany($tenantId, array_column($items, 'id'));
            $errors = [];
            foreach ($items as $item) {
                $rec = $recs->get((int) $item['id']);
                if ($rec === null) {
                    $errors[] = ['id' => (string) $item['id'], 'message' => 'ไม่พบรายการ'];

                    continue;
                }
                try {
                    $this->applyReview($rec, (float) $item['statementAmount'], null, $userId, isset($item['version']) ? (int) $item['version'] : null);
                } catch (RuntimeException $e) {
                    $errors[] = ['id' => (string) $rec->id, 'message' => $e->getMessage()];
                }
            }
            if ($errors !== []) {
                throw new ReceiptBulkException($errors);
            }

            return $recs->values();
        });
    }

    public function receive(CommissionReceivable $rec, ?float $amount, string $date, ?int $batchId, ?string $reason, ?string $note, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        return DB::transaction(function () use ($rec, $amount, $date, $batchId, $reason, $note, $userId, $expectedVersion): CommissionReceivable {
            $rec = $this->lock($rec);
            $batch = $this->resolveBatch((int) $rec->tenant_id, $batchId);
            $this->applyReceive($rec, $amount, $date, $batch, $reason, $note, $userId, $expectedVersion);

            return $rec->fresh();
        });
    }

    public function bulkReceive(int $tenantId, array $items, string $date, ?int $batchId, ?int $userId): Collection
    {
        return DB::transaction(function () use ($tenantId, $items, $date, $batchId, $userId): Collection {
            $batch = $this->resolveBatch($tenantId, $batchId);
            $recs = $this->lockMany($tenantId, array_column($items, 'id'));
            $errors = [];
            foreach ($items as $item) {
                $rec = $recs->get((int) $item['id']);
                if ($rec === null) {
                    $errors[] = ['id' => (string) $item['id'], 'message' => 'ไม่พบรายการ'];

                    continue;
                }
                try {
                    $this->applyReceive(
                        $rec,
                        isset($item['receivedAmount']) ? (float) $item['receivedAmount'] : null,
                        $date,
                        $batch,
                        $item['diffReason'] ?? null,
                        $item['diffNote'] ?? null,
                        $userId,
                        isset($item['version']) ? (int) $item['version'] : null,
                    );
                } catch (RuntimeException $e) {
                    $errors[] = ['id' => (string) $rec->id, 'message' => $e->getMessage()];
                }
            }
            if ($errors !== []) {
                throw new ReceiptBulkException($errors);
            }

            return $recs->values();
        });
    }

    private function applyReceive(CommissionReceivable $rec, ?float $amount, string $date, ?CommissionReceiptBatch $batch, ?string $reason, ?string $note, ?int $userId, ?int $expectedVersion): void
    {
        $this->guardVersion($rec, $expectedVersion);
        if (! $rec->isOpen()) {
            throw new RuntimeException($rec->status === CommissionReceivable::STATUS_RECEIVED
                ? 'รายการนี้รับเงินแล้ว'
                : 'รายการนี้ตั้งเป็นไม่มีค่าคอมแล้ว ต้องเปิดแก้ก่อน');
        }

        $amount ??= $rec->statement_amount !== null ? (float) $rec->statement_amount : null;
        if ($amount === null) {
            throw new RuntimeException('กรุณาระบุยอดที่ได้รับจริง');
        }
        $this->guardSign($rec, $amount);

        if ($batch !== null && (int) $batch->insurer_id !== (int) $rec->insurer_id) {
            throw new RuntimeException('รอบรับเอกสารเป็นของบริษัทประกันอื่น');
        }

        $diff = round($amount - (float) $rec->expected_amount, 2);
        $hasDiff = abs($diff) > CommissionReceivable::TOLERANCE;
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($hasDiff) {
            if ($reason === null || ! in_array($reason, CommissionReceivable::REASONS, true)) {
                throw new RuntimeException('ยอดที่รับต่างจากยอดที่ควรได้ '.number_format($diff, 2).' บาท กรุณาเลือกสาเหตุ');
            }
            if ($reason === CommissionReceivable::REASON_OTHER && $note === null) {
                throw new RuntimeException('สาเหตุ "อื่นๆ" ต้องระบุรายละเอียด');
            }
        }

        $old = $rec->only(['status', 'received_amount', 'received_date', 'receipt_batch_id']);
        $rec->update([
            'statement_amount' => $rec->statement_amount ?? round($amount, 2),
            'received_amount' => round($amount, 2),
            'received_date' => $date,
            'receipt_batch_id' => $batch?->id,
            'status' => CommissionReceivable::STATUS_RECEIVED,
            'diff_reason' => $hasDiff ? $reason : null,
            'diff_note' => $hasDiff ? $note : null,
            'received_by_user_id' => $userId,
            'version' => $rec->version + 1,
            'updated_by_user_id' => $userId,
        ]);

        $this->audit($rec, 'receivable.receive', $userId, $old, $rec->only(['status', 'received_amount', 'received_date', 'receipt_batch_id', 'diff_reason']), $hasDiff ? trim((CommissionReceivable::REASON_LABELS[$reason] ?? $reason).($note !== null ? ' — '.$note : '')) : null);

        if ($rec->isAdjustment() && $rec->adjustment_kind === CommissionReceivable::KIND_CANCELLATION && $amount < 0) {
            $this->markCarrierDeducted($rec, $userId);
        }
    }

    private function markCarrierDeducted(CommissionReceivable $rec, ?int $userId): void
    {
        $policy = Policy::query()->whereKey($rec->policy_id)->lockForUpdate()->first();
        if ($policy === null || $policy->cancel_check_carrier_deduct === 'done') {
            return;
        }
        $old = $policy->cancel_check_carrier_deduct;
        $policy->forceFill(['cancel_check_carrier_deduct' => 'done'])->saveQuietly();
        AuditEntry::create([
            'tenant_id' => $rec->tenant_id,
            'user_id' => $userId,
            'occurred_at' => now(),
            'actor' => $userId ? (string) $userId : 'system',
            'action' => 'policy.cancel_check_carrier_deduct',
            'target' => "policy:{$rec->policy_id}",
            'result' => 'success',
            'metadata' => ['old' => $old, 'new' => 'done', 'receivableId' => $rec->id],
        ]);
    }

    public function markNoCommission(CommissionReceivable $rec, string $reason, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        return DB::transaction(function () use ($rec, $reason, $userId, $expectedVersion): CommissionReceivable {
            $rec = $this->lock($rec);
            $this->guardVersion($rec, $expectedVersion);
            if (trim($reason) === '') {
                throw new RuntimeException('ต้องระบุเหตุผลว่าทำไมไม่มีค่าคอม');
            }
            if (! $rec->isOpen()) {
                throw new RuntimeException('รายการนี้ปิดแล้ว ต้องเปิดแก้ก่อน');
            }
            if ($rec->isAdjustment()) {
                throw new RuntimeException('รายการปรับปรุงให้ลบรายการแทน');
            }
            $old = $rec->only(['status']);
            $rec->update([
                'status' => CommissionReceivable::STATUS_NO_COMMISSION,
                'note' => trim($reason),
                'version' => $rec->version + 1,
                'updated_by_user_id' => $userId,
            ]);
            $this->audit($rec, 'receivable.no_commission', $userId, $old, $rec->only(['status']), $reason);

            return $rec->fresh();
        });
    }

    public function reopen(CommissionReceivable $rec, string $reason, ?int $userId, ?int $expectedVersion): CommissionReceivable
    {
        return DB::transaction(function () use ($rec, $reason, $userId, $expectedVersion): CommissionReceivable {
            $rec = $this->lock($rec);
            $this->guardVersion($rec, $expectedVersion);
            if (trim($reason) === '') {
                throw new RuntimeException('ต้องระบุเหตุผลที่เปิดแก้');
            }
            if ($rec->isOpen()) {
                throw new RuntimeException('เปิดแก้ได้เฉพาะรายการที่รับแล้วหรือไม่มีค่าคอม');
            }

            $statement = $rec->statement_amount !== null ? (float) $rec->statement_amount : null;
            $status = $statement === null
                ? CommissionReceivable::STATUS_PENDING
                : (abs($statement - (float) $rec->expected_amount) <= CommissionReceivable::TOLERANCE ? CommissionReceivable::STATUS_MATCHED : CommissionReceivable::STATUS_MISMATCH);

            $old = $rec->only(['status', 'received_amount', 'received_date', 'receipt_batch_id', 'diff_reason', 'source']);
            $rec->update([
                'status' => $status,
                'received_amount' => null,
                'received_date' => null,
                'receipt_batch_id' => null,
                'diff_reason' => null,
                'diff_note' => null,
                'received_by_user_id' => null,
                'source' => CommissionReceivable::SOURCE_SYSTEM,
                'version' => $rec->version + 1,
                'updated_by_user_id' => $userId,
            ]);
            $this->audit($rec, 'receivable.reopen', $userId, $old, $rec->only(['status']), $reason);

            return $rec->fresh();
        });
    }

    public function paidPayoutWarning(CommissionReceivable $rec): ?string
    {
        $paid = DB::table('commission_payout_batch_items')
            ->where('policy_id', $rec->policy_id)
            ->where('item_status', 'paid')
            ->exists();

        return $paid ? 'กรมธรรม์นี้จ่ายค่าคอมให้ตัวแทนไปแล้ว ตรวจสอบว่าต้องเรียกคืนหรือไม่' : null;
    }

    public function resyncExpected(CommissionReceivable $rec, ?int $userId): CommissionReceivable
    {
        return DB::transaction(function () use ($rec, $userId): CommissionReceivable {
            $rec = $this->lock($rec);
            if (! $rec->isOpen()) {
                throw new RuntimeException('รายการนี้ปิดแล้ว ต้องเปิดแก้ก่อนจึงจะดึงยอดใหม่ได้');
            }
            if ($rec->isAdjustment()) {
                throw new RuntimeException('รายการปรับปรุงไม่มียอดจากกรมธรรม์ให้ดึง');
            }

            $policy = DB::table('policies as p')
                ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
                ->where('p.id', $rec->policy_id)
                ->select('p.comm_carrier_to_hub_amount', 'r.calculated_amount', 'r.calculated_ov')
                ->first();
            if ($policy === null) {
                throw new RuntimeException('ไม่พบกรมธรรม์');
            }

            $newExpected = round($rec->commission_type === CommissionReceivable::TYPE_MAIN
                ? (float) ($policy->comm_carrier_to_hub_amount ?: $policy->calculated_amount ?: 0)
                : (float) ($policy->calculated_ov ?: 0), 2);

            $old = (float) $rec->expected_amount;
            $statement = $rec->statement_amount !== null ? (float) $rec->statement_amount : null;
            $rec->update([
                'expected_amount' => $newExpected,
                'status' => $statement === null
                    ? $rec->status
                    : (abs($statement - $newExpected) <= CommissionReceivable::TOLERANCE ? CommissionReceivable::STATUS_MATCHED : CommissionReceivable::STATUS_MISMATCH),
                'version' => $rec->version + 1,
                'updated_by_user_id' => $userId,
            ]);
            $this->audit($rec, 'receivable.resync', $userId, ['expected' => $old], ['expected' => $newExpected], null);

            return $rec->fresh();
        });
    }

    public function createAdjustment(int $tenantId, int $policyId, string $kind, float $expected, ?int $policyEventId, ?string $note, ?int $userId): CommissionReceivable
    {
        if (! in_array($kind, CommissionReceivable::KINDS, true)) {
            throw new RuntimeException('ประเภทรายการปรับปรุงไม่ถูกต้อง');
        }
        if ($kind === CommissionReceivable::KIND_CANCELLATION && $expected > 0) {
            throw new RuntimeException('รายการยกเลิก / บริษัทหักคืน ต้องเป็นยอดติดลบ');
        }
        if ($kind === CommissionReceivable::KIND_OTHER && ($note === null || trim($note) === '')) {
            throw new RuntimeException('รายการปรับปรุงประเภท "อื่นๆ" ต้องระบุรายละเอียด');
        }

        return DB::transaction(function () use ($tenantId, $policyId, $kind, $expected, $policyEventId, $note, $userId): CommissionReceivable {
            $policy = Policy::query()->where('tenant_id', $tenantId)->whereKey($policyId)->lockForUpdate()->first();
            if ($policy === null) {
                throw new RuntimeException('ไม่พบกรมธรรม์');
            }
            if ($policy->carrier_id === null) {
                throw new RuntimeException('กรมธรรม์นี้ยังไม่มีบริษัทประกัน');
            }
            if ($policyEventId !== null) {
                $event = DB::table('policy_events')->where('id', $policyEventId)->where('policy_id', $policyId)->first();
                if ($event === null || ! str_starts_with((string) $event->type, 'endorsement.')) {
                    throw new RuntimeException('ไม่พบรายการสลักหลังของกรมธรรม์นี้');
                }
                if ($event->voided_at !== null) {
                    throw new RuntimeException('รายการสลักหลังนี้ถูกยกเลิกแล้ว');
                }
                if (CommissionReceivable::query()->where('policy_event_id', $policyEventId)->exists()) {
                    throw new RuntimeException('สลักหลังนี้มีรายการปรับปรุงแล้ว');
                }
            }

            $lineNo = (int) CommissionReceivable::query()
                ->where('policy_id', $policyId)->where('commission_type', CommissionReceivable::TYPE_ADJ)->max('line_no') + 1;

            $rec = CommissionReceivable::create([
                'tenant_id' => $tenantId,
                'policy_id' => $policyId,
                'insurer_id' => $policy->carrier_id,
                'policy_year' => $policy->policy_year,
                'commission_type' => CommissionReceivable::TYPE_ADJ,
                'line_no' => $lineNo,
                'adjustment_kind' => $kind,
                'policy_event_id' => $policyEventId,
                'expected_amount' => round($expected, 2),
                'status' => CommissionReceivable::STATUS_PENDING,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'source' => CommissionReceivable::SOURCE_SYSTEM,
                'version' => 0,
                'updated_by_user_id' => $userId,
            ]);
            $this->audit($rec, 'receivable.adjustment_created', $userId, [], ['kind' => $kind, 'expected' => round($expected, 2)], $note);

            return $rec;
        });
    }

    public function deleteAdjustment(CommissionReceivable $rec, ?int $userId): void
    {
        DB::transaction(function () use ($rec, $userId): void {
            $rec = $this->lock($rec);
            if (! $rec->isAdjustment()) {
                throw new RuntimeException('ลบได้เฉพาะรายการปรับปรุง');
            }
            if (! $rec->isOpen()) {
                throw new RuntimeException('รายการนี้รับแล้ว ต้องเปิดแก้ก่อนจึงจะลบได้');
            }
            $this->audit($rec, 'receivable.adjustment_deleted', $userId, ['kind' => $rec->adjustment_kind, 'expected' => (float) $rec->expected_amount], [], null);
            $rec->delete();
        });
    }

    public function suggestAdjustment(int $tenantId, int $policyId, string $kind): array
    {
        $policy = DB::table('policies')->where('tenant_id', $tenantId)->where('id', $policyId)->first();
        if ($policy === null) {
            throw new RuntimeException('ไม่พบกรมธรรม์');
        }
        $rate = $policy->comm_carrier_to_hub_rate !== null ? (float) $policy->comm_carrier_to_hub_rate : null;

        if ($kind === CommissionReceivable::KIND_ENDORSEMENT) {
            $linked = CommissionReceivable::query()->where('policy_id', $policyId)->whereNotNull('policy_event_id')->pluck('policy_event_id')->all();
            $events = DB::table('policy_events')
                ->where('policy_id', $policyId)
                ->where('type', 'endorsement.premium_change')
                ->whereNull('voided_at')
                ->orderBy('endorsement_seq')
                ->get(['id', 'endorsement_seq', 'payload', 'occurred_at'])
                ->map(function (object $e) use ($rate, $linked): array {
                    $payload = is_string($e->payload) ? (json_decode($e->payload, true) ?: []) : (array) $e->payload;
                    $premium = round((float) ($payload['additionalPremium'] ?? 0), 2);

                    return [
                        'id' => (string) $e->id,
                        'seq' => $e->endorsement_seq !== null ? (int) $e->endorsement_seq : null,
                        'reason' => $payload['reason'] ?? null,
                        'effectiveDate' => $payload['effectiveDate'] ?? null,
                        'additionalPremium' => $premium,
                        'estimatedCommission' => $rate !== null ? round($premium * $rate, 2) : null,
                        'linked' => in_array($e->id, $linked, false),
                    ];
                })->values()->all();
            $first = collect($events)->firstWhere('linked', false);

            return [
                'kind' => $kind,
                'rate' => $rate,
                'amount' => $first['estimatedCommission'] ?? null,
                'basis' => $first !== null && $rate !== null
                    ? 'เบี้ยสลักหลัง '.number_format($first['additionalPremium'], 2).' × อัตรา '.round($rate * 100, 2).'%'
                    : ($rate === null ? 'กรมธรรม์ไม่มีอัตราค่าคอมจากบริษัท กรอกยอดเอง' : 'ไม่มีสลักหลังเบี้ยที่ยังไม่บันทึก'),
                'events' => $events,
            ];
        }

        if ($kind === CommissionReceivable::KIND_CANCELLATION) {
            $refund = (float) ($policy->refund_premium ?? 0);
            if ($refund > 0 && $rate !== null) {
                return [
                    'kind' => $kind, 'rate' => $rate,
                    'amount' => -round($refund * $rate, 2),
                    'basis' => 'เบี้ยคืน '.number_format($refund, 2).' × อัตรา '.round($rate * 100, 2).'%',
                    'events' => [],
                ];
            }
            $received = (float) CommissionReceivable::query()
                ->where('policy_id', $policyId)
                ->where('status', CommissionReceivable::STATUS_RECEIVED)
                ->where(fn ($q) => $q->where('commission_type', CommissionReceivable::TYPE_MAIN)
                    ->orWhere('adjustment_kind', CommissionReceivable::KIND_ENDORSEMENT))
                ->sum('received_amount');

            return [
                'kind' => $kind, 'rate' => $rate,
                'amount' => $received > 0 ? -round($received, 2) : null,
                'basis' => $received > 0 ? 'หักคืนเต็มยอดที่รับไปแล้ว '.number_format($received, 2) : 'ยังไม่มียอดที่รับไป กรอกยอดตามที่บริษัทแจ้ง',
                'events' => [],
            ];
        }

        return ['kind' => $kind, 'rate' => $rate, 'amount' => null, 'basis' => 'กรอกยอดตามที่บริษัทแจ้ง', 'events' => []];
    }

    public function statementMatch(int $tenantId, int $insurerId, string $type, array $lines): array
    {
        $type = $type === CommissionReceivable::TYPE_OV ? CommissionReceivable::TYPE_OV : CommissionReceivable::TYPE_MAIN;
        $norm = fn (string $v): string => strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $v));

        $seen = [];
        $wanted = [];
        $duplicates = [];
        $clean = [];
        foreach ($lines as $i => $line) {
            $ref = trim((string) ($line['ref'] ?? ''));
            $key = $norm($ref);
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $duplicates[] = ['line' => $i + 1, 'ref' => $ref, 'amount' => $line['amount'] ?? null];

                continue;
            }
            $seen[$key] = true;
            $wanted[] = $key;
            $clean[] = ['line' => $i + 1, 'ref' => $ref, 'key' => $key, 'amount' => isset($line['amount']) ? round((float) $line['amount'], 2) : null];
        }

        $byKey = [];
        foreach (array_chunk($wanted, 500) as $chunk) {
            $expr = fn (string $col): string => "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), '-', ''), ' ', ''), '/', ''), '.', ''))";
            DB::table('policies as p')
                ->leftJoin('policy_rebates as r', 'r.policy_id', '=', 'p.id')
                ->leftJoin('carriers as ca', 'ca.id', '=', 'p.carrier_id')
                ->where('p.tenant_id', $tenantId)
                ->whereNull('p.deleted_at')
                ->whereNotIn('p.status', ['draft', 'quote', 'quotation', 'rejected'])
                ->where(fn (Builder $w) => $w->whereIn(DB::raw($expr('p.policy_no')), $chunk)->orWhereIn(DB::raw($expr('p.application_no')), $chunk))
                ->select([
                    'p.id', 'p.tenant_id', 'p.carrier_id', 'p.policy_year', 'p.policy_no', 'p.application_no', 'p.status',
                    'p.comm_carrier_to_hub_amount', 'p.imported_from_legacy', 'p.com_rec_check',
                    'r.calculated_amount', 'r.calculated_ov', 'ca.name as insurer_name',
                ])
                ->orderBy('p.id')
                ->get()
                ->each(function (object $p) use (&$byKey, $norm): void {
                    foreach ([$p->policy_no, $p->application_no] as $v) {
                        if ($v === null || $v === '') {
                            continue;
                        }
                        $k = $norm((string) $v);
                        $byKey[$k] ??= [];
                        $byKey[$k][] = $p;
                    }
                });
        }

        $matched = [];
        $notFound = [];
        $otherInsurer = [];
        $alreadyClosed = [];
        foreach ($clean as $line) {
            $candidates = $byKey[$line['key']] ?? [];
            if ($candidates === []) {
                $notFound[] = ['line' => $line['line'], 'ref' => $line['ref'], 'amount' => $line['amount']];

                continue;
            }
            $policy = collect($candidates)->first(fn ($p) => (int) $p->carrier_id === $insurerId);
            if ($policy === null) {
                $other = $candidates[0];
                $otherInsurer[] = ['line' => $line['line'], 'ref' => $line['ref'], 'amount' => $line['amount'], 'policyNo' => $other->policy_no, 'insurerName' => $other->insurer_name];

                continue;
            }
            $rec = $this->ensureRow($policy, $type);
            $entry = [
                'line' => $line['line'], 'ref' => $line['ref'], 'amount' => $line['amount'],
                'receivableId' => (string) $rec->id, 'version' => (int) $rec->version,
                'policyId' => (string) $policy->id, 'policyNo' => $policy->policy_no ?: $policy->application_no,
                'expectedAmount' => round((float) $rec->expected_amount, 2),
                'difference' => $line['amount'] !== null ? round($line['amount'] - (float) $rec->expected_amount, 2) : null,
                'status' => $rec->status,
            ];
            if (! $rec->isOpen()) {
                $alreadyClosed[] = $entry;

                continue;
            }
            $matched[] = $entry;
        }

        return [
            'matched' => $matched,
            'notFound' => $notFound,
            'otherInsurer' => $otherInsurer,
            'alreadyClosed' => $alreadyClosed,
            'duplicates' => $duplicates,
        ];
    }

    public function createReceiptBatch(int $tenantId, array $data, ?int $userId): CommissionReceiptBatch
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): CommissionReceiptBatch {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $month = $data['statementMonth'] ?? null;
            $seq = CommissionReceiptBatch::where('tenant_id', $tenantId)->count() + 1;
            $stamp = $month !== null ? str_replace('-', '', $month) : now()->format('Ym');

            return CommissionReceiptBatch::create([
                'tenant_id' => $tenantId,
                'batch_no' => sprintf('REC-%s-%04d', $stamp, $seq),
                'insurer_id' => $data['insurerId'],
                'policy_year' => $data['policyYear'] ?? null,
                'statement_month' => $month,
                'statement_total' => isset($data['statementTotal']) ? round((float) $data['statementTotal'], 2) : null,
                'statement_date' => $data['statementDate'] ?? null,
                'received_file_date' => $data['receivedFileDate'] ?? null,
                'remark' => $data['remark'] ?? null,
                'created_by_user_id' => $userId,
            ]);
        });
    }

    public function batchTotals(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return DB::table('commission_receivables')
            ->whereIn('receipt_batch_id', $batchIds)
            ->where('status', CommissionReceivable::STATUS_RECEIVED)
            ->groupBy('receipt_batch_id')
            ->selectRaw('receipt_batch_id, COUNT(*) as n, SUM(received_amount) as received')
            ->get()
            ->mapWithKeys(fn (object $r): array => [(int) $r->receipt_batch_id => ['count' => (int) $r->n, 'received' => round((float) $r->received, 2)]])
            ->all();
    }

    public function summary(int $tenantId, array $f): array
    {
        $q = $this->listQuery($tenantId, [
            'history' => true,
            'type' => 'ALL',
            'includeOutstanding' => true,
            'insurerId' => $f['insurerId'] ?? null,
            'policyYear' => $f['policyYear'] ?? null,
            'monthFrom' => $f['monthFrom'] ?? null,
            'monthTo' => $f['monthTo'] ?? null,
        ]);
        $row = $q->selectRaw("
            SUM(CASE WHEN cr.commission_type <> 'ADJ' AND cr.status <> 'No Commission' THEN cr.expected_amount ELSE 0 END) as expected,
            SUM(CASE WHEN cr.commission_type <> 'ADJ' AND cr.status = 'Received' THEN cr.received_amount ELSE 0 END) as received,
            SUM(CASE WHEN cr.commission_type <> 'ADJ' AND cr.status IN ('Pending','Matched','Mismatch') THEN COALESCE(cr.statement_amount, cr.expected_amount) ELSE 0 END) as outstanding,
            SUM(CASE WHEN cr.status = 'Mismatch' THEN 1 ELSE 0 END) as mismatch_count,
            SUM(CASE WHEN cr.status = 'Mismatch' THEN ABS(cr.statement_amount - cr.expected_amount) ELSE 0 END) as mismatch_amount,
            SUM(CASE WHEN cr.status = 'No Commission' THEN 1 ELSE 0 END) as no_commission_count,
            SUM(CASE WHEN cr.commission_type = 'ADJ' AND cr.status = 'Received' THEN cr.received_amount ELSE 0 END) as adjustment_received,
            SUM(CASE WHEN cr.commission_type = 'ADJ' AND cr.status IN ('Pending','Matched','Mismatch') THEN COALESCE(cr.statement_amount, cr.expected_amount) ELSE 0 END) as adjustment_open,
            SUM(CASE WHEN cr.status = 'Received' AND cr.diff_reason IS NOT NULL THEN 1 ELSE 0 END) as received_with_diff
        ")->first();

        return [
            'expected' => round((float) ($row->expected ?? 0), 2),
            'received' => round((float) ($row->received ?? 0), 2),
            'outstanding' => round((float) ($row->outstanding ?? 0), 2),
            'mismatchCount' => (int) ($row->mismatch_count ?? 0),
            'mismatchAmount' => round((float) ($row->mismatch_amount ?? 0), 2),
            'noCommissionCount' => (int) ($row->no_commission_count ?? 0),
            'adjustmentReceived' => round((float) ($row->adjustment_received ?? 0), 2),
            'adjustmentOpen' => round((float) ($row->adjustment_open ?? 0), 2),
            'receivedWithDiff' => (int) ($row->received_with_diff ?? 0),
        ];
    }

    public function dashboard(int $tenantId, array $f): array
    {
        $ins = $this->summary($tenantId, $f);

        $policyScope = function (Builder $q) use ($f): Builder {
            return $q
                ->when(! empty($f['policyYear']), fn (Builder $w) => $w->where('p.policy_year', (int) $f['policyYear']))
                ->when(! empty($f['insurerId']), fn (Builder $w) => $w->where('p.carrier_id', (int) $f['insurerId']))
                ->when(! empty($f['monthFrom']), fn (Builder $w) => $w->where('p.effective_date', '>=', $this->monthStart($f['monthFrom'])))
                ->when(! empty($f['monthTo']), fn (Builder $w) => $w->where('p.effective_date', '<=', $this->monthEnd($f['monthTo'])));
        };

        $payQ = $policyScope(DB::table('policies as p')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', ['active', 'issued']));
        $agentPayable = (float) (clone $payQ)->sum('p.comm_hub_to_agent_amount')
            + (float) DB::table('policy_riders as pr')->whereIn('pr.policy_id', (clone $payQ)->select('p.id'))->sum('pr.com_amt_ag');
        $potentialExpected = (float) (clone $payQ)->sum('p.comm_carrier_to_hub_amount');

        $materialised = (int) $policyScope(DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->where('cr.tenant_id', $tenantId))->count();

        $paidQ = $policyScope(DB::table('commission_payout_batch_items as bi')
            ->join('policies as p', 'p.id', '=', 'bi.policy_id')
            ->where('bi.tenant_id', $tenantId)
            ->where('bi.item_status', 'paid'));
        $agentPaid = (float) (clone $paidQ)->where('bi.line_type', 'base')->sum(DB::raw('bi.snapshot_agent_commission + bi.snapshot_rider_commission'));
        $agentMgmPaid = (float) (clone $paidQ)->where('bi.line_type', '!=', 'base')->sum('bi.snapshot_agent_commission');

        $recBase = fn (): Builder => $policyScope(DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->where('cr.tenant_id', $tenantId));

        $monthly = $recBase()
            ->whereNotNull('p.effective_date')
            ->selectRaw("DATE_FORMAT(p.effective_date, '%Y-%m') as ym")
            ->selectRaw("SUM(CASE WHEN cr.commission_type <> 'ADJ' THEN cr.expected_amount ELSE 0 END) as expected")
            ->selectRaw("SUM(CASE WHEN cr.status = 'Received' THEN cr.received_amount ELSE 0 END) as received")
            ->groupBy('ym')->orderBy('ym')
            ->get()
            ->map(fn ($r): array => ['month' => $r->ym, 'expected' => round((float) $r->expected, 2), 'received' => round((float) $r->received, 2)]);

        $outstandingByInsurer = $recBase()
            ->leftJoin('carriers as ca', 'ca.id', '=', 'cr.insurer_id')
            ->whereIn('cr.status', CommissionReceivable::OPEN_STATUSES)
            ->selectRaw('ca.name as insurer')
            ->selectRaw('SUM(COALESCE(cr.statement_amount, cr.expected_amount)) as amount')
            ->groupBy('ca.name')->orderByDesc('amount')
            ->limit(15)->get()
            ->map(fn ($r): array => ['insurer' => $r->insurer ?? '—', 'amount' => round((float) $r->amount, 2)]);

        $byReason = $recBase()
            ->where('cr.status', CommissionReceivable::STATUS_RECEIVED)
            ->whereNotNull('cr.diff_reason')
            ->groupBy('cr.diff_reason')
            ->selectRaw('cr.diff_reason, COUNT(*) as n, SUM(cr.received_amount - cr.expected_amount) as diff')
            ->get()
            ->map(fn ($r): array => [
                'reason' => $r->diff_reason,
                'label' => CommissionReceivable::REASON_LABELS[$r->diff_reason] ?? $r->diff_reason,
                'count' => (int) $r->n,
                'difference' => round((float) $r->diff, 2),
            ])->values();
        $unresolved = (clone $recBase())->where('cr.status', CommissionReceivable::STATUS_MISMATCH)
            ->selectRaw('COUNT(*) as n, SUM(cr.statement_amount - cr.expected_amount) as diff')->first();

        return [
            'kpi' => [
                'expectedInsurer' => $ins['expected'],
                'potentialExpected' => round($potentialExpected, 2),
                'receivablesMaterialised' => $materialised,
                'receivedInsurer' => $ins['received'],
                'outstanding' => $ins['outstanding'],
                'mismatchCount' => $ins['mismatchCount'],
                'mismatchAmount' => $ins['mismatchAmount'],
                'noCommissionCount' => $ins['noCommissionCount'],
                'adjustmentReceived' => $ins['adjustmentReceived'],
                'adjustmentOpen' => $ins['adjustmentOpen'],
                'agentPayable' => round($agentPayable, 2),
                'agentPaid' => round($agentPaid, 2),
                'agentMgmPaid' => round($agentMgmPaid, 2),
                'expectedMargin' => round($ins['expected'] - $agentPayable, 2),
                'realizedMargin' => round($ins['received'] + $ins['adjustmentReceived'] - $agentPaid - $agentMgmPaid, 2),
            ],
            'monthly' => $monthly,
            'outstandingByInsurer' => $outstandingByInsurer,
            'mismatchByReason' => $byReason,
            'unresolvedMismatch' => ['count' => (int) ($unresolved->n ?? 0), 'difference' => round((float) ($unresolved->diff ?? 0), 2)],
            'policyLevel' => $this->policyLevel($tenantId, $recBase),
        ];
    }

    private function policyLevel(int $tenantId, callable $recBase): array
    {
        $recs = $recBase()->select([
            'cr.policy_id', 'cr.commission_type', 'cr.expected_amount', 'cr.received_amount', 'cr.status',
            'p.policy_no', 'p.application_no', 'p.comm_hub_to_agent_amount',
        ])->limit(3000)->get();

        $byPolicy = [];
        foreach ($recs as $r) {
            $pid = $r->policy_id;
            $byPolicy[$pid] ??= [
                'policyId' => (string) $pid,
                'policyNo' => $r->policy_no ?: $r->application_no,
                'insurerExpected' => 0.0,
                'insurerReceived' => 0.0,
                'mainStatus' => null,
                'ovStatus' => null,
                'adjustments' => 0,
                'agentPayable' => round((float) ($r->comm_hub_to_agent_amount ?? 0), 2),
                'agentPaid' => 0.0,
            ];
            if ($r->commission_type !== CommissionReceivable::TYPE_ADJ) {
                $byPolicy[$pid]['insurerExpected'] += (float) $r->expected_amount;
            }
            if ($r->status === CommissionReceivable::STATUS_RECEIVED) {
                $byPolicy[$pid]['insurerReceived'] += (float) ($r->received_amount ?? 0);
            }
            match ($r->commission_type) {
                CommissionReceivable::TYPE_MAIN => $byPolicy[$pid]['mainStatus'] = $r->status,
                CommissionReceivable::TYPE_OV => $byPolicy[$pid]['ovStatus'] = $r->status,
                default => $byPolicy[$pid]['adjustments']++,
            };
        }

        $pids = array_keys($byPolicy);
        if ($pids !== []) {
            DB::table('commission_payout_batch_items')
                ->whereIn('policy_id', $pids)
                ->where('item_status', 'paid')
                ->where('line_type', 'base')
                ->selectRaw('policy_id, SUM(snapshot_agent_commission + snapshot_rider_commission) as paid')
                ->groupBy('policy_id')->get()
                ->each(function (object $p) use (&$byPolicy): void {
                    $byPolicy[$p->policy_id]['agentPaid'] = round((float) $p->paid, 2);
                });
        }

        return array_map(function (array $r): array {
            $r['insurerExpected'] = round($r['insurerExpected'], 2);
            $r['insurerReceived'] = round($r['insurerReceived'], 2);
            $r['expectedMargin'] = round($r['insurerExpected'] - $r['agentPayable'], 2);
            $r['cashMargin'] = round($r['insurerReceived'] - $r['agentPaid'], 2);

            return $r;
        }, array_slice(array_values($byPolicy), 0, 500));
    }

    public function exportCsv(int $tenantId, array $f): string
    {
        $rows = $this->listRows($tenantId, $f, 20000)['rows'];
        $head = ['เลขกรมธรรม์', 'เลขใบคำขอ', 'ลูกค้า', 'บริษัทประกัน', 'วันเริ่มคุ้มครอง', 'ประเภท', 'รายการปรับปรุง',
            'ยอดที่ควรได้', 'ยอดตามบริษัท', 'ยอดรับจริง', 'ส่วนต่าง', 'สถานะ', 'วันที่รับ', 'รอบรับเอกสาร',
            'สาเหตุส่วนต่าง', 'รายละเอียดสาเหตุ', 'หมายเหตุ', 'ข้อสังเกต'];
        $statusTh = [
            'Pending' => 'รอตรวจ', 'Matched' => 'ยอดตรง', 'Mismatch' => 'ยอดไม่ตรง',
            'Received' => 'รับแล้ว', 'No Commission' => 'ไม่มีค่าคอม',
        ];
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $head, ',', '"', '');
        foreach ($rows as $r) {
            $line = [
                $r['policyNo'], $r['applicationNo'], $r['customerName'], $r['insurerName'], $r['effectiveDate'],
                $r['commissionType'], $r['adjustmentKindLabel'],
                $r['expectedAmount'], $r['statementAmount'], $r['receivedAmount'], $r['differenceAmount'],
                $statusTh[$r['status']] ?? $r['status'], $r['receivedDate'], $r['batchNo'],
                $r['diffReasonLabel'], $r['diffNote'], $r['note'],
                implode(' | ', array_column($r['hints'], 'label')),
            ];
            fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), $line), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function csvSafe(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = is_float($v) ? number_format($v, 2, '.', '') : (string) $v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) && ! is_numeric($s)) {
            return "'".$s;
        }

        return $s;
    }

    private function resolveBatch(int $tenantId, ?int $batchId): ?CommissionReceiptBatch
    {
        if ($batchId === null) {
            return null;
        }
        $batch = CommissionReceiptBatch::query()->where('tenant_id', $tenantId)->whereKey($batchId)->first();
        if ($batch === null) {
            throw new RuntimeException('ไม่พบรอบรับเอกสาร');
        }

        return $batch;
    }

    private function lock(CommissionReceivable $rec): CommissionReceivable
    {
        return CommissionReceivable::query()->whereKey($rec->id)->lockForUpdate()->firstOrFail();
    }

    private function lockMany(int $tenantId, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return CommissionReceivable::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function guardSign(CommissionReceivable $rec, float $amount): void
    {
        if (! $rec->isAdjustment() && $amount < 0) {
            throw new RuntimeException('ยอดค่าคอมหลัก / OV ต้องไม่ติดลบ ถ้าบริษัทหักคืนให้ทำรายการปรับปรุง');
        }
        if ($rec->isAdjustment() && $rec->adjustment_kind === CommissionReceivable::KIND_CANCELLATION && $amount > 0) {
            throw new RuntimeException('รายการยกเลิก / บริษัทหักคืน ต้องเป็นยอดติดลบ');
        }
    }

    private function guardVersion(CommissionReceivable $rec, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && (int) $rec->version !== $expectedVersion) {
            throw new RuntimeException('ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดใหม่');
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
