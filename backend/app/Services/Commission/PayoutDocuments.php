<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Models\CommissionPayoutBatch;
use App\Models\CommissionPayoutPayee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PayoutDocuments
{
    public function __construct(private readonly CommissionPayoutService $service)
    {
    }

    public function statement(array $data)
    {
        // ทุกเอกสาร (v1/v2, ขายเอง/แนะนำ, ทุก VAT) ใช้ layout ใบจ่ายสวยตัวเดียว
        // (pdf.commission-payout): โลโก้/QR, กล่องตัวแทน, ตาราง, ยอดเหลือง, ลายเซ็น.
        $brand = fn (string $file): ?string => is_file(public_path("brand/{$file}"))
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path("brand/{$file}")))
            : null;

        return Pdf::loadView('pdf.commission-payout', array_merge($data, [
            'doc' => $data['doc'] ?? null,
            'logoData' => $brand('logo.png'),
            'qrData' => $brand('line-qr.png'),
            'generatedAt' => now('Asia/Bangkok')->format('d/m/Y H:i'),
        ]))->setPaper('a4', 'landscape');
    }

    public function certificate(CommissionPayoutPayee $payee)
    {
        return Pdf::loadView('pdf.wht-certificate', [
            'cert' => $this->service->certificateData($payee),
            'generatedAt' => now('Asia/Bangkok')->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');
    }

    public function statementFilename(CommissionPayoutBatch $batch, array $data): string
    {
        $mmyyyy = $batch->to_date?->format('mY') ?? now()->format('mY');
        $tag = $this->service->vatTag($data['vatType']);
        $name = trim(($data['agent']['name'] ?? '') ?: ($data['agent']['code'] ?? 'agent'));
        $code = $data['agent']['code'] ?? 'agent';
        $docTag = ['direct' => '_ขายเอง', 'referral' => '_แนะนำ'][$data['doc'] ?? ''] ?? '';

        return $this->safe("{$code}_{$name}_{$mmyyyy}{$tag}{$docTag}").'.pdf';
    }

    public function certificateFilename(CommissionPayoutPayee $payee): string
    {
        return $this->safe("50ทวิ_{$payee->agent_code}_{$payee->cert_book}-{$payee->cert_no}").'.pdf';
    }

    public function respond(Request $request, $pdf, string $filename): Response|JsonResponse
    {
        if ($request->query('as') === 'base64') {
            return response()->json(['data' => ['filename' => $filename, 'mime' => 'application/pdf', 'base64' => base64_encode($pdf->output())]]);
        }

        return $pdf->download($filename);
    }

    private function safe(string $raw): string
    {
        $unsafe = ['\\', '/', ':', '*', '?', '"', '<', '>', '|', "\n", "\r", "\t"];

        return Str::limit(str_replace($unsafe, '_', $raw), 120, '');
    }
}
