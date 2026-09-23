<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionPayoutBatch;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Agent commission payout batches — ระบบทำจ่ายค่าคอมประจำเดือน (ตัวแทน).
 * See DEV Spec ชุดที่ 2. Phase 1: preview → create batch (snapshot) →
 * adjustments → mark paid. PDF/Excel are a later phase.
 */
class CommissionPayoutController extends Controller
{
    public function __construct(private readonly CommissionPayoutService $service)
    {
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    /** POST /commission-payout-batches/preview */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
        ]);

        $result = $this->service->preview($this->tenantId($request), $data['fromDate'], $data['toDate']);

        return response()->json($result);
    }

    /**
     * GET /commission-payout-batches/by-agent
     * Agent-first outstanding list — every agent with unpaid commission,
     * optionally filtered by a date range. Powers the payout landing page.
     */
    public function byAgent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        $result = $this->service->byAgent(
            $this->tenantId($request),
            $data['fromDate'] ?? null,
            $data['toDate'] ?? null,
        );

        return response()->json($result);
    }

    /**
     * GET /commission-payout-batches/by-agent/{agent}
     * One agent's outstanding line items (the policies a payout would cover).
     */
    public function agentDetail(Request $request, int $agent): JsonResponse
    {
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        $result = $this->service->agentDetail(
            $this->tenantId($request),
            $agent,
            $data['fromDate'] ?? null,
            $data['toDate'] ?? null,
        );

        return response()->json($result);
    }

    /**
     * POST /commission-payout-batches/by-agent/{agent}/pay
     * Pay one agent — snapshots their eligible items into a fresh single-agent
     * batch, approves, and marks paid, atomically. Returns the paid batch.
     */
    public function payAgent(Request $request, int $agent): JsonResponse
    {
        $data = $request->validate([
            'paymentDate' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:128'],
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $batch = $this->service->payAgent(
                $this->tenantId($request),
                $agent,
                $data['paymentDate'],
                $data['reference'] ?? null,
                $request->user()?->id,
                $data['fromDate'] ?? null,
                $data['toDate'] ?? null,
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    /** GET /commission-payout-batches */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $status = (string) $request->input('status', 'all');

        $q = CommissionPayoutBatch::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');
        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $rows = $q->limit(200)->get()->map(fn (CommissionPayoutBatch $b): array => $this->batchRow($b));

        return response()->json(['data' => $rows]);
    }

    /** GET /commission-payout-batches/{batch} */
    public function show(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        $batch->load(['items.policy:id,policy_no,application_no', 'items.agent:id,agent_code,first_name,last_name', 'adjustments']);

        // Group items by agent for the detail grid.
        $byAgent = [];
        foreach ($batch->items as $item) {
            $key = $item->agent_id ?? 0;
            if (! isset($byAgent[$key])) {
                $byAgent[$key] = [
                    'agentId' => $item->agent_id ? (string) $item->agent_id : null,
                    'agentCode' => $item->agent_code,
                    'agentName' => trim(($item->agent->first_name ?? '') . ' ' . ($item->agent->last_name ?? '')),
                    'vatType' => $item->vat_type,
                    'itemCount' => 0,
                    'commission' => 0.0,
                    'items' => [],
                ];
            }
            $byAgent[$key]['itemCount']++;
            $byAgent[$key]['commission'] += $item->total();
            $byAgent[$key]['items'][] = [
                'policyId' => (string) $item->policy_id,
                'policyNo' => $item->policy->policy_no ?? null,
                'applicationNo' => $item->policy->application_no ?? null,
                'amount' => round($item->total(), 2),
                'source' => $item->amount_source,
                'status' => $item->item_status,
            ];
        }

        // Apply adjustments (deducts) per agent for the display net.
        $adjByAgent = [];
        foreach ($batch->adjustments as $adj) {
            $adjByAgent[$adj->agent_id ?? 0] = ($adjByAgent[$adj->agent_id ?? 0] ?? 0) + (float) $adj->amount;
        }
        $agents = [];
        foreach ($byAgent as $key => $a) {
            $deduct = $adjByAgent[$key] ?? 0;
            $a['commission'] = round($a['commission'], 2);
            $a['deduct'] = round($deduct, 2);
            $a['net'] = round($a['commission'] - $deduct, 2);
            $agents[] = $a;
        }

        return response()->json([
            'data' => array_merge($this->batchRow($batch), [
                'agents' => $agents,
                'adjustments' => $batch->adjustments->map(fn ($a): array => [
                    'id' => (string) $a->id,
                    'agentId' => $a->agent_id ? (string) $a->agent_id : null,
                    'agentCode' => $a->agent_code,
                    'type' => $a->type,
                    'amount' => round((float) $a->amount, 2),
                    'reason' => $a->reason,
                    'createdAt' => $a->created_at?->toIso8601String(),
                ]),
            ]),
        ]);
    }

    /** POST /commission-payout-batches */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $batch = $this->service->createBatch(
                $this->tenantId($request), $data['fromDate'], $data['toDate'],
                $request->user()?->id, $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)], 201);
    }

    /** POST /commission-payout-batches/{batch}/adjustments */
    public function addAdjustment(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        $data = $request->validate([
            'agentId' => ['nullable', 'integer'],
            'agentCode' => ['nullable', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $adj = $this->service->addAdjustment(
                $batch, $data['agentId'] ?? null, $data['agentCode'] ?? null,
                (float) $data['amount'], $data['reason'], $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => (string) $adj->id]], 201);
    }

    /** POST /commission-payout-batches/{batch}/mark-paid */
    public function markPaid(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        $data = $request->validate([
            'paymentDate' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $batch = $this->service->markPaid($batch, $data['paymentDate'], $data['reference'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    /** POST /commission-payout-batches/{batch}/approve */
    public function approve(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        try {
            $batch = $this->service->approveBatch($batch, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    /** POST /commission-payout-batches/{batch}/unapprove */
    public function unapprove(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        try {
            $batch = $this->service->unapproveBatch($batch, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    /** POST /commission-payout-batches/{batch}/cancel */
    public function cancel(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        try {
            $batch = $this->service->cancelBatch($batch, $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    /** GET /commission-payout-batches/{batch}/agents/{code}/pdf — one agent. */
    public function agentPdf(Request $request, CommissionPayoutBatch $batch, string $code): Response
    {
        $this->authorizeTenant($request, $batch);
        $data = $this->service->buildAgentPdfData($batch, null, $code);
        if ($data === null) {
            abort(404, 'ไม่พบรายการของตัวแทนใน batch นี้');
        }

        $pdf = $this->renderAgentPdf($data);
        $filename = $this->pdfFilename($batch, $data);

        return $pdf->download($filename);
    }

    /** POST /commission-payout-batches/{batch}/generate-pdfs — ZIP of all (or selected) agents. */
    public function generatePdfs(Request $request, CommissionPayoutBatch $batch): Response
    {
        $this->authorizeTenant($request, $batch);
        $payload = $request->validate([
            'agentCodes' => ['nullable', 'array'],
            'agentCodes.*' => ['string'],
        ]);
        ini_set('memory_limit', '512M');

        // Distinct agent codes in the batch (optionally filtered to a selection).
        $codes = $batch->items()
            ->when(! empty($payload['agentCodes']), fn ($q) => $q->whereIn('agent_code', $payload['agentCodes']))
            ->whereNotNull('agent_code')
            ->distinct()
            ->pluck('agent_code');

        if ($codes->isEmpty()) {
            return response()->json(['message' => 'ไม่พบตัวแทนใน batch'], 422);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cpay_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $used = [];
        foreach ($codes as $code) {
            $data = $this->service->buildAgentPdfData($batch, null, $code);
            if ($data === null) {
                continue;
            }
            $name = $this->pdfFilename($batch, $data);
            // Guard against duplicate names within the archive.
            if (isset($used[$name])) {
                $name = preg_replace('/\.pdf$/', '', $name) . '_' . (++$used[$name]) . '.pdf';
            } else {
                $used[$name] = 1;
            }
            $zip->addFromString($name, $this->renderAgentPdf($data)->output());
        }
        $zip->close();

        $zipName = "CommissionPayout-{$batch->from_date?->format('Ymd')}-{$batch->to_date?->format('Ymd')}.zip";

        return response()->download($tmp, $zipName)->deleteFileAfterSend(true);
    }

    /** GET /commission-payout-batches/{batch}/export.csv — reconciliation export (§6.1). */
    public function exportCsv(Request $request, CommissionPayoutBatch $batch): StreamedResponse
    {
        $this->authorizeTenant($request, $batch);
        $rows = $this->service->exportRows($batch);

        $headers = [
            'ใบคำขอ', 'เลขกรมธรรม์', 'วันแจ้งงาน', 'รหัสตัวแทน', 'ชื่อตัวแทน', 'VAT_TYPE',
            'ลูกค้า', 'เบี้ยฐาน', 'ค่าคอมหลัก', 'ค่าคอมไรเดอร์', 'รวมค่าคอม',
            'ที่มายอด', 'สถานะรายการ', 'วันที่จ่าย', 'อ้างอิงการจ่าย',
        ];
        $filename = "CommissionPayout-{$batch->from_date?->format('Ymd')}-{$batch->to_date?->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders Thai correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function renderAgentPdf(array $data)
    {
        return Pdf::loadView('pdf.commission-payout', [
            'vatType' => $data['vatType'],
            'agent' => $data['agent'],
            'items' => $data['items'],
            'totals' => $data['totals'],
            'batch' => $data['batch'],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', 'portrait');
    }

    /** Filename per spec §5.1: <code>_<name>_<mmyyyy><vattag>.pdf, sanitised. */
    private function pdfFilename(CommissionPayoutBatch $batch, array $data): string
    {
        $mmyyyy = $batch->to_date?->format('mY') ?? now()->format('mY');
        $tag = $this->service->vatTag($data['vatType']);
        $name = trim(($data['agent']['name'] ?? '') ?: ($data['agent']['code'] ?? 'agent'));
        $code = $data['agent']['code'] ?? 'agent';
        // The agent code leads the filename and is unique per tenant, so two
        // different agents can never collide even if the display name is
        // truncated (codes are <=16 chars, well within the 120-char cap). The
        // ZIP path additionally dedups identical strings within one archive.
        $raw = "{$code}_{$name}_{$mmyyyy}{$tag}";
        // Strip filesystem-unsafe chars (spec §5.1 File Name Safety):
        // \ / : * ? " < > | plus control chars → underscore.
        $unsafe = ['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\n", "\r", "\t"];
        $safe = str_replace($unsafe, '_', $raw);
        $safe = Str::limit($safe, 120, '');

        return $safe . '.pdf';
    }

    private function batchRow(CommissionPayoutBatch $b): array
    {
        return [
            'id' => (string) $b->id,
            'fromDate' => $b->from_date?->toDateString(),
            'toDate' => $b->to_date?->toDateString(),
            'status' => $b->status,
            'totalAgents' => (int) $b->total_agents,
            'totalItems' => (int) $b->total_items,
            'totalAmount' => round((float) $b->total_amount, 2),
            'paymentDate' => $b->payment_date?->toDateString(),
            'paymentReference' => $b->payment_reference,
            'note' => $b->note,
            'approvedAt' => $b->approved_at?->toIso8601String(),
            'paidAt' => $b->paid_at?->toIso8601String(),
            'createdAt' => $b->created_at?->toIso8601String(),
        ];
    }

    private function authorizeTenant(Request $request, CommissionPayoutBatch $batch): void
    {
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
    }
}
