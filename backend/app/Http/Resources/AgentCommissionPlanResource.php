<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AgentCommissionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentCommissionPlan
 */
class AgentCommissionPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentId' => (string) $this->agent_id,
            'productId' => $this->product_id !== null ? (string) $this->product_id : null,
            'category' => $this->category,
            'agRate' => $this->ag_rate !== null ? (float) $this->ag_rate : null,
            'inhRate' => $this->inh_rate !== null ? (float) $this->inh_rate : null,
            'overrideRate' => $this->override_rate !== null ? (float) $this->override_rate : null,
            'validStart' => optional($this->valid_start)->toDateString(),
            'validEnd' => optional($this->valid_end)->toDateString(),
            'note' => $this->note,
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
