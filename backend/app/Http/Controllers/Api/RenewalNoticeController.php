<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Policy;
use App\Models\PolicyDocument;
use App\Models\RenewalNotice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RenewalNoticeController extends ApiController
{
    public function index(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $rows = RenewalNotice::query()
            ->with('createdBy:id,name')
            ->where('tenant_id', $policy->tenant_id)
            ->where('policy_id', $policy->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (RenewalNotice $n) => $n->toApi())->values(),
        ]);
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorizeTenant($request, $policy);
        $data = $request->validate([
            'annualPremium' => ['required', 'numeric', 'gt:0'],
            'netPremium' => ['nullable', 'numeric', 'min:0'],
            'dutyStamp' => ['nullable', 'numeric', 'min:0'],
            'vat' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'coverage' => ['nullable', 'numeric', 'min:0'],
            'premiumMode' => ['nullable', 'string', 'in:monthly,quarterly,semiannual,annual,single'],
            'dueDate' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'carrierDocumentId' => ['nullable', 'integer'],
        ]);

        $parts = [$data['netPremium'] ?? null, $data['dutyStamp'] ?? null, $data['vat'] ?? null];
        if (! in_array(null, $parts, true)
            && abs(array_sum(array_map('floatval', $parts)) - (float) $data['annualPremium']) > 0.05) {
            return response()->json([
                'message' => 'Net premium + duty stamp + VAT must equal the total premium.',
                'errors' => ['netPremium' => ['เบี้ยสุทธิ + อากร + VAT ต้องเท่ากับเบี้ยรวม']],
            ], 422);
        }

        $carrierDocId = $data['carrierDocumentId'] ?? PolicyDocument::query()
            ->where('policy_id', $policy->id)
            ->where('type', 'renewal_quote_carrier')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->value('id');
        if ($carrierDocId !== null
            && ! PolicyDocument::query()->where('policy_id', $policy->id)->whereKey($carrierDocId)->exists()) {
            $carrierDocId = null;
        }

        $notice = DB::transaction(function () use ($request, $policy, $data, $carrierDocId): RenewalNotice {
            Tenant::query()->whereKey($policy->tenant_id)->lockForUpdate()->first(['id']);
            $prefix = 'RN'.(now()->year + 543).'-';
            $last = RenewalNotice::query()
                ->where('tenant_id', $policy->tenant_id)
                ->where('notice_no', 'like', $prefix.'%')
                ->max('notice_no');
            $seq = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

            return RenewalNotice::create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'notice_no' => $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'annual_premium' => round((float) $data['annualPremium'], 2),
                'net_premium' => isset($data['netPremium']) ? round((float) $data['netPremium'], 2) : null,
                'duty_stamp' => isset($data['dutyStamp']) ? round((float) $data['dutyStamp'], 2) : null,
                'vat' => isset($data['vat']) ? round((float) $data['vat'], 2) : null,
                'discount' => isset($data['discount']) ? round((float) $data['discount'], 2) : null,
                'coverage' => isset($data['coverage']) ? round((float) $data['coverage'], 2) : null,
                'premium_mode' => $data['premiumMode'] ?? null,
                'due_date' => $data['dueDate'] ?? $policy->expiry_date,
                'valid_until' => $data['validUntil'] ?? null,
                'note' => $data['note'] ?? null,
                'carrier_document_id' => $carrierDocId,
                'created_by_user_id' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message' => 'Renewal notice created.',
            'data' => $notice->load('createdBy:id,name')->toApi(),
        ], 201);
    }

    private function authorizeTenant(Request $request, Policy $policy): void
    {
        if ((int) $policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }
}
