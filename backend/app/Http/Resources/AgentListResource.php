<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean list-row shape for /api/v1/agents index.
 * Includes joined parent-agent columns so the client can render
 * "upline: X" without a follow-up fetch.
 *
 * @mixin \stdClass
 */
class AgentListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'agentCode' => $this->agent_code,
            'agentType' => $this->agent_type,
            'firstName' => $this->first_name ?? '',
            'lastName' => $this->last_name ?? '',
            'nickname' => $this->nickname ?? '',
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'level' => $this->level,
            'team' => $this->team ?? '',
            'teamNo' => $this->team_no ?? '',
            'headStatus' => $this->head_status ?? '',
            'licenseLifeNo' => $this->license_life_no ?? '',
            'licenseLifeExpiry' => $this->license_life_expiry,
            'licenseNonLifeNo' => $this->license_non_life_no ?? '',
            'licenseNonLifeExpiry' => $this->license_non_life_expiry,
            'parentAgentId' => $this->parent_agent_id !== null ? (string) $this->parent_agent_id : null,
            'parentAgentCode' => $this->parent_agent_code,
            'parentAgentName' => trim(($this->parent_agent_first_name ?? '') . ' ' . ($this->parent_agent_last_name ?? '')),
            'joinedAt' => $this->joined_at,
            'active' => (bool) $this->active,
        ];
    }
}
