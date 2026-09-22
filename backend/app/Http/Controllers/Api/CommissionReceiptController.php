<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\CommissionReceivable;
use App\Models\CommissionReceiptBatch;
use App\Models\CommissionReceiptFile;
use App\Services\Commission\CommissionReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รับค่าคอมจากบริษัทประกัน (Insurance Commission Receipt) — DEV Spec ชุดที่ 3,
 * Phase 1 Manual Reconciliation. Main + OV share this controller/service.
 */
class CommissionReceiptController extends Controller
{
    public function __construct(private readonly CommissionReceiptService $service)
    {
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    /**
     * GET /commission-receivables — filtered list.
     * Main/OV pages require policyYear + insurerId; History (all=1) does not.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'policyYear' => ['nullable', 'integer'],
            'insurerId' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:MAIN,OV,ALL'],
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
            'all' => ['nullable', 'boolean'], // History mode
        ]);
        $isHistory = (bool) ($data['all'] ?? false);

        if (! $isHistory) {
            if (empty($data['policyYear']) || empty($data['insurerId'])) {
                return response()->json(['message' => 'กรุณาเลือกปีกรมธรรม์และบริษัทประกัน'], 422);
            }
            // Lazily materialise receivable rows for this filter.
            $this->service->seedReceivables($tenantId, (int) $data['policyYear'], (int) $data['insurerId']);
        }

        $q = DB::table('commission_receivables as cr')
            ->join('policies as p', 'p.id', '=', 'cr.policy_id')
            ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('carriers as ca', 'ca.id', '=', 'cr.insurer_id')
            ->leftJoin('agents as a', 'a.id', '=', 'p.writing_agent_id')
            ->where('cr.tenant_id', $tenantId);

        if (! empty($data['policyYear'])) {
            $q->where('cr.policy_year', $data['policyYear']);
        }
        if (! empty($data['insurerId'])) {
            $q->where('cr.insurer_id', $data['insurerId']);
        }
        $type = $data['type'] ?? 'ALL';
        if ($type !== 'ALL') {
            $q->where('cr.commission_type', $type);
        }
        if (! empty($data['status'])) {
            $q->where('cr.status', $data['status']);
        } elseif (! $isHistory) {
            // Main/OV pages show only outstanding (not Received / No Commission).
            $q->whereIn('cr.status', [
                CommissionReceivable::STATUS_PENDING,
                CommissionReceivable::STATUS_MATCHED,
                CommissionReceivable::STATUS_MISMATCH,
            ]);
        }
        if (! empty($data['q'])) {
            $like = '%' . $data['q'] . '%';
            $q->where(function ($w) use ($like): void {
                $w->where('p.policy_no', 'like', $like)
                    ->orWhere('p.application_no', 'like', $like)
                    ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?", [$like]);
            });
        }

        $rows = $q->select([
            'cr.id', 'cr.commission_type', 'cr.expected_amount', 'cr.statement_amount',
            'cr.received_amount', 'cr.status', 'cr.received_date', 'cr.version', 'cr.note',
            'cr.policy_id', 'p.policy_no', 'p.application_no', 'p.policy_year',
            DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as customer_name"),
            'ca.name as insurer_name', 'a.agent_code',
        ])
            ->orderByDesc('cr.id')
            ->limit(1000)
            ->get()
            ->map(fn ($r): array => $this->row($r));

        return response()->json(['data' => $rows]);
    }

    /** GET /commission-receivables/{receivable} */
    public function show(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $receivable->load(['policy:id,policy_no,application_no,customer_id,policy_year', 'insurer:id,name']);

        // Sibling row (MAIN↔OV) so the detail screen can record both (spec §5.1).
        $sibling = CommissionReceivable::where('policy_id', $receivable->policy_id)
            ->where('commission_type', $receivable->commission_type === 'MAIN' ? 'OV' : 'MAIN')
            ->first();

        return response()->json([
            'data' => $this->detail($receivable),
            'sibling' => $sibling ? $this->detail($sibling) : null,
        ]);
    }

    /** PATCH /commission-receivables/{receivable}/review */
    public function review(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'statementAmount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->review(
            $receivable, (float) $data['statementAmount'], $data['note'] ?? null,
            $request->user()?->id, $data['version'] ?? null,
        ));
    }

    /** POST /commission-receivables/{receivable}/confirm-received */
    public function confirmReceived(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'receivedAmount' => ['required', 'numeric', 'min:0'],
            'receivedDate' => ['required', 'date'],
            'receiptBatchId' => ['nullable', 'integer'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->confirmReceived(
            $receivable, (float) $data['receivedAmount'], $data['receivedDate'],
            $data['receiptBatchId'] ?? null, $request->user()?->id, $data['version'] ?? null,
        ));
    }

    /** POST /commission-receivables/{receivable}/mark-no-commission */
    public function markNoCommission(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->markNoCommission(
            $receivable, $data['reason'], $request->user()?->id, $data['version'] ?? null,
        ));
    }

    /** POST /commission-receivables/{receivable}/reopen */
    public function reopen(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->reopen(
            $receivable, $data['reason'], $request->user()?->id, $data['version'] ?? null,
        ));
    }

    /** GET /commission-receivables/{receivable}/audit-log */
    public function auditLog(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        $this->authorizeTenant($request, $receivable);
        $rows = AuditEntry::where('tenant_id', $receivable->tenant_id)
            ->where('target', "receivable:{$receivable->id}")
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->get(['action', 'occurred_at', 'actor', 'metadata'])
            ->map(fn ($e): array => [
                'action' => $e->action,
                'at' => $e->occurred_at?->toIso8601String(),
                'actor' => $e->actor,
                'reason' => $e->metadata['reason'] ?? null,
                'old' => $e->metadata['old'] ?? null,
                'new' => $e->metadata['new'] ?? null,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** GET /commission-reconciliation/summary */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'policyYear' => ['nullable', 'integer'],
            'insurerId' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->service->summary(
                $this->tenantId($request),
                isset($data['policyYear']) ? (int) $data['policyYear'] : null,
                isset($data['insurerId']) ? (int) $data['insurerId'] : null,
            ),
        ]);
    }

    // ── Receipt batches + files ──────────────────────────────────────────

    /** POST /commission-receipt-batches */
    public function storeBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'insurerId' => ['required', 'integer'],
            'policyYear' => ['nullable', 'integer'],
            'statementDate' => ['nullable', 'date'],
            'receivedFileDate' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);
        $batch = $this->service->createReceiptBatch($this->tenantId($request), $data, $request->user()?->id);

        return response()->json(['data' => $this->batchRow($batch)], 201);
    }

    /** GET /commission-receipt-batches?insurerId=&policyYear= */
    public function indexBatches(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $q = CommissionReceiptBatch::query()->where('tenant_id', $tenantId)->with('insurer:id,name')->withCount('files');
        if ($request->filled('insurerId')) {
            $q->where('insurer_id', (int) $request->input('insurerId'));
        }
        if ($request->filled('policyYear')) {
            $q->where('policy_year', (int) $request->input('policyYear'));
        }
        $rows = $q->orderByDesc('id')->limit(200)->get()->map(fn ($b): array => $this->batchRow($b));

        return response()->json(['data' => $rows]);
    }

    /** POST /commission-receipt-batches/{batch}/files */
    public function uploadFile(Request $request, CommissionReceiptBatch $batch): JsonResponse
    {
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,pdf', 'max:20480'], // 20 MB
        ]);
        $file = $request->file('file');
        $dir = "commission-receipts/{$batch->tenant_id}/{$batch->id}";
        $stored = $file->store($dir, 'local');

        $row = CommissionReceiptFile::create([
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $stored,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by_user_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->fileRow($row)], 201);
    }

    /** GET /commission-receipt-batches/{batch} */
    public function showBatch(Request $request, CommissionReceiptBatch $batch): JsonResponse
    {
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
        $batch->load(['insurer:id,name', 'files']);

        return response()->json([
            'data' => array_merge($this->batchRow($batch), [
                'files' => $batch->files->map(fn ($f): array => $this->fileRow($f)),
            ]),
        ]);
    }

    /** GET /commission-receipt-files/{file}/download */
    public function downloadFile(Request $request, CommissionReceiptFile $file): StreamedResponse
    {
        abort_unless((int) $file->tenant_id === $this->tenantId($request), 404);
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);
        $mime = Storage::disk('local')->mimeType($file->storage_path) ?: 'application/octet-stream';

        return Storage::disk('local')->download($file->storage_path, $file->original_filename, ['Content-Type' => $mime]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function run(callable $fn): JsonResponse
    {
        try {
            $rec = $fn();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->detail($rec)]);
    }

    private function row(object $r): array
    {
        $actual = $r->received_amount ?? $r->statement_amount;
        $diff = $actual !== null ? round((float) $actual - (float) $r->expected_amount, 2) : null;

        return [
            'id' => (string) $r->id,
            'commissionType' => $r->commission_type,
            'policyId' => (string) $r->policy_id,
            'policyNo' => $r->policy_no,
            'applicationNo' => $r->application_no,
            'policyYear' => $r->policy_year,
            'customerName' => $r->customer_name,
            'insurerName' => $r->insurer_name,
            'agentCode' => $r->agent_code,
            'expectedAmount' => round((float) $r->expected_amount, 2),
            'statementAmount' => $r->statement_amount !== null ? round((float) $r->statement_amount, 2) : null,
            'receivedAmount' => $r->received_amount !== null ? round((float) $r->received_amount, 2) : null,
            'differenceAmount' => $diff,
            'status' => $r->status,
            'receivedDate' => $r->received_date,
            'version' => (int) $r->version,
        ];
    }

    private function detail(CommissionReceivable $rec): array
    {
        return [
            'id' => (string) $rec->id,
            'commissionType' => $rec->commission_type,
            'policyId' => (string) $rec->policy_id,
            'policyNo' => $rec->policy?->policy_no,
            'applicationNo' => $rec->policy?->application_no,
            'policyYear' => $rec->policy_year,
            'insurerName' => $rec->insurer?->name,
            'expectedAmount' => round((float) $rec->expected_amount, 2),
            'statementAmount' => $rec->statement_amount !== null ? round((float) $rec->statement_amount, 2) : null,
            'receivedAmount' => $rec->received_amount !== null ? round((float) $rec->received_amount, 2) : null,
            'differenceAmount' => $rec->difference(),
            'status' => $rec->status,
            'receivedDate' => $rec->received_date?->toDateString(),
            'note' => $rec->note,
            'version' => (int) $rec->version,
        ];
    }

    private function batchRow(CommissionReceiptBatch $b): array
    {
        return [
            'id' => (string) $b->id,
            'batchNo' => $b->batch_no,
            'insurerId' => (string) $b->insurer_id,
            'insurerName' => $b->insurer?->name,
            'policyYear' => $b->policy_year,
            'statementDate' => $b->statement_date?->toDateString(),
            'receivedFileDate' => $b->received_file_date?->toDateString(),
            'remark' => $b->remark,
            'fileCount' => $b->files_count ?? $b->files()->count(),
            'createdAt' => $b->created_at?->toIso8601String(),
        ];
    }

    private function fileRow(CommissionReceiptFile $f): array
    {
        return [
            'id' => (string) $f->id,
            'originalFilename' => $f->original_filename,
            'mimeType' => $f->mime_type,
            'fileSize' => (int) $f->file_size,
            'uploadedAt' => $f->created_at?->toIso8601String(),
        ];
    }

    private function authorizeTenant(Request $request, CommissionReceivable $rec): void
    {
        abort_unless((int) $rec->tenant_id === $this->tenantId($request), 404);
    }
}
