<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionPayoutAdjustment;
use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutBatchItem;
use App\Models\CommissionPayoutPayee;
use App\Models\Policy;
use App\Services\Commission\CommissionPayoutService;
use App\Services\Commission\PayoutDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class CommissionPayoutController extends Controller
{
    public function __construct(
        private readonly CommissionPayoutService $service,
        private readonly PayoutDocuments $docs,
    ) {
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('payouts.preview');
        $data = $request->validate([
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
        ]);

        return response()->json($this->service->preview($this->tenantId($request), $data['fromDate'], $data['toDate']));
    }

    public function byAgent(Request $request): JsonResponse
    {
        Gate::authorize('payouts.view');
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        return response()->json($this->service->byAgent($this->tenantId($request), $data['fromDate'] ?? null, $data['toDate'] ?? null));
    }

    public function agentDetail(Request $request, int $agent): JsonResponse
    {
        Gate::authorize('payouts.view');
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        $result = $this->service->agentDetail($this->tenantId($request), $agent, $data['fromDate'] ?? null, $data['toDate'] ?? null);
        $result['meta'] = [
            'canPay' => Gate::allows('payouts.mark_paid'),
            'canOverride' => Gate::allows('commissions.override'),
        ];

        return response()->json($result);
    }

    public function payAgent(Request $request, int $agent): JsonResponse
    {
        Gate::authorize('payouts.mark_paid');
        $data = $request->validate([
            'paymentDate' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:128'],
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $batch = $this->service->payAgent(
                $this->tenantId($request), $agent, $data['paymentDate'], $data['reference'] ?? null,
                $request->user()?->id, $data['fromDate'] ?? null, $data['toDate'] ?? null, $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    public function override(Request $request, Policy $policy): JsonResponse
    {
        Gate::authorize('commissions.override');
        abort_unless((int) $policy->tenant_id === $this->tenantId($request), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'กรุณาระบุเหตุผลที่ยกเว้นเงื่อนไข']);
        $override = $this->service->overridePolicy($this->tenantId($request), (int) $policy->id, trim($data['reason']), $request->user()?->id);

        return response()->json(['data' => ['id' => (string) $override->id]], 201);
    }

    public function revokeOverride(Request $request, Policy $policy): JsonResponse
    {
        Gate::authorize('commissions.override');
        abort_unless((int) $policy->tenant_id === $this->tenantId($request), 404);

        return response()->json(['data' => ['revoked' => $this->service->revokeOverride($this->tenantId($request), (int) $policy->id, $request->user()?->id)]]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('payouts.view');
        $status = (string) $request->input('status', 'all');
        $q = CommissionPayoutBatch::query()
            ->withSum('payees as net_total', 'net_amount')
            ->withSum('payees as wht_total', 'wht_amount')
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('id');
        if ($status !== 'all') {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn (CommissionPayoutBatch $b): array => $this->batchRow($b))]);
    }

    public function show(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        $batch->load(['adjustments.policy:id,policy_no,application_no']);
        $lines = $this->service->batchLines($batch);
        $payees = $batch->payees()->get()->keyBy('agent_id');

        $byAgent = [];
        foreach ($lines as $item) {
            $key = $item->agent_id ?? 0;
            if (! isset($byAgent[$key])) {
                $payee = $payees->get($key);
                $byAgent[$key] = [
                    'agentId' => $item->agent_id ? (string) $item->agent_id : null,
                    'agentCode' => $item->agent_code,
                    'agentName' => $payee?->payee_name ?? $item->payeeName(),
                    'payeeKind' => $payee?->payee_kind ?? $item->payee_kind,
                    'vatType' => $payee?->vat_type ?? $item->vat_type,
                    'payeeId' => $payee ? (string) $payee->id : null,
                    'itemCount' => 0,
                    'commission' => 0.0,
                    'items' => [],
                ];
            }
            if ($item->item_status !== CommissionPayoutBatchItem::ITEM_EXCLUDED) {
                $byAgent[$key]['itemCount']++;
                $byAgent[$key]['commission'] += $item->total();
            }
            $byAgent[$key]['items'][] = [
                'policyId' => (string) $item->policy_id,
                'policyNo' => $item->policy?->policy_no,
                'applicationNo' => $item->policy?->application_no,
                'lineType' => $item->line_type,
                'lineLabel' => CommissionPayoutBatchItem::LINE_LABELS[$item->line_type] ?? $item->line_type,
                'sourceAgent' => $item->sourceAgent ? trim($item->sourceAgent->agent_code.' · '.trim(($item->sourceAgent->first_name ?? '').' '.($item->sourceAgent->last_name ?? ''))) : null,
                'ratioLabel' => $item->ratio_label,
                'override' => (bool) $item->condition_override,
                'amount' => round($item->total(), 2),
                'source' => $item->amount_source,
                'status' => $item->item_status,
            ];
        }

        $adjByAgent = [];
        foreach ($batch->adjustments as $adj) {
            $adjByAgent[$adj->agent_id ?? 0] = ($adjByAgent[$adj->agent_id ?? 0] ?? 0) + (float) $adj->amount;
        }
        $agents = [];
        foreach ($byAgent as $key => $a) {
            $payee = $payees->get($key);
            $a['commission'] = round($a['commission'], 2);
            $a['deduct'] = round($adjByAgent[$key] ?? 0, 2);
            $a['net'] = round($a['commission'] - $a['deduct'], 2);
            if ($payee !== null) {
                $a['tax'] = [
                    'base' => (float) $payee->base_amount,
                    'selfMgmt' => (float) $payee->self_mgmt_amount,
                    'referral' => (float) $payee->referral_amount,
                    'override' => (float) $payee->override_amount,
                    'taxable' => (float) $payee->taxable_amount,
                    'vat' => (float) $payee->vat_amount,
                    'total' => (float) $payee->total_amount,
                    'whtRate' => (float) $payee->wht_rate,
                    'whtForm' => CommissionPayoutPayee::FORM_LABELS[$payee->wht_form] ?? $payee->wht_form,
                    'wht' => (float) $payee->wht_amount,
                    'net' => (float) $payee->net_amount,
                    'certificate' => $payee->certificateLabel(),
                    'bankName' => $payee->bank_name,
                    'hasBankAccount' => filled($payee->bank_account_no),
                ];
            }
            $agents[] = $a;
        }

        return response()->json([
            'data' => array_merge($this->batchRow($batch), [
                'agents' => $agents,
                'adjustments' => $batch->adjustments->map(fn (CommissionPayoutAdjustment $a): array => [
                    'id' => (string) $a->id,
                    'agentId' => $a->agent_id ? (string) $a->agent_id : null,
                    'agentCode' => $a->agent_code,
                    'type' => $a->type,
                    'typeLabel' => CommissionPayoutAdjustment::TYPE_LABELS[$a->type] ?? $a->type,
                    'policyNo' => $a->policy?->policy_no ?: $a->policy?->application_no,
                    'amount' => round((float) $a->amount, 2),
                    'reason' => $a->reason,
                    'createdAt' => $a->created_at?->toIso8601String(),
                ]),
            ]),
            'meta' => [
                'canApprove' => Gate::allows('payouts.approve'),
                'canPay' => Gate::allows('payouts.mark_paid'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('payouts.approve');
        $data = $request->validate([
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $batch = $this->service->createBatch($this->tenantId($request), $data['fromDate'], $data['toDate'], $request->user()?->id, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)], 201);
    }

    public function addAdjustment(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.approve');
        $this->authorizeTenant($request, $batch);
        $data = $request->validate([
            'agentId' => ['nullable', 'integer'],
            'agentCode' => ['nullable', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $adj = $this->service->addAdjustment($batch, $data['agentId'] ?? null, $data['agentCode'] ?? null, (float) $data['amount'], $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => (string) $adj->id]], 201);
    }

    public function markPaid(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.mark_paid');
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

    public function approve(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.approve');

        return $this->transition($request, $batch, fn () => $this->service->approveBatch($batch, $request->user()?->id));
    }

    public function unapprove(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.approve');

        return $this->transition($request, $batch, fn () => $this->service->unapproveBatch($batch, $request->user()?->id));
    }

    public function cancel(Request $request, CommissionPayoutBatch $batch): JsonResponse
    {
        Gate::authorize('payouts.approve');

        return $this->transition($request, $batch, fn () => $this->service->cancelBatch($batch, $request->user()?->id));
    }

    private function transition(Request $request, CommissionPayoutBatch $batch, callable $fn): JsonResponse
    {
        $this->authorizeTenant($request, $batch);
        try {
            $batch = $fn();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->batchRow($batch)]);
    }

    public function agentPdf(Request $request, CommissionPayoutBatch $batch, string $code): Response|JsonResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        $data = $this->service->buildAgentPdfData($batch, null, $code);
        if ($data === null) {
            abort(404, 'ไม่พบรายการของตัวแทนใน batch นี้');
        }

        return $this->docs->respond($request, $this->docs->statement($data), $this->docs->statementFilename($batch, $data));
    }

    public function certificate(Request $request, CommissionPayoutBatch $batch, CommissionPayoutPayee $payee): Response|JsonResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        abort_unless((int) $payee->batch_id === (int) $batch->id, 404);
        if ($payee->cert_no === null) {
            return response()->json(['message' => 'ยังไม่มีหนังสือรับรองการหักภาษี (ออกเมื่อยืนยันการจ่ายและมียอดหัก ณ ที่จ่าย)'], 422);
        }

        return $this->docs->respond($request, $this->docs->certificate($payee), $this->docs->certificateFilename($payee));
    }

    public function generatePdfs(Request $request, CommissionPayoutBatch $batch): Response
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        $payload = $request->validate([
            'agentCodes' => ['nullable', 'array'],
            'agentCodes.*' => ['string'],
        ]);
        ini_set('memory_limit', '512M');

        $codes = $batch->items()
            ->when(! empty($payload['agentCodes']), fn ($q) => $q->whereIn('agent_code', $payload['agentCodes']))
            ->whereNotNull('agent_code')
            ->distinct()
            ->pluck('agent_code');

        if ($codes->isEmpty()) {
            return response()->json(['message' => 'ไม่พบตัวแทนใน batch'], 422);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cpay_').'.zip';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $used = [];
        foreach ($codes as $code) {
            $data = $this->service->buildAgentPdfData($batch, null, $code);
            if ($data === null) {
                continue;
            }
            $name = $this->docs->statementFilename($batch, $data);
            if (isset($used[$name])) {
                $name = preg_replace('/\.pdf$/', '', $name).'_'.(++$used[$name]).'.pdf';
            } else {
                $used[$name] = 1;
            }
            $zip->addFromString($name, $this->docs->statement($data)->output());
        }
        foreach ($batch->payees()->whereNotNull('cert_no')->get() as $payee) {
            $zip->addFromString($this->docs->certificateFilename($payee), $this->docs->certificate($payee)->output());
        }
        $zip->close();

        return response()->download($tmp, "CommissionPayout-{$batch->from_date?->format('Ymd')}-{$batch->to_date?->format('Ymd')}.zip")->deleteFileAfterSend(true);
    }

    public function exportCsv(Request $request, CommissionPayoutBatch $batch): StreamedResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        $rows = $this->service->exportRows($batch);
        $filename = "CommissionPayout-{$batch->id}-{$batch->to_date?->format('Ymd')}.csv";

        if (! $batch->isV2()) {
            $headers = [
                'ใบคำขอ', 'เลขกรมธรรม์', 'วันแจ้งงาน', 'รหัสตัวแทน', 'ชื่อตัวแทน', 'VAT_TYPE',
                'ลูกค้า', 'เบี้ยฐาน', 'ค่าคอมหลัก', 'ค่าคอมไรเดอร์', 'รวมค่าคอม',
                'ที่มายอด', 'สถานะรายการ', 'วันที่จ่าย', 'อ้างอิงการจ่าย',
            ];

            return $this->csv($filename, [[$headers, $rows]]);
        }

        return $this->csv($filename, [
            [['รอบจ่าย', 'รหัสผู้รับ', 'ชื่อผู้รับ', 'ประเภท', 'เลขกรมธรรม์', 'ใบคำขอ', 'ลูกค้า', 'จากตัวแทน', 'เบี้ยฐาน', 'สัดส่วนที่ลูกค้าชำระ', 'ยอด', 'ยกเว้นเงื่อนไข', 'สถานะ', 'วันที่จ่าย'], $rows],
            [['รหัสผู้รับ', 'ชื่อผู้รับ', 'ชนิด', 'ค่าคอมพื้นฐาน', 'ค่าบริหาร (ขายเอง)', 'ค่าแนะนำ', 'ค่าบริหารสายงาน', 'รวม', 'หักคืน', 'ยอดก่อน VAT', 'VAT', 'รวม VAT', 'อัตราหัก ณ ที่จ่าย (%)', 'หัก ณ ที่จ่าย', 'โอนสุทธิ', 'เลขที่ 50 ทวิ'], $this->service->payeeRows($batch)],
        ]);
    }

    public function bankCsv(Request $request, CommissionPayoutBatch $batch): StreamedResponse|JsonResponse
    {
        Gate::authorize('payouts.view');
        $this->authorizeTenant($request, $batch);
        if (! $batch->isV2()) {
            return response()->json(['message' => 'รอบจ่ายแบบเดิมไม่มีไฟล์โอนเงิน'], 422);
        }

        return $this->csv("BankTransfer-{$batch->id}.csv", [[
            ['ลำดับ', 'รหัสตัวแทน', 'ชื่อบัญชี', 'ธนาคาร', 'เลขที่บัญชี', 'ยอดโอน', 'อ้างอิง', 'หมายเหตุ'],
            $this->service->bankRows($batch),
        ]]);
    }

    public function whtReport(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('payouts.view');
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'form' => ['required', 'in:pnd3,pnd53'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $report = $this->service->whtReport($this->tenantId($request), $data['month'], $data['form']);
        if (($data['format'] ?? 'json') !== 'csv') {
            return response()->json(['data' => $report]);
        }
        $rows = array_map(fn (array $r): array => [
            $r['seq'], $r['taxId'], $r['name'], $r['address'], $r['date'], $r['incomeType'],
            number_format($r['rate'], 2, '.', ''), number_format($r['amount'], 2, '.', ''), number_format($r['wht'], 2, '.', ''), $r['condition'], $r['certificate'],
        ], $report['rows']);

        return $this->csv("{$report['formLabel']}-{$report['month']}.csv", [[
            ['ลำดับ', 'เลขประจำตัวผู้เสียภาษี', 'ชื่อผู้มีเงินได้', 'ที่อยู่', 'วันที่จ่าย', 'ประเภทเงินได้', 'อัตราภาษี (%)', 'จำนวนเงินที่จ่าย', 'ภาษีที่หัก', 'เงื่อนไข', 'เลขที่หนังสือรับรอง'],
            $rows,
        ]]);
    }

    private function csv(string $filename, array $sections): StreamedResponse
    {
        return response()->streamDownload(function () use ($sections): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($sections as $i => [$headers, $rows]) {
                if ($i > 0) {
                    fputcsv($out, []);
                }
                fputcsv($out, $headers);
                foreach ($rows as $r) {
                    fputcsv($out, array_values($r));
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function batchRow(CommissionPayoutBatch $b): array
    {
        return [
            'id' => (string) $b->id,
            'fromDate' => $b->from_date?->toDateString(),
            'toDate' => $b->to_date?->toDateString(),
            'status' => $b->status,
            'calcVersion' => (int) $b->calc_version,
            'totalAgents' => (int) $b->total_agents,
            'totalItems' => (int) $b->total_items,
            'totalAmount' => round((float) $b->total_amount, 2),
            'netTotal' => $b->isV2() ? round((float) ($b->net_total ?? $b->payees()->sum('net_amount')), 2) : null,
            'whtTotal' => $b->isV2() ? round((float) ($b->wht_total ?? $b->payees()->sum('wht_amount')), 2) : null,
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
