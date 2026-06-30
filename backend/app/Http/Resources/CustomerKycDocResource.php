<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerKycDoc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerKycDoc
 */
class CustomerKycDocResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'fileName' => $this->file_name,
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
            'uploadedByAgentId' => $this->uploaded_by_agent_id !== null ? (string) $this->uploaded_by_agent_id : null,
            'verified' => (bool) $this->verified,
        ];
    }
}
