<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyDocument
 */
class PolicyDocumentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'policyId' => (string) $this->policy_id,
            'type' => $this->type,
            'fileName' => $this->file_name,
            'uploadedAt' => $this->uploaded_at?->toIso8601String(),
            'uploadedByUserId' => $this->uploaded_by_user_id !== null ? (string) $this->uploaded_by_user_id : null,
        ];
    }
}
