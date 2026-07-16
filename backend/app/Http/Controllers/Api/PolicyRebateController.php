<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\PolicyRebate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editable rebate ledger — the /commissions/rebates page saves inline edits here.
 * Route-model-bound; the parent policy's tenant scope enforces authorization.
 */
class PolicyRebateController extends ApiController
{
    /**
     * PATCH /policy-rebates/{rebate}
     * Accepts any subset of the editable numeric/status fields.
     */
    public function update(Request $request, PolicyRebate $rebate): JsonResponse
    {
        // Verify the rebate belongs to the caller's tenant via the parent policy.
        $rebate->loadMissing('policy');
        if ($rebate->policy === null || (int) $rebate->policy->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        $data = $request->validate([
            'calculatedAmount' => ['sometimes', 'nullable', 'numeric'],
            'actualAmount' => ['sometimes', 'nullable', 'numeric'],
            'calculatedOv' => ['sometimes', 'nullable', 'numeric'],
            'actualOv' => ['sometimes', 'nullable', 'numeric'],
            'calculatedAgentAmount' => ['sometimes', 'nullable', 'numeric'],
            'actualAgentAmount' => ['sometimes', 'nullable', 'numeric'],
            'rebateStatus' => ['sometimes', 'nullable', 'string', 'max:32'],
            'ovStatus' => ['sometimes', 'nullable', 'string', 'max:32'],
            'agentRebateStatus' => ['sometimes', 'nullable', 'string', 'max:32'],
            'validateAmount' => ['sometimes', 'nullable', 'string', 'max:16'],
            'validateOv' => ['sometimes', 'nullable', 'string', 'max:16'],
            'agentCheckStatus' => ['sometimes', 'nullable', 'string', 'max:16'],
            'earnDate' => ['sometimes', 'nullable', 'date'],
            'ovDate' => ['sometimes', 'nullable', 'date'],
            'agentReceiveDate' => ['sometimes', 'nullable', 'date'],
        ]);

        // camelCase → snake_case for storage.
        $map = [
            'calculatedAmount' => 'calculated_amount',
            'actualAmount' => 'actual_amount',
            'calculatedOv' => 'calculated_ov',
            'actualOv' => 'actual_ov',
            'calculatedAgentAmount' => 'calculated_agent_amount',
            'actualAgentAmount' => 'actual_agent_amount',
            'rebateStatus' => 'rebate_status',
            'ovStatus' => 'ov_status',
            'agentRebateStatus' => 'agent_rebate_status',
            'validateAmount' => 'validate_amount',
            'validateOv' => 'validate_ov',
            'agentCheckStatus' => 'agent_check_status',
            'earnDate' => 'earn_date',
            'ovDate' => 'ov_date',
            'agentReceiveDate' => 'agent_receive_date',
        ];

        $payload = [];
        foreach ($data as $key => $value) {
            if (isset($map[$key])) {
                $payload[$map[$key]] = $value;
            }
        }

        if ($payload) {
            $rebate->update($payload);
        }

        return response()->json([
            'data' => $this->serialize($rebate->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(PolicyRebate $r): array
    {
        return [
            'id' => (string) $r->id,
            'policyId' => (string) $r->policy_id,
            'rebateStatus' => $r->rebate_status,
            'earnDate' => $r->earn_date?->toDateString(),
            'ovStatus' => $r->ov_status,
            'ovDate' => $r->ov_date?->toDateString(),
            'calculatedAmount' => $r->calculated_amount !== null ? (float) $r->calculated_amount : null,
            'calculatedOv' => $r->calculated_ov !== null ? (float) $r->calculated_ov : null,
            'actualAmount' => $r->actual_amount !== null ? (float) $r->actual_amount : null,
            'actualOv' => $r->actual_ov !== null ? (float) $r->actual_ov : null,
            'validateAmount' => $r->validate_amount,
            'validateOv' => $r->validate_ov,
            'agentRebateStatus' => $r->agent_rebate_status,
            'agentReceiveDate' => $r->agent_receive_date?->toDateString(),
            'calculatedAgentAmount' => $r->calculated_agent_amount !== null ? (float) $r->calculated_agent_amount : null,
            'actualAgentAmount' => $r->actual_agent_amount !== null ? (float) $r->actual_agent_amount : null,
            'agentCheckStatus' => $r->agent_check_status,
        ];
    }
}
