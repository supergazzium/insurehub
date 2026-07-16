<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyRebate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyRebate
 */
class PolicyRebateResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            // In-house (InH) side.
            'rebateStatus' => $this->rebate_status ?? '',
            'earnDate' => $this->earn_date?->toDateString(),
            'ovStatus' => $this->ov_status ?? '',
            'ovDate' => $this->ov_date?->toDateString(),
            'calculatedAmount' => $this->calculated_amount !== null ? (float) $this->calculated_amount : null,
            'calculatedOv' => $this->calculated_ov !== null ? (float) $this->calculated_ov : null,
            'actualAmount' => $this->actual_amount !== null ? (float) $this->actual_amount : null,
            'actualOv' => $this->actual_ov !== null ? (float) $this->actual_ov : null,
            'validateAmount' => $this->validate_amount ?? '',
            'validateOv' => $this->validate_ov ?? '',
            // Agent (AG) side.
            'agentRebateStatus' => $this->agent_rebate_status ?? '',
            'agentReceiveDate' => $this->agent_receive_date?->toDateString(),
            'calculatedAgentAmount' => $this->calculated_agent_amount !== null ? (float) $this->calculated_agent_amount : null,
            'actualAgentAmount' => $this->actual_agent_amount !== null ? (float) $this->actual_agent_amount : null,
            'agentCheckStatus' => $this->agent_check_status ?? '',
        ];
    }
}
