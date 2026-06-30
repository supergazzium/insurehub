<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RecruitmentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecruitmentLink
 */
class RecruitmentLinkResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentId' => (string) $this->agent_id,
            'token' => $this->token,
            'generatedAt' => $this->generated_at?->toIso8601String() ?? '',
            'clicks' => (int) $this->clicks,
            'signups' => (int) $this->signups,
            'pendingSignups' => (int) $this->pending_signups,
            'revoked' => (bool) $this->revoked,
        ];
    }
}
