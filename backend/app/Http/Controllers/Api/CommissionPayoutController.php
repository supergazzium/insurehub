<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommissionPayoutBatch;
use App\Services\Commission\CommissionPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

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
            'paidAt' => $b->paid_at?->toIso8601String(),
            'createdAt' => $b->created_at?->toIso8601String(),
        ];
    }

    private function authorizeTenant(Request $request, CommissionPayoutBatch $batch): void
    {
        abort_unless((int) $batch->tenant_id === $this->tenantId($request), 404);
    }
}
