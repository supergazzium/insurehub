<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerReferralLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerReferralLink
 */
class CustomerReferralLinkResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentId' => (string) $this->agent_id,
            'productId' => $this->product_id !== null ? (string) $this->product_id : null,
            'campaign' => $this->campaign ?? '',
            'token' => $this->token,
            'generatedAt' => $this->generated_at?->toIso8601String() ?? '',
            'clicks' => (int) $this->clicks,
            'leads' => (int) $this->leads,
            'policies' => (int) $this->policies,
            'revoked' => (bool) $this->revoked,
        ];
    }
}
