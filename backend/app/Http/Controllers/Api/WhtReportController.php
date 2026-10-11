<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Commission\WhtReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Withholding-tax (ภาษีหัก ณ ที่จ่าย) report for tax filing — read-only over
 * the payout data. See WhtReportService.
 */
class WhtReportController extends Controller
{
    public function __construct(private readonly WhtReportService $service)
    {
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('tenant_id', $request->user()->tenant_id);
    }

    /** GET /wht-report — per-agent WHT totals + summary for a period. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        return response()->json(
            $this->service->report($this->tenantId($request), $data['fromDate'] ?? null, $data['toDate'] ?? null),
        );
    }

    /** GET /wht-report/export.csv — ภ.ง.ด.53-friendly export. */
    public function exportCsv(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);
        $report = $this->service->report($this->tenantId($request), $data['fromDate'] ?? null, $data['toDate'] ?? null);

        $headers = [
            'ลำดับ', 'รหัสตัวแทน', 'ชื่อผู้ถูกหักภาษี', 'ประเภท',
            'เลขประจำตัวผู้เสียภาษี / บัตรประชาชน', 'ที่อยู่', 'สาขา',
            'จำนวนรอบจ่าย', 'ยอดค่าคอมก่อนหัก', 'ภาษีหัก ณ ที่จ่าย (3%)', 'ยอดจ่ายสุทธิ',
        ];
        $from = $data['fromDate'] ?? 'all';
        $to = $data['toDate'] ?? 'all';
        $filename = "WHT-Report-{$from}-{$to}.csv";

        return response()->streamDownload(function () use ($headers, $report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel/Thai
            fputcsv($out, $headers);
            $i = 0;
            foreach ($report['rows'] as $r) {
                $i++;
                fputcsv($out, [
                    $i,
                    $r['agentCode'],
                    $r['payeeName'],
                    $r['payeeType'] === 'corporate' ? 'นิติบุคคล' : 'บุคคลธรรมดา',
                    $r['taxId'],
                    $r['address'],
                    $r['branch'],
                    $r['payoutCount'],
                    number_format((float) $r['grossCommission'], 2, '.', ''),
                    number_format((float) $r['whtAmount'], 2, '.', ''),
                    number_format((float) $r['netPaid'], 2, '.', ''),
                ]);
            }
            // Totals row.
            fputcsv($out, [
                '', '', 'รวม', '', '', '', '',
                $report['totals']['payeeCount'],
                number_format((float) $report['totals']['grossCommission'], 2, '.', ''),
                number_format((float) $report['totals']['whtAmount'], 2, '.', ''),
                number_format((float) $report['totals']['netPaid'], 2, '.', ''),
            ]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
