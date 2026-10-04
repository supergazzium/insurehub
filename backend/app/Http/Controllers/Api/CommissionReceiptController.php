<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\CommissionReceivable;
use App\Models\CommissionReceiptBatch;
use App\Models\CommissionReceiptFile;
use App\Services\Commission\CommissionReceiptService;
use App\Services\Commission\ReceiptBulkException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionReceiptController extends Controller
{
    private const MONTH = ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(private readonly CommissionReceiptService $service)
    {
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'policyYear' => ['nullable', 'integer'],
            'insurerId' => ['nullable', 'integer'],
            'monthFrom' => self::MONTH,
            'monthTo' => self::MONTH,
            'includeOutstanding' => ['nullable', 'boolean'],
            'type' => ['nullable', 'in:MAIN,OV,ADJ,ALL,MAIN_ADJ'],
            'status' => ['nullable', 'in:Pending,Matched,Mismatch,Received,No Commission'],
            'q' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);
        $data['history'] = (bool) ($data['all'] ?? false);
        if (isset($data['includeOutstanding'])) {
            $data['includeOutstanding'] = (bool) $data['includeOutstanding'];
        }
        if (! empty($data['monthFrom']) && ! empty($data['monthTo']) && $data['monthFrom'] > $data['monthTo']) {
            [$data['monthFrom'], $data['monthTo']] = [$data['monthTo'], $data['monthFrom']];
        }

        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('receipts.view');
        $tenantId = $this->tenantId($request);
        $f = $this->filters($request);

        if (! $f['history']) {
            if (empty($f['insurerId'])) {
                return response()->json(['message' => 'กรุณาเลือกบริษัทประกัน'], 422);
            }
            $this->service->seedReceivables(
                $tenantId,
                (int) $f['insurerId'],
                $f['monthTo'] ?? null,
                isset($f['policyYear']) ? (int) $f['policyYear'] : null,
            );
        }

        $result = $this->service->listRows($tenantId, $f);

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['truncated' => $result['truncated'], 'limit' => CommissionReceiptService::LIST_LIMIT],
        ]);
    }

    public function export(Request $request): Response
    {
        Gate::authorize('receipts.view');
        $tenantId = $this->tenantId($request);
        $f = $this->filters($request);
        if (! $f['history'] && ! empty($f['insurerId'])) {
            $this->service->seedReceivables($tenantId, (int) $f['insurerId'], $f['monthTo'] ?? null, isset($f['policyYear']) ? (int) $f['policyYear'] : null);
        }
        $name = 'commission-receipts-'.($f['monthFrom'] ?? 'all').(! empty($f['monthTo']) ? '_'.$f['monthTo'] : '').'.csv';

        return response($this->service->exportCsv($tenantId, $f), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function show(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.view');
        $this->authorizeTenant($request, $receivable);

        return response()->json([
            'data' => $this->service->rowById((int) $receivable->id),
            'rows' => $this->service->rowsForPolicy((int) $receivable->tenant_id, (int) $receivable->policy_id),
        ]);
    }

    public function review(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.record');
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'statementAmount' => ['required', 'numeric', 'between:-999999999,999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->review(
            $receivable, (float) $data['statementAmount'], $data['note'] ?? null,
            $request->user()?->id, $data['version'] ?? null,
        ));
    }

    public function bulkReview(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:2000'],
            'items.*.id' => ['required', 'integer'],
            'items.*.statementAmount' => ['required', 'numeric', 'between:-999999999,999999999'],
            'items.*.version' => ['nullable', 'integer'],
        ]);

        return $this->runBulk(fn () => $this->service->bulkReview($this->tenantId($request), $data['items'], $request->user()?->id));
    }

    public function statementMatch(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $data = $request->validate([
            'insurerId' => ['required', 'integer'],
            'type' => ['nullable', 'in:MAIN,OV'],
            'lines' => ['required', 'array', 'min:1', 'max:2000'],
            'lines.*.ref' => ['required', 'string', 'max:100'],
            'lines.*.amount' => ['nullable', 'numeric', 'between:-999999999,999999999'],
        ]);

        return response()->json(['data' => $this->service->statementMatch(
            $this->tenantId($request), (int) $data['insurerId'], $data['type'] ?? 'MAIN', $data['lines'],
        )]);
    }

    public function receive(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.record');
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'receivedAmount' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'receivedDate' => ['required', 'date'],
            'receiptBatchId' => ['nullable', 'integer'],
            'diffReason' => ['nullable', 'in:'.implode(',', CommissionReceivable::REASONS)],
            'diffNote' => ['nullable', 'string', 'max:500'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->receive(
            $receivable,
            isset($data['receivedAmount']) ? (float) $data['receivedAmount'] : null,
            $data['receivedDate'],
            isset($data['receiptBatchId']) ? (int) $data['receiptBatchId'] : null,
            $data['diffReason'] ?? null,
            $data['diffNote'] ?? null,
            $request->user()?->id,
            $data['version'] ?? null,
        ));
    }

    public function bulkReceive(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $data = $request->validate([
            'receivedDate' => ['required', 'date'],
            'receiptBatchId' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:2000'],
            'items.*.id' => ['required', 'integer'],
            'items.*.version' => ['nullable', 'integer'],
            'items.*.receivedAmount' => ['nullable', 'numeric', 'between:-999999999,999999999'],
            'items.*.diffReason' => ['nullable', 'in:'.implode(',', CommissionReceivable::REASONS)],
            'items.*.diffNote' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->runBulk(fn () => $this->service->bulkReceive(
            $this->tenantId($request), $data['items'], $data['receivedDate'],
            isset($data['receiptBatchId']) ? (int) $data['receiptBatchId'] : null, $request->user()?->id,
        ));
    }

    public function markNoCommission(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.record');
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        return $this->run(fn () => $this->service->markNoCommission(
            $receivable, $data['reason'], $request->user()?->id, $data['version'] ?? null,
        ));
    }

    public function reopen(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.reopen');
        $this->authorizeTenant($request, $receivable);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'version' => ['nullable', 'integer'],
        ]);

        try {
            $rec = $this->service->reopen($receivable, $data['reason'], $request->user()?->id, $data['version'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->service->rowById((int) $rec->id),
            'meta' => ['warning' => $this->service->paidPayoutWarning($rec)],
        ]);
    }

    public function resyncExpected(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.record');
        $this->authorizeTenant($request, $receivable);

        return $this->run(fn () => $this->service->resyncExpected($receivable, $request->user()?->id));
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $data = $request->validate([
            'policyId' => ['required', 'integer'],
            'kind' => ['required', 'in:'.implode(',', CommissionReceivable::KINDS)],
            'expectedAmount' => ['required', 'numeric', 'between:-999999999,999999999'],
            'policyEventId' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $rec = $this->service->createAdjustment(
                $this->tenantId($request), (int) $data['policyId'], $data['kind'], (float) $data['expectedAmount'],
                isset($data['policyEventId']) ? (int) $data['policyEventId'] : null, $data['note'] ?? null, $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->service->rowById((int) $rec->id)], 201);
    }

    public function suggestAdjustment(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $data = $request->validate([
            'policyId' => ['required', 'integer'],
            'kind' => ['required', 'in:'.implode(',', CommissionReceivable::KINDS)],
        ]);

        try {
            return response()->json(['data' => $this->service->suggestAdjustment($this->tenantId($request), (int) $data['policyId'], $data['kind'])]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroyAdjustment(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.record');
        $this->authorizeTenant($request, $receivable);

        try {
            $this->service->deleteAdjustment($receivable, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function auditLog(Request $request, CommissionReceivable $receivable): JsonResponse
    {
        Gate::authorize('receipts.view');
        $this->authorizeTenant($request, $receivable);
        $rows = AuditEntry::query()
            ->leftJoin('users as u', 'u.id', '=', 'audit_entries.user_id')
            ->where('audit_entries.tenant_id', $receivable->tenant_id)
            ->where('audit_entries.target', "receivable:{$receivable->id}")
            ->orderByDesc('audit_entries.occurred_at')->orderByDesc('audit_entries.id')
            ->get(['audit_entries.action', 'audit_entries.occurred_at', 'audit_entries.actor', 'audit_entries.metadata', 'u.name as user_name'])
            ->map(fn ($e): array => [
                'action' => $e->action,
                'at' => $e->occurred_at?->toIso8601String(),
                'actor' => $e->user_name ?? ($e->actor === 'system' ? 'ระบบ' : $e->actor),
                'reason' => $e->metadata['reason'] ?? null,
                'old' => $e->metadata['old'] ?? null,
                'new' => $e->metadata['new'] ?? null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('receipts.view');

        return response()->json(['data' => $this->service->summary($this->tenantId($request), $this->filters($request))]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('receipts.view');

        return response()->json(['data' => $this->service->dashboard($this->tenantId($request), $this->filters($request))]);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        Gate::authorize('receipts.record');
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'insurerId' => ['required', 'integer'],
            'policyYear' => ['nullable', 'integer'],
            'statementMonth' => self::MONTH,
            'statementTotal' => ['nullable', 'numeric'],
            'statementDate' => ['nullable', 'date'],
            'receivedFileDate' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! DB::table('carriers')->where('id', $data['insurerId'])->exists()) {
            return response()->json(['message' => 'ไม่พบบริษัทประกัน'], 422);
        }
        $batch = $this->service->createReceiptBatch($tenantId, $data, $request->user()?->id);

        return response()->json(['data' => $this->batchRow($batch->load('insurer:id,name'), [])], 201);
    }

    public function indexBatches(Request $request): JsonResponse
    {
        Gate::authorize('receipts.view');
        $tenantId = $this->tenantId($request);
        $q = CommissionReceiptBatch::query()->where('tenant_id', $tenantId)->with('insurer:id,name')->withCount('files');
        if ($request->filled('insurerId')) {
            $q->where('insurer_id', (int) $request->input('insurerId'));
        }
        if ($request->filled('policyYear')) {
            $q->where('policy_year', (int) $request->input('policyYear'));
        }
        $batches = $q->orderByDesc('id')->limit(200)->get();
        $totals = $this->service->batchTotals($batches->pluck('id')->all());

        return response()->json(['data' => $batches->map(fn ($b): array => $this->batchRow($b, $totals))]);
    }

    public function uploadFile(Request $request, CommissionReceiptBatch $batch): JsonResponse
    {
        Gate::authorize('receipts.record');
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,pdf', 'max:20480'],
        ]);
        $file = $request->file('file');
        $stored = $file->store("commission-receipts/{$batch->tenant_id}/{$batch->id}", 'local');

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

    public function showBatch(Request $request, CommissionReceiptBatch $batch): JsonResponse
    {
        Gate::authorize('receipts.view');
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
        $batch->load(['insurer:id,name', 'files']);
        $totals = $this->service->batchTotals([$batch->id]);

        return response()->json([
            'data' => array_merge($this->batchRow($batch, $totals), [
                'files' => $batch->files->map(fn ($f): array => $this->fileRow($f)),
            ]),
        ]);
    }

    public function downloadFile(Request $request, CommissionReceiptFile $file): StreamedResponse|JsonResponse
    {
        Gate::authorize('receipts.view');
        abort_unless((int) $file->tenant_id === $this->tenantId($request), 404);
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);
        $mime = $file->mime_type ?: (Storage::disk('local')->mimeType($file->storage_path) ?: 'application/octet-stream');

        if ($request->query('as') === 'base64') {
            return response()->json(['data' => [
                'filename' => $file->original_filename,
                'mime' => $mime,
                'base64' => base64_encode((string) Storage::disk('local')->get($file->storage_path)),
            ]]);
        }

        return Storage::disk('local')->download($file->storage_path, $file->original_filename, ['Content-Type' => $mime]);
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            $rec = $fn();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->service->rowById((int) $rec->id)]);
    }

    private function runBulk(callable $fn): JsonResponse
    {
        try {
            $recs = $fn();
        } catch (ReceiptBulkException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->rowErrors], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $recs->map(fn (CommissionReceivable $r): array => $this->service->rowById((int) $r->id))->values()]);
    }

    private function batchRow(CommissionReceiptBatch $b, array $totals): array
    {
        $t = $totals[$b->id] ?? ['count' => 0, 'received' => 0.0];

        return [
            'id' => (string) $b->id,
            'batchNo' => $b->batch_no,
            'insurerId' => (string) $b->insurer_id,
            'insurerName' => $b->insurer?->name,
            'policyYear' => $b->policy_year,
            'statementMonth' => $b->statement_month,
            'statementTotal' => $b->statement_total !== null ? round((float) $b->statement_total, 2) : null,
            'receivedCount' => $t['count'],
            'receivedTotal' => $t['received'],
            'difference' => $b->statement_total !== null ? round($t['received'] - (float) $b->statement_total, 2) : null,
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
