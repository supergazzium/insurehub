<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyEvent
 */
class PolicyEventResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'type' => $this->type,
            'at' => $this->occurred_at?->toIso8601String(),
            'byUserId' => $this->by_user_id !== null ? (string) $this->by_user_id : null,
            'payload' => $this->payload ?? [],
        ];
    }
}
