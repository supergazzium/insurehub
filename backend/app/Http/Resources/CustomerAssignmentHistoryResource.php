<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerAssignmentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAssignmentHistory
 */
class CustomerAssignmentHistoryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fromAgentId' => $this->from_agent_id !== null ? (string) $this->from_agent_id : null,
            'fromAgentCode' => $this->relationLoaded('fromAgent') ? $this->fromAgent?->agent_code : null,
            'fromAgentName' => $this->relationLoaded('fromAgent') && $this->fromAgent ? trim(($this->fromAgent->first_name ?? '').' '.($this->fromAgent->last_name ?? '')) : null,
            'toAgentId' => $this->to_agent_id !== null ? (string) $this->to_agent_id : null,
            'toAgentCode' => $this->relationLoaded('toAgent') ? $this->toAgent?->agent_code : null,
            'toAgentName' => $this->relationLoaded('toAgent') && $this->toAgent ? trim(($this->toAgent->first_name ?? '').' '.($this->toAgent->last_name ?? '')) : null,
            'reason' => $this->reason ?? '',
            'byUserId' => $this->by_user_id !== null ? (string) $this->by_user_id : null,
            'byUserName' => $this->relationLoaded('user') ? $this->user?->name : null,
            'at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
