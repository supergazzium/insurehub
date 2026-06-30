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
            'toAgentId' => $this->to_agent_id !== null ? (string) $this->to_agent_id : null,
            'reason' => $this->reason ?? '',
            'byUserId' => $this->by_user_id !== null ? (string) $this->by_user_id : null,
            'at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
